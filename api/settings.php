<?php
// Dashboard settings: GET returns them (never the SMTP password), POST saves changes,
// POST ?action=test sends a test email with the saved mail settings.
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/smtp.php';

const THEMES = ['system', 'light', 'dark'];

function public_settings(): array {
    $s = get_settings();
    $s['has_password'] = $s['smtp_password'] !== '';
    unset($s['smtp_password']);
    $s['work_days'] = (int)$s['work_days'];
    $s['smtp_port'] = (int)$s['smtp_port'];
    $s['report_enabled'] = $s['report_enabled'] === '1';
    return $s;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_out(200, ['settings' => public_settings()]);
}

// Same cross-site protection as rules.php
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'timetracker') {
    json_out(403, ['error' => 'missing X-Requested-With header']);
}
if ($method !== 'POST') {
    json_out(405, ['error' => 'method not allowed']);
}

if (($_GET['action'] ?? '') === 'test') {
    $s = get_settings();
    $to = array_values(array_filter(array_map('trim', explode(',', $s['mail_to']))));
    if ($s['smtp_username'] === '' || $s['smtp_password'] === '' || !$to) {
        json_out(400, ['error' => 'Save the sender address, password and recipients first.']);
    }
    $text = "This is a test from Time Tracker. Weekly reports will be sent to: " . implode(', ', $to) . "\n";
    $html = '<p style="font-family:sans-serif">This is a test from <b>Time Tracker</b>. Weekly reports will be sent to: '
        . htmlspecialchars(implode(', ', $to)) . '</p>';
    try {
        (new SmtpMailer($s['smtp_host'], (int)$s['smtp_port'], $s['smtp_username'], $s['smtp_password']))
            ->send($s['smtp_username'], $s['from_name'], $to, 'Time Tracker test email', $text, $html);
    } catch (Throwable $e) {
        json_out(502, ['error' => 'Sending failed: ' . $e->getMessage()]);
    }
    json_out(200, ['ok' => true, 'sent_to' => $to]);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    json_out(400, ['error' => 'invalid JSON']);
}

$save = [];
$fail = fn(string $msg) => json_out(400, ['error' => $msg]);

if (array_key_exists('theme', $data)) {
    in_array($data['theme'], THEMES, true) || $fail('theme must be system, light or dark');
    $save['theme'] = $data['theme'];
}
if (array_key_exists('work_days', $data)) {
    $days = filter_var($data['work_days'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 127]]);
    $days !== false || $fail('Pick at least one work day.');
    $save['work_days'] = $days;
}
foreach (['work_start', 'work_end'] as $key) {
    if (array_key_exists($key, $data)) {
        preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$data[$key]) || $fail('Work hours must be times like 09:00.');
        $save[$key] = $data[$key];
    }
}
$start = $save['work_start'] ?? get_settings()['work_start'];
$end = $save['work_end'] ?? get_settings()['work_end'];
$end > $start || $fail('The workday must end after it starts.');

if (array_key_exists('report_enabled', $data)) {
    $save['report_enabled'] = $data['report_enabled'] ? '1' : '0';
}
if (array_key_exists('smtp_host', $data)) {
    $host = trim((string)$data['smtp_host']);
    preg_match('/^[A-Za-z0-9.-]{1,255}$/', $host) || $fail('Enter a mail server name like smtp.gmail.com.');
    $save['smtp_host'] = $host;
}
if (array_key_exists('smtp_port', $data)) {
    $port = filter_var($data['smtp_port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $port !== false || $fail('Port must be a number like 587.');
    $save['smtp_port'] = $port;
}
if (array_key_exists('smtp_username', $data)) {
    $user = trim((string)$data['smtp_username']);
    ($user === '' || filter_var($user, FILTER_VALIDATE_EMAIL)) || $fail('The sender must be an email address.');
    $save['smtp_username'] = $user;
}
// Only replaced when a new one is typed; App Passwords are shown in groups of four with spaces
if (($data['smtp_password'] ?? '') !== '') {
    $save['smtp_password'] = str_replace(' ', '', (string)$data['smtp_password']);
}
if (array_key_exists('from_name', $data)) {
    $name = trim(preg_replace('/[\r\n"]+/', ' ', (string)$data['from_name']));
    $save['from_name'] = mb_substr($name === '' ? 'Time Tracker' : $name, 0, 100);
}
if (array_key_exists('mail_to', $data)) {
    $to = array_values(array_filter(array_map('trim', explode(',', (string)$data['mail_to']))));
    foreach ($to as $addr) {
        filter_var($addr, FILTER_VALIDATE_EMAIL) || $fail("Not a valid email address: $addr");
    }
    $save['mail_to'] = implode(', ', $to);
}
if (array_key_exists('dashboard_url', $data)) {
    $url = trim((string)$data['dashboard_url']);
    ($url === '' || preg_match('#^https?://#i', $url)) || $fail('The dashboard address must start with http:// or https://');
    $save['dashboard_url'] = $url;
}

save_settings($save);
json_out(200, ['settings' => public_settings()]);
