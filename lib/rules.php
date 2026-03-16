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
    $all = players_with_computed($tournamentId);
    return array_values(array_filter($all, fn($p) => (int)($p['is_eliminated'] ?? 0) === 0));
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

/** Chips = initial (from setup) minus count of scores 5+ or timeout. Min 0. OUT when 0. */
function computed_chips(int $tournamentId, int $playerId, int $initialChips): int
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(chip_delta), 0) FROM turns WHERE tournament_id = ? AND player_id = ?');
    $stmt->execute([$tournamentId, $playerId]);
    $delta = (int) $stmt->fetchColumn();
    return max(0, $initialChips + $delta);
}

/** Returns players with chips_remaining and is_eliminated computed from turns (initial chips minus 5s/timeouts). */
function players_with_computed(int $tournamentId): array
{
    $tournament = active_tournament();
    $initialChips = $tournament && (int) $tournament['id'] === $tournamentId
        ? (int) ($tournament['chips_per_player'] ?? 5)
        : 5;

    $players = all_players($tournamentId);
    foreach ($players as &$p) {
        $chips = computed_chips($tournamentId, (int) $p['id'], $initialChips);
        $p['chips_remaining'] = $chips;
        $p['is_eliminated'] = $chips <= 0 ? 1 : 0;
    }
    return $players;
}

/** Returns [main_pot => int, first_five_pot => int] computed as origin minus sum of amounts awarded. */
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

/** Returns active players who have NOT played in current round (still in game, no score this round). */
function remaining_to_shoot(int $tournamentId, int $cycleNumber): array
{
    $active = active_players($tournamentId);
    if (empty($active)) {
        return [];
    }
    $stmt = db()->prepare('SELECT player_id FROM turns WHERE tournament_id = ? AND cycle_number = ?');
    $stmt->execute([$tournamentId, $cycleNumber]);
    $playedIds = array_map('intval', array_column($stmt->fetchAll(), 'player_id'));
    $remaining = [];
    foreach ($active as $p) {
        if (!in_array((int) $p['id'], $playedIds, true)) {
            $remaining[] = $p;
        }
    }
    return $remaining;
}

/** Returns a random active player who has NOT played in current round. Null if round complete. */
function next_player_for_current_round(int $tournamentId, int $cycleNumber): ?int
{
    $remaining = remaining_to_shoot($tournamentId, $cycleNumber);
    if (empty($remaining)) {
        return null;
    }
    shuffle($remaining);
    return (int) $remaining[0]['id'];
}

/** True if current player is the last to shoot this round (after them, round ends). */
function is_current_last_in_round(int $tournamentId, int $cycleNumber, int $currentPlayerId): bool
{
    $active = active_players($tournamentId);
    $stmt = db()->prepare('SELECT player_id FROM turns WHERE tournament_id = ? AND cycle_number = ?');
    $stmt->execute([$tournamentId, $cycleNumber]);
    $playedIds = array_map('intval', array_column($stmt->fetchAll(), 'player_id'));

    $remaining = [];
    foreach ($active as $p) {
        $pid = (int) $p['id'];
        if (!in_array($pid, $playedIds, true)) {
            $remaining[] = $pid;
        }
    }
    return count($remaining) === 1 && (int) ($remaining[0] ?? 0) === $currentPlayerId;
}

/** Returns a random player id (other than current) who hasn't shot this round. Null if current is last this round. */
function player_after_current_round(int $tournamentId, int $cycleNumber, int $currentPlayerId): ?int
{
    $remaining = remaining_to_shoot($tournamentId, $cycleNumber);
    $others = array_values(array_filter($remaining, fn($p) => (int) $p['id'] !== $currentPlayerId));
    if (empty($others)) {
        return null;
    }
    shuffle($others);
    return (int) $others[0]['id'];
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
    shuffle($active);
    return (int) $active[0]['id']; // Round complete; next round starts with random active
}

function second_active_player_id(array $tournament): ?int
{
    $tournamentId = (int) $tournament['id'];
    $cycleNumber = (int) ($tournament['current_cycle_number'] ?? 1);
    $remaining = remaining_to_shoot($tournamentId, $cycleNumber);
    if (count($remaining) < 2) {
        return null;
    }

    $nextId = next_active_player_id($tournament);
    $others = array_values(array_filter($remaining, fn($p) => (int) $p['id'] !== $nextId));
    if (empty($others)) {
        return null;
    }
    shuffle($others);
    return (int) $others[0]['id'];
}

