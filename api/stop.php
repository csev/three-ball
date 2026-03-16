<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

if (!auth_valid()) {
    redirect_to('../control.php');
    exit;
}

$tournament = active_tournament();
if ($tournament) {
    $stmt = db()->prepare('UPDATE tournaments SET status = ?, current_player_id = NULL, up_next_player_id = NULL, current_turn_started_at = NULL, current_turn_expires_at = NULL, break_started_at = NULL WHERE id = ?');
    $stmt->execute(['finished', (int) $tournament['id']]);
}

redirect_to('../setup.php');
exit;
