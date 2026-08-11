#!/usr/bin/env bash
# Uninstalls psbdx. Safe to run even if the `psbdx` command isn't on PATH.
# Run as `bash uninstall.sh` on Termux (no /usr/bin/env there), or
# `./uninstall.sh` on a regular Linux system.
set -euo pipefail

INSTALL_DIR="$HOME/.psbdx"

if [ -f "$INSTALL_DIR/psbdx/main.py" ]; then
  python3 "$INSTALL_DIR/psbdx/main.py" uninstall
else
  echo "psbdx doesn't look installed at $INSTALL_DIR."
fi
