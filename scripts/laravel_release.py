#!/usr/bin/env python3
"""Prepare Laravel releases without modifying the source checkout or using credentials."""

import argparse
import gzip
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import tarfile
import tempfile

PACKAGE = Path("packages/laravel")
CHANGELOG = PACKAGE / "CHANGELOG.md"
FORBIDDEN = {"vendor", "tests", "test", "node_modules", "storage", "cache", "secrets"}


def run(*args, cwd):
    return subprocess.check_output(args, cwd=cwd, stderr=subprocess.PIPE)


def read_json(path):
    return json.loads(path.read_text())


def write_json(path, value):
    path.write_text(json.dumps(value, indent=2) + "\n")


def allowed_distribution_file(name):
    path = PurePosixPath(name)
    if path.is_absolute() or any(p.startswith(".") or p.lower() in FORBIDDEN for p in path.parts):
        return False
    return name in {"composer.json", "README.md", "LICENSE.md", "CHANGELOG.md", "AUTOCAPTURE.md", "PENNANT.md"} or (
        len(path.parts) > 1 and path.parts[0] in {"src", "config"} and path.suffix == ".php"
    )


def archive(files):
    """Fixed ordering, ownership, mode and timestamps make byte-for-byte verification possible."""
    output = io.BytesIO()
    with gzip.GzipFile(fileobj=output, mode="wb", mtime=0, filename="") as zipped:
        with tarfile.open(fileobj=zipped, mode="w", format=tarfile.USTAR_FORMAT) as tar:
            for name, data in sorted(files.items()):
                entry = tarfile.TarInfo(name)
                entry.size = len(data)
                entry.mode = 0o644
                tar.addfile(entry, io.BytesIO(data))
    return output.getvalue()


def prepare(root, output, repository):
    if not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository):
        raise ValueError("Expected owner/repository")
    if output.exists():
        raise ValueError("Candidate output must not already exist")
    source = run("git", "rev-parse", "HEAD", cwd=root).decode().strip()
    # Only committed files enter the candidate; local vendors, .env and modifications never enter.
    with tempfile.TemporaryDirectory(prefix="laravel-release-") as temporary:
        work = Path(temporary) / "source"
        run("git", "clone", "--quiet", "--no-hardlinks", "--no-local", str(root), str(work), cwd=root)
        run("git", "checkout", "--quiet", "--detach", source, cwd=work)
        pending = sorted((work / ".changeset").glob("*.md"))
        metadata = read_json(work / PACKAGE / "package.json")
        composer = read_json(work / PACKAGE / "composer.json")
        current = metadata["version"]
        if composer.get("version", current) != current:
            raise ValueError("Laravel package.json/composer.json version mismatch")
        if composer["name"] != "posthog/posthog-laravel" or "repositories" in composer:
            raise ValueError("Expected production Laravel Composer metadata (no repository overrides)")
        runtime = work / PACKAGE / "src/PostHog.php"
        declaration = re.compile(r"(public const VERSION = ')([^']+)(';)")
        declarations = declaration.findall(runtime.read_text())
        if len(declarations) != 1 or declarations[0][1] != current:
            raise ValueError("Laravel runtime VERSION mismatch")
        run("pnpm", "--filter", "posthog-laravel", "version", "-r", cwd=work)
        new = read_json(work / PACKAGE / "package.json")["version"]
        if new == current:
            raise ValueError("No pending Laravel change intents")
        runtime.write_text(declaration.sub(lambda match: match[1] + new + match[3], runtime.read_text()))
        composer["version"] = new
        write_json(work / PACKAGE / "composer.json", composer)
        changelog = (work / CHANGELOG).read_text()
        notes = changelog[changelog.index(f"## {new}\n"):].split("\n## ", 1)[0]
        run("git", "add", "--intent-to-add", str(CHANGELOG), ".changeset/ledger.yaml", cwd=work)
        tracked = run("git", "ls-files", "-z", "--", str(PACKAGE), cwd=work).decode().split("\0")
        files = {}
        for name in filter(None, tracked):
            relative = Path(name).relative_to(PACKAGE).as_posix()
            if not allowed_distribution_file(relative):
                continue
            path = work / name
            if path.is_symlink() or not path.is_file():
                raise ValueError(f"Not a regular distribution file: {name}")
            files[relative] = path.read_bytes()
        if not {"composer.json", "README.md", "LICENSE.md"}.issubset(files) or not any(n.startswith("src/") for n in files):
            raise ValueError("Incomplete Laravel package")
        tag = f"laravel-v{new}"
        files["posthog-source.json"] = (json.dumps({
            "repository": repository, "commit": source, "tag": tag,
            "directory": PACKAGE.as_posix(), "version": new,
        }, indent=2) + "\n").encode()
        patch = run("git", "diff", "--binary", cwd=work)
        changed = run("git", "diff", "--name-only", cwd=work).decode().splitlines()
        permitted = {str(PACKAGE / "composer.json"), str(PACKAGE / "package.json"),
                     str(PACKAGE / "src/PostHog.php"), str(CHANGELOG), ".changeset/ledger.yaml"}
        permitted.update(str(path.relative_to(work)) for path in pending)
        if not set(changed).issubset(permitted):
            raise ValueError(f"Unexpected release changes: {changed}")
        payload = archive(files)
        output.mkdir(parents=True)
        (output / "release.patch").write_bytes(patch)
        (output / "package.tar.gz").write_bytes(payload)
        (output / "notes.md").write_text(notes + f"Source: {repository}@{source} ({PACKAGE})\n")
        write_json(output / "candidate.json", {
            "version": new, "source": source, "source_tag": tag, "distribution_tag": f"v{new}",
            "patch_sha256": hashlib.sha256(patch).hexdigest(),
            "archive_sha256": hashlib.sha256(payload).hexdigest(),
        })


