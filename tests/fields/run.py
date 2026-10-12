#!/usr/bin/env python3
"""Run a tests/fields script in a throwaway WordPress Playground.

Boots WordPress with this theme mounted and activated (same setup steps as the
Playground smoke test) and runs one script inside it:

  capture  capture.php: rewrites fixtures/carbon-fields-<version>.json
  compare  compare.php: App\\Fields\\Store and Registry against Carbon Fields;
           exits 1 on any difference

Needs Node.js (npx) and `composer install`. Activating the theme rewrites
theme.json in the mounted checkout; the file is restored afterwards.

Usage: python3 tests/fields/run.py capture|compare [--php 8.4] [--wp latest]
"""
import argparse
import json
import os
import subprocess
import sys
import tempfile

CLI = "@wp-playground/cli@3.1.57"
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
THEME_VFS = "/wordpress/wp-content/themes/adventistai"


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("script", choices=["capture", "compare"])
    parser.add_argument("--php", default="8.4")
    parser.add_argument("--wp", default="latest")
    args = parser.parse_args()

    if not os.path.isfile(os.path.join(ROOT, "vendor", "autoload.php")):
        sys.exit("Run composer install first.")
    with open(os.path.join(ROOT, ".github", "playground", "config.json"), encoding="utf-8") as fh:
        config = json.load(fh)

    steps = [{"step": "defineWpConfigConsts", "consts": {"WP_DEBUG": True, "WP_DEBUG_DISPLAY": True}}]
    steps += config.get("setup", [])
    steps.append({"step": "activateTheme", "themeFolderName": "adventistai"})
    # Playground does not print runPHP output or errors, so they go to a file.
    log_rel = os.path.join("tests", "fields", "fixtures", args.script + ".log")
    log = f"{THEME_VFS}/tests/fields/fixtures/{args.script}.log"
    log_host = os.path.join(ROOT, log_rel)
    if os.path.exists(log_host):
        os.remove(log_host)
    steps.append({"step": "runPHP", "code": (
        "<?php @mkdir(dirname('" + log + "'), 0777, true);"
        " register_shutdown_function(function () { $e = error_get_last();"
        " if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {"
        " file_put_contents('" + log + "', 'FATAL ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line'] . \"\\n\", FILE_APPEND); } });"
        " ob_start(); try { require '/wordpress/wp-load.php';"
        f" require '{THEME_VFS}/tests/fields/{args.script}.php';"
        " } catch (Throwable $t) { echo 'ERROR ' . $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine() . \"\\n\" . $t->getTraceAsString(); }"
        " file_put_contents('" + log + "', ob_get_clean(), FILE_APPEND);"
    )})
    # run-blueprint ignores --php/--wp (CLI 3.1.57 always ran PHP 8.5.10);
    # preferredVersions in the blueprint is honoured.
    blueprint = {"$schema": "https://playground.wordpress.net/blueprint-schema.json",
                 "preferredVersions": {"php": args.php, "wp": args.wp}, "steps": steps}

    theme_json = os.path.join(ROOT, "theme.json")
    with open(theme_json, "rb") as fh:
        original = fh.read()
    with tempfile.TemporaryDirectory() as tmp:
        path = os.path.join(tmp, "blueprint.json")
        with open(path, "w", encoding="utf-8") as fh:
            json.dump(blueprint, fh)
        cmd = ["npx", "--yes", CLI, "run-blueprint", f"--blueprint={path}", "--mount-dir", ROOT, THEME_VFS]
        try:
            result = subprocess.run(cmd, cwd=ROOT, shell=(os.name == "nt"))
        finally:
            with open(theme_json, "rb") as fh:
                if fh.read() != original:
                    with open(theme_json, "wb") as out:
                        out.write(original)
                    print("Restored theme.json (rewritten by theme activation).")
    if result.returncode:
        sys.exit(result.returncode)

    output = ""
    if os.path.exists(log_host):
        with open(log_host, encoding="utf-8") as fh:
            output = fh.read()
    print(output, end="")
    if args.script == "compare":
        lines = output.splitlines()
        summary = [line for line in lines if line.startswith("compare: ")]
        if not summary or not summary[0].endswith(" 0 failures") or any(
                line.startswith(("ERROR", "FATAL")) for line in lines):
            sys.exit(f"compare failed (log: {log_rel})")


if __name__ == "__main__":
    main()
