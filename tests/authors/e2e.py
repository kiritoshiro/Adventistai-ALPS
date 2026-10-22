#!/usr/bin/env python3
"""Browser test of author profiles (App\\Authors) in WordPress Playground.

Boots a Playground site with this theme and Yoast SEO, seeds accounts and
posts like adventistai.lt's, runs the migration (`wp alps authors migrate`,
through tests/authors/wp-cli-stub.php) and turns author profiles on, then runs
tests/authors/e2e.mjs: Posts → Authors, the editor's Author panel (as an
administrator and a contributor), ALPS Theme Settings → AUTHORS, and what
visitors get (author pages, old /author/ addresses, REST users, Yoast meta,
schema and sitemaps, RSS) with no login name anywhere.

Needs Node.js, `composer install` and Playwright (`npm i -g playwright` and
`npx playwright install chromium`; found through NODE_PATH = npm root -g).

Usage: python3 tests/authors/e2e.py [--php 8.4] [--yoast 28.7]
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
PORT = 9498
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
THEME_VFS = "/wordpress/wp-content/themes/adventistai"

SEED = r"""<?php
// Test site only: throwaway accounts and posts, then the migration.
require '/wordpress/wp-load.php';
require '/wordpress/wp-content/themes/adventistai/tests/authors/wp-cli-stub.php';
update_option('permalink_structure', '/%postname%/');
if (class_exists('WPSEO_Options')) {
    WPSEO_Options::set('should_redirect_after_install_free', false);
}
wp_update_user(['ID' => 1, 'display_name' => 'Adventistai']);
$make_user = function ($login, $display, $role, $extra = []) {
    $id = wp_insert_user(array_merge(['user_login' => $login, 'user_pass' => $login . '-pass', 'user_email' => "$login@example.test",
        'display_name' => $display, 'role' => $role], $extra));
    if (is_wp_error($id)) { throw new Exception($id->get_error_message()); }
    return $id;
};
$vaiva = $make_user('vaiva-login', 'Vaiva Paldavičienė', 'editor', ['description' => 'Redaktorė ir vertėja.']);
$jonas = $make_user('jonas-login', 'Jonas Jonaitis', 'contributor');
$mantas = $make_user('mantas-login', 'Mantas', '');
$tuscias = $make_user('tuscias-login', 'Tuščias', 'subscriber');
$make_post = function ($author, $slug, $title, $status = 'publish') {
    $id = wp_insert_post(['post_author' => $author, 'post_name' => $slug, 'post_title' => $title, 'post_status' => $status,
        'post_content' => '<!-- wp:paragraph --><p>Įrašo tekstas.</p><!-- /wp:paragraph -->']);
    if (is_wp_error($id) || ! $id) { throw new Exception('post not created'); }
    return $id;
};
$vp = $make_post($vaiva, 'vaivos-irasas', 'Vaivos įrašas');
$make_post($mantas, 'manto-irasas', 'Manto įrašas');
$make_post($jonas, 'jono-juodrastis', 'Jono juodraštis', 'draft');
wp_insert_comment(['comment_post_ID' => $vp, 'user_id' => $vaiva, 'comment_author' => 'Vaiva Paldavičienė',
    'comment_author_email' => 'vaiva-login@example.test', 'comment_content' => 'Komentaras.', 'comment_approved' => 1]);
(new \App\Authors\Cli())->migrate([], []);
\App\Fields\Store::setOption('alps_authors_enabled', 'true');
@mkdir('/wordpress/wp-content/themes/adventistai/tests/authors/fixtures', 0777, true);
file_put_contents('/wordpress/wp-content/themes/adventistai/tests/authors/fixtures/e2e-seed.json', json_encode([
    'users' => ['vaiva' => $vaiva, 'jonas' => $jonas, 'mantas' => $mantas, 'tuscias' => $tuscias],
    'logins' => ['admin', 'vaiva-login', 'jonas-login', 'mantas-login', 'tuscias-login'],
    'migration' => WP_CLI::$log,
]));
"""


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--php", default="8.4")
    parser.add_argument("--wp", default="latest")
    parser.add_argument("--yoast", default="28.7", help="Yoast SEO version from wordpress.org")
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
    steps.append({"step": "installPlugin", "pluginData": {"resource": "url",
                  "url": f"https://downloads.wordpress.org/plugin/wordpress-seo.{args.yoast}.zip"}})
    steps.append({"step": "activateTheme", "themeFolderName": "adventistai"})
    steps.append({"step": "runPHP", "code": SEED})
    blueprint = {"$schema": "https://playground.wordpress.net/blueprint-schema.json",
                 "preferredVersions": {"php": args.php, "wp": args.wp}, "steps": steps}

    seed_file = os.path.join(ROOT, "tests", "authors", "fixtures", "e2e-seed.json")
    if os.path.exists(seed_file):
        os.remove(seed_file)
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
        if not ready.is_set() or not os.path.exists(seed_file):
            sys.exit("Playground did not start, or the seed step failed")
        env = dict(os.environ)
        env["NODE_PATH"] = subprocess.run("npm root -g", shell=True, capture_output=True, text=True).stdout.strip()
        shot = os.path.join(ROOT, "tests", "authors", "fixtures", "author-panel.png")
        code = subprocess.run(["node", os.path.join(ROOT, "tests", "authors", "e2e.mjs"), f"http://127.0.0.1:{PORT}", seed_file, shot], env=env).returncode
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
                theme_lines = sorted({line.split("] ", 1)[-1].rstrip() for line in fh if "ALPS authors:" in line or ("PHP " in line and (
                    "/themes/adventistai/app/Authors" in line or "/acorn/framework/views/" in line or "PHP Fatal" in line))})
            for line in theme_lines:
                print("LOG   " + line)
            if theme_lines:
                code = code or 1
        shutil.rmtree(logs, ignore_errors=True)
        with open(os.path.join(ROOT, "theme.json"), "rb") as fh:
            if fh.read() != theme_json:
                with open(os.path.join(ROOT, "theme.json"), "wb") as out:
                    out.write(theme_json)
                print("FAIL  theme.json was rewritten (restored)")
                code = code or 1
    sys.exit(code)


if __name__ == "__main__":
    main()
