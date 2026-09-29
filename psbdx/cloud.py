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
import socket
import stat
import subprocess
import sys
import time
import urllib.error
import urllib.request

from . import __version__ as _VERSION
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
        running_n = sum(1 for t in storage.list_tunnels() if is_bg_running(t))
        menu = [
            ("create", "Create a new tunnel", "Quick trycloudflare.com URL, or your own domain"),
            ("manage_tunnels", "Manage existing tunnels", "Start, stop, logs, rename, autostart"),
            ("manage_domains", "Manage domains", "Hostnames, login, extra subdomains"),
            ("commands", "Manage start commands", "One-word commands, e.g. 'mysite -bg'"),
            ("manual_add", "Add an existing tunnel manually", "Track a tunnel you already run yourself"),
            ("dashboard", "Dashboard" + (f" ({running_n} running)" if running_n else ""),
             "See and control your background tunnels"),
        ]
        discovered = _discover() if cf.is_logged_in() else []
        if discovered:
            menu.append(("import", f"Import {len(discovered)} tunnel(s) found outside psbdx",
                         "Bring tunnels from your Cloudflare account into psbdx"))
        menu.append(("settings", "Settings", "Navigation, colors, boot autostart, updates, backup"))
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
        elif choice == "dashboard":
            dashboard_flow()
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
        if not nav.confirm(f"Import '{label}' into psbdx?", default=True):
            continue

        hostname = item["hostname"]
        port = item["port"]
        config_path = item["config_path"]

        if not hostname:
            if nav.confirm("No hostname found for it — set one up now?", default=True):
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

        if nav.confirm("Set up a one-word start command for it?", default=False):
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

    if nav.confirm("Set up a one-word start command for it?", default=True):
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

    if nav.confirm("Start it right now?", default=True):
        run_tunnel(record)

    if nav.confirm("Set up a one-word start command for this tunnel?", default=True):
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

    if nav.confirm("Start it right now?", default=True):
        run_tunnel(record)

    if nav.confirm("Set up a one-word start command for this tunnel?", default=True):
        create_start_command(record["id"])


# --------------------------------------------------------------------------
# Running a tunnel
# --------------------------------------------------------------------------
def port_listening(port, timeout=0.6):
    """True if something accepts connections on localhost:port."""
    for host in ("127.0.0.1", "::1"):
        try:
            with socket.create_connection((host, int(port)), timeout=timeout):
                return True
        except (OSError, ValueError, TypeError):
            continue
    return False


def _warn_if_port_closed(record):
    port = record.get("port")
    if port and not port_listening(port):
        warn(f"Nothing is listening on localhost:{port} yet - the tunnel will start, "
             f"but visitors will get an error until your app is running.")


def run_tunnel(record):
    """Runs the tunnel in the foreground. Ctrl+C stops it, same as
    running cloudflared directly."""
    if is_bg_running(record):
        warn(f"'{record['name']}' is already running in the background (pid {record['pid']}).")
        if record.get("bg_url"):
            info(f"It's live at {C.BOLD}{record['bg_url']}{C.RESET}")
        info("Starting it again here would run a second connector alongside it.")
        if not nav.confirm("Start another one in the foreground anyway?", default=False):
            info("Nothing started. Use 'Manage tunnels' to view its logs, or stop "
                 "the background one first if you'd rather run it here.")
            return
    _warn_if_port_closed(record)
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


def start_by_id(tunnel_id_or_name, background=False):
    record = storage.get_tunnel(tunnel_id_or_name)
    if not record:
        err(f"No saved tunnel matches '{tunnel_id_or_name}'.")
        sys.exit(1)
    if not cf.ensure_installed():
        sys.exit(1)
    if background:
        start_tunnel_background(record)
    else:
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


def _fmt_uptime(seconds):
    seconds = int(max(0, seconds))
    days, rem = divmod(seconds, 86400)
    hours, rem = divmod(rem, 3600)
    minutes, secs = divmod(rem, 60)
    if days:
        return f"{days}d {hours}h"
    if hours:
        return f"{hours}h {minutes}m"
    if minutes:
        return f"{minutes}m {secs}s"
    return f"{secs}s"


def _tunnel_url(record):
    """The public URL for a tunnel, if we know it. Quick-tunnel URLs are
    only valid while it's running; a domain tunnel's hostname is stable."""
    running = is_bg_running(record)
    if record["mode"] == "quick":
        if not running:
            return None
        url = record.get("bg_url") or _find_quick_url(record.get("log_path"))
        if url and not record.get("bg_url"):
            storage.update_tunnel(record["id"], bg_url=url)
        return url
    if record.get("hostname"):
        return f"https://{record['hostname']}"
    return record.get("bg_url") if running else None


