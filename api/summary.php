<?php
// Aggregated data for long periods (month/year views); see includes/summary.php.
require_once __DIR__ . '/../includes/summary.php';

const MAX_RANGE_DAYS = 400;

$from = filter_input(INPUT_GET, 'from', FILTER_VALIDATE_INT);
$to = filter_input(INPUT_GET, 'to', FILTER_VALIDATE_INT);
if (!$from || !$to || $to <= $from || $to - $from > MAX_RANGE_DAYS * 86400) {
    json_out(400, ['error' => 'from/to must be unix seconds, at most ' . MAX_RANGE_DAYS . ' days apart']);
}

// Days are bucketed in the viewer's timezone
$tz = $_GET['tz'] ?? 'UTC';
if (!in_array($tz, timezone_identifiers_list(), true)) {
    $tz = 'UTC';
}
date_default_timezone_set($tz);

// work=1 counts only time within the work schedule set in the dashboard's settings
$summary = build_summary($from, $to, $_GET['host'] ?? '', ($_GET['work'] ?? '') === '1' ? work_schedule() : null);

$pdo = db();
$hosts = $pdo->query('SELECT DISTINCT host FROM sessions ORDER BY host')->fetchAll(PDO::FETCH_COLUMN);
$last = $pdo->query('SELECT UNIX_TIMESTAMP(MAX(received_at)) FROM sessions')->fetchColumn();

json_out(200, [
    'total' => $summary['total'],
    'cats' => $summary['cats'],
    'days' => (object)$summary['days'],
    'apps' => $summary['apps'],
    'sites' => $summary['sites'],
    'goal_days' => (object)$summary['goal_days'],
    'hosts' => $hosts,
    'last_received' => $last ? (int)$last : null,
]);
