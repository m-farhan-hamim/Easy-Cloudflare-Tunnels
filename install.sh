#!/usr/bin/env bash
# Easy Cloudflare Tunnels (psbdx) — installer
# Works on Termux (Android) and regular Linux terminals.
#
# Usage:
#   curl -fsSL https://raw.githubusercontent.com/m-farhan-hamim/Easy-Cloudflare-Tunnels/main/install.sh | bash
#
set -euo pipefail

REPO_URL="https://github.com/m-farhan-hamim/Easy-Cloudflare-Tunnels.git"
INSTALL_DIR="$HOME/.psbdx"

c_green() { printf '\033[32m%s\033[0m\n' "$1"; }
c_yellow() { printf '\033[33m%s\033[0m\n' "$1"; }
c_red() { printf '\033[31m%s\033[0m\n' "$1"; }
c_cyan() { printf '\033[36m%s\033[0m\n' "$1"; }

step() { printf '\n\033[1;35m▶ %s\033[0m\n' "$1"; }

is_termux() {
  [ -n "${PREFIX:-}" ] && [[ "$PREFIX" == *"com.termux"* ]]
}

step "Detecting environment"
if is_termux; then
  ENV_NAME="Termux"
  BIN_DIR="$PREFIX/bin"
  PKG_INSTALL="pkg install -y"
  PKG_UPDATE="pkg update -y"
else
  ENV_NAME="Linux"
  BIN_DIR="$HOME/.local/bin"
  mkdir -p "$BIN_DIR"
  if command -v apt-get >/dev/null 2>&1; then
    PKG_INSTALL="sudo apt-get install -y"
    PKG_UPDATE="sudo apt-get update -y"
  elif command -v dnf >/dev/null 2>&1; then
    PKG_INSTALL="sudo dnf install -y"
    PKG_UPDATE="sudo dnf check-update -y || true"
  elif command -v pacman >/dev/null 2>&1; then
    PKG_INSTALL="sudo pacman -S --noconfirm"
    PKG_UPDATE="sudo pacman -Sy"
  else
    PKG_INSTALL=""
    PKG_UPDATE=""
  fi
fi
c_green "Detected: $ENV_NAME"

step "Checking dependencies (git, python3, curl)"
MISSING=()
for dep in git python3 curl; do
  command -v "$dep" >/dev/null 2>&1 || MISSING+=("$dep")
done

if [ "${#MISSING[@]}" -gt 0 ]; then
  if [ -n "$PKG_INSTALL" ]; then
    c_yellow "Installing missing packages: ${MISSING[*]}"
    eval "$PKG_UPDATE" || true
    eval "$PKG_INSTALL" "${MISSING[*]}"
  else
    c_red "Missing: ${MISSING[*]} — install these manually, then re-run this script."
    exit 1
  fi
else
  c_green "All set."
fi

step "Fetching Easy Cloudflare Tunnels"
if [ -d "$INSTALL_DIR/.git" ]; then
  c_yellow "Already installed, updating instead..."
  git -C "$INSTALL_DIR" pull --ff-only
else
  rm -rf "$INSTALL_DIR"
  git clone --depth 1 "$REPO_URL" "$INSTALL_DIR"
fi

step "Installing cloudflared"
if command -v cloudflared >/dev/null 2>&1; then
  c_green "cloudflared already installed."
elif is_termux; then
  pkg install -y cloudflared || c_yellow "pkg install failed, psbdx will try a direct download on first run."
else
  ARCH="$(uname -m)"
  case "$ARCH" in
    x86_64|amd64) CF_ARCH="amd64" ;;
    aarch64|arm64) CF_ARCH="arm64" ;;
    armv7l|armv6l) CF_ARCH="arm" ;;
    i386|i686) CF_ARCH="386" ;;
    *) CF_ARCH="amd64" ;;
  esac
  CF_URL="https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-${CF_ARCH}"
  if curl -fsSL "$CF_URL" -o "$BIN_DIR/cloudflared"; then
    chmod +x "$BIN_DIR/cloudflared"
    c_green "cloudflared installed to $BIN_DIR/cloudflared"
  else
    c_yellow "Couldn't download cloudflared automatically. psbdx will retry on first run."
  fi
fi

step "Setting up the 'psbdx' command"
mkdir -p "$BIN_DIR"
cat > "$BIN_DIR/psbdx" <<EOF
#!/usr/bin/env bash
exec python3 "$INSTALL_DIR/psbdx/main.py" "\$@"
EOF
chmod +x "$BIN_DIR/psbdx"
c_green "Installed launcher at $BIN_DIR/psbdx"

# Make sure BIN_DIR is on PATH for future shells (Termux's $PREFIX/bin
# already is; ~/.local/bin on plain Linux often needs adding).
if ! is_termux; then
  for RC in "$HOME/.bashrc" "$HOME/.zshrc"; do
    [ -f "$RC" ] || continue
    if ! grep -q '.local/bin' "$RC" 2>/dev/null; then
      echo 'export PATH="$HOME/.local/bin:$PATH"' >> "$RC"
    fi
  done
  export PATH="$HOME/.local/bin:$PATH"
fi

# Termux doesn't have a shared plugin help registry, so the friendliest
# thing we can do is remind the user on every new session via the MOTD.
if is_termux; then
  MOTD_LINE="[psbdx] Cloudflare tunnels: run 'psbdx cloud' (or 'psbdx help')"
  MOTD_FILE="$PREFIX/etc/motd"
  touch "$MOTD_FILE"
  if ! grep -qF "$MOTD_LINE" "$MOTD_FILE" 2>/dev/null; then
    echo "$MOTD_LINE" >> "$MOTD_FILE"
  fi
fi

step "Done"
c_cyan "Run:  psbdx cloud"
c_yellow "(Open a new terminal session first if 'psbdx' isn't found yet.)"
