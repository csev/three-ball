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
shuffle($active);
start_turn($tournamentId, (int) $active[0]['id']);
header('Location: ../control.php');
exit;
