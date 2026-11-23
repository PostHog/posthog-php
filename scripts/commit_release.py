#!/usr/bin/env python3
"""Create a GitHub-signed commit, atomically rejecting a moved destination branch.

Only called by the environment-approved workflow. Does not run Composer, package
hooks, git hooks, or shell code. Authentication is supplied to gh by the environment.
"""

import argparse
import base64
import json
from pathlib import Path
import re
import subprocess
import sys

sys.dont_write_bytecode = True
from release import allowed_distribution_file, distribution_files

SOURCE_FILES = {
    "package.json", "composer.json", "composer.lock", "lib/PostHog.php", "CHANGELOG.md",
    "packages/laravel/package.json", "packages/laravel/composer.json",
    "packages/laravel/src/PostHog.php", "packages/laravel/CHANGELOG.md", ".changeset/ledger.yaml",
}
QUERY = """
mutation($input: CreateCommitOnBranchInput!) {
  createCommitOnBranch(input: $input) { commit { oid } }
}
"""


def request(checkout, repository, mode, message):
    if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository):
        raise ValueError("Invalid repository")
    def git(*args):
        return subprocess.check_output(["git", *args], cwd=checkout)
    expected = git("rev-parse", "HEAD").decode().strip()
    # Include untracked distribution files, with no hooks or identity/signing configuration.
    paths = set(filter(None, git("diff", "--name-only", "--no-renames", "-z", "HEAD").decode().split("\0")))
    paths.update(filter(None, git("ls-files", "--others", "--exclude-standard", "-z").decode().split("\0")))
    additions, deletions = [], []
    for name in sorted(paths):
        permitted = (name in SOURCE_FILES or bool(re.fullmatch(r"\.changeset/[A-Za-z0-9_-]+\.md", name))) if mode == "source" else (
            allowed_distribution_file(name) or name == "posthog-source.json"
        )
        path = checkout / name
        # Any obsolete tracked distribution file may be deleted, but only allowlisted
        # package files may be added. Source changes always use the narrow allowlist.
        if not permitted and (mode == "source" or path.exists() or path.is_symlink()):
            raise ValueError(f"Unexpected {mode} release change: {name}")
        if path.is_symlink():
            raise ValueError(f"Symlink in release: {name}")
        if path.exists():
            additions.append({"path": name, "contents": base64.b64encode(path.read_bytes()).decode()})
        else:
            deletions.append({"path": name})
    if not paths:
        if mode == "distribution":
            return None
        raise ValueError("No release changes")
    return {"query": QUERY, "variables": {"input": {
        "branch": {"repositoryNameWithOwner": repository, "branchName": "main"},
        "expectedHeadOid": expected,
        "message": {"headline": message},
        "fileChanges": {"additions": additions, "deletions": deletions},
    }}}


def matching_distribution_commit(checkout, repository, candidate):
    files = distribution_files(candidate)
    def git(*args):
        return subprocess.check_output(["git", *args], cwd=checkout)
    head = git("rev-parse", "HEAD").decode().strip()
    committed = {}
    for entry in filter(None, git("ls-tree", "-r", "-z", head).split(b"\0")):
        metadata, name = entry.split(b"\t", 1)
        if not metadata.startswith(b"100644 blob "):
            raise ValueError("Distribution HEAD does not match the approved candidate")
        name = name.decode()
        committed[name] = git("show", f"{head}:{name}")
    if committed != files:
        raise ValueError("Distribution HEAD does not match the approved candidate")
    verified = subprocess.check_output([
        "gh", "api", f"repos/{repository}/commits/{head}", "--jq", ".commit.verification.verified",
    ]).decode().strip()
    if verified != "true":
        raise ValueError("Matching distribution commit must have a verified signature")
    return head


def landed_commit(checkout, repository, payload):
    """Return the commit an ambiguously failed mutation landed, or explain why it did not.

    Accepts only a verified single-parent child of expectedHeadOid whose changes are
    exactly the requested file changes. Never issues the mutation again.
    """
    expected = payload["variables"]["input"]["expectedHeadOid"]
    changes = payload["variables"]["input"]["fileChanges"]
    head = subprocess.check_output([
        "gh", "api", f"repos/{repository}/git/ref/heads/main", "--jq", ".object.sha",
    ]).decode().strip()
    if head == expected:
        raise ValueError("destination branch did not move")
    if not re.fullmatch(r"[a-f0-9]{40}", head):
        raise ValueError("invalid destination head")
    commit = json.loads(subprocess.check_output(["gh", "api", f"repos/{repository}/commits/{head}"]))
    if [parent["sha"] for parent in commit["parents"]] != [expected]:
        raise ValueError(f"destination head {head} is not a direct child of {expected}")
    if commit["commit"]["verification"]["verified"] is not True:
        raise ValueError(f"destination head {head} has no verified signature")
    def git(*args, **kwargs):
        return subprocess.check_output(["git", "-c", "core.hooksPath=/dev/null", *args], cwd=checkout, **kwargs)
    git("fetch", "--quiet", "--no-tags", "--no-write-fetch-head", "origin", head)
    wanted = {change["path"]: base64.b64decode(change["contents"]) for change in changes["additions"]}
    wanted.update({change["path"]: None for change in changes["deletions"]})
    landed = {}
    fields = git("diff-tree", "-r", "-z", "--no-renames", expected, head).split(b"\0")
    for metadata, name in zip(fields[0::2], fields[1::2]):
        old_mode, new_mode, _, blob, status = metadata.decode().lstrip(":").split(" ")
        if status == "D":
            landed[name.decode()] = None
        elif new_mode in {"100644", old_mode}:
            landed[name.decode()] = blob
        else:
            raise ValueError(f"unexpected mode {new_mode} for {name.decode()}")
    for name, data in wanted.items():
        if data is not None:
            wanted[name] = git("hash-object", "--stdin", input=data).decode().strip()
    if landed != wanted:
        raise ValueError(f"destination head {head} does not contain exactly the requested changes")
    return head


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--checkout", type=Path, required=True)
    parser.add_argument("--repository", required=True)
    parser.add_argument("--mode", choices=["source", "distribution"], required=True)
    parser.add_argument("--message", required=True)
    parser.add_argument("--candidate", type=Path)
    args = parser.parse_args()
    payload = request(args.checkout, args.repository, args.mode, args.message)
    if payload is None:
        if args.candidate is None:
            raise ValueError("Distribution retry requires the approved candidate")
        commit = matching_distribution_commit(args.checkout, args.repository, args.candidate)
    else:
        try:
            result = subprocess.check_output(["gh", "api", "graphql", "--input", "-"], input=json.dumps(payload).encode())
            response = json.loads(result)
            if response.get("errors"):
                raise RuntimeError(response["errors"])
            commit = response["data"]["createCommitOnBranch"]["commit"]["oid"]
        except (subprocess.CalledProcessError, RuntimeError, ValueError, KeyError, TypeError) as error:
            # A gateway error can be returned after GitHub already applied the commit.
            try:
                commit = landed_commit(args.checkout, args.repository, payload)
            except Exception as reconciliation:
                raise RuntimeError(f"{error!r}; reconciliation: {reconciliation}") from error
            print(f"Commit request failed ({error!r}) but GitHub applied it as {commit}", file=sys.stderr)
    if not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise ValueError("Invalid commit returned by GitHub")
    print(f"commit-hash={commit}")


if __name__ == "__main__":
    main()