def _tunnel_summary(t):
    if t["mode"] == "quick":
        where = f"quick tunnel \u2192 localhost:{t['port']}"
    elif t.get("hostname"):
        where = f"https://{t['hostname']} \u2192 localhost:{t['port']}"
    else:
        where = f"(no hostname set) \u2192 localhost:{t['port']}"
    via = f" {C.DIM}[token]{C.RESET}" if t.get("token") else ""
    cmd = f", start command: {C.CYAN}{t['start_command']}{C.RESET}" if t.get("start_command") else ""
    boot = f" {C.YELLOW}\u21bb autostart{C.RESET}" if t.get("autostart") else ""
    status = ""
    bg_url = ""
    if is_bg_running(t):
        up = f" {_fmt_uptime(time.time() - t['started_at'])}" if t.get("started_at") else ""
        status = f" {C.GREEN}\u25cf running{up}{C.RESET}"
        if t.get("bg_url"):
            bg_url = f" {C.DIM}({t['bg_url']}){C.RESET}"
    return f"{C.BOLD}{t['name']}{C.RESET} \u2014 {where}{via}{cmd}{boot}{status}{bg_url}"


def _tunnel_line(index, t):
    return f"  {C.CYAN}{index}{C.RESET}. {_tunnel_summary(t)}"


def _print_tunnels(tunnels):
    for i, t in enumerate(tunnels, start=1):
        print(_tunnel_line(i, t))


def _tunnel_label(t):
    """Plain one-line label for menus (no color codes)."""
    if is_bg_running(t):
        up = f" \u00b7 {_fmt_uptime(time.time() - t['started_at'])}" if t.get("started_at") else ""
        return f"\u25cf {t['name']}{up}"
    return f"\u25cb {t['name']}"


def _tunnel_desc(t):
    """Plain description shown under the highlighted menu entry."""
    if t["mode"] == "quick":
        parts = [f"quick tunnel \u2192 localhost:{t['port']}"]
    elif t.get("hostname"):
        parts = [f"{t['hostname']} \u2192 localhost:{t['port']}"]
    else:
        parts = [f"localhost:{t['port']}"]
    if t.get("start_command"):
        parts.append(f"cmd: {t['start_command']}")
    if t.get("autostart"):
        parts.append("autostart")
    return " \u00b7 ".join(parts)


def _filter_tunnels(query):
    tunnels = storage.list_tunnels()
    if not query:
        return tunnels
    q = query.lower()
    return [t for t in tunnels if q in (t["name"] or "").lower()]


def manage_tunnels_flow():
    title("Manage tunnels")
    all_tunnels = storage.list_tunnels()
    if not all_tunnels:
        warn("No tunnels saved yet. Create one, or import ones made outside psbdx.")

    query = ""
    if len(all_tunnels) > 8:
        query = ask("Search by name (Enter to show all)", required=False) or ""
        if query and not _filter_tunnels(query):
            warn("No matches \u2014 showing all instead.")
            query = ""

    if cf.is_logged_in():
        discovered = _discover_verbose()
        if discovered:
            print()
            warn(f"{len(discovered)} more tunnel(s) exist in your Cloudflare "
                 f"account but aren't imported yet (see 'Import' on the main menu).")

    if not all_tunnels:
        return

    while True:
        tunnels = _filter_tunnels(query)
        if not tunnels:
            return
        options = [(t["id"], _tunnel_label(t), _tunnel_desc(t)) for t in tunnels]
        options.append(("back", "Back"))
        choice = nav.ask_choice("Pick a tunnel", options)
        if choice == "back":
            return
        tunnel_actions_menu(choice)


