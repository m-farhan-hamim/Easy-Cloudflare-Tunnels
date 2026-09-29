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
        err("Usage: psbdx start <tunnel-name-or-id> [-bg | -stop | -restart | -logs | -status]")
        sys.exit(1)
    if args.stop:
        cloud.cli_stop(args.name)
    elif args.restart:
        cloud.cli_restart(args.name)
    elif args.logs:
        cloud.cli_logs(args.name, follow=True)
    elif args.status:
        cloud.cli_status(args.name)
    else:
        cloud.start_by_id(args.name, background=args.background)


def cmd_list(_args):
    cloud.cli_list()


def cmd_stop(args):
    if not args.all and not args.name:
        err("Usage: psbdx stop <tunnel-name-or-id>   (or: psbdx stop --all)")
        sys.exit(1)
    cloud.cli_stop(args.name, stop_all=args.all)


def cmd_restart(args):
    cloud.cli_restart(args.name)


def cmd_logs(args):
    cloud.cli_logs(args.name, follow=args.follow)


def cmd_status(args):
    if args.name:
        cloud.cli_status(args.name)
    else:
        cloud.cli_list()


def cmd_autostart(_args):
    cloud.run_autostart()


def cmd_doctor(_args):
    if cloud.run_doctor():
        sys.exit(1)


def cmd_update(_args):
    title("Updating psbdx")
    if not cloud.update_self():
        sys.exit(1)


def cmd_uninstall(_args):
    title("Uninstall psbdx")
    from psbdx.nav import confirm
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
                {C.DIM}add{C.RESET} {C.CYAN}-bg{C.RESET} {C.DIM}to run it in the background, e.g. 'psbdx start mysite -bg'{C.RESET}
  {C.CYAN}list{C.RESET}        Show saved tunnels and which are running
  {C.CYAN}status{C.RESET} [NAME]  Show one tunnel's details (or the list, with no name)
  {C.CYAN}stop{C.RESET} NAME   Stop a background tunnel ({C.CYAN}--all{C.RESET} stops every one)
  {C.CYAN}restart{C.RESET} NAME  Restart a tunnel in the background
  {C.CYAN}logs{C.RESET} NAME   Show a tunnel's recent logs ({C.CYAN}-f{C.RESET} to follow live)
  {C.CYAN}autostart{C.RESET}   Start every tunnel flagged 'autostart' (used at boot)
  {C.CYAN}doctor{C.RESET}      Check cloudflared, network, stale runs and start commands
  {C.CYAN}update{C.RESET}      Pull the latest version of psbdx
  {C.CYAN}uninstall{C.RESET}   Remove psbdx from this device
  {C.CYAN}help{C.RESET}        Show this message

{C.DIM}Tip: any tunnel can get its own one-word start command from inside
'psbdx cloud' → Manage start commands. Then, e.g. for a command 'mysite':
  mysite -bg   mysite -stop   mysite -restart   mysite -logs   mysite -status{C.RESET}
""")


def build_parser():
    parser = argparse.ArgumentParser(prog="psbdx", add_help=False)
    parser.add_argument("--version", action="store_true")
    sub = parser.add_subparsers(dest="command")

    sub.add_parser("cloud")

    p_start = sub.add_parser("start")
    p_start.add_argument("name", nargs="?")
    p_start.add_argument("-bg", "--background", action="store_true",
                          help="Run this tunnel in the background instead of the foreground.")
    p_start.add_argument("-stop", "--stop", action="store_true",
                          help="Stop this tunnel's background run.")
    p_start.add_argument("-restart", "--restart", action="store_true",
                          help="Restart this tunnel in the background.")
    p_start.add_argument("-logs", "--logs", action="store_true",
                          help="Follow this tunnel's logs live.")
    p_start.add_argument("-status", "--status", action="store_true",
                          help="Show this tunnel's details and status.")

    sub.add_parser("list")
    p_status = sub.add_parser("status")
    p_status.add_argument("name", nargs="?")
    p_stop = sub.add_parser("stop")
    p_stop.add_argument("name", nargs="?")
    p_stop.add_argument("-a", "--all", action="store_true")
    p_restart = sub.add_parser("restart")
    p_restart.add_argument("name")
    p_logs = sub.add_parser("logs")
    p_logs.add_argument("name")
    p_logs.add_argument("-f", "--follow", action="store_true")
    sub.add_parser("autostart")
    sub.add_parser("doctor")

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
        "list": cmd_list,
        "status": cmd_status,
        "stop": cmd_stop,
        "restart": cmd_restart,
        "logs": cmd_logs,
        "autostart": cmd_autostart,
        "doctor": cmd_doctor,
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
