"""
psbdx.utils
Shared helpers: platform detection, colored output, safe prompts,
and small subprocess wrappers used across the tool.
"""

import os
import re
import shutil
import subprocess
import sys

# --------------------------------------------------------------------------
# Colors (safe no-ops if the terminal doesn't support them)
# --------------------------------------------------------------------------
class C:
    RESET = "\033[0m"
    BOLD = "\033[1m"
    DIM = "\033[2m"
    RED = "\033[31m"
    GREEN = "\033[32m"
    YELLOW = "\033[33m"
    BLUE = "\033[34m"
    MAGENTA = "\033[35m"
    CYAN = "\033[36m"

    @staticmethod
    def disable():
        for attr in ("RESET", "BOLD", "DIM", "RED", "GREEN", "YELLOW", "BLUE", "MAGENTA", "CYAN"):
            setattr(C, attr, "")


if not sys.stdout.isatty():
    C.disable()


def ok(msg):
    print(f"{C.GREEN}✔{C.RESET} {msg}")


def warn(msg):
    print(f"{C.YELLOW}⚠{C.RESET} {msg}")


def err(msg):
    print(f"{C.RED}✘{C.RESET} {msg}")


def info(msg):
    print(f"{C.CYAN}ℹ{C.RESET} {msg}")


def title(msg):
    bar = "─" * max(4, len(msg) + 2)
    print(f"\n{C.BOLD}{C.MAGENTA}{bar}{C.RESET}")
    print(f"{C.BOLD}{C.MAGENTA} {msg}{C.RESET}")
    print(f"{C.BOLD}{C.MAGENTA}{bar}{C.RESET}\n")


# --------------------------------------------------------------------------
# Platform detection
# --------------------------------------------------------------------------
def is_termux():
    return "com.termux" in os.environ.get("PREFIX", "") or os.path.isdir(
        "/data/data/com.termux/files/usr"
    )


def bin_dir():
    """Directory used for installing wrapper/launcher scripts."""
    if is_termux():
        return os.environ.get("PREFIX", "/data/data/com.termux/files/usr") + "/bin"
    local_bin = os.path.expanduser("~/.local/bin")
    os.makedirs(local_bin, exist_ok=True)
    return local_bin


def install_dir():
    """Where the tool's own source lives (the git checkout)."""
    return os.path.expanduser("~/.psbdx")


def data_dir():
    d = os.path.expanduser("~/.psbdx-data")
    os.makedirs(d, exist_ok=True)
    return d


def cloudflared_dir():
    d = os.path.expanduser("~/.cloudflared")
    os.makedirs(d, exist_ok=True)
    return d


def arch_string():
    """Map platform arch to cloudflared release asset naming."""
    machine = os.uname().machine.lower()
    if machine in ("aarch64", "arm64"):
        return "arm64"
    if machine in ("armv7l", "armv6l", "arm"):
        return "arm"
    if machine in ("x86_64", "amd64"):
        return "amd64"
    if machine in ("i386", "i686"):
        return "386"
    return machine


# --------------------------------------------------------------------------
# Subprocess helpers
# --------------------------------------------------------------------------
def run(cmd, **kwargs):
    """Run a command, returning CompletedProcess. Never raises on nonzero exit."""
    return subprocess.run(cmd, text=True, capture_output=True, **kwargs)


def run_live(cmd, **kwargs):
    """Run a command with output streamed straight to the terminal (used for
    long-lived / interactive processes like `cloudflared tunnel run`)."""
    return subprocess.run(cmd, **kwargs)


def which(cmd):
    return shutil.which(cmd)


# --------------------------------------------------------------------------
# Prompts
# --------------------------------------------------------------------------
def ask(prompt, default=None, required=True):
    suffix = f" [{default}]" if default is not None else ""
    while True:
        val = input(f"{C.BOLD}?{C.RESET} {prompt}{suffix}: ").strip()
        if not val and default is not None:
            return default
        if not val and not required:
            return ""
        if val:
            return val
        warn("This value is required.")


def ask_port(prompt="Local port your app is running on", default=None):
    while True:
        raw = ask(prompt, default=default)
        if raw.isdigit() and 1 <= int(raw) <= 65535:
            return int(raw)
        warn("Enter a valid port number between 1 and 65535.")


def ask_choice(prompt, options):
    """options: list of (key, label). Returns the chosen key."""
    print(f"{C.BOLD}{prompt}{C.RESET}")
    for i, (_, label) in enumerate(options, start=1):
        print(f"  {C.CYAN}{i}{C.RESET}. {label}")
    while True:
        raw = input(f"{C.BOLD}>{C.RESET} Choose [1-{len(options)}]: ").strip()
        if raw.isdigit() and 1 <= int(raw) <= len(options):
            return options[int(raw) - 1][0]
        warn("Invalid choice, try again.")


def confirm(prompt, default=False):
    hint = "Y/n" if default else "y/N"
    raw = input(f"{C.BOLD}?{C.RESET} {prompt} ({hint}): ").strip().lower()
    if not raw:
        return default
    return raw in ("y", "yes")


SUBDOMAIN_RE = re.compile(r"^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$")
DOMAIN_RE = re.compile(
    r"^(?=.{1,253}$)([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$"
)


def ask_subdomain(prompt="Subdomain (e.g. app, api, blog)"):
    while True:
        val = ask(prompt)
        if SUBDOMAIN_RE.match(val):
            return val.lower()
        warn("That doesn't look like a valid subdomain label.")


def ask_domain(prompt="Your domain as added in Cloudflare (e.g. example.com)"):
    while True:
        val = ask(prompt).lower()
        if DOMAIN_RE.match(val):
            return val
        warn("That doesn't look like a valid domain, try again.")


def slugify(text):
    text = text.strip().lower()
    text = re.sub(r"[^a-z0-9-]+", "-", text)
    text = re.sub(r"-{2,}", "-", text).strip("-")
    return text or "tunnel"


def open_url(url):
    """Best-effort opening of a URL for the user (used for the Cloudflare
    login link). Falls back to just printing it."""
    if is_termux() and which("termux-open-url"):
        run(["termux-open-url", url])
        return True
    for opener in ("xdg-open", "open"):
        if which(opener):
            subprocess.Popen(
                [opener, url], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
            )
            return True
    return False
