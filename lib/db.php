<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $baseDir = dirname(__DIR__);
    $dataDir = $baseDir . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0777, true);
    }

    $dbPath = $dataDir . '/threeball.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS tournaments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        venue_name TEXT DEFAULT '',
        starting_pot INTEGER NOT NULL DEFAULT 0,
        current_pot INTEGER NOT NULL DEFAULT 0,
        timer_seconds INTEGER NOT NULL DEFAULT 60,
        chips_per_player INTEGER NOT NULL DEFAULT 5,
        status TEXT NOT NULL DEFAULT 'setup',
        current_player_id INTEGER DEFAULT NULL,
        current_turn_started_at TEXT DEFAULT NULL,
        current_turn_expires_at TEXT DEFAULT NULL,
        current_cycle_number INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS players (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tournament_id INTEGER NOT NULL,
        display_name TEXT NOT NULL,
        queue_position INTEGER NOT NULL,
        chips_remaining INTEGER NOT NULL DEFAULT 5,
        is_eliminated INTEGER NOT NULL DEFAULT 0,
        eliminated_at TEXT DEFAULT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS turns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tournament_id INTEGER NOT NULL,
        player_id INTEGER NOT NULL,
        cycle_number INTEGER NOT NULL,
        turn_number INTEGER NOT NULL,
        score INTEGER DEFAULT NULL,
        result_type TEXT NOT NULL,
        chip_delta INTEGER NOT NULL DEFAULT 0,
        payout_delta INTEGER NOT NULL DEFAULT 0,
        note TEXT DEFAULT '',
        created_at TEXT NOT NULL,
        FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
        FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
    )");

    // Add break_started_at for existing DBs (nullable = waiting for break; set = shooting, count-up)
    $cols = $pdo->query("PRAGMA table_info(tournaments)")->fetchAll(PDO::FETCH_ASSOC);
    $hasBreak = false;
    foreach ($cols as $c) {
        if ($c['name'] === 'break_started_at') {
            $hasBreak = true;
            break;
        }
    }
    if (!$hasBreak) {
        $pdo->exec("ALTER TABLE tournaments ADD COLUMN break_started_at TEXT DEFAULT NULL");
    }

    // Add first_five_round_pot for existing DBs
    $cols = $pdo->query("PRAGMA table_info(tournaments)")->fetchAll(PDO::FETCH_ASSOC);
    $hasFirstFive = false;
    foreach ($cols as $c) {
        if ($c['name'] === 'first_five_round_pot') {
            $hasFirstFive = true;
            break;
        }
    }
    if (!$hasFirstFive) {
        $pdo->exec("ALTER TABLE tournaments ADD COLUMN first_five_round_pot INTEGER NOT NULL DEFAULT 0");
    }

    // Add wins (amount won) to players
    $cols = $pdo->query("PRAGMA table_info(players)")->fetchAll(PDO::FETCH_ASSOC);
    $hasWins = false;
    foreach ($cols as $c) {
        if ($c['name'] === 'wins') {
            $hasWins = true;
            break;
        }
    }
    if (!$hasWins) {
        $pdo->exec("ALTER TABLE players ADD COLUMN wins INTEGER NOT NULL DEFAULT 0");
    }

    // Add first_five_amount and main_pot_amount to players
    $cols = $pdo->query("PRAGMA table_info(players)")->fetchAll(PDO::FETCH_ASSOC);
    $hasFirstFive = false;
    $hasMainPot = false;
    foreach ($cols as $c) {
        if ($c['name'] === 'first_five_amount') $hasFirstFive = true;
        if ($c['name'] === 'main_pot_amount') $hasMainPot = true;
    }
    if (!$hasFirstFive) {
        $pdo->exec("ALTER TABLE players ADD COLUMN first_five_amount INTEGER NOT NULL DEFAULT 0");
    }
    if (!$hasMainPot) {
        $pdo->exec("ALTER TABLE players ADD COLUMN main_pot_amount INTEGER NOT NULL DEFAULT 0");
    }

    // Add starting_first_five_round_pot (origin for First 5 pot - never changed)
    $cols = $pdo->query("PRAGMA table_info(tournaments)")->fetchAll(PDO::FETCH_ASSOC);
    $hasStartFirstFive = false;
    foreach ($cols as $c) {
        if ($c['name'] === 'starting_first_five_round_pot') {
            $hasStartFirstFive = true;
            break;
        }
    }
    if (!$hasStartFirstFive) {
        $pdo->exec("ALTER TABLE tournaments ADD COLUMN starting_first_five_round_pot INTEGER NOT NULL DEFAULT 0");
        // Backfill: reconstruct origin from current pot + awarded amounts
        $rows = $pdo->query("SELECT t.id, t.first_five_round_pot, COALESCE(SUM(p.first_five_amount), 0) as awarded FROM tournaments t LEFT JOIN players p ON p.tournament_id = t.id GROUP BY t.id")->fetchAll();
        foreach ($rows as $r) {
            $origin = (int)$r['first_five_round_pot'] + (int)$r['awarded'];
            $pdo->prepare("UPDATE tournaments SET starting_first_five_round_pot = ? WHERE id = ?")->execute([$origin, $r['id']]);
        }
    }
}
