"""
psbdx.storage
Tiny JSON-backed store for saved tunnels, custom start commands, etc.
Lives at ~/.psbdx-data/data.json so it survives `psbdx update` (which
only touches the git checkout in ~/.psbdx).
"""

import json
import os
import uuid

from . import utils

DATA_FILE = os.path.join(utils.data_dir(), "data.json")

DEFAULT = {
    "tunnels": [],       # list of tunnel records, see new_tunnel_record()
    "commands": {},      # {command_name: tunnel_id}
}


def _load():
    if not os.path.exists(DATA_FILE):
        return json.loads(json.dumps(DEFAULT))
    try:
        with open(DATA_FILE, "r") as f:
            data = json.load(f)
        data.setdefault("tunnels", [])
        data.setdefault("commands", {})
        return data
    except (json.JSONDecodeError, OSError):
        return json.loads(json.dumps(DEFAULT))


def _save(data):
    tmp = DATA_FILE + ".tmp"
    with open(tmp, "w") as f:
        json.dump(data, f, indent=2)
    os.replace(tmp, DATA_FILE)


def new_tunnel_record(mode, port, name=None, subdomain=None, domain=None,
                       cf_name=None, cf_id=None, hostname=None, config_path=None,
                       token=None):
    return {
        "id": uuid.uuid4().hex[:8],
        "mode": mode,                # "quick" or "domain"
        "name": name,                # friendly label
        "port": port,
        "subdomain": subdomain,
        "domain": domain,
        "cf_name": cf_name,          # cloudflared tunnel name (domain mode)
        "cf_id": cf_id,              # cloudflared tunnel UUID (domain mode)
        "hostname": hostname,        # full hostname (domain mode)
        "config_path": config_path,  # path to the cloudflared config.yml
        "token": token,              # tunnel run token, for remotely-managed
                                      # (dashboard-created) tunnels instead of
                                      # a named tunnel + local config.yml
        "start_command": None,       # custom command name, if any
    }


def add_tunnel(record):
    data = _load()
    data["tunnels"].append(record)
    _save(data)
    return record


def list_tunnels():
    return _load()["tunnels"]


def get_tunnel(tunnel_id_or_name):
    for t in list_tunnels():
        if t["id"] == tunnel_id_or_name or t.get("name") == tunnel_id_or_name:
            return t
    return None


def update_tunnel(tunnel_id, **fields):
    data = _load()
    for t in data["tunnels"]:
        if t["id"] == tunnel_id:
            t.update(fields)
            _save(data)
            return t
    return None


def delete_tunnel(tunnel_id):
    data = _load()
    data["tunnels"] = [t for t in data["tunnels"] if t["id"] != tunnel_id]
    data["commands"] = {
        cmd: tid for cmd, tid in data["commands"].items() if tid != tunnel_id
    }
    _save(data)


def set_command(command_name, tunnel_id):
    data = _load()
    data["commands"][command_name] = tunnel_id
    _save(data)


def remove_command(command_name):
    data = _load()
    data["commands"].pop(command_name, None)
    _save(data)


def all_commands():
    return _load()["commands"]
