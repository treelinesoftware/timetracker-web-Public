# Time Tracker

Self-hosted dashboard for desktop activity sent by the
[rescuetime-linux-mutter](https://github.com/Christopher-Hayes/rescuetime-linux-mutter) tracker
(`kde-plasma-support` branch) through its webhook option.

PHP + MariaDB on an existing Rocky Linux LAMP server, deployed alongside other apps without touching them.

## How it works

- `api/ingest.php` receives the tracker's webhook POSTs. It is exempt from the login but requires the
  `X-Tracker-Token` header to match `INGEST_TOKEN`. Each session is stored once
  (unique on host + start time + app), so re-sends are harmless. Sessions from ignored apps are dropped.
- `api/sessions.php` returns sessions for a time range; `index.html` + `assets/app.js` aggregate them
  in the browser into totals, a day timeline, per-app window titles and a 7-day chart.
- Websites: for browser windows (Chrome, Edge, Brave, Vivaldi, Chromium, Opera, Firefox) the tracker looks the
  page title up in the browser's own history files and sends the site's domain with the session (only the domain,
  never the full URL). The dashboard's Websites panel totals time per site. Pages not in the history (private
  windows, history turned off) have no domain and only show under the browser app.
- Categories: `api/rules.php` stores rules that mark time productive, neutral or distracting. App rules match
  an app ID exactly; website rules match a domain and its subdomains (`google.com` covers `mail.google.com`);
  window-title rules match text anywhere in the title (case-insensitive). Title rules win over website rules,
  which win over app rules. The dashboard shows a 0–100 productivity score (productive 100, neutral 50, distracting 0) and lets you
  edit rules in place. Starter rules (`seed-rules.sql`) are loaded on install only while the table is empty.
- Daily goals: `api/goals.php` stores "at least / at most N minutes" goals for a category, an app, or window
  titles containing some text, applying every day, weekdays or weekends. The dashboard shows progress for the
  selected day and which of the last 7 days each goal was met.
- Apache basic auth (`/etc/httpd/timetracker.htpasswd`) protects everything else.

- Views: Day and 7 days load raw sessions (`api/sessions.php`) and aggregate in the browser; Month and Year use
  `api/summary.php`, which streams the period's sessions, applies the category rules server-side and returns
  per-day, per-app and per-goal totals (days bucketed in the viewer's timezone). Goals only count days from the
  date they were created.
- CSV export (sessions, per-app totals or daily totals for the period on screen) is generated in the browser;
  session exports for long periods are fetched in month-sized chunks.

## Weekly email report

`cli/weekly-report.php` emails a Monday–Sunday summary (totals and score vs the previous week, day-by-day
bars, top apps, goals, per-computer lines) through Gmail SMTP with a built-in client (`includes/smtp.php`,
STARTTLS + App Password; no mail server needed). A systemd timer (`deploy/timetracker-report.timer`) runs it
every Monday at 08:00 server time. Configure it once on the server:

```bash
php /var/www/html/timetracker/cli/mail-setup.php   # prompts for the Gmail address, recipients, App Password
php /var/www/html/timetracker/cli/weekly-report.php --dry-run --current   # preview this week as text
```

Settings live in `/etc/timetracker/mail.ini` and `/etc/timetracker/smtp-password` (root-only, outside the web
root). `cli/` is denied by Apache.

## Installing the tracker on other computers

The dashboard's "Install on another computer" panel shows one-line commands (with the upload token) for:

- **Windows 10/11** — `downloads/install.ps1`: installs to `%LOCALAPPDATA%\TimeTracker`, starts at sign-in via a
  Startup-folder shortcut, logs to `tracker.log`. No admin rights needed.
- **Linux (KDE Plasma)** — `downloads/install.sh`: installs to `~/.local/share/timetracker` with a
  `timetracker.service` systemd user unit; installs `swayidle` if missing.

`/timetracker/downloads/` is served without a login (it holds no secrets). The tracker programs in it are built
from the tracker repo and are not committed here:

```bash
# in the tracker repo
./scripts/build-release.sh
cp dist/timetracker-* dist/SHA256SUMS /path/to/timetracker-web/downloads/
# then redeploy (below); re-running an install command on a PC updates it
```

## Install / update

Copy this folder to the server and run as root:

```bash
INGEST_TOKEN=<secret> bash deploy/install.sh
```

`INGEST_TOKEN` is only needed on the first install; afterwards `config.php` (with a generated
database password) is kept. Add a dashboard login with:

```bash
htpasswd /etc/httpd/timetracker.htpasswd <username>
```

On the desktop, the tracker's `.env` needs:

```
WEBHOOK_URL=http://<server>/timetracker/api/ingest.php
WEBHOOK_TOKEN=<secret>
```
