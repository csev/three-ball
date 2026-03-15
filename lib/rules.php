<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function all_players(int $tournamentId): array
{
    $stmt = db()->prepare('SELECT * FROM players WHERE tournament_id = ? ORDER BY queue_position ASC, id ASC');
    $stmt->execute([$tournamentId]);
    return $stmt->fetchAll();
}

function active_players(int $tournamentId): array
{
    $stmt = db()->prepare('SELECT * FROM players WHERE tournament_id = ? AND is_eliminated = 0 ORDER BY queue_position ASC, id ASC');
    $stmt->execute([$tournamentId]);
    return $stmt->fetchAll();
}

function find_player(int $playerId): ?array
{
    $stmt = db()->prepare('SELECT * FROM players WHERE id = ?');
    $stmt->execute([$playerId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function total_turns(int $tournamentId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM turns WHERE tournament_id = ?');
    $stmt->execute([$tournamentId]);
    return (int) $stmt->fetchColumn();
}

/** Returns [playerId => [cycleNumber => score|'TO']] for rounds 1-15 */
function player_scores_by_round(int $tournamentId): array
{
    $stmt = db()->prepare("SELECT player_id, cycle_number, score, result_type FROM turns WHERE tournament_id = ?");
    $stmt->execute([$tournamentId]);
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $pid = (int) $r['player_id'];
        $cycle = (int) $r['cycle_number'];
        if ($cycle < 1 || $cycle > 15) continue;
        if (!isset($out[$pid])) $out[$pid] = [];
        $out[$pid][$cycle] = $r['result_type'] === 'timeout' ? 'TO' : (string) ($r['score'] ?? '');
    }
    return $out;
}

/** Returns [main_pot => int, first_five_pot => int] computed as origin minus sum of amounts awarded in edit screen */
function computed_pots(int $tournamentId): array
{
    $stmt = db()->prepare('SELECT starting_pot, COALESCE(starting_first_five_round_pot, first_five_round_pot) AS first_five_origin FROM tournaments WHERE id = ?');
    $stmt->execute([$tournamentId]);
    $t = $stmt->fetch();
    if (!$t) {
        return ['main_pot' => 0, 'first_five_pot' => 0];
    }
    $stmt = db()->prepare('SELECT COALESCE(SUM(main_pot_amount), 0) AS main_awarded, COALESCE(SUM(first_five_amount), 0) AS first_five_awarded FROM players WHERE tournament_id = ?');
    $stmt->execute([$tournamentId]);
    $a = $stmt->fetch();
    $mainAwarded = (int) ($a['main_awarded'] ?? 0);
    $firstFiveAwarded = (int) ($a['first_five_awarded'] ?? 0);
    return [
        'main_pot' => max(0, (int) $t['starting_pot'] - $mainAwarded),
        'first_five_pot' => max(0, (int) $t['first_five_origin'] - $firstFiveAwarded),
    ];
}

function recent_turns(int $tournamentId, int $limit = 10): array
{
    $stmt = db()->prepare("SELECT t.*, p.display_name
        FROM turns t
        JOIN players p ON p.id = t.player_id
        WHERE t.tournament_id = ?
        ORDER BY t.id DESC
        LIMIT ?");
    $stmt->bindValue(1, $tournamentId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Returns first active player (queue order) who has NOT played in current round. Null if round complete. */
function next_player_for_current_round(int $tournamentId, int $cycleNumber): ?int
{
    $active = active_players($tournamentId);
    if (empty($active)) {
        return null;
    }

    $stmt = db()->prepare('SELECT player_id FROM turns WHERE tournament_id = ? AND cycle_number = ?');
    $stmt->execute([$tournamentId, $cycleNumber]);
    $playedIds = array_map('intval', array_column($stmt->fetchAll(), 'player_id'));

    foreach ($active as $p) {
        $pid = (int) $p['id'];
        if (!in_array($pid, $playedIds, true)) {
            return $pid;
        }
    }

    return null; // Everyone has played this round
}

/** Returns player id after $currentPlayerId in round order (who shoots after current). Null if current is last this round. */
function player_after_current_round(int $tournamentId, int $cycleNumber, int $currentPlayerId): ?int
{
    $active = active_players($tournamentId);
    if (empty($active)) {
        return null;
    }

    $stmt = db()->prepare('SELECT player_id FROM turns WHERE tournament_id = ? AND cycle_number = ?');
    $stmt->execute([$tournamentId, $cycleNumber]);
    $playedIds = array_map('intval', array_column($stmt->fetchAll(), 'player_id'));

    $found = false;
    foreach ($active as $p) {
        $pid = (int) $p['id'];
        if (in_array($pid, $playedIds, true)) {
            continue;
        }
        if ($found) {
            return $pid; // Next person after current
        }
        if ($pid === $currentPlayerId) {
            $found = true;
        }
    }
    // Current is last to shoot this round; up next is first of next round
    return !empty($active) ? (int) $active[0]['id'] : null;
}

function next_active_player_id(array $tournament): ?int
{
    $tournamentId = (int) $tournament['id'];
    $cycleNumber = (int) ($tournament['current_cycle_number'] ?? 1);

    $next = next_player_for_current_round($tournamentId, $cycleNumber);
    if ($next !== null) {
        return $next;
    }

    $active = active_players($tournamentId);
    if (empty($active)) {
        return null;
    }
    return (int) $active[0]['id']; // Round complete; next round starts with first active
}

function second_active_player_id(array $tournament): ?int
{
    $players = active_players((int) $tournament['id']);
    if (count($players) < 2) {
        return null;
    }

    $nextId = next_active_player_id($tournament);
    foreach ($players as $index => $player) {
        if ((int) $player['id'] === $nextId) {
            return (int) $players[($index + 1) % count($players)]['id'];
        }
    }

    return null;
}

function start_turn(int $tournamentId, int $playerId): void
{
    $tournament = active_tournament();
    if (!$tournament || (int) $tournament['id'] !== $tournamentId) {
        return;
    }

    $timerSeconds = (int) $tournament['timer_seconds'];
    $started = time();
    $expires = $started + $timerSeconds;

    $stmt = db()->prepare('UPDATE tournaments SET current_player_id = ?, current_turn_started_at = ?, current_turn_expires_at = ?, break_started_at = NULL, status = ? WHERE id = ?');
    $stmt->execute([
        $playerId,
        gmdate('c', $started),
        gmdate('c', $expires),
        'running',
        $tournamentId,
    ]);
}

function maybe_eliminate_player(int $playerId): void
{
    $player = find_player($playerId);
    if (!$player) {
        return;
    }

    if ((int) $player['chips_remaining'] <= 0 && (int) $player['is_eliminated'] === 0) {
        $stmt = db()->prepare('UPDATE players SET is_eliminated = 1, eliminated_at = ? WHERE id = ?');
        $stmt->execute([now_utc(), $playerId]);
    }
}

function advance_queue(int $tournamentId): void
{
    $tournament = active_tournament();
    if (!$tournament || (int) $tournament['id'] !== $tournamentId) {
        return;
    }

    $active = active_players($tournamentId);
    if (count($active) <= 1) {
        $stmt = db()->prepare('UPDATE tournaments SET status = ?, current_player_id = NULL, current_turn_started_at = NULL, current_turn_expires_at = NULL WHERE id = ?');
        $stmt->execute(['finished', $tournamentId]);
        return;
    }

    $cycleNumber = (int) $tournament['current_cycle_number'];
    $nextId = next_player_for_current_round($tournamentId, $cycleNumber);

    if ($nextId === null) {
        // Round complete: everyone has played. Increment cycle and start next round with first active.
        $cycleNumber = min(15, $cycleNumber + 1);
        $stmt = db()->prepare('UPDATE tournaments SET current_cycle_number = ? WHERE id = ?');
        $stmt->execute([$cycleNumber, $tournamentId]);
        $nextId = (int) $active[0]['id'];
    }

    start_turn($tournamentId, $nextId);
}

function apply_turn_result(int $tournamentId, int $playerId, ?int $score, string $resultType, string $note = '', int $payoutDelta = 0): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $tournament = active_tournament();
        if (!$tournament || (int) $tournament['id'] !== $tournamentId) {
            throw new RuntimeException('No active tournament');
        }

        $player = find_player($playerId);
        if (!$player) {
            throw new RuntimeException('Player not found');
        }

        $chipDelta = 0;
        if ($resultType === 'timeout') {
            $chipDelta = -1;
        } elseif ($score !== null && $score > 4) {
            $chipDelta = -1;
        }

        $newChips = (int) $player['chips_remaining'] + $chipDelta;
        $stmt = $pdo->prepare('UPDATE players SET chips_remaining = ? WHERE id = ?');
        $stmt->execute([$newChips, $playerId]);

        $turnNumber = total_turns($tournamentId) + 1;
        $stmt = $pdo->prepare('INSERT INTO turns (tournament_id, player_id, cycle_number, turn_number, score, result_type, chip_delta, payout_delta, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $tournamentId,
            $playerId,
            (int) $tournament['current_cycle_number'],
            $turnNumber,
            $score,
            $resultType,
            $chipDelta,
            $payoutDelta,
            $note,
            now_utc(),
        ]);

        if ($payoutDelta !== 0) {
            $stmt = $pdo->prepare('UPDATE tournaments SET current_pot = current_pot + ? WHERE id = ?');
            $stmt->execute([$payoutDelta, $tournamentId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    maybe_eliminate_player($playerId);
    advance_queue($tournamentId);
}

function tournament_state(): ?array
{
    $tournament = active_tournament();
    if (!$tournament) {
        return null;
    }

    $tournamentId = (int) $tournament['id'];
    $players = all_players($tournamentId);
    $cycleNumber = (int) ($tournament['current_cycle_number'] ?? 1);

    $currentPlayer = !empty($tournament['current_player_id']) ? find_player((int) $tournament['current_player_id']) : null;
    if (!$currentPlayer || (int) ($currentPlayer['is_eliminated'] ?? 0) === 1) {
        // Stored current is missing or eliminated - derive from round (first active who hasn't played)
        $effectiveCurrentId = next_player_for_current_round($tournamentId, $cycleNumber);
        if ($effectiveCurrentId === null) {
            $active = active_players($tournamentId);
            $effectiveCurrentId = !empty($active) ? (int) $active[0]['id'] : null;
        }
        $currentPlayer = $effectiveCurrentId ? find_player($effectiveCurrentId) : null;
    }

    $upNext = null;
    if ($currentPlayer) {
        $upNextId = player_after_current_round($tournamentId, $cycleNumber, (int) $currentPlayer['id']);
        $upNext = $upNextId ? find_player($upNextId) : null;
    }

    $computedPots = computed_pots($tournamentId);
    return [
        'tournament' => $tournament,
        'players' => $players,
        'current_player' => $currentPlayer,
        'up_next' => $upNext,
        'recent_turns' => recent_turns((int) $tournament['id']),
        'player_scores_by_round' => player_scores_by_round((int) $tournament['id']),
        'computed_main_pot' => $computedPots['main_pot'],
        'computed_first_five_pot' => $computedPots['first_five_pot'],
    ];
}
