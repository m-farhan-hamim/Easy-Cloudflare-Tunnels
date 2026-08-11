#!/usr/bin/env python3
"""
psbdx — entry point.

Installed by install.sh as a thin wrapper that execs this file with
python3. Kept import-safe when run either as `python3 main.py` or as
`python3 -m psbdx.main`, since the wrapper script always calls it by
absolute path.
"""

import argparse
import os
import subprocess
import sys

# Make sure `psbdx` (this file's parent package) is importable even when
# this file is executed directly by absolute path, not via `-m`.
_THIS_DIR = os.path.dirname(os.path.abspath(__file__))
_PARENT = os.path.dirname(_THIS_DIR)
if _PARENT not in sys.path:
    sys.path.insert(0, _PARENT)

from psbdx import __version__            # noqa: E402
from psbdx import cloud, storage, utils  # noqa: E402
from psbdx.utils import C, ok, err, info, title  # noqa: E402


BANNER = f"""{C.CYAN}{C.BOLD}
   ____  ______ ____  ____  __  __
  / __ \\/ ___/ // __ )/ __ \\/ /_/ /
 / /_/ /\\__ \\/ // __  / / / / __  /
/ ____/___/ /_/ /_/ / /_/ / / / /
/_/    /____/(_)_____/_____/_/ /_/{C.RESET}
{C.DIM}Easy Cloudflare Tunnels — psbdx cloud{C.RESET}
"""


def cmd_cloud(_args):
    cloud.main_menu()


def cmd_start(args):
    if not args.name:
        err("Usage: psbdx start <tunnel-name-or-id>")
        sys.exit(1)
    cloud.start_by_id(args.name)


def cmd_update(_args):
    title("Updating psbdx")
    install_dir = utils.install_dir()
    if not os.path.isdir(os.path.join(install_dir, ".git")):
        err(f"{install_dir} isn't a git checkout — reinstall using install.sh instead.")
        sys.exit(1)
    info("Pulling latest changes...")
    proc = subprocess.run(["git", "-C", install_dir, "pull", "--ff-only"])
    if proc.returncode != 0:
        err("Update failed. Resolve any local changes in ~/.psbdx and try again.")
        sys.exit(1)
    ok("psbdx is up to date.")


def cmd_uninstall(_args):
    title("Uninstall psbdx")
    from psbdx.utils import confirm
    if not confirm("This removes psbdx, its saved tunnels list, and start "
                    "commands (cloudflared itself and your DNS records are "
                    "left alone). Continue?", default=False):
        print("Cancelled.")
        return

    for cmd_name in list(storage.all_commands().keys()):
        cloud._remove_command_file(cmd_name)

    wrapper = os.path.join(utils.bin_dir(), "psbdx")
    if os.path.exists(wrapper):
        os.remove(wrapper)

    import shutil
    if os.path.isdir(utils.install_dir()):
        shutil.rmtree(utils.install_dir())
    if os.path.isdir(utils.data_dir()):
        shutil.rmtree(utils.data_dir())

    ok("Uninstalled. Your Cloudflare tunnels/DNS records are untouched — "
       "remove those from the Cloudflare dashboard if you want them gone too.")


def cmd_help(_args):
    print(BANNER)
    print(f"""{C.BOLD}Usage:{C.RESET} psbdx <command>

{C.BOLD}Commands:{C.RESET}
  {C.CYAN}cloud{C.RESET}       Open the tunnel manager (create/manage tunnels & domains)
  {C.CYAN}start{C.RESET} NAME  Start a saved tunnel directly by its name or id
  {C.CYAN}update{C.RESET}      Pull the latest version of psbdx
  {C.CYAN}uninstall{C.RESET}   Remove psbdx from this device
  {C.CYAN}help{C.RESET}        Show this message

{C.DIM}Tip: any tunnel can get its own one-word start command from inside
'psbdx cloud' → Manage start commands.{C.RESET}
""")


def build_parser():
    parser = argparse.ArgumentParser(prog="psbdx", add_help=False)
    parser.add_argument("--version", action="store_true")
    sub = parser.add_subparsers(dest="command")

    sub.add_parser("cloud")

    p_start = sub.add_parser("start")
    p_start.add_argument("name", nargs="?")

    sub.add_parser("update")
    sub.add_parser("uninstall")
    sub.add_parser("help")
    return parser


def main():
    parser = build_parser()
    args = parser.parse_args()

    if args.version:
        print(f"psbdx {__version__}")
        return

    handlers = {
        "cloud": cmd_cloud,
        "start": cmd_start,
        "update": cmd_update,
        "uninstall": cmd_uninstall,
        "help": cmd_help,
        None: cmd_help,
    }
    handler = handlers.get(args.command, cmd_help)
    try:
        handler(args)
    except KeyboardInterrupt:
        print("\nCancelled.")
        sys.exit(130)


if __name__ == "__main__":
    main()
