<?php
// Dashboard settings (settings table): weekly email, theme and work schedule.
require_once __DIR__ . '/db.php';

const SETTING_DEFAULTS = [
    'theme' => 'system',              // system, light or dark
    'work_days' => '62',              // bitmask, bit 0 = Sunday ... bit 6 = Saturday (62 = Mon–Fri)
    'work_start' => '09:00',
    'work_end' => '17:00',
    'report_enabled' => '1',
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => '587',
    'smtp_username' => '',
    'smtp_password' => '',
    'from_name' => 'Time Tracker',
    'mail_to' => '',
    'dashboard_url' => '',
];

function get_settings(): array {
    $settings = SETTING_DEFAULTS;
    foreach (db()->query('SELECT name, value FROM settings') as $r) {
        if (array_key_exists($r['name'], $settings)) {
            $settings[$r['name']] = $r['value'];
        }
    }
    return $settings;
}

function save_settings(array $values): void {
    $stmt = db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    foreach ($values as $name => $value) {
        if (array_key_exists($name, SETTING_DEFAULTS)) {
            $stmt->execute([$name, (string)$value]);
        }
    }
}

// Work days and hours as ['days' => bitmask, 'start' => [h, m], 'end' => [h, m]]
function work_schedule(?array $settings = null): array {
    $s = $settings ?? get_settings();
    $time = fn(string $v) => array_map('intval', explode(':', $v) + [0, 0]);
    return ['days' => (int)$s['work_days'], 'start' => $time($s['work_start']), 'end' => $time($s['work_end'])];
}

// Seconds of [start, end) that fall within work hours on work days, in PHP's current default timezone
function work_seconds(int $start, int $end, array $schedule): int {
    $total = 0;
    $day = (new DateTimeImmutable("@$start"))->setTimezone(new DateTimeZone(date_default_timezone_get()))->setTime(0, 0);
    while ($day->getTimestamp() < $end) {
        if (($schedule['days'] >> (int)$day->format('w')) & 1) {
            $from = $day->setTime(...$schedule['start'])->getTimestamp();
            $to = $day->setTime(...$schedule['end'])->getTimestamp();
            $total += max(0, min($end, $to) - max($start, $from));
        }
        $day = $day->modify('+1 day');
    }
    return $total;
}

// "Mon–Fri 9:00–17:00"
function work_schedule_label(array $schedule): string {
    $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $days = [];
    foreach ([1, 2, 3, 4, 5, 6, 0] as $d) {
        if (($schedule['days'] >> $d) & 1) {
            $days[] = $names[$d];
        }
    }
    $daysLabel = match (implode(',', $days)) {
        'Mon,Tue,Wed,Thu,Fri' => 'Mon–Fri',
        'Mon,Tue,Wed,Thu,Fri,Sat,Sun' => 'Every day',
        default => implode(', ', $days),
    };
    $fmt = fn(array $t) => sprintf('%d:%02d', $t[0], $t[1]);
    return $daysLabel . ' ' . $fmt($schedule['start']) . '–' . $fmt($schedule['end']);
}
