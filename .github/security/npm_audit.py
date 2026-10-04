"""Fail on high/critical npm advisories, except narrow, dated, reviewed ones.

Shipped (production) dependencies are audited separately with plain
`npm audit --omit=dev --audit-level=high`; no exception can apply there. This
script audits the complete graph and fails on every high or critical advisory
unless .github/security/npm-audit-exceptions.json lists its GHSA ID with an
unexpired date. An excepted advisory that reaches production dependencies, or
an expired exception, still fails.
"""
import datetime
import json
import re
import subprocess
import sys

EXCEPTIONS = ".github/security/npm-audit-exceptions.json"
BLOCKING = {"high", "critical"}


def advisories(omit_dev):
    cmd = ["npm", "audit", "--json"] + (["--omit=dev"] if omit_dev else [])
    proc = subprocess.run(cmd, capture_output=True, text=True, shell=sys.platform == "win32")
    try:
        data = json.loads(proc.stdout)
    except json.JSONDecodeError:
        sys.exit(f"::error::npm audit did not return JSON (exit {proc.returncode}): {proc.stderr.strip()[:500]}")
    if "vulnerabilities" not in data:
        sys.exit(f"::error::npm audit failed: {json.dumps(data)[:500]}")
    found = {}
    for name, vuln in data["vulnerabilities"].items():
        for via in vuln.get("via", []):
            if isinstance(via, dict) and via.get("severity") in BLOCKING:
                match = re.search(r"GHSA-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}", via.get("url", ""))
                key = match.group(0) if match else f"npm-{via.get('source')}"
                found.setdefault(key, {"package": via.get("name", name), "title": via.get("title", ""), "severity": via["severity"]})
    return found


def main():
    today = datetime.date.today()
    exceptions = {e["id"]: e for e in json.load(open(EXCEPTIONS, encoding="utf-8"))}
    for e in exceptions.values():
        for field in ("id", "package", "reason", "owner", "expires"):
            if not e.get(field):
                sys.exit(f"::error file={EXCEPTIONS}::exception {e.get('id')} is missing '{field}'")

    production = advisories(omit_dev=True)
    complete = advisories(omit_dev=False)
    failed = False
    for gid, adv in sorted(complete.items()):
        exc = exceptions.get(gid)
        label = f"{gid} {adv['severity']} in {adv['package']}: {adv['title']}"
        if exc is None:
            print(f"::error::{label}")
            failed = True
        elif gid in production:
            print(f"::error::{label} reaches production dependencies; the exception covers build tooling only")
            failed = True
        elif datetime.date.fromisoformat(exc["expires"]) < today:
            print(f"::error file={EXCEPTIONS}::exception for {gid} expired on {exc['expires']}; re-review it")
            failed = True
        else:
            print(f"::warning::{label} - accepted until {exc['expires']} ({exc['owner']}): {exc['reason']}")
    for gid in sorted(set(exceptions) - set(complete)):
        print(f"::warning file={EXCEPTIONS}::exception for {gid} no longer matches any advisory; remove it")
    print(f"{len(complete)} blocking advisories in the complete graph, {len(production)} in production.")
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
