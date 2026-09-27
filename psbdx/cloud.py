"""
psbdx.cloud
The interactive "psbdx cloud" experience: create tunnels (quick or
own-domain), manage saved tunnels/domains, and set up one-word start
commands.
"""

import datetime
import os
import re
import signal
import stat
import subprocess
import sys
import time
import urllib.error
import urllib.request

from . import cloudflared as cf
from . import nav
from . import storage
from . import utils
from .utils import C, ok, warn, err, info, title, ask, ask_port, \
    ask_subdomain, ask_domain, confirm, slugify, bin_dir, which


def main_menu():
    if not storage.get_setting("colors_enabled", True):
        C.disable()

    if not cf.ensure_installed():
        err("Can't continue without cloudflared. Fix the install and try again.")
        return

    _notify_untracked_once()

    while True:
        title("Easy Cloudflare Tunnels — psbdx cloud")
        menu = [
            ("create", "Create a new tunnel"),
            ("manage_tunnels", "Manage existing tunnels"),
            ("manage_domains", "Manage domains"),
            ("commands", "Manage start commands"),
            ("manual_add", "Add an existing tunnel manually"),
        ]
        discovered = _discover() if cf.is_logged_in() else []
        if discovered:
            menu.append(("import", f"Import {len(discovered)} tunnel(s) found outside psbdx"))
        menu.append(("settings", "Settings"))
        menu.append(("exit", "Exit"))

        choice = nav.ask_choice("What would you like to do?", menu)
        print()
        if choice == "create":
            create_tunnel_flow()
        elif choice == "manage_tunnels":
            manage_tunnels_flow()
        elif choice == "manage_domains":
            manage_domains_flow()
        elif choice == "commands":
            manage_commands_flow()
        elif choice == "manual_add":
            add_manual_tunnel_flow()
        elif choice == "import":
            import_tunnels_flow(discovered)
        elif choice == "settings":
            settings_flow()
        elif choice == "exit":
            print("Bye!")
            return


# --------------------------------------------------------------------------
# Discovering tunnels created outside psbdx (e.g. by hand with
# `cloudflared tunnel create`, before psbdx was installed, or from
# another machine sharing the same Cloudflare account)
# --------------------------------------------------------------------------
def _discover():
    known_ids = {t["cf_id"] for t in storage.list_tunnels() if t.get("cf_id")}
    return cf.discover_untracked(known_ids)


def _discover_verbose():
    """Like _discover(), but prints a diagnostic if the account-tunnel
    lookup itself failed (vs. genuinely having nothing to import)."""
    discovered = _discover()
    if not discovered and cf.LAST_LIST_ERROR:
        warn("Couldn't check your Cloudflare account for existing tunnels:")
        print(f"  {C.DIM}{cf.LAST_LIST_ERROR}{C.RESET}")
        info("Try running 'cloudflared tunnel list -o json' by hand to see what's going on.")
    return discovered


def _notify_untracked_once():
    if not cf.is_logged_in():
        return
    discovered = _discover_verbose()
    if discovered:
        warn(f"Found {len(discovered)} tunnel(s) in your Cloudflare account that "
             f"weren't created through psbdx. Import them from the main menu "
             f"any time to manage/start them here too.")


