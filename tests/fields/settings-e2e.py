#!/usr/bin/env python3
"""Browser test of Appearance → ALPS Theme Settings in WordPress Playground.

Boots a Playground site with this theme, the render-seed.php content and a
test Editor account, then runs settings-page.mjs (Playwright) against it.
Needs Node.js, `composer install` and Playwright (`npm i -g playwright` and
`npx playwright install chromium`; found through NODE_PATH = npm root -g).

Usage: python3 tests/fields/settings-e2e.py [--php 8.4]
"""
import argparse
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time

CLI = "@wp-playground/cli@3.1.57"
PORT = 9497
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
THEME_VFS = "/wordpress/wp-content/themes/adventistai"


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--php", default="8.4")
    parser.add_argument("--wp", default="latest")
    args = parser.parse_args()
    if not os.path.isfile(os.path.join(ROOT, "vendor", "autoload.php")):
        sys.exit("Run composer install first.")
    with socket.socket() as sock:
        if sock.connect_ex(("127.0.0.1", PORT)) == 0:
            sys.exit(f"port {PORT} is already in use")
    with open(os.path.join(ROOT, ".github", "playground", "config.json"), encoding="utf-8") as fh:
        config = json.load(fh)

    steps = [{"step": "defineWpConfigConsts", "consts": {"WP_DEBUG": True, "WP_DEBUG_LOG": "/logs/debug.log", "WP_DEBUG_DISPLAY": False}}]
    steps += config.get("setup", [])
    steps.append({"step": "activateTheme", "themeFolderName": "adventistai"})
    steps.append({"step": "runPHP", "code": (
        "<?php require '/wordpress/wp-load.php'; $alps_render_seed = true;"
        f" require '{THEME_VFS}/tests/fields/render-seed.php';"
        # Test-only account on this throwaway site.
        " wp_insert_user(['user_login' => 'fields-editor', 'user_pass' => 'fields-editor-test',"
        " 'user_email' => 'fields-editor@example.test', 'role' => 'editor']);")})
    blueprint = {"$schema": "https://playground.wordpress.net/blueprint-schema.json",
                 "preferredVersions": {"php": args.php, "wp": args.wp}, "steps": steps}

    # Carbon Fields' admin scripts, copied into public/ as the release
    # workflow does (a plain checkout has none); removed afterwards.
    cf_public = os.path.join(ROOT, "public", "carbon-fields")
    copied = not os.path.exists(cf_public)
    if copied:
        cf = os.path.join(ROOT, "vendor", "htmlburger", "carbon-fields")
        shutil.copytree(os.path.join(cf, "build"), os.path.join(cf_public, "build"))
        shutil.copytree(os.path.join(cf, "core", "Libraries", "Sidebar_Manager", "assets"),
                        os.path.join(cf_public, "core", "Libraries", "Sidebar_Manager", "assets"))
    logs = tempfile.mkdtemp()
    bp_path = os.path.join(logs, "blueprint.json")
    with open(bp_path, "w", encoding="utf-8") as fh:
        json.dump(blueprint, fh)
    with open(os.path.join(ROOT, "theme.json"), "rb") as fh:
        theme_json = fh.read()
    cmd = ["npx", "--yes", CLI, "server", f"--port={PORT}", f"--blueprint={bp_path}",
           "--mount-dir", logs, "/logs", "--mount-dir", ROOT, THEME_VFS]
    extra = {"creationflags": subprocess.CREATE_NEW_PROCESS_GROUP} if os.name == "nt" else {"start_new_session": True}
    proc = subprocess.Popen(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True,
                            encoding="utf-8", errors="replace", shell=os.name == "nt", **extra)
    ready = threading.Event()
    threading.Thread(target=lambda: [ready.set() for line in proc.stdout if "Ready!" in line], daemon=True).start()
    code = 1
    try:
        deadline = time.time() + 600
        while time.time() < deadline and not ready.is_set() and proc.poll() is None:
            time.sleep(1)
        if not ready.is_set():
            sys.exit("Playground did not start")
        env = dict(os.environ)
        npm_root = subprocess.run("npm root -g", shell=True, capture_output=True, text=True).stdout.strip()
        env["NODE_PATH"] = npm_root
        shot = os.path.join(ROOT, "tests", "fields", "fixtures", "settings-page-phone.png")
        code = subprocess.run(["node", os.path.join(ROOT, "tests", "fields", "settings-page.mjs"), f"http://127.0.0.1:{PORT}", shot], env=env).returncode
    finally:
        if proc.poll() is None:
            if os.name == "nt":
                subprocess.run(["taskkill", "/PID", str(proc.pid), "/T", "/F"], capture_output=True)
            else:
                os.killpg(proc.pid, 15)
            proc.wait(timeout=30)
        log = os.path.join(logs, "debug.log")
        if os.path.exists(log):
            with open(log, encoding="utf-8", errors="replace") as fh:
                theme_lines = [line.rstrip() for line in fh if "PHP " in line and "/themes/adventistai/app/Fields" in line]
            for line in theme_lines:
                print("LOG   " + line)
            if theme_lines:
                code = code or 1
        shutil.rmtree(logs, ignore_errors=True)
        if copied:
            shutil.rmtree(cf_public, ignore_errors=True)
        with open(os.path.join(ROOT, "theme.json"), "rb") as fh:
            if fh.read() != theme_json:
                with open(os.path.join(ROOT, "theme.json"), "wb") as out:
                    out.write(theme_json)
                print("FAIL  theme.json was rewritten (restored)")
                code = code or 1
    sys.exit(code)


if __name__ == "__main__":
    main()
