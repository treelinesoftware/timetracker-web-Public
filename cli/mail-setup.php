<?php
// Command-line setup for the weekly email (the dashboard's Settings → Email does the same). Run as root:
//   php /var/www/html/timetracker/cli/mail-setup.php
//   php /var/www/html/timetracker/cli/mail-setup.php --import-ini   (used by deploy/install.sh)
// Settings are saved in the database (settings table). --import-ini copies settings from the older
// /etc/timetracker/mail.ini and smtp-password files once, if none are saved in the database yet.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "Run this as root.\n");
    exit(1);
}
require_once __DIR__ . '/../includes/settings.php';

const INI = '/etc/timetracker/mail.ini';
const PASSWORD_FILE = '/etc/timetracker/smtp-password';

$current = get_settings();

if (in_array('--import-ini', $argv, true)) {
    if ($current['smtp_username'] !== '' || !is_readable(INI)) {
        exit(0);
    }
    $ini = parse_ini_file(INI);
    save_settings([
        'smtp_host' => $ini['smtp_host'] ?? 'smtp.gmail.com',
        'smtp_port' => $ini['smtp_port'] ?? 587,
        'smtp_username' => $ini['username'] ?? '',
        'smtp_password' => is_readable(PASSWORD_FILE) ? trim(file_get_contents(PASSWORD_FILE)) : '',
        'from_name' => $ini['from_name'] ?? 'Time Tracker',
        'mail_to' => $ini['to'] ?? '',
        'dashboard_url' => $ini['dashboard_url'] ?? '',
    ]);
    echo "    imported email settings from " . INI . " into the dashboard settings\n";
    exit(0);
}


function ask(string $question, string $default = ''): string {
    echo $question . ($default !== '' ? " [$default]" : '') . ': ';
    $answer = trim((string)fgets(STDIN));
    return $answer === '' ? $default : $answer;
}

function ask_hidden(string $question): string {
    echo "$question: ";
    shell_exec('stty -echo');
    $answer = trim((string)fgets(STDIN));
    shell_exec('stty echo');
    echo "\n";
    return $answer;
}

echo "Time Tracker weekly email setup (sent through Gmail)\n";
echo "You need a Google App Password: https://myaccount.google.com/apppasswords\n";
echo "(requires 2-Step Verification on the Google account)\n\n";

$username = ask('Gmail address to send from', $current['smtp_username']);
if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "That doesn't look like an email address.\n");
    exit(1);
}
$to = ask('Send reports to (comma-separated)', $current['mail_to'] ?: $username);
foreach (array_map('trim', explode(',', $to)) as $addr) {
    if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "Invalid recipient: $addr\n");
        exit(1);
    }
}

$ip = trim((string)shell_exec("hostname -I 2>/dev/null | awk '{print \$1}'"));
$dashboard = ask('Dashboard address', $current['dashboard_url'] ?: ($ip ? "http://$ip/timetracker/" : ''));

$hasPassword = $current['smtp_password'] !== '';
$password = ask_hidden('Gmail App Password' . ($hasPassword ? ' (leave empty to keep the current one)' : ''));
// App Passwords are shown in groups of four; spaces are not part of them
$password = str_replace(' ', '', $password);
if ($password === '' && !$hasPassword) {
    fwrite(STDERR, "A password is required.\n");
    exit(1);
}

$values = [
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_username' => $username,
    'mail_to' => $to,
    'dashboard_url' => $dashboard,
];
if ($password !== '') {
    $values['smtp_password'] = $password;
}
save_settings($values);
echo "\nSaved. You can change these later in the dashboard's Settings.\n";

if (strtolower(ask('Send a test report for this week so far now? (y/n)', 'y')) === 'y') {
    passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/weekly-report.php') . ' --current', $code);
    exit($code);
}