def tunnel_actions_menu(tunnel_id):
    """Everything you can do with one tunnel. Loops until you go back,
    so you can start it, check its logs, then stop it without leaving."""
    while True:
        record = storage.get_tunnel(tunnel_id)
        if not record:
            return
        running = is_bg_running(record)
        print()
        print("  " + _tunnel_summary(record))
        url = _tunnel_url(record)

        actions = []
        if running:
            actions.append(("stop_bg", "Stop background run", "Sends cloudflared a clean shutdown"))
            actions.append(("restart", "Restart in the background", "Stop it, then start it again"))
            actions.append(("follow", "Follow logs live", "Streams new lines; Ctrl+C brings you back"))
        else:
            actions.append(("start_bg", "Start in the background", "Keeps running after you leave psbdx"))
            actions.append(("start", "Start now (foreground)", "Runs in this window; Ctrl+C stops it"))
        if record.get("log_path") and os.path.exists(record["log_path"]):
            actions.append(("logs", "View recent logs", "The last 30 lines of its log"))
        if url:
            actions.append(("copy_url", "Copy URL to clipboard", url))
            if which("termux-share"):
                actions.append(("share", "Share URL...", "Opens the Android share sheet"))
            actions.append(("check", "Check if it's reachable", "Sends a quick HTTP request to the URL"))
        actions.append(("details", "Show details", "ID, mode, port, status, start command"))
        actions.append(("autostart",
                        f"Autostart on boot: {'on' if record.get('autostart') else 'off'}",
                        "Toggle; needs the boot script from Settings"))
        actions.append(("rename", "Rename", "Just a label for you"))
        if record["mode"] == "quick":
            actions.append(("port", "Change local port", "Which local app this tunnel points at"))
        actions.append(("command", "Set/change its start command", "A one-word command, e.g. 'mysite -bg'"))
        actions.append(("delete", "Delete it", "Removes it from psbdx"))
        actions.append(("back", "Back"))

        action = nav.ask_choice(f"'{record['name']}'", actions)
        if action == "back":
            return
        elif action == "start":
            run_tunnel(record)
        elif action == "start_bg":
            start_tunnel_background(record)
        elif action == "stop_bg":
            stop_tunnel_background(record)
        elif action == "restart":
            restart_tunnel_background(record)
        elif action == "follow":
            follow_tunnel_logs(record)
        elif action == "logs":
            show_tunnel_logs(record)
        elif action == "copy_url":
            info(url)
            _maybe_copy_to_clipboard(url)
        elif action == "share":
            _share_url(url)
        elif action == "check":
            check_tunnel_reachable(record)
        elif action == "details":
            show_tunnel_details(record)
        elif action == "autostart":
            _toggle_autostart(record)
        elif action == "rename":
            _rename_tunnel(record)
        elif action == "port":
            _change_quick_port(record)
        elif action == "command":
            create_start_command(record["id"])
        elif action == "delete":
            if running:
                if not nav.confirm("It's running in the background. Stop it and delete?", default=False):
                    continue
                stop_tunnel_background(record, quiet=True)
            _delete_tunnel(record)


def _toggle_autostart(record):
    new_val = not record.get("autostart")
    storage.update_tunnel(record["id"], autostart=new_val)
    if new_val:
        ok(f"'{record['name']}' will start in the background on device boot.")
        if not boot_script_installed():
            warn("The boot script isn't installed yet \u2014 turn it on in Settings \u2192 "
                 "Auto-start tunnels on boot.")
    else:
        ok("Autostart turned off for this tunnel.")


def _rename_tunnel(record):
    new_name = ask("New name", default=record["name"])
    if new_name == record["name"]:
        return
    clash = storage.get_tunnel(new_name)
    if clash and clash["id"] != record["id"]:
        warn(f"Another tunnel is already called '{new_name}'.")
        return
    storage.update_tunnel(record["id"], name=new_name)
    ok(f"Renamed to '{new_name}'.")


def _change_quick_port(record):
    port = ask_port(default=record["port"])
    if port == record["port"]:
        return
    storage.update_tunnel(record["id"], port=port)
    ok(f"'{record['name']}' now points at localhost:{port}.")
    if is_bg_running(record):
        info("Restart it for the new port to take effect.")


def show_tunnel_details(record):
    title(f"Details \u2014 {record['name']}")
    running = is_bg_running(record)
    rows = [
        ("Name", record["name"]),
        ("ID", record["id"]),
        ("Mode", "quick (trycloudflare.com)" if record["mode"] == "quick" else "own domain"),
        ("Local port", record["port"]),
    ]
    if record.get("hostname"):
        rows.append(("Hostname", record["hostname"]))
    url = _tunnel_url(record)
    if url:
        rows.append(("Public URL", url))
    if record.get("config_path"):
        rows.append(("Config", record["config_path"]))
    if record.get("token"):
        rows.append(("Runs with", "a saved run token"))
    rows.append(("Start command", record.get("start_command") or "\u2014"))
    rows.append(("Autostart", "on" if record.get("autostart") else "off"))
    if running:
        up = f", up {_fmt_uptime(time.time() - record['started_at'])}" if record.get("started_at") else ""
        rows.append(("Status", f"{C.GREEN}running{C.RESET} (pid {record['pid']}{up})"))
    else:
        rows.append(("Status", "stopped"))
    log_path = record.get("log_path")
    if log_path and os.path.exists(log_path):
        rows.append(("Log file", f"{log_path} ({os.path.getsize(log_path)} bytes)"))
    for key, value in rows:
        print(f"  {C.DIM}{key:<14}{C.RESET} {value}")
    print()