def import_tunnels_flow(discovered=None):
    title("Import existing tunnels")
    discovered = discovered if discovered is not None else _discover_verbose()
    if not discovered:
        info("Nothing new to import — every tunnel on your account is already tracked.")
        return

    for item in discovered:
        label = item["cf_name"] or item["cf_id"]
        hint = f" → {item['hostname']}" if item["hostname"] else ""
        print(f"\n{C.BOLD}{label}{C.RESET}{hint}  {C.DIM}(id: {item['cf_id']}){C.RESET}")
        if not confirm(f"Import '{label}' into psbdx?", default=True):
            continue

        hostname = item["hostname"]
        port = item["port"]
        config_path = item["config_path"]

        if not hostname:
            if confirm("No hostname found for it — set one up now?", default=True):
                subdomain = ask_subdomain()
                domain = ask_domain()
                hostname = f"{subdomain}.{domain}"
                if not port:
                    port = ask_port()
                if cf.route_dns(item["cf_name"], hostname):
                    config_path = cf.write_config(item["cf_name"], item["cf_id"], hostname, port)
                    ok(f"https://{hostname} now points at '{item['cf_name']}'.")
                else:
                    err("Couldn't set up the DNS route — importing it as-is instead.")
                    hostname = None
            elif not port:
                port = ask_port("Local port this tunnel forwards to")
        elif not port:
            port = ask_port("Local port this tunnel forwards to")

        name = ask("Friendly name for this tunnel", default=item["cf_name"] or item["cf_id"])
        domain = hostname.split(".", 1)[1] if hostname and "." in hostname else None
        subdomain = hostname.split(".", 1)[0] if hostname and "." in hostname else None

        record = storage.new_tunnel_record(
            mode="domain", port=port, name=name, subdomain=subdomain, domain=domain,
            cf_name=item["cf_name"], cf_id=item["cf_id"], hostname=hostname,
            config_path=config_path,
        )
        storage.add_tunnel(record)
        ok(f"Imported '{name}'.")

        if confirm("Set up a one-word start command for it?", default=False):
            create_start_command(record["id"])


def add_manual_tunnel_flow():
    """For when auto-detection can't find a tunnel you already run
    yourself — e.g. a token-based tunnel created in the Zero Trust
    dashboard, or one whose config psbdx's scan didn't pick up. You just
    tell psbdx how it's normally started."""
    title("Add an existing tunnel manually")
    info("Use this for a tunnel you already created/run outside psbdx.")

    name = ask("Friendly name for this tunnel")
    port = ask_port("Local port it forwards to")
    hostname = ask("Hostname it's reachable at (e.g. api.example.com), or leave blank",
                    required=False) or None

    run_mode = nav.ask_choice("How do you normally start it?", [
        ("named", "cloudflared tunnel run <name>  (a named/local tunnel)"),
        ("token", "cloudflared tunnel run --token <token>  (from the Zero Trust dashboard)"),
    ])

    subdomain = domain = None
    if hostname and "." in hostname:
        subdomain, domain = hostname.split(".", 1)

    kwargs = dict(mode="domain", port=port, name=name, hostname=hostname,
                  subdomain=subdomain, domain=domain)

    if run_mode == "named":
        cf_name = ask("Tunnel name (exactly as used in 'cloudflared tunnel run <name>')")
        cf_id = ask("Tunnel ID/UUID, if you know it (optional)", required=False) or None
        config_path = ask(
            "Path to its config.yml, if it uses one (optional — leave blank to have "
            "psbdx generate one, or if it's run purely with --token)",
            required=False,
        ) or None
        if not config_path and cf_id and hostname:
            config_path = cf.write_config(cf_name, cf_id, hostname, port)
            ok(f"Generated a config at {config_path}")
        kwargs.update(cf_name=cf_name, cf_id=cf_id, config_path=config_path)
    else:
        token = ask("Paste the tunnel token (from the Zero Trust dashboard "
                     "'install and run a connector' step)")
        warn("This token is stored in plain text at ~/.psbdx-data/data.json, "
             "same as it would be in any shell script — treat it like a password.")
        kwargs["token"] = token

    record = storage.new_tunnel_record(**kwargs)
    storage.add_tunnel(record)
    ok(f"Saved '{name}'.")

    if confirm("Set up a one-word start command for it?", default=True):
        create_start_command(record["id"])


# --------------------------------------------------------------------------
# Create tunnel
# --------------------------------------------------------------------------
def create_tunnel_flow():
    title("Create a tunnel")
    mode = nav.ask_choice("Choose a mode:", [
        ("quick", "Quick mode — no domain needed, get an instant *.trycloudflare.com URL"),
        ("domain", "Own domain — use a domain you've added to Cloudflare"),
    ])
    print()
    if mode == "quick":
        _create_quick()
    else:
        _create_domain()


