<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/rules.php';
$tournament = active_tournament();
// Timeout only applies during countdown (before break); ignore if already shooting
if ($tournament && !empty($tournament['current_player_id']) && empty($tournament['break_started_at'])) {
    apply_turn_result((int) $tournament['id'], (int) $tournament['current_player_id'], null, 'timeout', 'Automatic timeout');
}
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
if (str_contains($accept, 'application/json')) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}
redirect_to('../control.php');