function start_turn(int $tournamentId, int $playerId): void
{
    $tournament = active_tournament();
    if (!$tournament || (int) $tournament['id'] !== $tournamentId) {
        return;
    }

    $cycleNumber = (int) ($tournament['current_cycle_number'] ?? 1);
    $upNextId = player_after_current_round($tournamentId, $cycleNumber, $playerId);

    $timerSeconds = (int) $tournament['timer_seconds'];
    $started = time();
    $expires = $started + $timerSeconds;

    $stmt = db()->prepare('UPDATE tournaments SET current_player_id = ?, up_next_player_id = ?, current_turn_started_at = ?, current_turn_expires_at = ?, break_started_at = NULL, status = ? WHERE id = ?');
    $stmt->execute([
        $playerId,
        $upNextId,
        gmdate('c', $started),
        gmdate('c', $expires),
        'running',
        $tournamentId,
    ]);
}

function advance_queue(int $tournamentId): void
{
    $tournament = active_tournament();
    if (!$tournament || (int) $tournament['id'] !== $tournamentId) {
        return;
    }

    $active = active_players($tournamentId);
    if (count($active) <= 1) {
        $stmt = db()->prepare('UPDATE tournaments SET status = ?, current_player_id = NULL, up_next_player_id = NULL, current_turn_started_at = NULL, current_turn_expires_at = NULL WHERE id = ?');
        $stmt->execute(['finished', $tournamentId]);
        return;
    }

    $cycleNumber = (int) $tournament['current_cycle_number'];
    $nextId = next_player_for_current_round($tournamentId, $cycleNumber);

    if ($nextId === null) {
        // Round complete: everyone has played. Auto-pause so folks can get paid, double-check scores.
        $cycleNumber = min(15, $cycleNumber + 1);
        shuffle($active);
        $firstActiveId = (int) $active[0]['id'];
        $upNextId = player_after_current_round($tournamentId, $cycleNumber, $firstActiveId);
        $stmt = db()->prepare('UPDATE tournaments SET current_cycle_number = ?, current_player_id = ?, up_next_player_id = ?, break_started_at = NULL, current_turn_started_at = NULL, current_turn_expires_at = NULL WHERE id = ?');
        $stmt->execute([$cycleNumber, $firstActiveId, $upNextId, $tournamentId]);
        set_tournament_paused(true);
        set_round_complete(true);
        return;
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

    advance_queue($tournamentId);
}

function tournament_state(): ?array
{
    $tournament = active_tournament();
    if (!$tournament) {
        return null;
    }

    $tournamentId = (int) $tournament['id'];
    $players = players_with_computed($tournamentId);
    $cycleNumber = (int) ($tournament['current_cycle_number'] ?? 1);
    $initialChips = (int) ($tournament['chips_per_player'] ?? 5);

    $currentPlayer = !empty($tournament['current_player_id']) ? find_player((int) $tournament['current_player_id']) : null;
    if ($currentPlayer) {
        $chips = computed_chips($tournamentId, (int) $currentPlayer['id'], $initialChips);
        $currentPlayer['chips_remaining'] = $chips;
        $currentPlayer['is_eliminated'] = $chips <= 0 ? 1 : 0;
    }
    if (!$currentPlayer || (int) ($currentPlayer['is_eliminated'] ?? 0) === 1) {
        // Stored current is missing or eliminated - derive from round (first active who hasn't played)
        $effectiveCurrentId = next_player_for_current_round($tournamentId, $cycleNumber);
        if ($effectiveCurrentId === null) {
            $active = active_players($tournamentId);
            if (!empty($active)) {
                shuffle($active);
                $effectiveCurrentId = (int) $active[0]['id'];
            }
        }
        $currentPlayer = $effectiveCurrentId ? find_player($effectiveCurrentId) : null;
        if ($currentPlayer) {
            $chips = computed_chips($tournamentId, (int) $currentPlayer['id'], $initialChips);
            $currentPlayer['chips_remaining'] = $chips;
            $currentPlayer['is_eliminated'] = $chips <= 0 ? 1 : 0;
        }
    }

    $upNext = null;
    if ($currentPlayer) {
        // When current is last in round, there is no up next — show "End of Round"
        if (is_current_last_in_round($tournamentId, $cycleNumber, (int) $currentPlayer['id'])) {
            $upNext = null;
        } else {
            $upNextId = !empty($tournament['up_next_player_id']) ? (int) $tournament['up_next_player_id'] : null;
            if ($upNextId === null) {
                $upNextId = player_after_current_round($tournamentId, $cycleNumber, (int) $currentPlayer['id']);
            }
            $upNext = $upNextId ? find_player($upNextId) : null;
            // If stored up_next is eliminated, recompute (fallback)
            if ($upNext && (int) ($upNext['is_eliminated'] ?? 0) === 1) {
                $upNextId = player_after_current_round($tournamentId, $cycleNumber, (int) $currentPlayer['id']);
                $upNext = $upNextId ? find_player($upNextId) : null;
            }
        }
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
