"""
psbdx.cloud
The interactive "psbdx cloud" experience: create tunnels (quick or
own-domain), manage saved tunnels/domains, and set up one-word start
commands.
"""

import os
import re
import stat
import subprocess
import sys

from . import cloudflared as cf
from . import storage
from . import utils
from .utils import C, ok, warn, err, info, title, ask, ask_port, ask_choice, \
    ask_subdomain, ask_domain, confirm, slugify, bin_dir, which


def main_menu():
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
        ]
        discovered = _discover() if cf.is_logged_in() else []
        if discovered:
            menu.append(("import", f"Import {len(discovered)} tunnel(s) found outside psbdx"))
        menu.append(("exit", "Exit"))

        choice = ask_choice("What would you like to do?", menu)
        print()
        if choice == "create":
            create_tunnel_flow()
        elif choice == "manage_tunnels":
            manage_tunnels_flow()
        elif choice == "manage_domains":
            manage_domains_flow()
        elif choice == "commands":
            manage_commands_flow()
        elif choice == "import":
            import_tunnels_flow(discovered)
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


def _notify_untracked_once():
    if not cf.is_logged_in():
        return
    discovered = _discover()
    if discovered:
        warn(f"Found {len(discovered)} tunnel(s) in your Cloudflare account that "
             f"weren't created through psbdx. Import them from the main menu "
             f"any time to manage/start them here too.")


def import_tunnels_flow(discovered=None):
    title("Import existing tunnels")
    discovered = discovered if discovered is not None else _discover()
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


# --------------------------------------------------------------------------
# Create tunnel
# --------------------------------------------------------------------------
def create_tunnel_flow():
    title("Create a tunnel")
    mode = ask_choice("Choose a mode:", [
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
    else:
        info(f"Starting tunnel '{record['cf_name']}' for https://{record['hostname']} ...")
        info("Press Ctrl+C to stop.")
        try:
            subprocess.run(
                ["cloudflared", "tunnel", "--config", record["config_path"], "run",
                 record["cf_name"]]
            )
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
        else:
            where = f"https://{t['hostname']} → localhost:{t['port']}"
        cmd = f", start command: {C.CYAN}{t['start_command']}{C.RESET}" if t.get("start_command") else ""
        print(f"  {C.CYAN}{i}{C.RESET}. {C.BOLD}{t['name']}{C.RESET} — {where}{cmd}")


def manage_tunnels_flow():
    title("Manage tunnels")
    tunnels = storage.list_tunnels()
    if not tunnels:
        warn("No tunnels saved yet. Create one, or import ones made outside psbdx.")
    else:
        _print_tunnels(tunnels)

    if cf.is_logged_in():
        discovered = _discover()
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

    action = ask_choice(f"'{record['name']}' — what do you want to do?", [
        ("start", "Start it now"),
        ("command", "Set/change its start command"),
        ("delete", "Delete it"),
        ("back", "Back"),
    ])
    if action == "start":
        run_tunnel(record)
    elif action == "command":
        create_start_command(record["id"])
    elif action == "delete":
        _delete_tunnel(record)


def _delete_tunnel(record):
    if not confirm(f"Really delete '{record['name']}'? This can't be undone.", default=False):
        return
    if record["mode"] == "domain":
        cf.delete_tunnel(record["cf_name"])
        if record.get("config_path") and os.path.exists(record["config_path"]):
            os.remove(record["config_path"])
    if record.get("start_command"):
        _remove_command_file(record["start_command"])
    storage.delete_tunnel(record["id"])
    ok("Deleted.")


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

    discovered = _discover() if logged_in else []
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

    choice = ask_choice("What next?", menu)
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
    choice = ask_choice("What next?", [
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
