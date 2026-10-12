#!/usr/bin/env python3
"""Check that this checkout renders the same pages as an older one.

Starts one WordPress Playground with both checkouts mounted as themes
(`adventistai` = this one, `adventistai-old` = --old), seeds the content of
render-seed.php, then fetches every URL with each theme (picked per request by
a cookie, so the database, salts and clock are shared) for two sets of theme
options, and compares the HTML after replacing the theme folder name and
file-modification version strings. PHP log lines written while each theme
renders are compared too (the same notices may occur in both). Exits 1 on any
difference.

Both checkouts need `composer install`. Activating the theme rewrites
theme.json in both; the files are restored afterwards.

Usage: python3 tests/fields/render-compare.py --old ../alps-old [--php 8.4]
"""
import argparse
import difflib
import json
import os
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request

CLI = "@wp-playground/cli@3.1.57"
PORT = 9496
BASE = f"http://127.0.0.1:{PORT}"
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
THEMES = "/wordpress/wp-content/themes"

SWITCH = r"""<?php
// Test site only: the theme for this request comes from a cookie, and
// ?alps_render_options=N applies the second set of render-test options.
if (($_COOKIE['alps_theme'] ?? '') === 'old') {
    foreach (['template', 'stylesheet'] as $option) {
        add_filter('pre_option_' . $option, function () { return 'adventistai-old'; });
    }
    // Theme mods (menu locations …) are stored per theme folder.
    add_filter('pre_option_theme_mods_adventistai-old', function () { return get_option('theme_mods_adventistai'); });
}
if (isset($_GET['alps_render_options'])) {
    add_action('init', function () {
        $alps_render_seed = false;
        require get_template_directory() . '/tests/fields/render-seed.php';
        alps_render_options((int) $_GET['alps_render_options']);
        exit('ok');
    }, 20);
}
"""


def normalise(html):
    html = html.replace("adventistai-old", "adventistai")
    # filemtime() version strings differ between checkouts.
    return re.sub(r"([?&](?:ver|v)=)\d{9,}", r"\g<1>MTIME", html)


def log_lines(path, start):
    """New PHP log lines since byte offset start, without time or theme folder."""
    if not os.path.exists(path):
        return [], 0
    with open(path, encoding="utf-8", errors="replace") as fh:
        fh.seek(start)
        text = fh.read()
        end = fh.tell()
    lines = []
    for line in text.splitlines():
        if "PHP " not in line or "_load_textdomain_just_in_time" in line:
            continue
        line = re.sub(r"^\[[^]]+\] ", "", line).replace("adventistai-old", "adventistai")
        lines.append(re.sub(r"views/[0-9a-f]{32}\.php", "views/<compiled>.php", line))
    return lines, end


def get(path, theme):
    request = urllib.request.Request(BASE + path, headers={"User-Agent": "render-compare", "Cookie": f"alps_theme={theme}"})
    try:
        with urllib.request.urlopen(request, timeout=180) as resp:
            return resp.status, resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as err:
        return err.code, err.read().decode("utf-8", "replace")


