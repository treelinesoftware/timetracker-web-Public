<?php
// Aggregates sessions for a time range: totals per day, per app and per website (with top window
// titles) and per category, plus each goal's value per day. Categories use the same rules as the
// dashboard: the longest matching title rule wins, then the most specific site rule, then the app rule. Used by api/summary.php and the weekly report.
require_once __DIR__ . '/settings.php';

const TITLES_PER_APP = 25;

function empty_cats(): array {
    return ['productive' => 0, 'neutral' => 0, 'distracting' => 0, 'none' => 0];
}

// Days are bucketed in PHP's current default timezone (set it before calling). With a work schedule
// (see work_schedule()), only time within work hours on work days is counted.
function build_summary(int $from, int $to, string $host = '', ?array $work = null): array {
    $pdo = db();

    // ---- Rules and goals ----
    $titleRules = [];
    $siteRules = [];
    $appRules = [];
    foreach ($pdo->query('SELECT match_type, pattern, category FROM rules') as $r) {
        if ($r['match_type'] === 'title') {
            $titleRules[] = [mb_strtolower($r['pattern']), $r['category']];
        } elseif ($r['match_type'] === 'site') {
            $siteRules[mb_strtolower($r['pattern'])] = $r['category'];
        } else {
            $appRules[mb_strtolower($r['pattern'])] = $r['category'];
        }
    }
    // Longest pattern first, so the first match is the most specific
    usort($titleRules, fn($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));

    $goals = $pdo->query('SELECT id, target_type, target FROM goals')->fetchAll();
    foreach ($goals as &$g) {
        $g['target_lc'] = mb_strtolower($g['target']);
    }
    unset($g);

    $categoryCache = [];
    $categorize = function (string $app, string $title, string $domain) use ($titleRules, $siteRules, $appRules, &$categoryCache): string {
        $key = $app . "\0" . $title . "\0" . $domain;
        if (isset($categoryCache[$key])) {
            return $categoryCache[$key];
        }
        $t = mb_strtolower($title);
        $category = null;
        foreach ($titleRules as [$pattern, $cat]) {
            if ($pattern !== '' && str_contains($t, $pattern)) {
                $category = $cat;
                break;
            }
        }
        // "google.com" also covers "mail.google.com"; the most specific domain wins
        for ($parts = $domain === '' ? [] : explode('.', $domain); $category === null && $parts; array_shift($parts)) {
            $category = $siteRules[implode('.', $parts)] ?? null;
        }
        $category ??= $appRules[mb_strtolower($app)] ?? 'none';
        if (count($categoryCache) < 50000) {
            $categoryCache[$key] = $category;
        }
        return $category;
    };

    // ---- Aggregate sessions (streamed, so a year of data doesn't sit in memory) ----
    $sql = 'SELECT app, title, domain, start_ts, end_ts, duration_sec FROM sessions WHERE start_ts < ? AND end_ts > ?';
    $params = [$to, $from];
    if ($host !== '') {
        $sql .= ' AND host = ?';
        $params[] = $host;
    }
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $total = 0;
    $cats = empty_cats();
    $days = [];
    $apps = [];
    $sites = [];
    $goalDays = [];

    while ($s = $stmt->fetch()) {
        $start = (int)$s['start_ts'];
        $end = (int)$s['end_ts'];
        $span = $end - $start;
        if ($span <= 0) {
            continue;
        }
        // Count only the part inside the range (sessions are short; a day is assigned by start time)
        $overlap = $work
            ? work_seconds(max($start, $from), min($end, $to), $work)
            : min($end, $to) - max($start, $from);
        if ($overlap <= 0) {
            continue;
        }
        $sec = (int)$s['duration_sec'] * min(1, $overlap / $span);
        $app = $s['app'];
        $title = $s['title'] === '' ? '(no title)' : $s['title'];
        $cat = $categorize($app, $s['title'], $s['domain']);
        $day = date('Y-m-d', max($start, $from));

        $total += $sec;
        $cats[$cat] += $sec;

        $days[$day] ??= ['total' => 0, 'cats' => empty_cats(), 'apps' => []];
        $days[$day]['total'] += $sec;
        $days[$day]['cats'][$cat] += $sec;
        $days[$day]['apps'][$app] = ($days[$day]['apps'][$app] ?? 0) + $sec;

        $apps[$app] ??= ['total' => 0, 'cats' => empty_cats(), 'titles' => []];
        $apps[$app]['total'] += $sec;
        $apps[$app]['cats'][$cat] += $sec;
        if (!isset($apps[$app]['titles'][$title])) {
            $apps[$app]['titles'][$title] = [0, $cat];
        }
        $apps[$app]['titles'][$title][0] += $sec;

        if ($s['domain'] !== '') {
            $site = $s['domain'];
            $sites[$site] ??= ['total' => 0, 'cats' => empty_cats(), 'titles' => []];
            $sites[$site]['total'] += $sec;
            $sites[$site]['cats'][$cat] += $sec;
            if (!isset($sites[$site]['titles'][$title])) {
                $sites[$site]['titles'][$title] = [0, $cat];
            }
            $sites[$site]['titles'][$title][0] += $sec;
        }

        foreach ($goals as $g) {
            $match = match ($g['target_type']) {
                'category' => $cat === $g['target'],
                'app' => mb_strtolower($app) === $g['target_lc'],
                default => $g['target_lc'] !== '' && str_contains(mb_strtolower($s['title']), $g['target_lc']),
            };
            if ($match) {
                $goalDays[$g['id']][$day] = ($goalDays[$g['id']][$day] ?? 0) + $sec;
            }
        }
    }
    $stmt->closeCursor();
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);

    // ---- Shape the response ----
    $round = fn($v) => (int)round($v);
    $roundCats = fn($c) => array_map($round, $c);

    // Apps and sites: busiest first, each with its top window titles
    $toList = function (array $items) use ($round, $roundCats): array {
        uasort($items, fn($a, $b) => $b['total'] <=> $a['total']);
        $list = [];
        foreach ($items as $id => $a) {
            uasort($a['titles'], fn($x, $y) => $y[0] <=> $x[0]);
            $titles = [];
            foreach (array_slice($a['titles'], 0, TITLES_PER_APP, true) as $t => [$sec, $cat]) {
                $titles[] = [(string)$t, $round($sec), $cat];
            }
            $list[] = ['id' => (string)$id, 'total' => $round($a['total']), 'cats' => $roundCats($a['cats']), 'titles' => $titles];
        }
        return $list;
    };

    ksort($days);
    foreach ($days as &$d) {
        $d['total'] = $round($d['total']);
        $d['cats'] = $roundCats($d['cats']);
        $d['apps'] = array_map($round, $d['apps']);
    }
    unset($d);

    foreach ($goalDays as &$gd) {
        $gd = array_map($round, $gd);
    }
    unset($gd);

    return [
        'total' => $round($total),
        'cats' => $roundCats($cats),
        'days' => $days,
        'apps' => $toList($apps),
        'sites' => $toList($sites),
        'goal_days' => $goalDays,
    ];
}
