<?php
// Receives activity sessions from the desktop tracker's webhook.
// Not behind basic auth; protected by the X-Tracker-Token shared secret instead.
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(405, ['error' => 'POST only']);
}

$token = $_SERVER['HTTP_X_TRACKER_TOKEN'] ?? '';
if (INGEST_TOKEN === '' || INGEST_TOKEN === 'change-me' || !hash_equals(INGEST_TOKEN, $token)) {
    json_out(401, ['error' => 'invalid token']);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    json_out(400, ['error' => 'invalid JSON']);
}

$host = preg_replace('/[^A-Za-z0-9._-]/', '', $_SERVER['HTTP_X_TRACKER_HOST'] ?? '');
$host = substr($host, 0, 100) ?: 'default';

// Go sends RFC 3339 timestamps with nanoseconds; drop the fraction before parsing
function parse_ts($value): ?int {
    if (!is_string($value) || $value === '') {
        return null;
    }
    $ts = strtotime(preg_replace('/\.\d+/', '', $value));
    return $ts === false ? null : $ts;
}

$stmt = db()->prepare(
    'INSERT IGNORE INTO sessions (host, app, title, domain, start_ts, end_ts, duration_sec) VALUES (?, ?, ?, ?, ?, ?, ?)'
);

$inserted = 0;
$skipped = 0;
$sessions = is_array($data['sessions'] ?? null) ? $data['sessions'] : [];
foreach ($sessions as $s) {
    if (!is_array($s) || !empty($s['ignored'])) {
        $skipped++;
        continue;
    }
    $app = trim((string)($s['app_class'] ?? ''));
    $start = parse_ts($s['start_time'] ?? null);
    $end = parse_ts($s['end_time'] ?? null);
    if ($app === '' || $start === null || $end === null || $end < $start) {
        $skipped++;
        continue;
    }
    // duration is a Go time.Duration (nanoseconds); never more than the wall-clock span
    $duration = isset($s['duration']) ? (int)round($s['duration'] / 1e9) : $end - $start;
    $duration = min($duration, $end - $start + 1);
    if ($duration <= 0) {
        $skipped++;
        continue;
    }
    $title = mb_substr((string)($s['window_title'] ?? ''), 0, 1024);
    // Website shown in a browser window, looked up by the tracker in the browser's history
    $domain = strtolower(trim((string)($s['domain'] ?? '')));
    $domain = preg_match('/^[a-z0-9.-]{1,255}$/', $domain) ? $domain : '';

    $stmt->execute([$host, mb_substr($app, 0, 255), $title, $domain, $start, $end, $duration]);
    $inserted += $stmt->rowCount();
}

json_out(200, [
    'ok' => true,
    'inserted' => $inserted,
    'duplicates' => count($sessions) - $inserted - $skipped,
    'skipped' => $skipped,
]);
