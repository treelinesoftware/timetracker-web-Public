<?php
// Weekly email report (Monday–Sunday). Run from cron/systemd as root:
//   php cli/weekly-report.php                 send last week's report
//   php cli/weekly-report.php --current       send this week so far
//   php cli/weekly-report.php --week=2026-10-05   send the week containing that date
//   php cli/weekly-report.php --dry-run --out=/tmp/report.html   build without sending
// Mail settings come from the dashboard's Settings (settings table); anything not set there falls back to
// /etc/timetracker/mail.ini and /etc/timetracker/smtp-password from older installs.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/summary.php';
require_once __DIR__ . '/../includes/smtp.php';

const MAIL_INI = '/etc/timetracker/mail.ini';
const MAIL_PASSWORD_FILE = '/etc/timetracker/smtp-password';
const CAT_LABELS = ['productive' => 'Productive', 'neutral' => 'Neutral', 'distracting' => 'Distracting', 'none' => 'Uncategorized'];
const CAT_COLORS = ['productive' => '#2ea043', 'neutral' => '#7b8aa8', 'distracting' => '#d9393b', 'none' => '#c4cad6'];

$opts = getopt('', ['dry-run', 'current', 'week:', 'to:', 'out:']);
$ini = is_readable(MAIL_INI) ? parse_ini_file(MAIL_INI) : [];
$settings = get_settings();
$config = [
    'smtp_host' => $settings['smtp_host'],
    'smtp_port' => $settings['smtp_port'],
    'username' => $settings['smtp_username'] ?: ($ini['username'] ?? ''),
    'from_name' => $settings['from_name'],
    'to' => $settings['mail_to'] ?: ($ini['to'] ?? ''),
    'dashboard_url' => $settings['dashboard_url'] ?: ($ini['dashboard_url'] ?? ''),
    'timezone' => $ini['timezone'] ?? '',
];
if ($settings['smtp_username'] === '' && isset($ini['smtp_host'])) {
    $config['smtp_host'] = $ini['smtp_host'];
    $config['smtp_port'] = $ini['smtp_port'] ?? 587;
}
$password = $settings['smtp_password']
    ?: (is_readable(MAIL_PASSWORD_FILE) ? trim(file_get_contents(MAIL_PASSWORD_FILE)) : '');

// The scheduled run (no options) respects "Send weekly email" in Settings; manual runs always send
if ($settings['report_enabled'] !== '1' && !array_intersect_key($opts, ['dry-run' => 1, 'current' => 1, 'week' => 1, 'to' => 1])) {
    echo "Weekly email is turned off in Settings.\n";
    exit(0);
}
// Weeks run Monday–Sunday in the configured (or system) timezone; PHP's own default is often UTC
$tz = $config['timezone'] ?? '';
if ($tz === '' && ($link = @readlink('/etc/localtime')) && preg_match('#zoneinfo/(.+)$#', $link, $m)) {
    $tz = $m[1];
}
if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
    date_default_timezone_set($tz);
}

// ---- Week ----
$anchor = new DateTimeImmutable($opts['week'] ?? (isset($opts['current']) ? 'today' : 'today -7 days'));
$weekStart = $anchor->modify('monday this week')->setTime(0, 0);
$weekEnd = $weekStart->modify('+7 days');
$prevStart = $weekStart->modify('-7 days');
$today = (new DateTimeImmutable('today'))->format('Y-m-d');

$week = build_summary($weekStart->getTimestamp(), $weekEnd->getTimestamp());
$prev = build_summary($prevStart->getTimestamp(), $weekStart->getTimestamp());
// The same week counting only time within the work schedule from Settings
$schedule = work_schedule($settings);
$work = build_summary($weekStart->getTimestamp(), $weekEnd->getTimestamp(), '', $schedule);
$prevWork = build_summary($prevStart->getTimestamp(), $weekStart->getTimestamp(), '', $schedule);

// ---- Helpers ----
$appNames = json_decode((string)@file_get_contents(__DIR__ . '/../assets/app-names.json'), true) ?: [];
function app_name(string $id): string {
    global $appNames;
    $key = strtolower($id);
    if (isset($appNames[$key])) {
        return $appNames[$key];
    }
    $last = str_contains($id, '.') ? substr($id, strrpos($id, '.') + 1) : $id;
    return ucwords(preg_replace('/[-_]+/', ' ', $last));
}