def stop(proc):
    if proc.poll() is None:
        if os.name == "nt":
            subprocess.run(["taskkill", "/PID", str(proc.pid), "/T", "/F"], capture_output=True)
        else:
            os.killpg(proc.pid, 15)
        proc.wait(timeout=30)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--old", required=True, help="checkout of the version to compare against")
    parser.add_argument("--php", default="8.4")
    parser.add_argument("--wp", default="latest")
    parser.add_argument("--out", default=os.path.join(ROOT, "tests", "fields", "fixtures", "render"))
    args = parser.parse_args()
    old = os.path.abspath(args.old)
    for checkout in (ROOT, old):
        if not os.path.isfile(os.path.join(checkout, "vendor", "autoload.php")):
            sys.exit(f"Run composer install in {checkout} first.")
    with socket.socket() as sock:
        if sock.connect_ex(("127.0.0.1", PORT)) == 0:
            sys.exit(f"port {PORT} is already in use")
    with open(os.path.join(ROOT, ".github", "playground", "config.json"), encoding="utf-8") as fh:
        config = json.load(fh)

    urls_file = os.path.join(ROOT, "tests", "fields", "fixtures", "render-urls.json")
    if os.path.exists(urls_file):
        os.remove(urls_file)
    shutil.rmtree(args.out, ignore_errors=True)
    os.makedirs(args.out)
    logs = tempfile.mkdtemp()
    steps = [{"step": "defineWpConfigConsts", "consts": {
        "WP_DEBUG": True, "WP_DEBUG_LOG": "/logs/debug.log", "WP_DEBUG_DISPLAY": False, "SCRIPT_DEBUG": False}}]
    steps += config.get("setup", [])
    steps.append({"step": "writeFile", "path": "/wordpress/wp-content/mu-plugins/render-compare-switch.php", "data": SWITCH})
    steps.append({"step": "activateTheme", "themeFolderName": "adventistai"})
    steps.append({"step": "runPHP", "code": (
        "<?php require '/wordpress/wp-load.php'; $alps_render_seed = true;"
        f" require '{THEMES}/adventistai/tests/fields/render-seed.php';")})
    blueprint = {"$schema": "https://playground.wordpress.net/blueprint-schema.json",
                 "preferredVersions": {"php": args.php, "wp": args.wp}, "steps": steps}

    originals = {}
    for checkout in (ROOT, old):
        with open(os.path.join(checkout, "theme.json"), "rb") as fh:
            originals[checkout] = fh.read()
    bp_path = os.path.join(logs, "blueprint.json")
    with open(bp_path, "w", encoding="utf-8") as fh:
        json.dump(blueprint, fh)
    cmd = ["npx", "--yes", CLI, "server", f"--port={PORT}", f"--blueprint={bp_path}",
           "--mount-dir", logs, "/logs",
           "--mount-dir", ROOT, f"{THEMES}/adventistai",
           "--mount-dir", old, f"{THEMES}/adventistai-old"]
    extra = {"creationflags": subprocess.CREATE_NEW_PROCESS_GROUP} if os.name == "nt" else {"start_new_session": True}
    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
                            encoding="utf-8", errors="replace", shell=os.name == "nt", **extra)
    ready = threading.Event()
    threading.Thread(target=lambda: [ready.set() for line in proc.stdout if "Ready!" in line], daemon=True).start()

    failures = []
    checked = 0
    log_path = os.path.join(logs, "debug.log")
    logged = {"old": [], "new": []}
    offset = 0
    try:
        deadline = time.time() + 600
        while time.time() < deadline and not ready.is_set() and proc.poll() is None:
            time.sleep(1)
        if not ready.is_set():
            sys.exit("Playground did not start")
        with open(urls_file, encoding="utf-8") as fh:
            urls = json.load(fh)
        for options in (1, 2):
            if options == 2:
                status, body = get("/?alps_render_options=2", "new")
                if body.strip() != "ok":
                    sys.exit(f"could not switch options: HTTP {status} {body[:300]}")
            _, offset = log_lines(log_path, offset)
            rendered = {}
            for theme in ("old", "new"):
                for url in urls:
                    status, body = get(url, theme)
                    rendered[(theme, url)] = (status, normalise(body))
                lines, offset = log_lines(log_path, offset)
                logged[theme] += lines
            for url in urls:
                pages = {theme: rendered[(theme, url)] for theme in ("old", "new")}
                checked += 1
                name = f"o{options}-" + re.sub(r"[^a-z0-9]+", "-", url.lower()).strip("-")
                if pages["old"] == pages["new"]:
                    print(f"same  options {options}  {url}  (HTTP {pages['new'][0]}, {len(pages['new'][1])} bytes)")
                    continue
                failures.append(f"options {options} {url}")
                for theme in ("old", "new"):
                    with open(os.path.join(args.out, f"{name}.{theme}.html"), "w", encoding="utf-8") as fh:
                        fh.write(pages[theme][1])
                diff = list(difflib.unified_diff(pages["old"][1].splitlines(), pages["new"][1].splitlines(), "old", "new", lineterm="", n=1))
                print(f"DIFF  options {options}  {url}  (HTTP {pages['old'][0]} / {pages['new'][0]})")
                print("\n".join(diff[:40]))
    finally:
        stop(proc)
        for checkout, data in originals.items():
            with open(os.path.join(checkout, "theme.json"), "wb") as fh:
                fh.write(data)

    shutil.rmtree(logs, ignore_errors=True)
    log_diff = list(difflib.unified_diff(sorted(logged["old"]), sorted(logged["new"]), "old log", "new log", lineterm="", n=0))
    for line in log_diff[:40]:
        print("LOG   " + line)
    print(f"render-compare: {checked} pages x 2 themes, {len(failures)} different;"
          f" PHP log lines old {len(logged['old'])}, new {len(logged['new'])}, {'same' if not log_diff else 'DIFFERENT'}")
    if failures or log_diff:
        sys.exit(1)


if __name__ == "__main__":
    main()