def install(candidate, destination, repository):
    """Replace a dedicated distribution checkout; never extract tar paths unchecked."""
    manifest = read_json(candidate / "candidate.json")
    payload = (candidate / "package.tar.gz").read_bytes()
    if hashlib.sha256(payload).hexdigest() != manifest["archive_sha256"]:
        raise ValueError("Distribution archive digest mismatch")
    if not (destination / ".git").exists():
        raise ValueError("Destination must be a dedicated distribution Git checkout")
    files = {}
    with tarfile.open(fileobj=io.BytesIO(payload), mode="r:gz") as tar:
        for entry in tar:
            if not entry.isfile() or entry.name in files or not (
                allowed_distribution_file(entry.name) or entry.name == "posthog-source.json"
            ):
                raise ValueError(f"Unexpected archive member: {entry.name}")
            files[entry.name] = tar.extractfile(entry).read()
    if json.loads(files["composer.json"])["version"] != manifest["version"]:
        raise ValueError("Distribution version mismatch")
    source = json.loads(files["posthog-source.json"])
    if repository.lower() == source["repository"].lower() or not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository):
        raise ValueError("Distribution repository must be separate from source")
    remote = run("git", "remote", "get-url", "origin", cwd=destination).decode().strip()
    if remote not in {f"https://github.com/{repository}", f"https://github.com/{repository}.git"}:
        raise ValueError("Distribution checkout origin does not match configured repository")
    for path in destination.iterdir():
        if path.name == ".git":
            continue
        if path.is_dir() and not path.is_symlink():
            shutil.rmtree(path)
        else:
            path.unlink()
    for name, data in files.items():
        path = destination / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    candidate = sub.add_parser("prepare")
    candidate.add_argument("--root", type=Path, default=Path.cwd())
    candidate.add_argument("--output", type=Path, required=True)
    candidate.add_argument("--repository", required=True)
    unpack = sub.add_parser("install")
    unpack.add_argument("--candidate", type=Path, required=True)
    unpack.add_argument("--destination", type=Path, required=True)
    unpack.add_argument("--repository", required=True)
    args = parser.parse_args()
    if args.command == "prepare":
        prepare(args.root.resolve(), args.output.resolve(), args.repository)
    else:
        install(args.candidate.resolve(), args.destination.resolve(), args.repository)


if __name__ == "__main__":
    main()
