<?php

declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function now_utc(): string
{
    return gmdate('c');
}

function redirect_to(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/** Web path to the app public root (trailing slash), e.g. `/` or `/threeball/`. */
function app_public_directory_path(): string
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($dir === '' || $dir === '.') {
        return '/';
    }
    return $dir . '/';
}

/** Absolute URL to the app public root (for QR / sharing). */
function app_public_root_absolute(): string
{
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . app_public_directory_path();
}

function post_int(string $key, int $default = 0): int
{
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        return $default;
    }
    return (int) $_POST[$key];
}

function active_tournament(): ?array
{
    $stmt = db()->query("SELECT * FROM tournaments ORDER BY id DESC LIMIT 1");
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Pause flag on the active tournament row (SQLite) so any client sees the same state */
function tournament_paused(): bool
{
    require_once __DIR__ . '/db.php';
    $t = active_tournament();
    if (!$t) {
        return false;
    }
    return (int) ($t['paused'] ?? 0) === 1;
}

function set_tournament_paused(bool $paused): void
{
    require_once __DIR__ . '/db.php';
    $t = active_tournament();
    if (!$t) {
        return;
    }
    $stmt = db()->prepare('UPDATE tournaments SET paused = ? WHERE id = ?');
    $stmt->execute([$paused ? 1 : 0, (int) $t['id']]);
}

/** Hide eliminated players on display; flag on the active tournament row */
function hide_out_players(): bool
{
    require_once __DIR__ . '/db.php';
    $t = active_tournament();
    if (!$t) {
        return false;
    }
    return (int) ($t['hide_out_players'] ?? 0) === 1;
}

function set_hide_out_players(bool $hide): void
{
    require_once __DIR__ . '/db.php';
    $t = active_tournament();
    if (!$t) {
        return;
    }
    $stmt = db()->prepare('UPDATE tournaments SET hide_out_players = ? WHERE id = ?');
    $stmt->execute([$hide ? 1 : 0, (int) $t['id']]);
}

/** Set when a round completes (auto-pause). Cleared when Start Next Round is clicked. */
function round_complete(): bool
{
    require_once __DIR__ . '/db.php';
    $t = active_tournament();
    if (!$t) {
        return false;
    }
    return (int) ($t['round_complete'] ?? 0) === 1;
}

function set_round_complete(bool $complete): void
{
    require_once __DIR__ . '/db.php';
    $t = active_tournament();
    if (!$t) {
        return;
    }
    $stmt = db()->prepare('UPDATE tournaments SET round_complete = ? WHERE id = ?');
    $stmt->execute([$complete ? 1 : 0, (int) $t['id']]);
}