def _create_quick():
    port = ask_port()
    name = ask("Give this tunnel a name (just for you to recognize it later)",
                default=f"quick-{port}")

    record = storage.new_tunnel_record(mode="quick", port=port, name=name)
    storage.add_tunnel(record)
    ok(f"Saved '{name}'.")

    if confirm("Start it right now?", default=True):
        run_tunnel(record)

    if confirm("Set up a one-word start command for this tunnel?", default=True):
        create_start_command(record["id"])


def _create_domain():
    if not cf.is_logged_in():
        info("You need to log in to Cloudflare once to use your own domain.")
        if not cf.login():
            return
    else:
        ok("Already logged in to Cloudflare.")

    subdomain = ask_subdomain()
    domain = ask_domain()
    hostname = f"{subdomain}.{domain}"
    port = ask_port()

    cf_name = slugify(ask("Internal tunnel name (used by cloudflared)",
                           default=f"{subdomain}-{domain}".replace(".", "-")))

    info(f"Creating tunnel '{cf_name}'...")
    tunnel_id = cf.create_tunnel(cf_name)
    if not tunnel_id:
        err("Couldn't create the tunnel. Nothing was saved.")
        return
    ok(f"Tunnel created (id: {tunnel_id}).")

    config_path = cf.write_config(cf_name, tunnel_id, hostname, port)
    ok(f"Config written to {config_path}")

    info(f"Pointing {hostname} at this tunnel...")
    if not cf.route_dns(cf_name, hostname):
        err("DNS routing failed — check the domain is active in your Cloudflare account.")
        return
    ok(f"DNS route created: {hostname}")

    record = storage.new_tunnel_record(
        mode="domain", port=port, name=cf_name, subdomain=subdomain,
        domain=domain, cf_name=cf_name, cf_id=tunnel_id, hostname=hostname,
        config_path=config_path,
    )
    storage.add_tunnel(record)
    ok(f"Saved '{cf_name}' → https://{hostname}")

    if confirm("Start it right now?", default=True):
        run_tunnel(record)

    if confirm("Set up a one-word start command for this tunnel?", default=True):
        create_start_command(record["id"])


# --------------------------------------------------------------------------
# Running a tunnel
# --------------------------------------------------------------------------
def run_tunnel(record):
    """Runs the tunnel in the foreground. Ctrl+C stops it, same as
    running cloudflared directly."""
    if record["mode"] == "quick":
        info(f"Starting quick tunnel for http://localhost:{record['port']} ...")
        info("Press Ctrl+C to stop.")
        try:
            subprocess.run(
                ["cloudflared", "tunnel", "--url", f"http://localhost:{record['port']}"]
            )
        except KeyboardInterrupt:
            print()
        ok("Tunnel stopped.")
    elif record.get("token"):
        label = record.get("hostname") or record["name"]
        info(f"Starting tunnel '{record['name']}' ({label}) via its run token...")
        info("Press Ctrl+C to stop.")
        try:
            subprocess.run(["cloudflared", "tunnel", "run", "--token", record["token"]])
        except KeyboardInterrupt:
            print()
        ok("Tunnel stopped.")
    else:
        target = f"https://{record['hostname']}" if record.get("hostname") else "(no hostname set)"
        info(f"Starting tunnel '{record['cf_name']}' for {target} ...")
        info("Press Ctrl+C to stop.")
        cmd = ["cloudflared"]
        if record.get("config_path"):
            cmd += ["--config", record["config_path"]]
        cmd += ["tunnel", "run", record["cf_name"]]
        try:
            subprocess.run(cmd)
        except KeyboardInterrupt:
            print()
        ok("Tunnel stopped.")


def start_by_id(tunnel_id_or_name):
    record = storage.get_tunnel(tunnel_id_or_name)
    if not record:
        err(f"No saved tunnel matches '{tunnel_id_or_name}'.")
        sys.exit(1)
    if not cf.ensure_installed():
        sys.exit(1)
    run_tunnel(record)


# --------------------------------------------------------------------------
# Manage tunnels
# --------------------------------------------------------------------------
def _list_tunnels_or_none():
    tunnels = storage.list_tunnels()
    if not tunnels:
        warn("No tunnels saved yet. Create one first.")
        return None
    return tunnels


