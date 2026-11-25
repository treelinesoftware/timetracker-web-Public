-- Time Tracker schema. The database and its user are created by deploy/install.sh.
CREATE TABLE IF NOT EXISTS sessions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  host VARCHAR(100) NOT NULL,
  app VARCHAR(255) NOT NULL,
  title VARCHAR(1024) NOT NULL DEFAULT '',
  domain VARCHAR(255) NOT NULL DEFAULT '',
  start_ts BIGINT UNSIGNED NOT NULL,
  end_ts BIGINT UNSIGNED NOT NULL,
  duration_sec INT UNSIGNED NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_session (host, start_ts, app),
  INDEX idx_start (start_ts),
  INDEX idx_end (end_ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categorization rules. Title rules (case-insensitive "contains") override site rules (the website's
-- domain or a parent domain), which override app rules (exact app id).
CREATE TABLE IF NOT EXISTS rules (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  match_type ENUM('app', 'title', 'site') NOT NULL,
  pattern VARCHAR(255) NOT NULL,
  category ENUM('productive', 'neutral', 'distracting') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_rule (match_type, pattern)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Daily goals. days is a bitmask of applicable weekdays: bit 0 = Sunday ... bit 6 = Saturday.
CREATE TABLE IF NOT EXISTS goals (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  target_type ENUM('category', 'app', 'title') NOT NULL,
  target VARCHAR(255) NOT NULL,
  comparison ENUM('at_least', 'at_most') NOT NULL,
  minutes INT UNSIGNED NOT NULL,
  days TINYINT UNSIGNED NOT NULL DEFAULT 127,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upgrades for databases created before websites were tracked
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS domain VARCHAR(255) NOT NULL DEFAULT '' AFTER title;
ALTER TABLE rules MODIFY match_type ENUM('app', 'title', 'site') NOT NULL;

-- Speeds up the "apps that have been used" lookup in api/rules.php
CREATE INDEX IF NOT EXISTS idx_app ON sessions (app);

-- Dashboard settings (weekly email, theme, work schedule); see includes/settings.php for names and defaults
CREATE TABLE IF NOT EXISTS settings (
  name VARCHAR(64) PRIMARY KEY,
  value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
