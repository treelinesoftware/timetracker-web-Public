<?php
// Returns what the dashboard needs to show install commands for other computers.
// Behind the dashboard login, so only signed-in users can see the upload token.
require_once __DIR__ . '/../includes/db.php';

json_out(200, ['token' => INGEST_TOKEN]);
