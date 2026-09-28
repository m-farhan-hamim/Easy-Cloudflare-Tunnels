"""
psbdx.nav
Two ways to move through psbdx's menus:

  * Number navigation - type the number next to an option and press
    Enter. Works anywhere, always has, still the fallback if arrow
    nav isn't usable.
  * Arrow navigation - move a cursor with the Up/Down arrow keys (the
    ones right above Termux's on-screen keyboard work great) and press
    Enter to pick. A digit key still jumps straight to that option.

The style is picked once, the first time psbdx runs. It's saved in
~/.psbdx-data (not the git checkout), so it survives 'psbdx update'.
It can be changed anytime from the main menu's Settings screen.
"""

import os
import sys

from . import storage
from .utils import C, warn, ask_choice as _numbered_choice

try:
    import termios
    import tty
    import select
    _RAW_AVAILABLE = True
except ImportError:  # pragma: no cover - not on a POSIX terminal
    _RAW_AVAILABLE = False


def supports_arrow_nav():
    """Whether raw single-keypress input is usable right now."""
    if not _RAW_AVAILABLE:
        return False
    try:
        return sys.stdin.isatty() and sys.stdout.isatty()
    except Exception:
        return False


def _read_key():
    """Blocks for one keypress and resolves arrow-key escape sequences.
    Returns 'up' / 'down' / 'left' / 'right' / 'enter' / 'quit' / a
    single digit character / or '' for anything else we don't act on.

    Reads raw bytes straight from the file descriptor: going through
    sys.stdin's buffer would swallow the whole 'ESC [ A' sequence in one
    read, leaving select() nothing to see and making every arrow press
    look like a lone Esc."""
    fd = sys.stdin.fileno()
    old = termios.tcgetattr(fd)
    try:
        tty.setraw(fd)
        data = os.read(fd, 1)
        if not data:
            return "quit"  # EOF
        ch = data.decode("latin-1")
        if ch in ("\r", "\n"):
            return "enter"
        if ch in ("\x03", "q", "Q"):  # Ctrl+C, q
            return "quit"
        if ch == "\x1b":
            # Arrow keys arrive as ESC [ A/B/C/D (or ESC O A/B/C/D in
            # application mode). Give the rest of the sequence a moment
            # to show up; if it never does, it was a lone Esc press.
            seq = ""
            while len(seq) < 2:
                ready, _, _ = select.select([fd], [], [], 0.15)
                if not ready:
                    break
                more = os.read(fd, 1)
                if not more:
                    break
                seq += more.decode("latin-1")
            if len(seq) == 2 and seq[0] in ("[", "O"):
                return {"A": "up", "B": "down", "C": "right", "D": "left"}.get(seq[1], "")
            if not seq:
                return "quit"
            return ""  # some other escape sequence we don't handle
        if ch.isdigit():
            return ch
        return ""
    finally:
        termios.tcsetattr(fd, termios.TCSADRAIN, old)


def _arrow_choice(prompt, options):
    """Returns the chosen key, or None if arrow nav isn't usable right
    now / the user backed out - callers fall back to number nav then."""
    if not supports_arrow_nav():
        return None

    n = len(options)
    selected = 0

    def draw(first=False):
        if not first:
            sys.stdout.write(f"\x1b[{n}A")
        for i, (_, label) in enumerate(options):
            if i == selected:
                sys.stdout.write(f"\x1b[2K{C.CYAN}\u276f {C.BOLD}{label}{C.RESET}\n")
            else:
                sys.stdout.write(f"\x1b[2K  {label}\n")
        sys.stdout.flush()

    print(f"{C.BOLD}{prompt}{C.RESET}")
    print(f"{C.DIM}up/down move . Enter select . digits jump . q cancel{C.RESET}")
    draw(first=True)

    try:
        while True:
            key = _read_key()
            if key in ("up", "left"):
                selected = (selected - 1) % n
                draw()
            elif key in ("down", "right"):
                selected = (selected + 1) % n
                draw()
            elif key == "enter":
                print()
                return options[selected][0]
            elif key == "quit":
                print()
                return None
            elif key and key.isdigit():
                idx = int(key)
                if 1 <= idx <= n:
                    selected = idx - 1
                    draw()
                    print()
                    return options[selected][0]
    except (termios.error, OSError):
        print()
        return None


def get_nav_mode():
    return storage.get_setting("nav_mode")


def set_nav_mode(mode):
    storage.set_setting("nav_mode", mode)


def ensure_nav_mode_chosen():
    """Ask once, the very first time psbdx runs, which navigation style
    to use. Later calls just return the saved choice."""
    mode = get_nav_mode()
    if mode in ("number", "arrow"):
        return mode

    from .utils import title
    title("Welcome - choose how you'd like to navigate psbdx")
    print(f"  {C.CYAN}1{C.RESET}. Number navigation - type a number, press Enter (classic)")
    print(f"  {C.CYAN}2{C.RESET}. Arrow navigation - up/down then Enter "
          f"(great with Termux's arrow keys)")
    print(f"{C.DIM}You can change this anytime from the main menu -> Settings.{C.RESET}\n")
    while True:
        raw = input(f"{C.BOLD}>{C.RESET} Choose [1-2]: ").strip()
        if raw == "1":
            set_nav_mode("number")
            return "number"
        if raw == "2":
            if not supports_arrow_nav():
                warn("This terminal doesn't support arrow-key input right now - "
                     "using number navigation instead.")
                set_nav_mode("number")
                return "number"
            set_nav_mode("arrow")
            return "arrow"
        warn("Enter 1 or 2.")


def change_nav_mode():
    """Used from the Settings screen to switch styles anytime."""
    from .utils import title
    title("Navigation style")
    current = get_nav_mode() or "number"
    print(f"Current: {C.BOLD}{current}{C.RESET}\n")
    print(f"  {C.CYAN}1{C.RESET}. Number navigation")
    print(f"  {C.CYAN}2{C.RESET}. Arrow navigation")
    raw = input(f"{C.BOLD}>{C.RESET} Choose [1-2], or Enter to keep '{current}': ").strip()
    if raw == "1":
        set_nav_mode("number")
        print(f"{C.GREEN}\u2714{C.RESET} Switched to number navigation.")
    elif raw == "2":
        if not supports_arrow_nav():
            warn("This terminal doesn't support arrow-key input right now.")
            return
        set_nav_mode("arrow")
        print(f"{C.GREEN}\u2714{C.RESET} Switched to arrow navigation.")


def ask_choice(prompt, options):
    """Drop-in replacement for utils.ask_choice that respects the
    user's chosen navigation style, falling back to number nav
    whenever arrow nav can't be used."""
    mode = ensure_nav_mode_chosen()
    if mode == "arrow":
        result = _arrow_choice(prompt, options)
        if result is not None:
            return result
        # Arrow nav became unusable mid-session (e.g. piped input) -
        # don't strand the user, just fall back for this one prompt.
    return _numbered_choice(prompt, options)
