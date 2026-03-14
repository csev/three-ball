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
}
