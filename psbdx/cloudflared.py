"""
psbdx.cloudflared
Detecting / installing the `cloudflared` binary itself, and small
wrappers around the commands psbdx needs from it.
"""

import json
import os
import re
import stat
import urllib.request

from . import utils
from .utils import C, ok, warn, err, info, run, which, is_termux


RELEASE_BASE = "https://github.com/cloudflare/cloudflared/releases/latest/download"

# Set by list_account_tunnels() whenever it comes back empty because of a
# real problem (auth, a failed command, unparsable output) rather than
# there genuinely being zero tunnels on the account.
LAST_LIST_ERROR = None


def is_installed():
    return which("cloudflared") is not None


def install():
    """Install cloudflared, preferring the platform package manager and
    falling back to downloading the official release binary."""
    info("Installing cloudflared...")

    if is_termux():
        res = run(["pkg", "install", "-y", "cloudflared"])
        if res.returncode == 0 and is_installed():
            ok("cloudflared installed via pkg.")
            return True
        warn("pkg install didn't work, falling back to a direct download.")
    else:
        # Try apt if it's around (works on Debian/Ubuntu once cloudflared
        # isn't in the default repos, so this will usually fall through
        # to the binary download below — kept as a cheap first attempt).
        if which("apt-get"):
            run(["sudo", "-n", "true"])  # touch sudo cache, ignore result

    return _install_from_release()


def _install_from_release():
    arch = utils.arch_string()
    asset = f"cloudflared-linux-{arch}"
    url = f"{RELEASE_BASE}/{asset}"
    dest_dir = utils.bin_dir()
    dest = os.path.join(dest_dir, "cloudflared")

    info(f"Downloading {url}")
    try:
        urllib.request.urlretrieve(url, dest)
    except Exception as e:
        err(f"Download failed: {e}")
        err("Install cloudflared manually and re-run psbdx.")
        return False

    st = os.stat(dest)
    os.chmod(dest, st.st_mode | stat.S_IEXEC | stat.S_IXGRP | stat.S_IXOTH)

    if is_installed():
        ok("cloudflared installed.")
        return True
    err("cloudflared still not found on PATH after download.")
    warn(f"Make sure {dest_dir} is in your PATH, then restart your shell.")
    return False


def ensure_installed():
    if is_installed():
        return True
    warn("cloudflared isn't installed yet.")
    return install()


def is_logged_in():
    return os.path.exists(os.path.join(utils.cloudflared_dir(), "cert.pem"))


def login():
    """Runs `cloudflared tunnel login`, which prints a browser URL and
    waits for the user to authorize. We try to auto-open it for them."""
    info("Opening the Cloudflare login page. Authorize the domain you want to use.")
    proc = run(["cloudflared", "tunnel", "login"])
    out = (proc.stdout or "") + (proc.stderr or "")
    m = re.search(r"https://\S+", out)
    if m:
        utils.open_url(m.group(0))
        print(f"{C.DIM}If the browser didn't open, use this link:{C.RESET}")
        print(m.group(0))
    if is_logged_in():
        ok("Logged in to Cloudflare.")
        return True
    err("Login doesn't look complete (no cert.pem found). Try again.")
    return False


def create_tunnel(name):
    proc = run(["cloudflared", "tunnel", "create", name])
    out = (proc.stdout or "") + (proc.stderr or "")
    if proc.returncode != 0:
        err(out.strip() or "Failed to create tunnel.")
        return None
    m = re.search(r"[0-9a-fA-F-]{36}", out)
    tunnel_id = m.group(0) if m else None
    if not tunnel_id:
        tunnel_id = get_tunnel_id_by_name(name)
    return tunnel_id


def get_tunnel_id_by_name(name):
    proc = run(["cloudflared", "tunnel", "list", "-o", "json"])
    if proc.returncode != 0:
        return None
    try:
        items = json.loads(proc.stdout)
    except json.JSONDecodeError:
        return None
    for item in items:
        if item.get("name") == name:
            return item.get("id")
    return None


def route_dns(cf_name, hostname):
    proc = run(["cloudflared", "tunnel", "route", "dns", "-f", cf_name, hostname])
    if proc.returncode != 0:
        err((proc.stdout or "") + (proc.stderr or ""))
        return False
    return True


def delete_tunnel(cf_name):
    run(["cloudflared", "tunnel", "cleanup", cf_name])
    proc = run(["cloudflared", "tunnel", "delete", "-f", cf_name])
    return proc.returncode == 0


