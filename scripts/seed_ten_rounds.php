#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

/**
 * Loads demo data: one tournament, four players, full turns for rounds 1–10.
 * Paused at round 11 (Start Next Round on control).
 *
 * Usage: php scripts/seed_ten_rounds.php
 * Replaces all existing tournament data (SQLite CASCADE delete).
 */

$base = dirname(__DIR__);
require_once $base . '/lib/db.php';
require_once $base . '/lib/helpers.php';

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->exec('DELETE FROM tournaments');

    $now = now_utc();
    $stmt = $pdo->prepare(
        'INSERT INTO tournaments (name, venue_name, starting_pot, current_pot, first_five_round_pot, starting_first_five_round_pot, timer_seconds, chips_per_player, status, current_player_id, up_next_player_id, current_turn_started_at, current_turn_expires_at, break_started_at, current_cycle_number, paused, round_complete, hide_out_players, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, ?, ?, ?, 0, ?)'
    );
    $stmt->execute([
        'Demo — 10 Rounds',
        'Seed',
        500,
        500,
        100,
        100,
        60,
        5,
        'running',
        11,
        1,
        1,
        $now,
    ]);
    $tournamentId = (int) $pdo->lastInsertId();

    $names = ['Alex', 'Blake', 'Casey', 'Drew'];
    $playerIds = [];
    $stmt = $pdo->prepare(
        'INSERT INTO players (tournament_id, display_name, queue_position, chips_remaining, is_eliminated, first_five_amount, main_pot_amount, created_at) VALUES (?, ?, ?, 5, 0, 0, 0, ?)'
    );
    foreach ($names as $i => $name) {
        $stmt->execute([$tournamentId, $name, $i + 1, $now]);
        $playerIds[] = (int) $pdo->lastInsertId();
    }

    // Per-round shooting order: rotate so each round looks different
    $orderBase = [0, 1, 2, 3];
    $scoresGrid = [
        [2, 3, 4, 2],
        [3, 2, 4, 3],
        [4, 4, 2, 3],
        [2, 2, 3, 4],
        [3, 4, 3, 2],
        [2, 3, 2, 4],
        [4, 3, 4, 2],
        [3, 2, 2, 3],
        [2, 4, 3, 4],
        [3, 3, 4, 2],
    ];

    $turnNum = 0;
    $stmt = $pdo->prepare(
        'INSERT INTO turns (tournament_id, player_id, cycle_number, turn_number, score, result_type, chip_delta, payout_delta, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
    );

    for ($cycle = 1; $cycle <= 10; $cycle++) {
        $rotate = ($cycle - 1) % 4;
        $order = [];
        for ($i = 0; $i < 4; $i++) {
            $order[] = $orderBase[($i + $rotate) % 4];
        }
        $rowScores = $scoresGrid[$cycle - 1];
        foreach ($order as $slot => $playerIdx) {
            $turnNum++;
            $score = $rowScores[$slot];
            $chipDelta = $score > 4 ? -1 : 0;
            $stmt->execute([
                $tournamentId,
                $playerIds[$playerIdx],
                $cycle,
                $turnNum,
                $score,
                'scored',
                $chipDelta,
                '',
                $now,
            ]);
        }
    }

    // Round 10 finished → app advances to cycle 11, paused, first shooter + up next
    $currentId = $playerIds[0];
    $upNextId = $playerIds[1];
    $stmt = $pdo->prepare(
        'UPDATE tournaments SET current_player_id = ?, up_next_player_id = ? WHERE id = ?'
    );
    $stmt->execute([$currentId, $upNextId, $tournamentId]);

    // Sync stored chips with turns (same as save_edit)
    $initialChips = 5;
    require_once $base . '/lib/rules.php';
    foreach ($playerIds as $pid) {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(chip_delta), 0) FROM turns WHERE tournament_id = ? AND player_id = ?');
        $stmt->execute([$tournamentId, $pid]);
        $delta = (int) $stmt->fetchColumn();
        $chips = max(0, $initialChips + $delta);
        $stmt = $pdo->prepare('UPDATE players SET chips_remaining = ?, is_eliminated = ? WHERE id = ?');
        $stmt->execute([$chips, $chips <= 0 ? 1 : 0, $pid]);
    }

    $pdo->commit();
    echo "Seeded tournament id {$tournamentId} with 10 rounds (40 turns), 4 players. Paused at round 11 — use Control → Start Next Round.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}
