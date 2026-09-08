"""Regressions for the pinned harness Markdown inventory gate (no SDK/network)."""

from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

from check_report import check_report, load_inventory, PROFILES


def report_fixture(profile="lib_curl", failed=False):
    inventory = load_inventory()
    lines = [f"# posthog-php-{profile} Compliance Report", "", "**Date**: 2026-09-07T00:00:00Z",
             "**Duration**: 100ms", "", "## ⚠️ Some Tests Failed" if failed else "## ✅ All Tests Passed!",
             "", "**46/47** tests passed, **1** failed" if failed else "**47/47** tests passed", "", "---", ""]
    for suite, ids in inventory.items():
        suite_failed = failed and suite == "capture"
        lines += [f"## {suite.title()} Tests", "",
                  f"⚠️ **{len(ids) - 1}/{len(ids)}** tests passed, **1** failed" if suite_failed
                  else f"✅ **{len(ids)}/{len(ids)}** tests passed", "", "<details>",
                  "<summary>View Details</summary>", "", "| Test | Status | Duration |",
                  "|------|--------|----------|"]
        for index, name in enumerate(ids):
            status = "❌" if suite_failed and index == 0 else "✅"
            display = name.split(".", 1)[1].replace("_", " ").title()
            lines.append(f"| {display} | {status} | 1ms |")
        if suite_failed:
            lines += ["", "### Failures", "", f"**{ids[0].split('.', 1)[1]}**", "```",
                      "Expected 1 requests, got 0", "```"]
        lines += ["", "</details>", ""]
    return "\n".join(lines)


class ReportInventoryTest(unittest.TestCase):
    def test_complete_passing_and_failing_reports_for_every_profile(self):
        for profile in PROFILES:
            for failed in (False, True):
                with self.subTest(profile=profile, failed=failed):
                    self.assertEqual(check_report(report_fixture(profile, failed), profile),
                                     f"{profile}: selected=47, passed={46 if failed else 47}, failed={int(failed)}")

    def test_missing_empty_and_incomplete_reports_fail(self):
        report = report_fixture()
        row = next(line for line in report.splitlines() if line.startswith("| Format"))
        variants = ["", "# posthog-php-lib_curl Compliance Report", report.replace(row + "\n", ""),
                    report.split("## Feature_Flags Tests")[0], report.replace("</details>", "", 1)]
        for malformed in variants:
            with self.subTest(report=malformed[:80]):
                with self.assertRaises(ValueError):
                    check_report(malformed, "lib_curl")
        with tempfile.TemporaryDirectory() as directory:
            result = subprocess.run([sys.executable, str(Path(__file__).with_name("check_report.py")),
                                     "lib_curl", str(Path(directory) / "missing.md")], capture_output=True)
            self.assertEqual(result.returncode, 1)
            self.assertIn(b"Report completeness failed", result.stderr)

    def test_zero_test_report_fails(self):
        report = report_fixture()
        for suite_ids in load_inventory().values():
            for name in suite_ids:
                display = name.split(".", 1)[1].replace("_", " ").title()
                report = report.replace(f"| {display} | ✅ | 1ms |\n", "")
        for count in (47, 30, 17):
            report = report.replace(f"{count}/{count}", "0/0")
        with self.assertRaises(ValueError):
            check_report(report, "lib_curl")

    def test_duplicate_unexpected_and_malformed_rows_fail(self):
        report = report_fixture()
        rows = [line for line in report.splitlines() if line.startswith("| Format")]
        variants = [report.replace(rows[1], rows[0]), report.replace(rows[0], rows[0] + "\n" + rows[0]),
                    report.replace(rows[0], "| Unexpected Test | ✅ | 1ms |"),
                    report.replace(rows[0], rows[0].replace("✅", "SKIP")),
                    report.replace(rows[0], rows[0].replace("1ms", "unknown"))]
        for malformed in variants:
            with self.subTest(report=malformed[:80]):
                with self.assertRaises(ValueError):
                    check_report(malformed, "lib_curl")

    def test_duplicate_unexpected_suites_and_wrong_profile_fail(self):
        report = report_fixture()
        variants = [report + report[report.index("## Feature_Flags Tests"):],
                    report.replace("## Feature_Flags Tests", "## Unexpected Tests"),
                    report.replace("posthog-php-lib_curl", "posthog-php-socket")]
        for malformed in variants:
            with self.assertRaises(ValueError):
                check_report(malformed, "lib_curl")

    def test_inconsistent_overall_and_suite_counts_fail(self):
        report = report_fixture()
        variants = [report.replace("47/47", "46/47"), report.replace("47/47", "47/48"),
                    report.replace("30/30", "29/30"), report.replace("17/17", "17/18"),
                    report.replace("47/47", "46/47").replace("**46/47** tests passed", "**46/47** tests passed, **1** failed"),
                    report_fixture(failed=True).replace("**1** failed", "**2** failed"),
                    report.replace("| ✅ |", "| ❌ |", 1)]
        for malformed in variants:
            with self.assertRaises(ValueError):
                check_report(malformed, "lib_curl")

    def test_failure_diagnostics_are_not_counted_as_result_rows(self):
        report = report_fixture(failed=True).replace("Expected 1 requests, got 0",
                                                    "| Diagnostic Text | ❌ | 0ms |")
        self.assertIn("selected=47, passed=46, failed=1", check_report(report, "lib_curl"))


if __name__ == "__main__":
    unittest.main()
