<?php
// Categorization rules: GET lists them, POST creates/updates one, DELETE removes one (?id=).
require_once __DIR__ . '/../includes/db.php';

const MATCH_TYPES = ['app', 'title', 'site'];
const CATEGORIES = ['productive', 'neutral', 'distracting'];

function list_rules(): array {
    $rows = db()->query('SELECT id, match_type, pattern, category FROM rules ORDER BY match_type, pattern')->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
    }
    return $rows;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Apps seen in any tracked session, so the dashboard can hide rules for apps never used
    $used = db()->query('SELECT DISTINCT app FROM sessions ORDER BY app')->fetchAll(PDO::FETCH_COLUMN);
    $sites = db()->query("SELECT DISTINCT domain FROM sessions WHERE domain != '' ORDER BY domain LIMIT 2000")
        ->fetchAll(PDO::FETCH_COLUMN);
    json_out(200, ['rules' => list_rules(), 'used_apps' => $used, 'used_sites' => $sites]);
}

// Changes must come from the dashboard's own scripts: a custom header can't be set by a
// cross-site form, and adding one to a cross-site fetch requires a CORS preflight we never allow.
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'timetracker') {
    json_out(403, ['error' => 'missing X-Requested-With header']);
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $type = $data['match_type'] ?? '';
    $pattern = trim((string)($data['pattern'] ?? ''));
    $category = $data['category'] ?? '';
    if ($type === 'site') {
        // Accept a pasted URL or "www.example.com" and keep just the domain
        $pattern = strtolower(preg_replace('#^[a-z]+://|[/?\#].*$#i', '', $pattern));
        $pattern = preg_replace('/^www\./', '', $pattern);
    }
    if (!in_array($type, MATCH_TYPES, true) || $pattern === '' || mb_strlen($pattern) > 255) {
        json_out(400, ['error' => 'match_type must be app, title or site, pattern 1-255 characters']);
    }
    if ($category === 'none') {
        // Remove the rule so the item becomes uncategorized
        db()->prepare('DELETE FROM rules WHERE match_type = ? AND pattern = ?')->execute([$type, $pattern]);
    } elseif (in_array($category, CATEGORIES, true)) {
        db()->prepare(
            'INSERT INTO rules (match_type, pattern, category) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE category = VALUES(category)'
        )->execute([$type, $pattern, $category]);
    } else {
        json_out(400, ['error' => 'category must be productive, neutral, distracting or none']);
    }
    json_out(200, ['rules' => list_rules()]);
}

if ($method === 'DELETE') {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$id) {
        json_out(400, ['error' => 'id required']);
    }
    db()->prepare('DELETE FROM rules WHERE id = ?')->execute([$id]);
    json_out(200, ['rules' => list_rules()]);
}

json_out(405, ['error' => 'method not allowed']);