def write_config(cf_name, tunnel_id, hostname, port):
    cred_file = os.path.join(utils.cloudflared_dir(), f"{tunnel_id}.json")
    config_path = os.path.join(utils.cloudflared_dir(), f"{cf_name}.yml")
    content = (
        f"tunnel: {tunnel_id}\n"
        f"credentials-file: {cred_file}\n"
        f"ingress:\n"
        f"  - hostname: {hostname}\n"
        f"    service: http://localhost:{port}\n"
        f"  - service: http_status:404\n"
    )
    with open(config_path, "w") as f:
        f.write(content)
    return config_path


# --------------------------------------------------------------------------
# Discovery of tunnels that exist in the Cloudflare account / on this
# machine but weren't created through psbdx (e.g. made by hand with
# `cloudflared tunnel create` before psbdx was installed).
# --------------------------------------------------------------------------
def list_account_tunnels():
    """All named tunnels on the logged-in Cloudflare account, deleted ones
    excluded. Returns [{id, name, created_at}, ...].

    On any failure this returns [] and records the reason in
    LAST_LIST_ERROR so callers can surface it instead of silently
    looking like "no tunnels found"."""
    global LAST_LIST_ERROR
    LAST_LIST_ERROR = None

    if not is_logged_in():
        LAST_LIST_ERROR = "not logged in (no ~/.cloudflared/cert.pem)"
        return []

    proc = run(["cloudflared", "tunnel", "list", "-o", "json"])
    raw = (proc.stdout or "")
    if proc.returncode != 0:
        LAST_LIST_ERROR = (proc.stderr or raw or "cloudflared tunnel list exited with an error").strip()
        return []

    # cloudflared sometimes mixes a warning/nag line in with the JSON on
    # stdout depending on version, so pull out just the [...] array
    # instead of assuming raw.strip() is valid JSON on its own.
    start, end = raw.find("["), raw.rfind("]")
    if start == -1 or end == -1 or end < start:
        LAST_LIST_ERROR = f"unexpected output from 'cloudflared tunnel list -o json': {raw.strip()[:300] or '(empty)'}"
        return []

    try:
        items = json.loads(raw[start:end + 1])
    except json.JSONDecodeError as e:
        LAST_LIST_ERROR = f"couldn't parse cloudflared's JSON output ({e})"
        return []

    out = []
    for item in items:
        if item.get("deleted_at"):
            continue
        out.append({
            "id": item.get("id"),
            "name": item.get("name"),
            "created_at": item.get("created_at"),
        })
    return out


def _local_config_files():
    d = utils.cloudflared_dir()
    if not os.path.isdir(d):
        return []
    return [
        os.path.join(d, f) for f in os.listdir(d)
        if f.endswith(".yml") or f.endswith(".yaml")
    ]


def parse_config_file(path):
    """Minimal parser for cloudflared's config.yml — just enough to pull
    out the tunnel id and the first hostname/service ingress rule. Avoids
    a PyYAML dependency since our own files (and the common hand-written
    ones) follow a predictable, simple layout."""
    try:
        with open(path, "r") as f:
            text = f.read()
    except OSError:
        return None

    tunnel_m = re.search(r"^tunnel:\s*(\S+)", text, re.MULTILINE)
    hostname_m = re.search(r"hostname:\s*(\S+)", text)
    service_m = re.search(r"service:\s*http[s]?://(?:localhost|127\.0\.0\.1):(\d+)", text)

    return {
        "path": path,
        "tunnel_id": tunnel_m.group(1) if tunnel_m else None,
        "hostname": hostname_m.group(1) if hostname_m else None,
        "port": int(service_m.group(1)) if service_m else None,
    }


def find_config_for_tunnel(tunnel_id):
    for path in _local_config_files():
        parsed = parse_config_file(path)
        if parsed and parsed["tunnel_id"] == tunnel_id:
            return parsed
    return None


def discover_untracked(known_cf_ids):
    """Named tunnels that exist on the account/machine but aren't in
    psbdx's own storage yet. known_cf_ids: set of cf_id values already
    tracked in storage."""
    discovered = []
    for t in list_account_tunnels():
        if not t["id"] or t["id"] in known_cf_ids:
            continue
        config = find_config_for_tunnel(t["id"])
        discovered.append({
            "cf_id": t["id"],
            "cf_name": t["name"],
            "hostname": config["hostname"] if config else None,
            "port": config["port"] if config else None,
            "config_path": config["path"] if config else None,
        })
    return discovered