def dashboard_flow():
    while True:
        tunnels = storage.list_tunnels()
        title("Dashboard")
        if not tunnels:
            warn("No tunnels saved yet. Create one first.")
            return
        running = [t for t in tunnels if is_bg_running(t)]
        stopped = [t for t in tunnels if not is_bg_running(t)]
        print(f"{C.BOLD}{len(running)}{C.RESET} of {len(tunnels)} tunnel(s) running "
              f"in the background\n")

        options = []
        for t in running:
            options.append((t["id"], _tunnel_label(t), _tunnel_url(t) or _tunnel_desc(t)))
        if stopped:
            options.append(("start_all", f"Start all stopped tunnels ({len(stopped)})",
                            "Launches each one in the background"))
        if running:
            options.append(("stop_all", f"Stop all running tunnels ({len(running)})",
                            "Sends each a clean shutdown"))
        options.append(("back", "Back"))

        choice = nav.ask_choice("Dashboard", options)
        if choice == "back":
            return
        elif choice == "start_all":
            for t in stopped:
                start_tunnel_background(t, quiet=True)
                fresh = storage.get_tunnel(t["id"])
                mark = f"{C.GREEN}\u2714{C.RESET}" if fresh and is_bg_running(fresh) else f"{C.RED}\u2718{C.RESET}"
                print(f"  {mark} {t['name']}")
        elif choice == "stop_all":
            for t in running:
                stop_tunnel_background(t, quiet=True)
                print(f"  {C.GREEN}\u2714{C.RESET} stopped {t['name']}")
        else:
            tunnel_actions_menu(choice)


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


_QUICK_URL_RE = re.compile(r"https://[a-zA-Z0-9-]+\.trycloudflare\.com")


def _find_quick_url(log_path):
    if not log_path:
        return None
    try:
        with open(log_path, "r", errors="replace") as f:
            m = _QUICK_URL_RE.search(f.read())
    except OSError:
        return None
    return m.group(0) if m else None


def _pid_is_cloudflared(pid):
    """Guards against a recycled pid: a process with our saved pid that
    isn't cloudflared (or is a zombie) doesn't count as 'running'."""
    if not os.path.isdir("/proc/self"):
        return True  # no /proc to check; trust the kill(0) test
    try:
        with open(f"/proc/{pid}/cmdline", "rb") as f:
            data = f.read()
    except FileNotFoundError:
        return False
    except OSError:
        return True  # can't tell; trust the kill(0) test
    if not data:
        return False  # zombie
    return b"cloudflared" in data


def is_bg_running(record):
    pid = record.get("pid")
    if not pid:
        return False
    try:
        os.kill(pid, 0)
    except OSError:
        return False
    return _pid_is_cloudflared(pid)


def _print_log_tail(log_path, lines=8):
    try:
        with open(log_path, "r", errors="replace") as f:
            content = f.readlines()
    except OSError:
        return
    for line in content[-lines:]:
        print(f"  {C.DIM}{line.rstrip()}{C.RESET}")


def start_tunnel_background(record, quiet=False):
    """Starts the tunnel detached from this terminal. Returns True if it
    is up and running afterwards. quiet=True skips the chatter and the
    wait for a quick-tunnel URL (used for bulk starts and autostart)."""
    if is_bg_running(record):
        if not quiet:
            warn(f"'{record['name']}' is already running in the background (pid {record['pid']}).")
        return True
    log_path = _log_path(record)
    cmd = _build_run_cmd(record)
    if not quiet:
        _warn_if_port_closed(record)
        info(f"Starting '{record['name']}' in the background...")
    try:
        with open(log_path, "w") as logf:
            proc = subprocess.Popen(
                cmd, stdout=logf, stderr=subprocess.STDOUT,
                stdin=subprocess.DEVNULL, start_new_session=True,
            )
    except Exception as e:
        err(f"Couldn't start '{record['name']}': {e}")
        return False

    # Startup health check: a bad config or token makes cloudflared exit
    # within a moment - say so now instead of pretending it's running.
    time.sleep(0.8)
    code = proc.poll()
    if code is not None:
        err(f"'{record['name']}' exited right away (exit code {code}). Last log lines:")
        _print_log_tail(log_path)
        storage.update_tunnel(record["id"], pid=None, started_at=None, bg_url=None,
                              log_path=log_path)
        return False

    storage.update_tunnel(record["id"], pid=proc.pid, log_path=log_path,
                          bg_url=None, started_at=time.time())
    if quiet:
        return True
    ok(f"Running in the background (pid {proc.pid}). Logs: {log_path}")

    if record["mode"] == "quick":
        info("Waiting for the *.trycloudflare.com URL...")
        url = _wait_for_quick_url(log_path)
        if url:
            storage.update_tunnel(record["id"], bg_url=url)
            ok(f"Live at {C.BOLD}{url}{C.RESET}")
            _maybe_copy_to_clipboard(url)
            _notify(f"{record['name']} is live", url, record["id"])
        else:
            warn("Didn't see a URL yet \u2014 check the logs in a moment.")
    return True