def _print_tunnels(tunnels):
    for i, t in enumerate(tunnels, start=1):
        if t["mode"] == "quick":
            where = f"quick tunnel → localhost:{t['port']}"
        elif t.get("hostname"):
            where = f"https://{t['hostname']} → localhost:{t['port']}"
        else:
            where = f"(no hostname set) → localhost:{t['port']}"
        via = f" {C.DIM}[token]{C.RESET}" if t.get("token") else ""
        cmd = f", start command: {C.CYAN}{t['start_command']}{C.RESET}" if t.get("start_command") else ""
        running = is_bg_running(t)
        status = f" {C.GREEN}● running{C.RESET}" if running else ""
        bg_url = f" {C.DIM}({t['bg_url']}){C.RESET}" if running and t.get("bg_url") else ""
        print(f"  {C.CYAN}{i}{C.RESET}. {C.BOLD}{t['name']}{C.RESET} — {where}{via}{cmd}{status}{bg_url}")


def manage_tunnels_flow():
    title("Manage tunnels")
    all_tunnels = storage.list_tunnels()
    tunnels = all_tunnels
    if not all_tunnels:
        warn("No tunnels saved yet. Create one, or import ones made outside psbdx.")
    else:
        if len(all_tunnels) > 8:
            query = ask("Search by name (Enter to show all)", required=False)
            if query:
                filtered = [t for t in all_tunnels if query.lower() in (t["name"] or "").lower()]
                if filtered:
                    tunnels = filtered
                else:
                    warn("No matches — showing all instead.")
        _print_tunnels(tunnels)

    if cf.is_logged_in():
        discovered = _discover_verbose()
        if discovered:
            print()
            warn(f"{len(discovered)} more tunnel(s) exist in your Cloudflare "
                 f"account but aren't imported yet (see 'Import' on the main menu).")

    if not tunnels:
        return
    print()
    idx = ask("Pick a tunnel by number, or Enter to go back", required=False)
    if not idx:
        return
    if not idx.isdigit() or not (1 <= int(idx) <= len(tunnels)):
        warn("Invalid selection.")
        return
    record = tunnels[int(idx) - 1]

    running = is_bg_running(record)
    actions = [("start", "Start it now (foreground)")]
    if running:
        actions.append(("stop_bg", "Stop its background run"))
    else:
        actions.append(("start_bg", "Start it in the background"))
    if record.get("log_path") and os.path.exists(record["log_path"]):
        actions.append(("logs", "View recent logs"))
    if record.get("bg_url") or record.get("hostname"):
        actions.append(("copy_url", "Copy its URL to clipboard"))
        actions.append(("check", "Check if it's reachable"))
    actions.append(("command", "Set/change its start command"))
    actions.append(("delete", "Delete it"))
    actions.append(("back", "Back"))

    action = nav.ask_choice(f"'{record['name']}' — what do you want to do?", actions)
    if action == "start":
        run_tunnel(record)
    elif action == "start_bg":
        start_tunnel_background(record)
    elif action == "stop_bg":
        stop_tunnel_background(record)
    elif action == "logs":
        show_tunnel_logs(record)
    elif action == "copy_url":
        url = record.get("bg_url") or (f"https://{record['hostname']}" if record.get("hostname") else None)
        if url:
            info(url)
            _maybe_copy_to_clipboard(url)
        else:
            warn("No URL known for this tunnel yet — start it first.")
    elif action == "check":
        check_tunnel_reachable(record)
    elif action == "command":
        create_start_command(record["id"])
    elif action == "delete":
        _delete_tunnel(record)


# --------------------------------------------------------------------------
# Background running - start a tunnel detached (survives this menu
# closing), track its pid/log so it can be checked on, tailed, or
# stopped later; plus a quick reachability check and clipboard copy.
# --------------------------------------------------------------------------
def _log_path(record):
    return os.path.join(utils.logs_dir(), f"{record['id']}.log")


def _build_run_cmd(record):
    """Same command run_tunnel() would use in the foreground, as an
    argv list, so background mode launches the identical process."""
    if record["mode"] == "quick":
        return ["cloudflared", "tunnel", "--url", f"http://localhost:{record['port']}"]
    if record.get("token"):
        return ["cloudflared", "tunnel", "run", "--token", record["token"]]
    cmd = ["cloudflared"]
    if record.get("config_path"):
        cmd += ["--config", record["config_path"]]
    cmd += ["tunnel", "run", record["cf_name"]]
    return cmd


