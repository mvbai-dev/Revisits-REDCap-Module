#!/usr/bin/env python3
"""Prüft, ob Änderungen am PHP-Code des Moduls einen Review-Nachweis haben.

Nachweis ist eines von beiden:
  - eine Zeile "Sicherheitsreview: <Text>" in der Commit-Nachricht
    (auch "Sicherheitsreview: nicht nötig (Grund)"), oder
  - eine inhaltliche Änderung im README-Abschnitt "## Sicherheit".
Ausgenommen sind Dateien unter tests/ und phpstan/.

Aufrufe:
  check-review-vermerk.py --staged <commit-msg-datei>   (commit-msg-Hook)
  check-review-vermerk.py --commits <sha> [<sha> ...]   (CI)
Exit-Code 0 = in Ordnung, 1 = Nachweis fehlt.
"""
import re
import subprocess
import sys

TRAILER = re.compile(r"^Sicherheitsreview:[ \t]*\S", re.MULTILINE)


def git(*args):
    r = subprocess.run(["git", *args], capture_output=True, text=True)
    return r.stdout if r.returncode == 0 else ""


def relevant_php(paths):
    return [p for p in paths if p.endswith(".php") and not p.startswith(("tests/", "phpstan/"))]


def sicherheit_section(readme):
    m = re.search(r"^## Sicherheit[ \t]*$(.*?)(?=^## |\Z)", readme, re.MULTILINE | re.DOTALL)
    return m.group(1).strip() if m else None


def check(php, message, readme_before, readme_after, label):
    if not php:
        return True
    if TRAILER.search(message or ""):
        return True
    if sicherheit_section(readme_after) is not None and sicherheit_section(readme_before) != sicherheit_section(readme_after):
        return True
    print(f"{label}: ändert PHP-Code ({', '.join(php[:3])}{' …' if len(php) > 3 else ''}) ohne Review-Nachweis.\n"
          "  Entweder den README-Abschnitt \"## Sicherheit\" aktualisieren oder in die Commit-Nachricht schreiben:\n"
          "    Sicherheitsreview: <Ergebnis>   bzw.   Sicherheitsreview: nicht nötig (<Grund>)", file=sys.stderr)
    return False


def main():
    if len(sys.argv) >= 3 and sys.argv[1] == "--staged":
        message = "\n".join(l for l in open(sys.argv[2], encoding="utf-8").read().splitlines() if not l.startswith("#"))
        php = relevant_php(git("diff", "--cached", "--name-only", "--diff-filter=ACMRD").split())
        ok = check(php, message, git("show", "HEAD:README.md"), git("show", ":README.md"), "Commit")
        return 0 if ok else 1
    if len(sys.argv) >= 2 and sys.argv[1] == "--commits":
        ok = True
        for sha in sys.argv[2:]:
            php = relevant_php(git("diff-tree", "--no-commit-id", "--name-only", "-r", "--root", sha).split())
            ok &= check(php, git("log", "-1", "--format=%B", sha), git("show", f"{sha}^:README.md"), git("show", f"{sha}:README.md"), f"Commit {sha[:7]}")
        return 0 if ok else 1
    print(__doc__, file=sys.stderr)
    return 2


if __name__ == "__main__":
    sys.exit(main())
