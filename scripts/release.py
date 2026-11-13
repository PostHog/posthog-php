#!/usr/bin/env python3
"""Prepare one dependency-aware PHP/Laravel release candidate without publishing credentials."""

import argparse
import gzip
import hashlib
import io
import json
from pathlib import Path, PurePosixPath
import re
import shutil
import subprocess
import sys
import tarfile
import tempfile

PACKAGE = Path("packages/laravel")
CHANGELOG = PACKAGE / "CHANGELOG.md"
FORBIDDEN = {"vendor", "tests", "test", "node_modules", "storage", "cache", "secrets"}


def run(*args, cwd):
    try:
        return subprocess.check_output(args, cwd=cwd, stderr=subprocess.PIPE)
    except subprocess.CalledProcessError as error:
        if error.output:
            print(error.output.decode(errors="replace"), end="", file=sys.stdout, flush=True)
        if error.stderr:
            print(error.stderr.decode(errors="replace"), end="", file=sys.stderr, flush=True)
        raise


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


def prepare(root, output, repository, bump_script_sha256=None):
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
        current_core = read_json(work / "package.json")["version"]
        current = metadata["version"]
        if metadata.get("dependencies", {}).get("posthog-php") != "workspace:*":
            raise ValueError("Laravel must declare its workspace dependency on posthog-php")
        if composer["require"]["posthog/posthog-php"] != current_core:
            raise ValueError("Laravel must pin the exact current core SDK version")
        if composer.get("version", current) != current:
            raise ValueError("Laravel package.json/composer.json version mismatch")
        if composer["name"] != "posthog/posthog-laravel" or "repositories" in composer:
            raise ValueError("Expected production Laravel Composer metadata (no repository overrides)")
        runtime = work / PACKAGE / "src/PostHog.php"
        declaration = re.compile(r"(public const VERSION = ')([^']+)(';)")
        declarations = declaration.findall(runtime.read_text())
        if len(declarations) != 1 or declarations[0][1] != current:
            raise ValueError("Laravel runtime VERSION mismatch")
        core_runtime = work / "lib/PostHog.php"
        if read_json(work / "composer.json")["version"] != current_core or (
            [match[1] for match in declaration.findall(core_runtime.read_text())] != [current_core]
        ):
            raise ValueError("Core SDK version mismatch")
        run("pnpm", "version", "-r", cwd=work)
        new = read_json(work / PACKAGE / "package.json")["version"]
        core_version = read_json(work / "package.json")["version"]
        core_changed = core_version != current_core
        if new == current:
            if core_changed:
                raise ValueError("Core release must include its Laravel dependent")
            raise ValueError("No pending change intents")
        if current == "0.0.0" and new.split(".")[:2] == ["0", "0"]:
            raise ValueError("Initial Laravel release requires a minor or major intent")
        if core_changed:
            script = work / "scripts/bump-version.sh"
            if hashlib.sha256(script.read_bytes()).hexdigest() != bump_script_sha256:
                raise ValueError("Core bump script sha256 mismatch")
            run("bash", str(script), core_version, cwd=work)
            run("composer", "update", "--lock", "--no-install", "--no-interaction", "--no-audit", "--no-scripts", "--no-plugins", cwd=work)
            run("git", "add", "--intent-to-add", "CHANGELOG.md", cwd=work)
        runtime.write_text(declaration.sub(lambda match: match[1] + new + match[3], runtime.read_text()))
        composer["version"] = new
        composer["require"]["posthog/posthog-php"] = core_version
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
            if relative == "LICENSE.md" and path.is_symlink() and path.readlink() == Path("../../LICENSE.md"):
                path = work / "LICENSE.md"
            if path.is_symlink() or not path.is_file():
                raise ValueError(f"Not a regular distribution file: {name}")
            files[relative] = path.read_bytes()
        if not {"composer.json", "README.md", "LICENSE.md"}.issubset(files) or not any(n.startswith("src/") for n in files):
            raise ValueError("Incomplete Laravel package")
        tag = f"posthog-laravel-v{new}"
        files["posthog-source.json"] = (json.dumps({
            "repository": repository, "commit": source, "tag": tag,
            "directory": PACKAGE.as_posix(), "version": new,
        }, indent=2) + "\n").encode()
        patch = run("git", "diff", "--binary", cwd=work)
        changed = run("git", "diff", "--name-only", cwd=work).decode().splitlines()
        permitted = {str(PACKAGE / "composer.json"), str(PACKAGE / "package.json"),
                     str(PACKAGE / "src/PostHog.php"), str(CHANGELOG), ".changeset/ledger.yaml"}
        if core_changed:
            permitted.update({"package.json", "composer.json", "composer.lock", "lib/PostHog.php", "CHANGELOG.md"})
        permitted.update(str(path.relative_to(work)) for path in pending)
        if not set(changed).issubset(permitted):
            raise ValueError(f"Unexpected release changes: {changed}")
        payload = archive(files)
        output.mkdir(parents=True)
        (output / "release.patch").write_bytes(patch)
        (output / "package.tar.gz").write_bytes(payload)
        (output / "notes.md").write_text(notes + f"Source: {repository}@{source} ({PACKAGE})\n")
        core_notes = ""
        if core_changed:
            changelog = (work / "CHANGELOG.md").read_text()
            core_notes = changelog[changelog.index(f"## {core_version}\n"):].split("\n## ", 1)[0]
        (output / "core-notes.md").write_text(core_notes)
        write_json(output / "candidate.json", {
            "version": new, "core_version": core_version, "core_changed": core_changed,
            "source": source, "source_tag": tag, "distribution_tag": new,
            "patch_sha256": hashlib.sha256(patch).hexdigest(),
            "archive_sha256": hashlib.sha256(payload).hexdigest(),
        })
        validate(work, output)


