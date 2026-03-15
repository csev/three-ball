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

function next_active_player_id(array $tournament): ?int
{
    $players = active_players((int) $tournament['id']);
    if (count($players) === 0) {
        return null;
    }

    if (empty($tournament['current_player_id'])) {
        return (int) $players[0]['id'];
    }

    $currentId = (int) $tournament['current_player_id'];
    $count = count($players);
    foreach ($players as $index => $player) {
        if ((int) $player['id'] === $currentId) {
            $next = $players[($index + 1) % $count];
            return (int) $next['id'];
        }
    }

    return (int) $players[0]['id'];
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

    $nextId = next_active_player_id($tournament);
    if ($nextId === null) {
        return;
    }

    $firstActiveId = (int) $active[0]['id'];
    $cycleNumber = (int) $tournament['current_cycle_number'];
    if ($nextId === $firstActiveId && !empty($tournament['current_player_id'])) {
        $cycleNumber++;
        $stmt = db()->prepare('UPDATE tournaments SET current_cycle_number = ? WHERE id = ?');
        $stmt->execute([$cycleNumber, $tournamentId]);
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

    $players = all_players((int) $tournament['id']);
    $currentPlayer = !empty($tournament['current_player_id']) ? find_player((int) $tournament['current_player_id']) : null;
    $upNext = null;
    if ($currentPlayer) {
        $copy = $tournament;
        $upNextId = next_active_player_id($copy);
        if ($upNextId !== null && $upNextId !== (int) $currentPlayer['id']) {
            $upNext = find_player($upNextId);
        }
    }

    $computedPots = computed_pots((int) $tournament['id']);
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
