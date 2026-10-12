#!/usr/bin/env python3
"""Browser tests of the theme's field screens in WordPress Playground.

Boots a Playground site with this theme, the render-seed.php content, a test
Editor account and test-only REST routes that show raw values to
admins (post meta and options), then runs one Playwright script against it:

  settings-page  settings-page.mjs: Appearance → ALPS Theme Settings
  editor-panels  editor-panels.mjs: the block editor sidebar panels
  widgets        widgets.mjs: the theme's widgets on Appearance → Widgets

Needs Node.js, `composer install` and Playwright (`npm i -g playwright` and
`npx playwright install chromium`; found through NODE_PATH = npm root -g).

Usage: python3 tests/fields/e2e.py settings-page|editor-panels|widgets [--php 8.4]
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
    parser.add_argument("script", choices=["settings-page", "editor-panels", "widgets"])
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
    test_meta = [
        '<?php',
        '// Test site only: raw post meta for the browser tests.',
        "add_action('rest_api_init', function () {",
        "    register_rest_route('alps-test/v1', '/meta/(?P<id>\\d+)', [",
        "        'methods' => 'GET',",
        "        'permission_callback' => function () { return current_user_can('manage_options'); },",
        "        'callback' => function ($request) { return get_post_meta((int) $request['id']); },",
        '    ]);',
        "    register_rest_route('alps-test/v1', '/option/(?P<name>[a-z0-9_]+)', [",
        "        'methods' => 'GET',",
        "        'permission_callback' => function () { return current_user_can('manage_options'); },",
        "        'callback' => function ($request) { return get_option($request['name']); },",
        '    ]);',
        '});',
    ]
    steps.append({"step": "writeFile", "path": "/wordpress/wp-content/mu-plugins/alps-test-meta.php", "data": chr(10).join(test_meta) + chr(10)})
    steps.append({"step": "activateTheme", "themeFolderName": "adventistai"})
    steps.append({"step": "runPHP", "code": (
        "<?php require '/wordpress/wp-load.php'; $alps_render_seed = true;"
        f" require '{THEME_VFS}/tests/fields/render-seed.php';"
        # Test-only account on this throwaway site.
        " wp_insert_user(['user_login' => 'fields-editor', 'user_pass' => 'fields-editor-test',"
        " 'user_email' => 'fields-editor@example.test', 'role' => 'editor']);"
        # Its rendered title has entities (&#038;, &#8217;) for the search results.
        " wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => \"Rock & Roll's page\"]);")})
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
        shot = os.path.join(ROOT, "tests", "fields", "fixtures", f"{args.script}-phone.png")
        code = subprocess.run(["node", os.path.join(ROOT, "tests", "fields", f"{args.script}.mjs"), f"http://127.0.0.1:{PORT}", shot], env=env).returncode
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
                theme_lines = [line.rstrip() for line in fh if "PHP " in line and (
                    "/themes/adventistai/app/Fields" in line or "PHP Fatal" in line
                    # The widget classes, not their (unchanged, already noisy) templates.
                    or ("/themes/adventistai/app/Widgets/" in line and "/templates/" not in line))]
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