def is_bg_running(record):
    pid = record.get("pid")
    if not pid:
        return False
    try:
        os.kill(pid, 0)
        return True
    except (ProcessLookupError, PermissionError):
        return False
    except OSError:
        return False


def start_tunnel_background(record):
    if is_bg_running(record):
        warn(f"'{record['name']}' is already running in the background (pid {record['pid']}).")
        return
    log_path = _log_path(record)
    cmd = _build_run_cmd(record)
    info(f"Starting '{record['name']}' in the background...")
    try:
        logf = open(log_path, "w")
        proc = subprocess.Popen(
            cmd, stdout=logf, stderr=subprocess.STDOUT,
            stdin=subprocess.DEVNULL, start_new_session=True,
        )
    except Exception as e:
        err(f"Couldn't start it: {e}")
        return
    storage.update_tunnel(record["id"], pid=proc.pid, log_path=log_path, bg_url=None)
    ok(f"Running in the background (pid {proc.pid}). Logs: {log_path}")

    if record["mode"] == "quick":
        info("Waiting for the *.trycloudflare.com URL...")
        url = _wait_for_quick_url(log_path)
        if url:
            storage.update_tunnel(record["id"], bg_url=url)
            ok(f"Live at {C.BOLD}{url}{C.RESET}")
            _maybe_copy_to_clipboard(url)
        else:
            warn("Didn't see a URL yet — check the logs in a moment.")


def _wait_for_quick_url(log_path, timeout=15):
    deadline = time.time() + timeout
    pattern = re.compile(r"https://[a-zA-Z0-9-]+\.trycloudflare\.com")
    while time.time() < deadline:
        try:
            with open(log_path, "r") as f:
                text = f.read()
            m = pattern.search(text)
            if m:
                return m.group(0)
        except OSError:
            pass
        time.sleep(1)
    return None


def stop_tunnel_background(record):
    pid = record.get("pid")
    if not pid or not is_bg_running(record):
        warn(f"'{record['name']}' isn't running in the background.")
        storage.update_tunnel(record["id"], pid=None)
        return
    try:
        os.kill(pid, signal.SIGTERM)
    except OSError as e:
        err(f"Couldn't stop it: {e}")
        return
    storage.update_tunnel(record["id"], pid=None)
    ok(f"Stopped '{record['name']}'.")


def show_tunnel_logs(record, lines=30):
    log_path = record.get("log_path")
    if not log_path or not os.path.exists(log_path):
        warn("No logs yet for this tunnel.")
        return
    with open(log_path, "r") as f:
        content = f.readlines()
    title(f"Last {min(lines, len(content))} log line(s) — {record['name']}")
    for line in content[-lines:]:
        print(line.rstrip("\n"))
    print()


def check_tunnel_reachable(record):
    url = record.get("bg_url") or (f"https://{record['hostname']}" if record.get("hostname") else None)
    if not url:
        warn("No public URL known for this tunnel yet — start it first.")
        return
    info(f"Checking {url} ...")
    try:
        req = urllib.request.Request(url, method="HEAD")
        with urllib.request.urlopen(req, timeout=8) as resp:
            ok(f"Reachable — HTTP {resp.status}.")
    except urllib.error.HTTPError as e:
        # Any HTTP response, even an error page, means the tunnel itself
        # is up and forwarding traffic — the status just came from
        # whatever's running on the local port.
        ok(f"Reachable — HTTP {e.code} (tunnel is up; that status came from your app).")
    except Exception as e:
        err(f"Not reachable: {e}")


def _maybe_copy_to_clipboard(text):
    if which("termux-clipboard-set"):
        try:
            subprocess.run(["termux-clipboard-set"], input=text, text=True, timeout=5)
            info("Copied to clipboard.")
        except Exception:
            pass


