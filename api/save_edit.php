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
$players = players_with_computed($tournamentId);

$startingPot = isset($_POST['starting_pot']) ? max(0, (int) $_POST['starting_pot']) : (int) $tournament['starting_pot'];
$startingFirstFive = isset($_POST['starting_first_five_round_pot']) ? max(0, (int) $_POST['starting_first_five_round_pot']) : (int) ($tournament['starting_first_five_round_pot'] ?? $tournament['first_five_round_pot'] ?? 0);
$currentCycle = isset($_POST['current_cycle_number']) ? max(1, min(15, (int) $_POST['current_cycle_number'])) : (int) ($tournament['current_cycle_number'] ?? 1);

$pdo = db();
$pdo->beginTransaction();
try {
    // Update pot origins and current round (current player and up next are managed by control/play flow)
    $stmt = $pdo->prepare('UPDATE tournaments SET starting_pot = ?, starting_first_five_round_pot = ?, current_cycle_number = ?, break_started_at = NULL, current_turn_started_at = NULL, current_turn_expires_at = NULL WHERE id = ?');
    $stmt->execute([$startingPot, $startingFirstFive, $currentCycle, $tournamentId]);

    // Update First 5 $ and Main $ only (chips and in/out are computed from scores)
    foreach ($players as $player) {
        $pid = (int) $player['id'];
        $firstFiveAmount = isset($_POST['first_five_amount_' . $pid]) ? (int) $_POST['first_five_amount_' . $pid] : (int) ($player['first_five_amount'] ?? 0);
        $firstFiveAmount = max(0, $firstFiveAmount);
        $mainPotAmount = isset($_POST['main_pot_amount_' . $pid]) ? (int) $_POST['main_pot_amount_' . $pid] : (int) ($player['main_pot_amount'] ?? 0);
        $mainPotAmount = max(0, $mainPotAmount);

        $stmt = $pdo->prepare('UPDATE players SET first_five_amount = ?, main_pot_amount = ? WHERE id = ?');
        $stmt->execute([$firstFiveAmount, $mainPotAmount, $pid]);
    }

    // Fetch existing turns: (player_id, cycle_number) => turn row
    $stmt = $pdo->prepare("SELECT id, player_id, cycle_number FROM turns WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    $existingTurns = [];
    while ($row = $stmt->fetch()) {
        $key = (int) $row['player_id'] . '_' . (int) $row['cycle_number'];
        $existingTurns[$key] = $row;
    }

    $stmt = $pdo->prepare("SELECT COALESCE(MAX(turn_number), 0) FROM turns WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    $maxTurnNum = (int) $stmt->fetchColumn();
    $nextTurnNum = (int) $maxTurnNum + 1;

    foreach ($players as $player) {
        $pid = (int) $player['id'];
        for ($r = 1; $r <= 15; $r++) {
            $key = $pid . '_' . $r;
            $raw = trim((string) ($_POST['score_' . $pid . '_' . $r] ?? ''));
            $keyDb = $pid . '_' . $r;
            $existing = $existingTurns[$keyDb] ?? null;

            if ($raw === '') {
                if ($existing) {
                    $stmt = $pdo->prepare('DELETE FROM turns WHERE id = ?');
                    $stmt->execute([(int) $existing['id']]);
                }
                continue;
            }

            $score = null;
            $resultType = 'scored';
            if (strtoupper($raw) === 'TO') {
                $resultType = 'timeout';
                $chipDelta = -1;
            } else {
                $score = (int) $raw;
                if ($score < 1 || $score > 5) {
                    continue;
                }
                $chipDelta = $score >= 5 ? -1 : 0;
            }

            if ($existing) {
                $stmt = $pdo->prepare('UPDATE turns SET score = ?, result_type = ?, chip_delta = ? WHERE id = ?');
                $stmt->execute([$score, $resultType, $chipDelta, (int) $existing['id']]);
            } else {
                $turnNum = $nextTurnNum++;
                $stmt = $pdo->prepare('INSERT INTO turns (tournament_id, player_id, cycle_number, turn_number, score, result_type, chip_delta, payout_delta, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)');
                $stmt->execute([$tournamentId, $pid, $r, $turnNum, $score, $resultType, $chipDelta, 'edited', now_utc()]);
            }
        }
    }

    // Persist computed chips and is_eliminated to players table
    $initialChips = (int) ($tournament['chips_per_player'] ?? 5);
    foreach ($players as $player) {
        $pid = (int) $player['id'];
        $chips = computed_chips($tournamentId, $pid, $initialChips);
        $isEliminated = $chips <= 0 ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE players SET chips_remaining = ?, is_eliminated = ? WHERE id = ?');
        $stmt->execute([$chips, $isEliminated, $pid]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
}

header('Location: ../edit.php');
exit;
