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