def _delete_tunnel(record):
    if not confirm(f"Really delete '{record['name']}'? This can't be undone.", default=False):
        return
    if record["mode"] == "domain" and record.get("cf_name") and confirm(
            "Also delete the tunnel itself from your Cloudflare account?", default=False):
        cf.delete_tunnel(record["cf_name"])
        if record.get("config_path") and os.path.exists(record["config_path"]):
            os.remove(record["config_path"])
    if record.get("start_command"):
        _remove_command_file(record["start_command"])
    storage.delete_tunnel(record["id"])
    ok("Removed from psbdx.")


# --------------------------------------------------------------------------
# Manage domains
# --------------------------------------------------------------------------
def manage_domains_flow():
    title("Manage domains")
    tunnels = [t for t in storage.list_tunnels() if t["mode"] == "domain"]

    logged_in = cf.is_logged_in()
    print(f"Cloudflare login: {C.GREEN + 'connected' + C.RESET if logged_in else C.YELLOW + 'not connected' + C.RESET}")
    if not tunnels:
        info("No own-domain tunnels tracked in psbdx yet.")
    else:
        print("\nDomains in use:")
        for t in tunnels:
            print(f"  • https://{t['hostname']}  (tunnel: {t['cf_name']}, port {t['port']})")

    discovered = _discover_verbose() if logged_in else []
    if discovered:
        print(f"\n{C.YELLOW}Also on your account, not yet imported:{C.RESET}")
        for item in discovered:
            hint = f"https://{item['hostname']}" if item["hostname"] else "(no hostname set)"
            print(f"  • {hint}  (tunnel: {item['cf_name'] or item['cf_id']})")

    print()
    menu = [
        ("login", "(Re)connect a Cloudflare account"),
        ("add", "Point another subdomain at an existing tunnel"),
    ]
    if discovered:
        menu.append(("import", "Import the tunnel(s) listed above"))
    menu.append(("back", "Back"))

    choice = nav.ask_choice("What next?", menu)
    if choice == "login":
        cf.login()
    elif choice == "add":
        _add_domain_to_existing(tunnels)
    elif choice == "import":
        import_tunnels_flow(discovered)


def _add_domain_to_existing(tunnels):
    if not tunnels:
        warn("Create an own-domain tunnel first.")
        return
    _print_tunnels(tunnels)
    idx = ask("Add a route to which tunnel? (number)")
    if not idx.isdigit() or not (1 <= int(idx) <= len(tunnels)):
        warn("Invalid selection.")
        return
    record = tunnels[int(idx) - 1]
    subdomain = ask_subdomain()
    hostname = f"{subdomain}.{record['domain']}"
    if cf.route_dns(record["cf_name"], hostname):
        ok(f"https://{hostname} now points at tunnel '{record['cf_name']}'.")
    else:
        err("Couldn't create that route.")


# --------------------------------------------------------------------------
# Start commands
# --------------------------------------------------------------------------
RESERVED = {"psbdx", "cloudflared", "cd", "ls", "exit", "help", "sudo"}


def manage_commands_flow():
    title("Start commands")
    commands = storage.all_commands()
    if not commands:
        info("No custom start commands yet.")
    else:
        print("Custom commands:")
        for cmd, tid in commands.items():
            t = storage.get_tunnel(tid)
            label = t["name"] if t else "(missing tunnel)"
            print(f"  • {C.CYAN}{cmd}{C.RESET} → {label}")
    print()
    choice = nav.ask_choice("What next?", [
        ("add", "Add a start command to a tunnel"),
        ("remove", "Remove a start command"),
        ("back", "Back"),
    ])
    if choice == "add":
        tunnels = _list_tunnels_or_none()
        if not tunnels:
            return
        _print_tunnels(tunnels)
        idx = ask("Which tunnel? (number)")
        if idx.isdigit() and 1 <= int(idx) <= len(tunnels):
            create_start_command(tunnels[int(idx) - 1]["id"])
        else:
            warn("Invalid selection.")
    elif choice == "remove":
        if not commands:
            return
        name = ask("Which command should be removed?")
        if name in commands:
            _remove_command_file(name)
            ok(f"Removed '{name}'.")
        else:
            warn("No such command.")


