<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/rules.php';

$tournament = active_tournament();
if (!$tournament || empty($tournament['current_player_id'])) {
    redirect_to('../control.php');
}

// Only allow break when we're still in countdown phase (break not yet pressed)
if (!empty($tournament['break_started_at'])) {
    redirect_to('../control.php');
}

$stmt = db()->prepare('UPDATE tournaments SET break_started_at = ? WHERE id = ? AND break_started_at IS NULL');
$stmt->execute([now_utc(), (int) $tournament['id']]);

redirect_to('../control.php');
