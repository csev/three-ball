<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/rules.php';
$tournament = active_tournament();
if (!$tournament || empty($tournament['current_player_id'])) {
    redirect_to('../setup.php');
}
$score = isset($_POST['score']) ? (int) $_POST['score'] : null;
if ($score === null) {
    redirect_to('../control.php');
}
// Must have pressed Break before entering score (no scoring during countdown phase)
if (empty($tournament['break_started_at'])) {
    redirect_to('../control.php');
}
apply_turn_result((int) $tournament['id'], (int) $tournament['current_player_id'], $score, 'scored');
redirect_to('../control.php');
