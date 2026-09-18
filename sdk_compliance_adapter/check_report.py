"""Check inventory completeness in harness 1.0.0 Markdown; assertions stay advisory."""

import argparse
import json
from pathlib import Path
import re


PROFILES = ("lib_curl", "socket", "fork_curl")
INVENTORY = Path(__file__).with_name("expected_inventory.json")


def load_inventory():
    inventory = json.loads(INVENTORY.read_text())
    if set(inventory) != {"capture", "feature_flags"}:
        raise ValueError("Expected capture and feature_flags inventories")
    for suite, count in (("capture", 30), ("feature_flags", 17)):
        ids = inventory[suite]
        if len(ids) != count or len(set(ids)) != count:
            raise ValueError(f"Expected {count} unique {suite} IDs")
        if any(not name.startswith(suite + ".") for name in ids):
            raise ValueError(f"Invalid {suite} ID")
    return inventory


def check_report(report, profile):
    if profile not in PROFILES:
        raise ValueError(f"Unknown profile: {profile}")
    inventory = load_inventory()
    lines = iter(line for line in report.splitlines() if line.strip())

    def take(pattern):
        line = next(lines, "")
        match = re.fullmatch(pattern, line)
        if not match:
            raise ValueError(f"Expected {pattern!r}, got {line!r}")
        return match

    def counts(prefix=""):
        match = take(prefix + r"\*\*(\d+)/(\d+)\*\* tests passed(?:, \*\*(\d+)\*\* failed)?")
        passed, total, failed = int(match[1]), int(match[2]), int(match[3] or 0)
        if passed + failed != total:
            raise ValueError("Inconsistent summary totals")
        return passed, total, failed

    take(re.escape(f"# posthog-php-{profile} Compliance Report"))
    take(r"\*\*Date\*\*: .+")
    take(r"\*\*Duration\*\*: \d+ms")
    take(r"## (?:✅ All Tests Passed!|⚠️ Some Tests Failed)")
    overall = counts()
    take("---")
    seen_suites = set()
    all_passed = 0
    all_total = 0
    for line in lines:
        heading = re.fullmatch(r"## (\w+) Tests", line)
        if not heading:
            raise ValueError(f"Unexpected report content: {line!r}")
        suite = heading[1].lower()
        if suite not in inventory or suite in seen_suites:
            raise ValueError(f"Unexpected or duplicate suite: {suite}")
        seen_suites.add(suite)
        summary = counts(r"(?:✅|⚠️) ")
        take("<details>")
        take("<summary>View Details</summary>")
        take(re.escape("| Test | Status | Duration |"))
        take(re.escape("|------|--------|----------|"))
        # The pinned renderer title-cases IDs and replaces underscores with spaces.
        expected = {name.split(".", 1)[1].replace("_", " ").title(): name
                    for name in inventory[suite]}
        if len(expected) != len(inventory[suite]):
            raise ValueError("Inventory has ambiguous rendered names")
        seen = set()
        passed = 0
        line = next(lines, "")
        while line.startswith("|"):
            row = re.fullmatch(r"\| (.+?) \| (✅|❌) \| \d+ms \|", line)
            if not row or row[1] not in expected:
                raise ValueError(f"Unexpected test row: {line!r}")
            if row[1] in seen:
                raise ValueError(f"Duplicate test ID: {expected[row[1]]}")
            seen.add(row[1])
            passed += row[2] == "✅"
            line = next(lines, "")
        if seen != set(expected):
            missing = [expected[name] for name in sorted(set(expected) - seen)]
            raise ValueError(f"Missing IDs: {missing}")
        if summary != (passed, len(seen), len(seen) - passed):
            raise ValueError(f"{suite} summary differs from test rows")
        if line == "### Failures":
            # Diagnostics may contain table-like text; only the results table counts.
            for line in lines:
                if line == "</details>":
                    break
        if line != "</details>":
            raise ValueError("Missing suite closing details tag")
        all_passed += passed
        all_total += len(seen)
    if seen_suites != set(inventory):
        raise ValueError("Missing test suites")
    if overall != (all_passed, all_total, all_total - all_passed):
        raise ValueError("Overall summary differs from test rows")
    return f"{profile}: selected={all_total}, passed={all_passed}, failed={all_total - all_passed}"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("profile", choices=PROFILES)
    parser.add_argument("report", type=Path)
    args = parser.parse_args()
    try:
        print(check_report(args.report.read_text(), args.profile))
    except (OSError, ValueError) as error:
        parser.exit(1, f"Report completeness failed: {error}\n")


if __name__ == "__main__":
    main()
