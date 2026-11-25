<?php
// Daily goals: GET lists them, POST creates one, DELETE removes one (?id=).
require_once __DIR__ . '/../includes/db.php';

const TARGET_TYPES = ['category', 'app', 'title'];
const GOAL_CATEGORIES = ['productive', 'neutral', 'distracting'];
const COMPARISONS = ['at_least', 'at_most'];

function list_goals(): array {
    // created is the local date the goal was added; days before it don't count as met or missed
    $rows = db()->query("SELECT id, target_type, target, comparison, minutes, days, DATE_FORMAT(created_at, '%Y-%m-%d') AS created FROM goals ORDER BY id")->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['minutes'] = (int)$r['minutes'];
        $r['days'] = (int)$r['days'];
    }
    return $rows;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_out(200, ['goals' => list_goals()]);
}

// Same cross-site protection as rules.php
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'timetracker') {
    json_out(403, ['error' => 'missing X-Requested-With header']);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $type = $data['target_type'] ?? '';
    $target = trim((string)($data['target'] ?? ''));
    $comparison = $data['comparison'] ?? '';
    $minutes = filter_var($data['minutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
    $days = filter_var($data['days'] ?? 127, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 127]]);

    if (!in_array($type, TARGET_TYPES, true) || $target === '' || mb_strlen($target) > 255
        || ($type === 'category' && !in_array($target, GOAL_CATEGORIES, true))) {
        json_out(400, ['error' => 'invalid goal target']);
    }
    if (!in_array($comparison, COMPARISONS, true) || $minutes === false || $days === false) {
        json_out(400, ['error' => 'comparison must be at_least/at_most, minutes 1-1440, days 1-127']);
    }

    db()->prepare('INSERT INTO goals (target_type, target, comparison, minutes, days) VALUES (?, ?, ?, ?, ?)')
        ->execute([$type, $target, $comparison, $minutes, $days]);
    json_out(200, ['goals' => list_goals()]);
}

if ($method === 'DELETE') {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        json_out(400, ['error' => 'id required']);
    }
    db()->prepare('DELETE FROM goals WHERE id = ?')->execute([$id]);
    json_out(200, ['goals' => list_goals()]);
}

json_out(405, ['error' => 'method not allowed']);
