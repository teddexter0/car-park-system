"""Turn an agent's reviewed file groups into a Git patch series."""

import datetime
import json
import re
import subprocess
import sys
from pathlib import Path


PLAN = Path(".parking-commit-plan.json")
PROJECT_FILES = {
    "README.md", "api.php", "app.js", "config.php", "database.sql", "parking-bays.sql",
    "index.php", "style.css", "tests.php",
}


def git(*args, capture=False):
    return subprocess.run(
        ["git", "-c", "core.hooksPath=/dev/null", *args], check=True,
        stdout=subprocess.PIPE if capture else None,
    ).stdout


def paths_from_git(*args):
    return set(git(*args, capture=True).decode().rstrip("\0").split("\0")) - {""}


def validate_groups(groups, changed):
    if not isinstance(groups, list) or not 1 <= len(groups) <= 5:
        raise ValueError("Supply one to five coherent commit groups")
    remaining = changed.copy()
    for group in groups:
        message, paths = group["message"], group["paths"]
        if not isinstance(message, str) or not re.fullmatch(r"[^\r\n]{8,90}", message):
            raise ValueError("Each commit needs a single descriptive subject")
        if not isinstance(paths, list) or not paths:
            raise ValueError("Commit groups must include changed paths")
        for path in paths:
            if path not in remaining:
                raise ValueError(f"Path is unchanged, repeated or unavailable: {path}")
            if path not in PROJECT_FILES and not re.fullmatch(
                r"(?:docs|tests)/[a-zA-Z0-9][a-zA-Z0-9_.-]*\.(?:md|php|js|sql)", path
            ):
                raise ValueError(f"Path outside the development scope: {path}")
            if Path(path).is_symlink():
                raise ValueError(f"Symlink is outside the development scope: {path}")
            remaining.remove(path)
    if remaining:
        raise ValueError(f"Changes missing from the commit plan: {sorted(remaining)}")


def main(day, out_file):
    date = datetime.date.fromisoformat(day)
    if not datetime.date(2026, 11, 17) <= date <= datetime.date(2026, 11, 23):
        raise ValueError("Date falls outside the scheduled milestones")
    if paths_from_git("diff", "--cached", "--name-only", "-z"):
        raise ValueError("The agent must leave the index clean")
    base = git("rev-parse", "HEAD", capture=True).decode().strip()
    changes = paths_from_git("diff", "--name-only", "-z", "--no-renames")
    changes |= paths_from_git("ls-files", "--others", "--exclude-standard", "-z")
    groups = json.loads(PLAN.read_text(encoding="utf-8"))
    changes.discard(str(PLAN))
    validate_groups(groups, changes)
    PLAN.unlink()
    for group in groups:
        git("add", "-A", "--", *group["paths"])
        git("diff", "--cached", "--check")
        git(
            "-c", "user.name=Parking Codex automation",
            "-c", "user.email=41898282+github-actions[bot]@users.noreply.github.com",
            "commit", "-m", group["message"],
        )
    Path(out_file).write_bytes(git("format-patch", "--stdout", f"{base}..HEAD", capture=True))


if __name__ == "__main__":
    main(*sys.argv[1:])
