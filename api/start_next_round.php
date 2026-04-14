<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/rules.php';

if (!auth_valid()) {
    header('Location: ../control.php');
    exit;
}

$tournament = active_tournament();
if (!$tournament) {
    header('Location: ../control.php');
    exit;
}

$tournamentId = (int) $tournament['id'];
$active = active_players($tournamentId);
if (empty($active)) {
    header('Location: ../control.php');
    exit;
}

set_round_complete(false);
set_tournament_paused(false);
$nextId = !empty($tournament['current_player_id']) ? (int) $tournament['current_player_id'] : null;
if ($nextId === null) {
    $nextId = next_player_for_current_round($tournamentId, (int) ($tournament['current_cycle_number'] ?? 1));
}
if ($nextId !== null) {
    start_turn($tournamentId, $nextId);
}
header('Location: ../control.php');
exit;
