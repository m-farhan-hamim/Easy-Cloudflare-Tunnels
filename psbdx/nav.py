"""
psbdx.nav
Two ways to move through psbdx's menus:

  * Number navigation - type the number next to an option and press
    Enter. Works anywhere, always has, still the fallback if arrow
    nav isn't usable.
  * Arrow navigation - a boxed menu with a highlighted selection bar.
    Move with the Up/Down arrows (the ones above Termux's on-screen
    keyboard work great), j/k, Home/End or PgUp/PgDn; press Enter to
    pick, or a digit to jump straight to that option. q / Esc goes
    back. Long lists scroll, and the chosen option collapses to a
    one-line summary so your scrollback stays tidy.

The style is picked once, the first time psbdx runs, and can be changed
anytime from Settings. The accent color of the arrow menu is a setting
too. Everything is stored in ~/.psbdx-data, so it survives updates.

Options are (key, label) or (key, label, description) tuples; the
description of the highlighted option is shown under the list.
"""

import os
import shutil
import sys

from . import storage
from .utils import C, warn, ask_choice as _numbered_choice, \
    confirm as _numbered_confirm

try:
    import select
    import termios
    import tty
    _RAW_AVAILABLE = True
except ImportError:  # pragma: no cover - not on a POSIX terminal
    _RAW_AVAILABLE = False

ACCENTS = {
    "cyan": "CYAN",
    "green": "GREEN",
    "magenta": "MAGENTA",
    "yellow": "YELLOW",
    "blue": "BLUE",
}

_REVERSE = "\x1b[7m"
_HIDE_CURSOR = "\x1b[?25l"
_SHOW_CURSOR = "\x1b[?25h"
_EXIT_KEYS = ("back", "exit", "cancel")

# Final byte(s) of an escape sequence -> key name.
_ESC_KEYS = {
    "A": "up", "B": "down", "C": "right", "D": "left",
    "H": "home", "F": "end",
    "1~": "home", "7~": "home", "4~": "end", "8~": "end",
    "5~": "pgup", "6~": "pgdn",
}


def supports_arrow_nav():
    """Whether raw single-keypress input is usable right now."""
    if not _RAW_AVAILABLE:
        return False
    try:
        return sys.stdin.isatty() and sys.stdout.isatty()
    except Exception:
        return False


# --------------------------------------------------------------------------
# Settings
# --------------------------------------------------------------------------
def get_accent_name():
    name = storage.get_setting("accent", "cyan")
    return name if name in ACCENTS else "cyan"


def _accent_code():
    return getattr(C, ACCENTS[get_accent_name()])


# --------------------------------------------------------------------------
# Keyboard input
# --------------------------------------------------------------------------
def _next_byte(fd, timeout=0.15):
    ready, _, _ = select.select([fd], [], [], timeout)
    if not ready:
        return None
    data = os.read(fd, 1)
    return data.decode("latin-1") if data else None


def _read_key(fd):
    """Blocks for one keypress (the terminal must already be in cbreak
    mode) and returns a key name: up/down/left/right/home/end/pgup/
    pgdn/enter/quit, a single digit character, or '' for anything we
    don't act on.

    Reads raw bytes from the file descriptor: going through sys.stdin's
    buffer would swallow a whole 'ESC [ A' sequence in one read and make
    every arrow press look like a lone Esc."""
    try:
        data = os.read(fd, 1)
    except KeyboardInterrupt:
        return "quit"
    if not data:
        return "quit"  # EOF
    ch = data.decode("latin-1")
    if ch in ("\r", "\n"):
        return "enter"
    if ch in ("\x03", "\x04", "\x7f", "q", "Q"):  # Ctrl+C/D, Backspace, q
        return "quit"
    if ch == "\x1b":
        first = _next_byte(fd)
        if first is None:
            return "quit"  # a lone Esc press
        if first == "O":  # application-mode arrows: ESC O A
            second = _next_byte(fd)
            return _ESC_KEYS.get(second or "", "")
        if first == "[":  # ESC [ A  /  ESC [ 5 ~
            seq = ""
            while len(seq) < 6:
                b = _next_byte(fd)
                if b is None:
                    break
                seq += b
                if "@" <= b <= "~":
                    break
            return _ESC_KEYS.get(seq, "")
        return ""
    if ch in ("k", "K"):
        return "up"
    if ch in ("j", "J"):
        return "down"
    if ch == "g":
        return "home"
    if ch == "G":
        return "end"
    if ch.isdigit():
        return ch
    return ""


