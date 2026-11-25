<?php
// Returns sessions overlapping [from, to) (unix seconds) for the dashboard.
require_once __DIR__ . '/../includes/db.php';

$from = filter_input(INPUT_GET, 'from', FILTER_VALIDATE_INT);
$to = filter_input(INPUT_GET, 'to', FILTER_VALIDATE_INT);
if (!$from || !$to || $to <= $from || $to - $from > 40 * 86400) {
    json_out(400, ['error' => 'from/to must be unix seconds, at most 40 days apart']);
}
$host = $_GET['host'] ?? '';

$sql = 'SELECT host, app, title, domain, start_ts, end_ts, duration_sec FROM sessions
        WHERE start_ts < ? AND end_ts > ?';
$params = [$to, $from];
if ($host !== '') {
    $sql .= ' AND host = ?';
    $params[] = $host;
}
$sql .= ' ORDER BY start_ts LIMIT 50000';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();
foreach ($rows as &$r) {
    $r['start_ts'] = (int)$r['start_ts'];
    $r['end_ts'] = (int)$r['end_ts'];
    $r['duration_sec'] = (int)$r['duration_sec'];
}

$hosts = db()->query('SELECT DISTINCT host FROM sessions ORDER BY host')->fetchAll(PDO::FETCH_COLUMN);
$last = db()->query('SELECT UNIX_TIMESTAMP(MAX(received_at)) FROM sessions')->fetchColumn();

json_out(200, ['sessions' => $rows, 'hosts' => $hosts, 'last_received' => $last ? (int)$last : null]);
