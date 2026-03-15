<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/db.php';
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

// Get last turn (must be a scored turn, not timeout)
$stmt = db()->prepare("SELECT t.*, p.chips_remaining as current_chips
    FROM turns t
    JOIN players p ON p.id = t.player_id
    WHERE t.tournament_id = ?
    ORDER BY t.id DESC
    LIMIT 1");
$stmt->execute([$tournamentId]);
$turn = $stmt->fetch();

if (!$turn || $turn['result_type'] !== 'scored') {
    header('Location: ../control.php');
    exit;
}

$pdo = db();
$pdo->beginTransaction();
try {
    $playerId = (int) $turn['player_id'];
    $chipDelta = (int) $turn['chip_delta'];
    $payoutDelta = (int) $turn['payout_delta'];
    $cycleNumber = (int) $turn['cycle_number'];

    // Restore chips
    $stmt = $pdo->prepare('UPDATE players SET chips_remaining = chips_remaining - ? WHERE id = ?');
    $stmt->execute([$chipDelta, $playerId]);

    // Restore pot if payout was applied
    if ($payoutDelta !== 0) {
        $stmt = $pdo->prepare('UPDATE tournaments SET current_pot = current_pot - ? WHERE id = ?');
        $stmt->execute([$payoutDelta, $tournamentId]);
    }

    // Un-eliminate if they had been eliminated (chips went 0 -> 1 from undo)
    $stmt = $pdo->prepare('UPDATE players SET is_eliminated = 0, eliminated_at = NULL WHERE id = ? AND chips_remaining > 0');
    $stmt->execute([$playerId]);

    // Delete the turn
    $stmt = $pdo->prepare('DELETE FROM turns WHERE id = ?');
    $stmt->execute([(int) $turn['id']]);

    // Restore current player to this player, in shooting phase (break pressed)
    $stmt = $pdo->prepare('UPDATE tournaments SET current_player_id = ?, current_cycle_number = ?, current_turn_started_at = ?, current_turn_expires_at = ?, break_started_at = ? WHERE id = ?');
    $stmt->execute([$playerId, $cycleNumber, now_utc(), null, now_utc(), $tournamentId]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
}

header('Location: ../control.php');
exit;