def validate(root, candidate):
    manifest = read_json(candidate / "candidate.json")
    for path, runtime, version in [
        (Path(), Path("lib/PostHog.php"), manifest["core_version"]),
        (PACKAGE, PACKAGE / "src/PostHog.php", manifest["version"]),
    ]:
        composer = read_json(root / path / "composer.json")
        declared = re.findall(r"public const VERSION = '([^']+)';", (root / runtime).read_text())
        if read_json(root / path / "package.json")["version"] != version or composer["version"] != version or declared != [version]:
            raise ValueError(f"Version mismatch: {path}")
    if read_json(root / PACKAGE / "composer.json")["require"]["posthog/posthog-php"] != manifest["core_version"]:
        raise ValueError("Laravel core SDK pin mismatch")


def distribution_files(candidate):
    manifest = read_json(candidate / "candidate.json")
    payload = (candidate / "package.tar.gz").read_bytes()
    if hashlib.sha256(payload).hexdigest() != manifest["archive_sha256"]:
        raise ValueError("Distribution archive digest mismatch")
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
    return files


def install(candidate, destination, repository):
    """Replace a dedicated distribution checkout; never extract tar paths unchecked."""
    files = distribution_files(candidate)
    if not (destination / ".git").exists():
        raise ValueError("Destination must be a dedicated distribution Git checkout")
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
    candidate.add_argument("--bump-script-sha256")
    validation = sub.add_parser("validate")
    validation.add_argument("--root", type=Path, default=Path.cwd())
    validation.add_argument("--candidate", type=Path, required=True)
    unpack = sub.add_parser("install")
    unpack.add_argument("--candidate", type=Path, required=True)
    unpack.add_argument("--destination", type=Path, required=True)
    unpack.add_argument("--repository", required=True)
    args = parser.parse_args()
    if args.command == "prepare":
        prepare(args.root.resolve(), args.output.resolve(), args.repository, args.bump_script_sha256)
    elif args.command == "validate":
        validate(args.root.resolve(), args.candidate.resolve())
    else:
        install(args.candidate.resolve(), args.destination.resolve(), args.repository)


if __name__ == "__main__":
    main()
