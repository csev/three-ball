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

/** Tournament pause state stored in file so display (often on different device) can read it */
function tournament_paused(): bool
{
    $path = dirname(__DIR__) . '/data/tournament_paused';
    return is_file($path) && trim((string) file_get_contents($path)) === '1';
}

function set_tournament_paused(bool $paused): void
{
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $path = $dir . '/tournament_paused';
    if ($paused) {
        file_put_contents($path, '1');
    } elseif (is_file($path)) {
        unlink($path);
    }
}

/** Hide eliminated players on display; stored in file so display (on different device) reads it */
function hide_out_players(): bool
{
    $path = dirname(__DIR__) . '/data/hide_out_players';
    return is_file($path) && trim((string) file_get_contents($path)) === '1';
}

function set_hide_out_players(bool $hide): void
{
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $path = $dir . '/hide_out_players';
    if ($hide) {
        file_put_contents($path, '1');
    } elseif (is_file($path)) {
        unlink($path);
    }
}