def create_start_command(tunnel_id):
    record = storage.get_tunnel(tunnel_id)
    if not record:
        err("Tunnel not found.")
        return

    while True:
        name = slugify(ask("Command name to type in your terminal (e.g. mytunnel)"))
        if name in RESERVED:
            warn(f"'{name}' is reserved, pick something else.")
            continue
        existing = os.path.join(bin_dir(), name)
        if os.path.exists(existing) and name not in storage.all_commands():
            warn(f"'{name}' already exists as another command on this system.")
            continue
        break

    wrapper_path = os.path.join(bin_dir(), name)
    main_py = os.path.join(utils.install_dir(), "psbdx", "main.py")
    bash_path = which("bash") or which("sh") or "/bin/sh"
    script = f"#!{bash_path}\nexec python3 \"{main_py}\" start \"{record['id']}\"\n"
    with open(wrapper_path, "w") as f:
        f.write(script)
    st = os.stat(wrapper_path)
    os.chmod(wrapper_path, st.st_mode | stat.S_IEXEC | stat.S_IXGRP | stat.S_IXOTH)

    storage.set_command(name, record["id"])
    storage.update_tunnel(record["id"], start_command=name)
    ok(f"Done — just type '{C.BOLD}{name}{C.RESET}' anytime to start this tunnel.")


def _remove_command_file(name):
    path = os.path.join(bin_dir(), name)
    if os.path.exists(path):
        os.remove(path)
    tunnel_id = storage.all_commands().get(name)
    storage.remove_command(name)
    if tunnel_id:
        storage.update_tunnel(tunnel_id, start_command=None)


# --------------------------------------------------------------------------
# Settings - navigation style, color output, backup/restore of psbdx's
# own data (tunnels, commands, settings) as one portable JSON file.
# --------------------------------------------------------------------------
def settings_flow():
    while True:
        title("Settings")
        nav_mode = nav.get_nav_mode() or "number"
        colors = storage.get_setting("colors_enabled", True)
        print(f"Navigation style: {C.BOLD}{nav_mode}{C.RESET}")
        print(f"Colored output:   {C.BOLD}{'on' if colors else 'off'}{C.RESET}\n")

        choice = nav.ask_choice("Settings", [
            ("nav", "Change navigation style"),
            ("colors", "Toggle colored output"),
            ("backup", "Back up tunnels & commands to a file"),
            ("restore", "Restore from a backup file"),
            ("back", "Back to main menu"),
        ])
        print()
        if choice == "nav":
            nav.change_nav_mode()
        elif choice == "colors":
            _toggle_colors()
        elif choice == "backup":
            _backup_flow()
        elif choice == "restore":
            _restore_flow()
        elif choice == "back":
            return


def _toggle_colors():
    current = storage.get_setting("colors_enabled", True)
    new_val = not current
    storage.set_setting("colors_enabled", new_val)
    if new_val:
        C.enable()
        ok("Colored output turned on.")
    else:
        C.disable()
        print("Colored output turned off.")


def _backup_flow():
    title("Back up psbdx data")
    default_name = f"psbdx-backup-{datetime.datetime.now().strftime('%Y%m%d-%H%M%S')}.json"
    default_path = os.path.join(os.path.expanduser("~"), default_name)
    path = ask("Save backup to", default=default_path)
    try:
        saved_to = storage.export_backup(path)
    except OSError as e:
        err(f"Couldn't write the backup: {e}")
        return
    ok(f"Backup saved to {saved_to}")
    info("This file has your tunnels, start commands and settings in plain "
         "text (including any stored run tokens) - keep it somewhere safe.")


def _restore_flow():
    title("Restore from backup")
    path = ask("Path to a psbdx backup .json file")
    expanded = os.path.expanduser(path)
    if not os.path.exists(expanded):
        err("File not found.")
        return
    if not confirm("This replaces your current tunnels, commands and settings "
                    "with what's in the backup. Continue?", default=False):
        print("Cancelled.")
        return
    try:
        storage.import_backup(path)
    except (OSError, ValueError) as e:
        err(f"Couldn't read that backup: {e}")
        return
    ok("Restored. Reopen 'Manage existing tunnels' to see them.")