function fmt_duration(float $sec): string {
    $sec = (int)round($sec);
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    if ($h > 0) {
        return sprintf('%dh %02dm', $h, $m);
    }
    return $m > 0 ? "{$m}m" : "{$sec}s";
}

function score(array $cats): ?int {
    $rated = $cats['productive'] + $cats['neutral'] + $cats['distracting'];
    return $rated > 0 ? (int)round(($cats['productive'] * 100 + $cats['neutral'] * 50) / $rated) : null;
}

function score_color(?int $score): string {
    if ($score === null) {
        return '#7b8aa8';
    }
    return $score >= 65 ? '#2ea043' : ($score >= 40 ? '#c27c0e' : '#d9393b');
}

function change(float $now, float $before, bool $points = false): string {
    if ($before <= 0) {
        return '';
    }
    $diff = $points ? $now - $before : ($now - $before) / $before * 100;
    $sign = $diff >= 0 ? '+' : '−';
    return $sign . abs((int)round($diff)) . ($points ? ' pts' : '%') . ' vs last week';
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bar(array $segments, int $height = 10): string {
    // $segments: [[percent, color, title], ...]; tables render reliably in email clients
    $cells = '';
    $used = 0;
    foreach ($segments as [$pct, $color, $title]) {
        if ($pct <= 0) {
            continue;
        }
        $pct = max(0.5, round($pct, 1));
        $used += $pct;
        $cells .= "<td title=\"" . h($title) . "\" style=\"width:{$pct}%;background:$color;height:{$height}px;font-size:0;line-height:0\">&nbsp;</td>";
    }
    if ($used < 100) {
        $cells .= '<td style="background:#eef0f4;height:' . $height . 'px;font-size:0;line-height:0">&nbsp;</td>';
    }
    return "<table role=\"presentation\" width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"border-collapse:collapse;border-radius:4px;overflow:hidden\"><tr>$cells</tr></table>";
}

// ---- Figures ----
$days = [];
for ($d = $weekStart; $d < $weekEnd; $d = $d->modify('+1 day')) {
    $key = $d->format('Y-m-d');
    $days[$key] = $week['days'][$key] ?? ['total' => 0, 'cats' => empty_cats(), 'apps' => []];
}
$activeDays = count(array_filter($days, fn($d) => $d['total'] > 0));
$weekScore = score($week['cats']);
$prevScore = score($prev['cats']);
$workScore = score($work['cats']);
$prevWorkScore = score($prevWork['cats']);
$scheduleLabel = work_schedule_label($schedule);
$maxDay = max(1, ...array_values(array_map(fn($d) => $d['total'], $days)));
$range = $weekStart->format('M j') . ' – ' . $weekEnd->modify('-1 day')->format('M j, Y');
$dashboard = rtrim($config['dashboard_url'] ?? '', '/') . '/';

// Per computer
$pdo = db();
$stmt = $pdo->prepare('SELECT DISTINCT host FROM sessions WHERE start_ts < ? AND end_ts > ? ORDER BY host');
$stmt->execute([$weekEnd->getTimestamp(), $weekStart->getTimestamp()]);
$hosts = $stmt->fetchAll(PDO::FETCH_COLUMN);
$perHost = [];
if (count($hosts) > 1) {
    foreach ($hosts as $host) {
        $perHost[$host] = build_summary($weekStart->getTimestamp(), $weekEnd->getTimestamp(), $host);
    }
}

// Goals: each scheduled day since the goal was created is met or missed (today still counts as open)
$goals = $pdo->query("SELECT id, target_type, target, comparison, minutes, days, DATE_FORMAT(created_at, '%Y-%m-%d') AS created FROM goals ORDER BY id")->fetchAll();
$goalRows = [];
foreach ($goals as $g) {
    $marks = [];
    $met = 0;
    $counted = 0;
    foreach (array_keys($days) as $key) {
        $dow = (int)(new DateTimeImmutable($key))->format('w');
        if (!(($g['days'] >> $dow) & 1) || $key < $g['created'] || $key >= $today) {
            $marks[] = ['·', '#c4cad6', $key];
            continue;
        }
        $value = $week['goal_days'][$g['id']][$key] ?? 0;
        $limit = $g['minutes'] * 60;
        $ok = $g['comparison'] === 'at_least' ? $value >= $limit : $value <= $limit;
        $counted++;
        $met += $ok ? 1 : 0;
        $marks[] = [$ok ? '✓' : '✗', $ok ? '#2ea043' : '#d9393b', $key];
    }
    $amount = $g['minutes'] % 60 === 0 ? ($g['minutes'] / 60) . 'h' : fmt_duration($g['minutes'] * 60);
    $what = match ($g['target_type']) {
        'category' => strtolower(CAT_LABELS[$g['target']]) . ' time',
        'app' => 'in ' . app_name($g['target']),
        default => 'on “' . $g['target'] . '”',
    };
    $label = ($g['comparison'] === 'at_least' ? 'At least ' : 'At most ') . "$amount $what";
    $goalRows[] = compact('label', 'marks', 'met', 'counted');
}

// ---- HTML ----
$card = fn(string $label, string $value, string $sub = '', string $color = '#1d2433') => "
  <td style=\"padding:6px\" valign=\"top\" width=\"25%\">
    <div style=\"border:1px solid #e3e7ee;border-radius:8px;padding:12px\">
      <div style=\"color:#6b7488;font-size:12px\">" . h($label) . "</div>
      <div style=\"font-size:20px;font-weight:700;color:$color;margin-top:4px\">" . h($value) . "</div>
      <div style=\"color:#6b7488;font-size:11px;margin-top:2px\">" . h($sub) . "</div>
    </div>
  </td>";

$topApp = $week['apps'][0]['id'] ?? null;
$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Your week in review</title></head><body style="margin:0;background:#f4f6fa;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1d2433">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
    . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:12px;padding:24px">'
    . '<tr><td><div style="font-size:22px;font-weight:700">⏱️ Your week in review</div>'
    . '<div style="color:#6b7488;margin-top:2px">' . h($range) . '</div></td></tr>';

if ($week['total'] <= 0) {
    $html .= '<tr><td style="padding:24px 0;color:#6b7488">No activity was recorded this week.</td></tr>';
} else {
    $html .= '<tr><td style="padding-top:16px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . $card('Tracked time', fmt_duration($week['total']), change($week['total'], $prev['total']))
        . $card('Productivity score', $weekScore === null ? '–' : (string)$weekScore, $weekScore !== null && $prevScore !== null ? change($weekScore, $prevScore, true) : '', score_color($weekScore))
        . $card('Daily average', $activeDays ? fmt_duration($week['total'] / $activeDays) : '–', "$activeDays active " . ($activeDays === 1 ? 'day' : 'days'))
        . $card('Top app', $topApp ? app_name($topApp) : '–', $topApp ? fmt_duration($week['apps'][0]['total']) : '')
        . '</tr></table></td></tr>';

    // Work hours
    $html .= '<tr><td style="padding:10px 6px 0;font-size:13px"><b>Work hours</b> <span style="color:#6b7488">(' . h($scheduleLabel) . ')</span>: '
        . fmt_duration($work['total']) . ' tracked'
        . ' · score <b style="color:' . score_color($workScore) . '">' . ($workScore ?? '–') . '</b>'
        . ($workScore !== null && $prevWorkScore !== null ? ' <span style="color:#6b7488">(' . change($workScore, $prevWorkScore, true) . ')</span>' : '')
        . ' · ' . fmt_duration($week['total'] - $work['total']) . ' outside work hours</td></tr>';

    // Categories
    $segments = [];
    $legend = '';
    foreach (CAT_LABELS as $c => $label) {
        $pct = $week['cats'][$c] / $week['total'] * 100;
        $segments[] = [$pct, CAT_COLORS[$c], $label];
        $legend .= '<span style="display:inline-block;margin:6px 14px 0 0;font-size:13px"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:' . CAT_COLORS[$c] . '"></span> '
            . h($label) . ' <b>' . fmt_duration($week['cats'][$c]) . '</b> <span style="color:#6b7488">' . round($pct) . '%</span></span>';
    }
    $html .= '<tr><td style="padding-top:20px"><div style="font-weight:700;margin-bottom:8px">Productivity</div>' . bar($segments, 14) . $legend . '</td></tr>';

    // Days
    $rows = '';
    foreach ($days as $key => $d) {
        $dt = new DateTimeImmutable($key);
        $segments = [];
        foreach (CAT_LABELS as $c => $label) {
            $segments[] = [$d['cats'][$c] / $maxDay * 100, CAT_COLORS[$c], $label];
        }
        $s = score($d['cats']);
        $rows .= '<tr><td style="padding:4px 8px 4px 0;font-size:13px;white-space:nowrap" width="70">' . $dt->format('D j') . '</td>'
            . '<td style="padding:4px 0">' . bar($segments) . '</td>'
            . '<td style="padding:4px 0 4px 8px;font-size:13px;text-align:right;white-space:nowrap" width="70">' . ($d['total'] ? fmt_duration($d['total']) : '–') . '</td>'
            . '<td style="padding:4px 0 4px 8px;font-size:13px;text-align:right;color:' . score_color($s) . '" width="32">' . ($s ?? '') . '</td></tr>';
    }
    $html .= '<tr><td style="padding-top:20px"><div style="font-weight:700;margin-bottom:4px">Day by day</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table></td></tr>';

    // Apps
    $rows = '';
    foreach (array_slice($week['apps'], 0, 10) as $a) {
        $main = array_keys($a['cats'], max($a['cats']))[0];
        $rows .= '<tr><td style="padding:5px 0;font-size:13px"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . CAT_COLORS[$main] . '" title="' . h(CAT_LABELS[$main]) . '"></span> '
            . h(app_name($a['id'])) . '</td>'
            . '<td style="padding:5px 0;font-size:13px;text-align:right;white-space:nowrap">' . fmt_duration($a['total']) . '</td>'
            . '<td style="padding:5px 0 5px 10px;font-size:13px;text-align:right;color:#6b7488" width="40">' . round($a['total'] / $week['total'] * 100) . '%</td></tr>';
    }
    $html .= '<tr><td style="padding-top:20px"><div style="font-weight:700;margin-bottom:4px">Top apps</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">' . $rows . '</table></td></tr>';

    // Websites
    if (!empty($week['sites'])) {
        $rows = '';
        foreach (array_slice($week['sites'], 0, 10) as $a) {
            $main = array_keys($a['cats'], max($a['cats']))[0];
            $rows .= '<tr><td style="padding:5px 0;font-size:13px"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . CAT_COLORS[$main] . '" title="' . h(CAT_LABELS[$main]) . '"></span> '
                . h($a['id']) . '</td>'
                . '<td style="padding:5px 0;font-size:13px;text-align:right;white-space:nowrap">' . fmt_duration($a['total']) . '</td>'
                . '<td style="padding:5px 0 5px 10px;font-size:13px;text-align:right;color:#6b7488" width="40">' . round($a['total'] / $week['total'] * 100) . '%</td></tr>';
        }
        $html .= '<tr><td style="padding-top:20px"><div style="font-weight:700;margin-bottom:4px">Top websites</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">' . $rows . '</table></td></tr>';
    }

    // Goals
    if ($goalRows) {
        $rows = '';
        foreach ($goalRows as $g) {
            $marks = implode('', array_map(fn($m) => '<span title="' . h($m[2]) . '" style="display:inline-block;width:16px;text-align:center;color:' . $m[1] . ';font-weight:700">' . $m[0] . '</span>', $g['marks']));
            $summary = $g['counted'] ? "{$g['met']} of {$g['counted']} days" : 'no scheduled days';
            $rows .= '<tr><td style="padding:5px 0;font-size:13px">' . h($g['label']) . '</td>'
                . '<td style="padding:5px 0;white-space:nowrap">' . $marks . '</td>'
                . '<td style="padding:5px 0 5px 10px;font-size:13px;text-align:right;color:#6b7488;white-space:nowrap">' . $summary . '</td></tr>';
        }
        $html .= '<tr><td style="padding-top:20px"><div style="font-weight:700;margin-bottom:4px">Goals <span style="font-weight:400;color:#6b7488;font-size:12px">(Mon → Sun)</span></div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table></td></tr>';
    }

    // Computers
    if ($perHost) {
        $rows = '';
        foreach ($perHost as $host => $s) {
            $hs = score($s['cats']);
            $rows .= '<tr><td style="padding:5px 0;font-size:13px">' . h($host) . '</td>'
                . '<td style="padding:5px 0;font-size:13px;text-align:right">' . fmt_duration($s['total']) . '</td>'
                . '<td style="padding:5px 0 5px 10px;font-size:13px;text-align:right;color:' . score_color($hs) . '">' . ($hs === null ? '–' : "score $hs") . '</td>'
                . '<td style="padding:5px 0 5px 10px;font-size:13px;text-align:right;color:#6b7488">' . h(isset($s['apps'][0]) ? app_name($s['apps'][0]['id']) : '') . '</td></tr>';
        }
        $html .= '<tr><td style="padding-top:20px"><div style="font-weight:700;margin-bottom:4px">By computer</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table></td></tr>';
    }
}

if ($dashboard !== '/') {
    $html .= '<tr><td style="padding-top:24px"><a href="' . h($dashboard) . '" style="display:inline-block;background:#5b8cff;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600">Open dashboard</a></td></tr>';
}
$html .= '</table><div style="color:#9aa2b3;font-size:11px;margin-top:12px">Sent by Time Tracker every Monday.</div></td></tr></table></body></html>';

// ---- Plain text ----
$text = "Your week in review: $range\n\n";
if ($week['total'] <= 0) {
    $text .= "No activity was recorded this week.\n";
} else {
    $text .= 'Tracked time: ' . fmt_duration($week['total']) . ' ' . change($week['total'], $prev['total']) . "\n";
    $text .= 'Productivity score: ' . ($weekScore ?? '–') . "\n";
    $text .= "Work hours ($scheduleLabel): " . fmt_duration($work['total']) . ' tracked, score ' . ($workScore ?? '–')
        . ', ' . fmt_duration($week['total'] - $work['total']) . " outside work hours\n";
    $text .= 'Daily average: ' . ($activeDays ? fmt_duration($week['total'] / $activeDays) : '–') . " ($activeDays active " . ($activeDays === 1 ? 'day' : 'days') . ")\n\n";
    foreach (CAT_LABELS as $c => $label) {
        $text .= sprintf("%-14s %s\n", $label . ':', fmt_duration($week['cats'][$c]));
    }
    $text .= "\nDay by day:\n";
    foreach ($days as $key => $d) {
        $text .= sprintf("  %-7s %s\n", (new DateTimeImmutable($key))->format('D j'), $d['total'] ? fmt_duration($d['total']) : '-');
    }
    $text .= "\nTop apps:\n";
    foreach (array_slice($week['apps'], 0, 10) as $a) {
        $text .= sprintf("  %-28s %s\n", app_name($a['id']), fmt_duration($a['total']));
    }
    if (!empty($week['sites'])) {
        $text .= "\nTop websites:\n";
        foreach (array_slice($week['sites'], 0, 10) as $a) {
            $text .= sprintf("  %-28s %s\n", $a['id'], fmt_duration($a['total']));
        }
    }
    if ($goalRows) {
        $text .= "\nGoals:\n";
        foreach ($goalRows as $g) {
            $text .= '  ' . $g['label'] . ': ' . ($g['counted'] ? "{$g['met']} of {$g['counted']} days" : 'no scheduled days') . "\n";
        }
    }
}
if ($dashboard !== '/') {
    $text .= "\nDashboard: $dashboard\n";
}

$subject = $week['total'] > 0
    ? "Your week: " . fmt_duration($week['total']) . ($weekScore !== null ? ", score $weekScore" : '') . " ($range)"
    : "Your week: no activity ($range)";

// ---- Output ----
if (isset($opts['dry-run'])) {
    if (isset($opts['out'])) {
        file_put_contents($opts['out'], $html);
        file_put_contents(preg_replace('/\.html?$/', '', $opts['out']) . '.txt', $text);
    }
    echo "Subject: $subject\n\n$text";
    exit(0);
}

foreach (['smtp_host', 'smtp_port', 'username', 'to'] as $key) {
    if (empty($config[$key])) {
        fwrite(STDERR, "Email isn't set up ('$key' is missing). Open the dashboard's Settings → Email.\n");
        exit(1);
    }
}
if ($password === '') {
    fwrite(STDERR, "No mail password saved. Open the dashboard's Settings → Email.\n");
    exit(1);
}

$to = array_values(array_filter(array_map('trim', explode(',', $opts['to'] ?? $config['to']))));
try {
    (new SmtpMailer($config['smtp_host'], (int)$config['smtp_port'], $config['username'], $password))
        ->send($config['username'], $config['from_name'] ?? 'Time Tracker', $to, $subject, $text, $html);
} catch (Throwable $e) {
    fwrite(STDERR, 'Sending failed: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "Sent \"$subject\" to " . implode(', ', $to) . "\n";
