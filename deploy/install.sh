#!/bin/bash
# Time Tracker install/deploy script for Rocky Linux (existing LAMP stack).
# Only touches its own files: /var/www/html/timetracker, the "timetracker" database/user,
# /etc/httpd/conf.d/timetracker.conf and /etc/httpd/timetracker.htpasswd.
#
# Run as root from the uploaded source directory:
#   INGEST_TOKEN=<secret> bash deploy/install.sh
# INGEST_TOKEN is only needed on first install (it is kept in config.php afterwards).
set -euo pipefail

SRC_DIR="$(cd "$(dirname "$0")/.." && pwd)"
APP_DIR="/var/www/html/timetracker"
CONF_FILE="/etc/httpd/conf.d/timetracker.conf"
HTPASSWD="/etc/httpd/timetracker.htpasswd"
DB_NAME="timetracker"
DB_USER="timetracker"

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this as root." >&2
    exit 1
fi

echo "==> [1/5] Copying application files to $APP_DIR"
mkdir -p "$APP_DIR"
for item in index.html assets api includes cli db.sql downloads; do
    cp -r "$SRC_DIR/$item" "$APP_DIR/"
done

echo "==> [2/5] Database and config"
if [ ! -f "$APP_DIR/config.php" ]; then
    if [ -z "${INGEST_TOKEN:-}" ]; then
        echo "ERROR: INGEST_TOKEN must be set on first install." >&2
        exit 1
    fi
    DB_PASS="$(openssl rand -hex 24)"
    mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    cat > "$APP_DIR/config.php" <<PHP
<?php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', '$DB_NAME');
define('DB_USER', '$DB_USER');
define('DB_PASS', '$DB_PASS');
define('INGEST_TOKEN', '$INGEST_TOKEN');
PHP
    echo "    created database, user and config.php"
else
    echo "    config.php exists, keeping current credentials"
fi
mysql -u root "$DB_NAME" < "$SRC_DIR/db.sql"
if [ "$(mysql -u root -N "$DB_NAME" -e 'SELECT COUNT(*) FROM rules')" = 0 ]; then
    mysql -u root "$DB_NAME" < "$SRC_DIR/seed-rules.sql"
    echo "    loaded starter categorization rules"
fi
mysql -u root "$DB_NAME" < "$SRC_DIR/seed-rules-windows.sql"
# Email settings used to live in /etc/timetracker; move them into the dashboard settings once
php "$APP_DIR/cli/mail-setup.php" --import-ini

echo "==> [3/5] Permissions and SELinux labels"
chown -R root:apache "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} +
find "$APP_DIR" -type f -exec chmod 644 {} +
chmod 640 "$APP_DIR/config.php"
restorecon -R "$APP_DIR"

echo "==> [4/5] Apache config"
cp "$SRC_DIR/deploy/timetracker.conf" "$CONF_FILE"
if [ ! -f "$HTPASSWD" ]; then
    touch "$HTPASSWD"
    echo "    created empty $HTPASSWD (add a user with: htpasswd $HTPASSWD <username>)"
fi
chown root:apache "$HTPASSWD"
chmod 640 "$HTPASSWD"
restorecon "$CONF_FILE" "$HTPASSWD"
apachectl configtest
systemctl reload httpd

echo "==> Weekly email timer"
cp "$SRC_DIR/deploy/timetracker-report.service" "$SRC_DIR/deploy/timetracker-report.timer" /etc/systemd/system/
restorecon /etc/systemd/system/timetracker-report.*
systemctl daemon-reload
systemctl enable --now timetracker-report.timer >/dev/null 2>&1
if [ -z "$(mysql -u root -N "$DB_NAME" -e "SELECT value FROM settings WHERE name = 'smtp_password'")" ]; then
    echo "    email not configured yet; set it up in the dashboard under Settings (gear icon) → Email"
fi

echo "==> [5/5] Self-test"
TOKEN="$(php -r 'include "'"$APP_DIR"'/config.php"; echo INGEST_TOKEN;')"
dash=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/timetracker/)
cfg=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/timetracker/config.php)
cli=$(curl -s -o /dev/null -w "%{http_code}" http://localhost/timetracker/cli/weekly-report.php)
bad=$(curl -s -o /dev/null -w "%{http_code}" -X POST -d '{}' http://localhost/timetracker/api/ingest.php)
good=$(curl -s -o /dev/null -w "%{http_code}" -X POST -H "X-Tracker-Token: $TOKEN" -d '{"sessions":[]}' http://localhost/timetracker/api/ingest.php)
echo "    dashboard without login: $dash (expect 401)"
echo "    config.php:              $cfg (expect 401/403)"
echo "    cli scripts:             $cli (expect 401/403)"
echo "    ingest without token:    $bad (expect 401)"
echo "    ingest with token:       $good (expect 200)"
[ "$dash" = 401 ] && [ "$bad" = 401 ] && [ "$good" = 200 ] && echo "==> Done" || { echo "Self-test failed" >&2; exit 1; }