# --------------------------------------------------------------------------
# Arrow menu
# --------------------------------------------------------------------------
def _fit(text, width):
    if width <= 0:
        return ""
    if len(text) <= width:
        return text
    return text[:max(0, width - 1)] + "\u2026"


def _arrow_choice(prompt, options, default_key=None):
    """Returns the chosen key, or None if arrow nav isn't usable right
    now / the user backed out - callers fall back then."""
    if not supports_arrow_nav():
        return None
    fd = sys.stdin.fileno()
    try:
        old_attrs = termios.tcgetattr(fd)
    except termios.error:
        return None

    n = len(options)
    out = sys.stdout
    accent = _accent_code()
    rev = _REVERSE if C.RESET else ""  # no reverse video if colors are off
    R, B, D = C.RESET, C.BOLD, C.DIM
    back_word = "back" if options[-1][0] in _EXIT_KEYS else "cancel"
    numw = len(str(n))
    start = next((i for i, o in enumerate(options) if o[0] == default_key), 0)
    state = {"sel": start, "top": 0, "drawn": 0}

    def build():
        cols, lines = shutil.get_terminal_size((80, 24))
        width = max(24, min(cols - 1, 60))
        inner = width - 2
        vis = min(n, max(3, lines - 6))
        sel = state["sel"]
        top = state["top"]
        if sel < top:
            top = sel
        elif sel >= top + vis:
            top = sel - vis + 1
        top = max(0, min(top, n - vis))
        state["top"] = top

        head = prompt if n <= vis else f"{prompt}  ({sel + 1}/{n})"
        rows = [f"{accent}{B}\u256d\u2500 {_fit(head, width - 4)}{R}"]
        for i in range(top, top + vis):
            label = options[i][1]
            num = str(i + 1).rjust(numw)
            if i == sel:
                text = _fit(f"\u276f {num}  {label}", inner).ljust(inner)
                rows.append(f"{accent}\u2502{R} {rev}{accent}{B}{text}{R}")
            else:
                room = inner - (4 + numw)
                rows.append(f"{accent}\u2502{R}   {D}{num}{R}  {_fit(label, room)}")
        desc = options[sel][2] if len(options[sel]) > 2 else ""
        rows.append(f"{accent}\u2502{R}  {D}{_fit(desc, inner - 1)}{R}")
        if cols >= 46:
            hint = f"\u2191\u2193 move \u00b7 Enter select \u00b7 1-9 jump \u00b7 q {back_word}"
        else:
            hint = f"\u2191\u2193 \u00b7 Enter \u00b7 q {back_word}"
        rows.append(f"{accent}\u2570\u2500{R} {D}{_fit(hint, width - 4)}{R}")
        return rows

    def draw():
        rows = build()
        buf = []
        if state["drawn"]:
            buf.append(f"\x1b[{state['drawn']}A\r")
        for row in rows:
            buf.append("\x1b[2K" + row + "\n")
        if len(rows) < state["drawn"]:
            buf.append("\x1b[J")
        state["drawn"] = len(rows)
        out.write("".join(buf))
        out.flush()

    def collapse(summary=""):
        if state["drawn"]:
            out.write(f"\x1b[{state['drawn']}A\r\x1b[J")
        if summary:
            out.write(summary + "\n")
        out.flush()

    chosen = None
    try:
        tty.setcbreak(fd)
        out.write(_HIDE_CURSOR)
        draw()
        while True:
            key = _read_key(fd)
            sel = state["sel"]
            if key == "":
                continue
            if key in ("up", "left"):
                state["sel"] = (sel - 1) % n
            elif key in ("down", "right"):
                state["sel"] = (sel + 1) % n
            elif key == "home":
                state["sel"] = 0
            elif key == "end":
                state["sel"] = n - 1
            elif key == "pgup":
                state["sel"] = max(0, sel - 5)
            elif key == "pgdn":
                state["sel"] = min(n - 1, sel + 5)
            elif key == "enter":
                chosen = sel
                break
            elif key == "quit":
                break
            elif key.isdigit():
                idx = int(key)
                if 1 <= idx <= n:
                    state["sel"] = idx - 1
                    draw()
                    chosen = idx - 1
                    break
                continue
            draw()
    except KeyboardInterrupt:
        chosen = None
    except (termios.error, OSError):
        chosen = None
    finally:
        try:
            termios.tcsetattr(fd, termios.TCSADRAIN, old_attrs)
        except termios.error:
            pass
        out.write(_SHOW_CURSOR)
        out.flush()

    if chosen is None:
        collapse()
        return None
    label = options[chosen][1]
    avail = max(10, shutil.get_terminal_size((80, 24))[0] - 6)
    p = _fit(prompt, avail // 2)
    collapse(f"{accent}\u2714{R} {B}{p}{R} {D}\u203a{R} {_fit(label, avail - len(p))}")
    return options[chosen][0]


# --------------------------------------------------------------------------
# Public API
# --------------------------------------------------------------------------
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

    def label(key, text):
        return text + (" (current)" if key == current else "")

    choice = ask_choice("Navigation style", [
        ("number", label("number", "Number navigation"), "Type the number of an option, press Enter"),
        ("arrow", label("arrow", "Arrow navigation"), "Up/Down (or j/k) to move, Enter to select"),
        ("back", "Keep it as it is"),
    ], default_key=current)
    if choice == "number":
        set_nav_mode("number")
        print(f"{C.GREEN}\u2714{C.RESET} Number navigation on.")
    elif choice == "arrow":
        if not supports_arrow_nav():
            warn("This terminal doesn't support arrow-key input right now.")
            return
        set_nav_mode("arrow")
        print(f"{C.GREEN}\u2714{C.RESET} Arrow navigation on.")


def confirm(prompt, default=False):
    """Yes/No question in the user's navigation style: a two-option
    arrow menu (with the default pre-selected) or the classic typed
    (Y/n) prompt. q / Esc counts as No."""
    if ensure_nav_mode_chosen() == "arrow" and supports_arrow_nav():
        header = prompt
        if len(prompt) > 50:  # the menu header is one truncated line
            print(f"{C.BOLD}{prompt}{C.RESET}")
            header = "Confirm"
        result = _arrow_choice(header, [("yes", "Yes"), ("no", "No")],
                               default_key="yes" if default else "no")
        if result is not None:
            return result == "yes"
        if supports_arrow_nav():
            return False
    return _numbered_confirm(prompt, default)


def change_accent():
    """Pick the accent color used by the arrow-navigation menu."""
    from .utils import title
    title("Menu accent color")
    current = get_accent_name()
    options = [(name, name.capitalize() + (" (current)" if name == current else ""))
               for name in ACCENTS]
    options.append(("back", "Back"))
    choice = ask_choice("Pick an accent color", options)
    if choice in ACCENTS:
        storage.set_setting("accent", choice)
        print(f"{C.GREEN}\u2714{C.RESET} Accent color set to {choice}"
              f"{'' if get_nav_mode() == 'arrow' else ' (used by arrow navigation)'}.")


def ask_choice(prompt, options, default_key=None):
    """Drop-in replacement for utils.ask_choice that respects the
    user's chosen navigation style, falling back to number nav
    whenever arrow nav can't be used. default_key pre-selects an option
    in the arrow menu."""
    mode = ensure_nav_mode_chosen()
    if mode == "arrow":
        result = _arrow_choice(prompt, options, default_key)
        if result is not None:
            return result
        if supports_arrow_nav() and options[-1][0] in _EXIT_KEYS:
            return options[-1][0]  # q / Esc = back
        # Arrow nav unusable (e.g. piped input) - fall through.
    return _numbered_choice(prompt, [(o[0], o[1]) for o in options])