def _wait_for_quick_url(log_path, timeout=15):
    deadline = time.time() + timeout
    while time.time() < deadline:
        url = _find_quick_url(log_path)
        if url:
            return url
        time.sleep(1)
    return None


def stop_tunnel_background(record, quiet=False):
    """Stops a background tunnel (SIGTERM, then SIGKILL if it lingers).
    Returns True if something was stopped."""
    pid = record.get("pid")
    if not pid or not is_bg_running(record):
        if not quiet:
            warn(f"'{record['name']}' isn't running in the background.")
        storage.update_tunnel(record["id"], pid=None, started_at=None, bg_url=None)
        return False
    try:
        os.kill(pid, signal.SIGTERM)
    except OSError as e:
        err(f"Couldn't stop it: {e}")
        return False
    deadline = time.time() + 5
    while time.time() < deadline and is_bg_running(record):
        time.sleep(0.2)
    if is_bg_running(record):
        try:
            os.kill(pid, signal.SIGKILL)
        except OSError:
            pass
        time.sleep(0.3)
    storage.update_tunnel(record["id"], pid=None, started_at=None, bg_url=None)
    if not quiet:
        ok(f"Stopped '{record['name']}'.")
    return True


def restart_tunnel_background(record):
    stop_tunnel_background(record, quiet=True)
    fresh = storage.get_tunnel(record["id"]) or record
    return start_tunnel_background(fresh)


def follow_tunnel_logs(record, backlog=15):
    """Streams a tunnel's log until Ctrl+C, like `tail -f`."""
    log_path = record.get("log_path")
    if not log_path or not os.path.exists(log_path):
        warn("No logs yet for this tunnel.")
        return
    info(f"Following {log_path} \u2014 press Ctrl+C to stop.")
    try:
        with open(log_path, "r", errors="replace") as f:
            for line in f.readlines()[-backlog:]:
                print(line.rstrip("\n"))
            while True:
                line = f.readline()
                if line:
                    print(line.rstrip("\n"))
                    continue
                if os.path.getsize(log_path) < f.tell():  # restarted -> log truncated
                    f.seek(0)
                time.sleep(0.4)
    except KeyboardInterrupt:
        print()
    except OSError as e:
        err(f"Lost the log file: {e}")


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
    url = _tunnel_url(record)
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


