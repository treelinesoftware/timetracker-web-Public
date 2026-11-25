-- Starter rules for Windows app IDs (lower-case executable names). INSERT IGNORE keeps existing rules.
INSERT IGNORE INTO rules (match_type, pattern, category) VALUES
  ('app', 'chrome', 'neutral'),
  ('app', 'msedge', 'neutral'),
  ('app', 'explorer', 'neutral'),
  ('app', 'windowsterminal', 'productive'),
  ('app', 'winword', 'productive'),
  ('app', 'excel', 'productive'),
  ('app', 'powerpnt', 'productive'),
  ('app', 'outlook', 'neutral'),
  ('app', 'olk', 'neutral'),
  ('app', 'ms-teams', 'neutral'),
  ('app', 'notepad', 'neutral'),
  ('app', 'claude', 'productive');
