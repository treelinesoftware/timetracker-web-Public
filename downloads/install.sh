#!/bin/bash
# Time Tracker installer for Linux (KDE Plasma). Run as your normal user, not root:
#   curl -fsSL <server>/downloads/install.sh | bash -s -- --server <server> --token <token>
# Uninstall:
#   curl -fsSL <server>/downloads/install.sh | bash -s -- --uninstall
set -euo pipefail

APP_DIR="$HOME/.local/share/timetracker"
UNIT_DIR="$HOME/.config/systemd/user"
UNIT="timetracker.service"
SERVER=""
TOKEN=""
UNINSTALL=0

while [ $# -gt 0 ]; do
    case "$1" in
        --server) SERVER="${2%/}"; shift 2 ;;
        --token) TOKEN="$2"; shift 2 ;;
        --uninstall) UNINSTALL=1; shift ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done

if [ "$(id -u)" -eq 0 ]; then
    echo "Run this as your normal user (not root); it installs into your home directory." >&2
    exit 1
fi

if [ "$UNINSTALL" = 1 ]; then
    systemctl --user disable --now "$UNIT" 2>/dev/null || true
    rm -f "$UNIT_DIR/$UNIT"
    systemctl --user daemon-reload
    rm -rf "$APP_DIR"
    echo "Time Tracker removed."
    exit 0
fi

if [ -z "$SERVER" ] || [ -z "$TOKEN" ]; then
    echo "Usage: install.sh --server http://host/timetracker --token TOKEN" >&2
    exit 1
fi

echo "==> Checking this computer"
case "${XDG_CURRENT_DESKTOP:-}" in
    *KDE*) echo "    KDE Plasma detected" ;;
    *) echo "    WARNING: KDE Plasma not detected (XDG_CURRENT_DESKTOP=${XDG_CURRENT_DESKTOP:-unset})."
       echo "    The tracker supports KDE Plasma, and GNOME with the Focused Window D-Bus extension." ;;
esac
case "$(uname -m)" in
    x86_64) ARCH=amd64 ;;
    aarch64|arm64) ARCH=arm64 ;;
    *) echo "Unsupported CPU architecture: $(uname -m)" >&2; exit 1 ;;
esac

echo "==> Downloading tracker ($ARCH)"
mkdir -p "$APP_DIR"
systemctl --user stop "$UNIT" 2>/dev/null || true
curl -fsSL "$SERVER/downloads/timetracker-linux-$ARCH" -o "$APP_DIR/timetracker.new"
chmod 755 "$APP_DIR/timetracker.new"
mv -f "$APP_DIR/timetracker.new" "$APP_DIR/timetracker"

echo "==> Writing configuration"
umask 077
cat > "$APP_DIR/.env" <<EOF
WEBHOOK_URL=$SERVER/api/ingest.php
WEBHOOK_TOKEN=$TOKEN
EOF
umask 022

echo "==> Checking idle detection (swayidle)"
if command -v swayidle >/dev/null 2>&1; then
    echo "    swayidle found"
else
    INSTALL_CMD=""
    if command -v dnf >/dev/null 2>&1; then INSTALL_CMD="sudo dnf install -y swayidle"
    elif command -v apt-get >/dev/null 2>&1; then INSTALL_CMD="sudo apt-get install -y swayidle"
    elif command -v zypper >/dev/null 2>&1; then INSTALL_CMD="sudo zypper install -y swayidle"
    elif command -v pacman >/dev/null 2>&1; then INSTALL_CMD="sudo pacman -S --noconfirm swayidle"
    fi
    if [ -n "$INSTALL_CMD" ] && [ -r /dev/tty ]; then
        echo "    swayidle is missing; installing it (you may be asked for your password)"
        $INSTALL_CMD </dev/tty || echo "    WARNING: could not install swayidle; idle time will be counted as active."
    else
        echo "    WARNING: swayidle is missing; idle time will be counted as active. Install the 'swayidle' package."
    fi
fi

echo "==> Setting up autostart"
mkdir -p "$UNIT_DIR"
cat > "$UNIT_DIR/$UNIT" <<EOF
[Unit]
Description=Time Tracker activity tracker
PartOf=graphical-session.target
After=graphical-session.target plasma-workspace.target

[Service]
Type=simple
WorkingDirectory=$APP_DIR
ExecStart=$APP_DIR/timetracker -track -tray -submission-interval 5m
Restart=on-failure
RestartSec=15
KillSignal=SIGTERM
TimeoutStopSec=30

[Install]
WantedBy=graphical-session.target
EOF
systemctl --user daemon-reload
systemctl --user enable --now "$UNIT" >/dev/null 2>&1

echo "==> Testing connection to the server"
CODE=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "X-Tracker-Token: $TOKEN" -d '{"sessions":[]}' "$SERVER/api/ingest.php" || true)
if [ "$CODE" = 200 ]; then
    echo "    server accepted the token"
else
    echo "    WARNING: server answered $CODE (expected 200). Check the server address and token."
fi

sleep 2
if systemctl --user is-active --quiet "$UNIT"; then
    echo "==> Done. Time Tracker is running as $(hostname) and starts at login."
    echo "    Logs: journalctl --user -u $UNIT -f"
else
    echo "==> Installed, but the tracker is not running. See: journalctl --user -u $UNIT" >&2
    exit 1
fi