def _notify(title_text, content, key):
    """Android notification via Termux:API, if it's available. Handy
    for -bg starts: the URL is one swipe away after you leave Termux."""
    if not which("termux-notification"):
        return
    try:
        subprocess.run(["termux-notification", "--id", f"psbdx-{key}",
                        "--title", title_text, "--content", content],
                       timeout=8, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except Exception:
        pass


def _share_url(url):
    """Open the Android share sheet with the URL (Termux:API)."""
    try:
        subprocess.run(["termux-share", "-a", "send", "-c", "text/plain"],
                       input=url, text=True, timeout=15)
    except Exception as e:
        warn(f"Couldn't open the share sheet: {e}")


def _maybe_copy_to_clipboard(text):
    if which("termux-clipboard-set"):
        try:
            subprocess.run(["termux-clipboard-set"], input=text, text=True, timeout=5)
            info("Copied to clipboard.")
        except Exception:
            pass


def _delete_tunnel(record):
    if not nav.confirm(f"Really delete '{record['name']}'? This can't be undone.", default=False):
        return
    if record["mode"] == "domain" and record.get("cf_name") and nav.confirm(
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


def _write_wrapper_script(name, tunnel_id):
    """Writes/overwrites the one-word launcher for a tunnel. Any extra
    arguments typed after the command are forwarded straight to
    'psbdx start' — e.g. 'mysite -bg' runs it in the background."""
    wrapper_path = os.path.join(bin_dir(), name)
    main_py = os.path.join(utils.install_dir(), "psbdx", "main.py")
    bash_path = which("bash") or which("sh") or "/bin/sh"
    script = f"#!{bash_path}\nexec python3 \"{main_py}\" start \"{tunnel_id}\" \"$@\"\n"
    with open(wrapper_path, "w") as f:
        f.write(script)
    st = os.stat(wrapper_path)
    os.chmod(wrapper_path, st.st_mode | stat.S_IEXEC | stat.S_IXGRP | stat.S_IXOTH)
    return wrapper_path


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

    _write_wrapper_script(name, record["id"])
    storage.set_command(name, record["id"])
    storage.update_tunnel(record["id"], start_command=name)
    ok(f"Done — just type '{C.BOLD}{name}{C.RESET}' anytime to start this tunnel "
       f"(add {C.CYAN}-bg{C.RESET} to run it in the background, e.g. '{name} -bg').")


def run_doctor():
    """Health check of everything psbdx depends on. Offers to fix what it
    safely can. Returns the number of problems found."""
    title("psbdx doctor")
    problems = 0

    # cloudflared itself
    if which("cloudflared"):
        try:
            out = subprocess.run(["cloudflared", "--version"], capture_output=True,
                                 text=True, timeout=10).stdout.strip().splitlines()
            ok(f"cloudflared installed ({out[0] if out else 'version unknown'})")
        except Exception:
            warn("cloudflared is installed but didn't answer --version")
            problems += 1
    else:
        err("cloudflared isn't installed - run 'psbdx cloud' to install it")
        problems += 1

    # network
    try:
        socket.create_connection(("1.1.1.1", 443), timeout=4).close()
        ok("Internet reachable")
    except OSError:
        err("Can't reach the internet (1.1.1.1:443) - tunnels won't connect")
        problems += 1

    # account
    if cf.is_logged_in():
        ok("Logged in to Cloudflare (own-domain tunnels available)")
    else:
        info("Not logged in to Cloudflare - fine for quick tunnels; "
             "own-domain tunnels need a login")

    tunnels = storage.list_tunnels()
    ok(f"{len(tunnels)} saved tunnel(s), {len(storage.all_commands())} start command(s)")

    # stale process ids
    stale = [t for t in tunnels if t.get("pid") and not is_bg_running(t)]
    if stale:
        warn(f"{len(stale)} tunnel(s) are marked as running but their process is gone: "
             + ", ".join(t["name"] for t in stale))
        problems += 1
        if nav.confirm("Clear those stale markers?", default=True):
            for t in stale:
                storage.update_tunnel(t["id"], pid=None, started_at=None, bg_url=None)
            ok("Cleared.")
            problems -= 1
    else:
        ok("No stale background runs")

    # running tunnels whose local app isn't up
    for t in tunnels:
        if is_bg_running(t) and t.get("port") and not port_listening(t["port"]):
            warn(f"'{t['name']}' is running but nothing listens on localhost:{t['port']}")
            problems += 1

    # start-command launchers
    old = []
    missing = []
    for name in storage.all_commands():
        path = os.path.join(bin_dir(), name)
        if not os.path.exists(path):
            missing.append(name)
        else:
            with open(path, encoding="utf-8", errors="replace") as f:
                if '"$@"' not in f.read():
                    old.append(name)
    if missing:
        warn("Start command file(s) missing: " + ", ".join(missing))
        problems += 1
    if old:
        warn("Start command(s) from an older version (no -bg support): " + ", ".join(old))
        problems += 1
        if nav.confirm("Refresh them now?", default=True):
            regenerate_start_commands()
            ok("Refreshed.")
            problems -= 1
    if not missing and not old:
        ok("Start commands are up to date")

    if boot_script_installed():
        ok("Boot autostart script installed")
    else:
        info("Boot autostart not installed (optional - Settings -> Auto-start on boot)")

    extras = [t for t in ("termux-clipboard-set", "termux-notification", "termux-share") if not which(t)]
    if extras:
        info("Optional Termux:API tools missing: " + ", ".join(extras)
             + " (clipboard/notify/share features skip themselves)")
    else:
        ok("Termux:API tools available (clipboard, notifications, share)")

    print()
    if problems:
        warn(f"{problems} problem(s) left.")
    else:
        ok("All good.")
    return problems


def regenerate_start_commands():
    """Rewrites every installed one-word launcher with the current
    wrapper template. Safe to call anytime — a no-op if nothing's
    changed. Used after 'psbdx update' so existing commands (created
    before a feature like -bg existed) pick it up without the user
    having to recreate them."""
    updated = 0
    for name, tunnel_id in storage.all_commands().items():
        wrapper_path = os.path.join(bin_dir(), name)
        if not os.path.exists(wrapper_path):
            continue  # user removed it by hand; don't resurrect it
        _write_wrapper_script(name, tunnel_id)
        updated += 1
    return updated


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
        print(f"Version:          {C.BOLD}{_VERSION}{C.RESET}")
        print(f"Navigation style: {C.BOLD}{nav_mode}{C.RESET}")
        print(f"Accent color:     {C.BOLD}{nav.get_accent_name()}{C.RESET}")
        print(f"Colored output:   {C.BOLD}{'on' if colors else 'off'}{C.RESET}")
        print(f"Boot autostart:   {C.BOLD}{'installed' if boot_script_installed() else 'off'}{C.RESET}")
        print()

        choice = nav.ask_choice("Settings", [
            ("nav", "Change navigation style", "Number keys, or arrow keys + Enter"),
            ("accent", "Menu accent color", "The highlight color of the arrow menu"),
            ("colors", "Toggle colored output", "Turn all color off or on"),
            ("boot", "Auto-start tunnels on boot", "Termux:Boot script for tunnels flagged 'autostart'"),
            ("logs", "Clear tunnel logs", "Deletes logs of tunnels that aren't running"),
            ("doctor", "Run diagnostics", "Checks cloudflared, network, stale runs, start commands"),
            ("update", "Check for updates", "See if a newer psbdx is available, and install it"),
            ("backup", "Back up tunnels & commands to a file", "One portable JSON file"),
            ("restore", "Restore from a backup file", "Replaces your current data"),
            ("back", "Back to main menu"),
        ])
        print()
        if choice == "nav":
            nav.change_nav_mode()
        elif choice == "accent":
            nav.change_accent()
        elif choice == "colors":
            _toggle_colors()
        elif choice == "boot":
            _boot_flow()
        elif choice == "logs":
            _clear_logs_flow()
        elif choice == "doctor":
            run_doctor()
        elif choice == "update":
            _update_flow()
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
    if not nav.confirm("This replaces your current tunnels, commands and settings "
                    "with what's in the backup. Continue?", default=False):
        print("Cancelled.")
        return
    try:
        storage.import_backup(path)
    except (OSError, ValueError) as e:
        err(f"Couldn't read that backup: {e}")
        return
    ok("Restored. Reopen 'Manage existing tunnels' to see them.")


# --------------------------------------------------------------------------
# Self-update, boot autostart, log housekeeping
# --------------------------------------------------------------------------
def update_self():
    """git-pulls psbdx and refreshes the one-word start commands.
    Returns True on success."""
    install_dir = utils.install_dir()
    if not os.path.isdir(os.path.join(install_dir, ".git")):
        err(f"{install_dir} isn't a git checkout \u2014 reinstall using install.sh instead.")
        return False
    info("Pulling latest changes...")
    proc = subprocess.run(["git", "-C", install_dir, "pull", "--ff-only"])
    if proc.returncode != 0:
        err("Update failed. Resolve any local changes in ~/.psbdx and try again.")
        return False
    ok("psbdx is up to date.")
    updated = regenerate_start_commands()
    if updated:
        info(f"Refreshed {updated} existing start command(s) so they pick up the latest features.")
    return True


def check_for_updates():
    """How many commits behind the remote we are, or None if unknown."""
    install_dir = utils.install_dir()
    if not os.path.isdir(os.path.join(install_dir, ".git")):
        return None
    try:
        subprocess.run(["git", "-C", install_dir, "fetch", "--quiet"],
                       check=True, capture_output=True, timeout=25)
        out = subprocess.run(["git", "-C", install_dir, "rev-list", "--count", "HEAD..@{u}"],
                             capture_output=True, text=True, timeout=10)
        if out.returncode != 0:
            return None
        return int(out.stdout.strip())
    except Exception:
        return None


def _update_flow():
    title("Check for updates")
    info("Checking...")
    behind = check_for_updates()
    if behind is None:
        warn("Couldn't check (offline, or psbdx wasn't installed with git).")
        return
    if behind == 0:
        ok(f"You're on the latest version ({_VERSION}).")
        return
    ok(f"{behind} new commit(s) available.")
    if nav.confirm("Update now?", default=True):
        if update_self():
            info("Restart psbdx to use the new version.")


def _boot_script_path():
    return os.path.expanduser("~/.termux/boot/psbdx-autostart.sh")


def boot_script_installed():
    return os.path.exists(_boot_script_path())


def install_boot_script():
    path = _boot_script_path()
    os.makedirs(os.path.dirname(path), exist_ok=True)
    launcher = os.path.join(bin_dir(), "psbdx")
    if os.path.exists(launcher):
        run_cmd = f'"{launcher}" autostart'
    else:
        main_py = os.path.join(utils.install_dir(), "psbdx", "main.py")
        run_cmd = f'python3 "{main_py}" autostart'
    shell = which("sh") or "/bin/sh"
    script = f"#!{shell}\n# Installed by psbdx \u2014 starts tunnels flagged 'autostart' at boot.\ntermux-wake-lock\n{run_cmd}\n"
    with open(path, "w") as f:
        f.write(script)
    st = os.stat(path)
    os.chmod(path, st.st_mode | stat.S_IEXEC | stat.S_IXGRP | stat.S_IXOTH)
    return path


def remove_boot_script():
    path = _boot_script_path()
    if os.path.exists(path):
        os.remove(path)


def _boot_flow():
    title("Auto-start tunnels on boot")
    if boot_script_installed():
        if nav.confirm("The boot script is installed. Remove it?", default=False):
            remove_boot_script()
            ok("Boot script removed.")
        return
    info("This needs the Termux:Boot app (install it from F-Droid and open it once).")
    info("Tunnels you flag 'Autostart on boot' in their menu will then start "
         "in the background whenever the device boots.")
    if not nav.confirm("Install the boot script now?", default=True):
        return
    path = install_boot_script()
    ok(f"Installed {path}")
    if not any(t.get("autostart") for t in storage.list_tunnels()):
        warn("No tunnel is flagged for autostart yet \u2014 turn it on from a tunnel's menu.")


def run_autostart():
    """Starts every tunnel flagged 'autostart' in the background. This is
    what the boot script runs. Returns the number started."""
    if not which("cloudflared"):
        err("cloudflared isn't installed \u2014 run 'psbdx cloud' once to set it up.")
        return 0
    started = 0
    for t in storage.list_tunnels():
        if not t.get("autostart"):
            continue
        if start_tunnel_background(t, quiet=True):
            started += 1
            print(f"started {t['name']}")
        else:
            print(f"failed to start {t['name']}")
    if not started:
        print("No tunnels started (none flagged for autostart).")
    return started


def _clear_logs_flow():
    title("Clear tunnel logs")
    keep = {os.path.basename(t["log_path"]) for t in storage.list_tunnels()
            if is_bg_running(t) and t.get("log_path")}
    removed = freed = 0
    for name in os.listdir(utils.logs_dir()):
        path = os.path.join(utils.logs_dir(), name)
        if name in keep or not os.path.isfile(path):
            continue
        freed += os.path.getsize(path)
        os.remove(path)
        removed += 1
    if removed:
        ok(f"Deleted {removed} log file(s), freed {freed} bytes.")
    else:
        info("Nothing to clear.")
    if keep:
        info(f"Kept the log(s) of {len(keep)} running tunnel(s).")


# --------------------------------------------------------------------------
# Command-line helpers (psbdx list/stop/restart/logs/status and the
# -stop/-restart/-logs/-status flags on one-word start commands)
# --------------------------------------------------------------------------
def _resolve_tunnel(target):
    record = storage.get_tunnel(target) if target else None
    if not record:
        err(f"No saved tunnel matches '{target}'." if target else "Give a tunnel name or id.")
        sys.exit(1)
    return record


def cli_list():
    tunnels = storage.list_tunnels()
    if not tunnels:
        warn("No tunnels saved yet. Run 'psbdx cloud' to create one.")
        return
    _print_tunnels(tunnels)
    running = sum(1 for t in tunnels if is_bg_running(t))
    print(f"\n{C.DIM}{running} of {len(tunnels)} running in the background.{C.RESET}")


def cli_stop(target=None, stop_all=False):
    if stop_all:
        stopped = 0
        for t in storage.list_tunnels():
            if is_bg_running(t):
                stop_tunnel_background(t)
                stopped += 1
        if not stopped:
            info("Nothing is running in the background.")
        return
    stop_tunnel_background(_resolve_tunnel(target))


def cli_restart(target):
    record = _resolve_tunnel(target)
    if not cf.ensure_installed():
        sys.exit(1)
    if not restart_tunnel_background(record):
        sys.exit(1)


def cli_logs(target, follow=False):
    record = _resolve_tunnel(target)
    if follow:
        follow_tunnel_logs(record)
    else:
        show_tunnel_logs(record)


def cli_status(target):
    show_tunnel_details(_resolve_tunnel(target))
