<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const AUTH_SECRET = '42';
const AUTH_COOKIE = 'threeball_key';
const AUTH_COOKIE_HOURS = 24;

function auth_valid(): bool
{
    return isset($_COOKIE[AUTH_COOKIE]) && $_COOKIE[AUTH_COOKIE] === AUTH_SECRET;
}

function auth_set_cookie(): void
{
    $expires = time() + (AUTH_COOKIE_HOURS * 3600);
    setcookie(AUTH_COOKIE, AUTH_SECRET, [
        'expires' => $expires,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function require_auth(string $redirectTo): void
{
    if (auth_valid()) {
        return;
    }

    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_code'])) {
        $code = trim((string) ($_POST['unlock_code'] ?? ''));
        if ($code === AUTH_SECRET) {
            auth_set_cookie();
            header('Location: ' . $redirectTo);
            exit;
        }
        $error = 'Incorrect code. Try again.';
    }

    $self = htmlspecialchars($redirectTo, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Three-Ball — Unlock</title>
<style>
body{font-family:Arial,sans-serif;background:#111;color:#f5f5f5;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}
.card{background:#1e1e1e;border-radius:16px;padding:2rem;width:100%;max-width:320px;text-align:center}
h1{font-size:1.5rem;margin:0 0 1rem;color:#90caf9}
label{display:block;text-align:left;font-size:.9rem;color:#9e9e9e;margin-bottom:.35rem}
input{width:100%;padding:.75rem;font-size:1.5rem;text-align:center;letter-spacing:.3em;border:2px solid #333;border-radius:10px;background:#2a2a2a;color:#fff;box-sizing:border-box}
input:focus{outline:none;border-color:#1976d2}
button{width:100%;margin-top:1rem;padding:.9rem;font-size:1.1rem;background:#1976d2;color:white;border:none;border-radius:10px;cursor:pointer}
button:hover{background:#2196f3}
.err{color:#ff8a80;font-size:.9rem;margin-top:.5rem}
</style>
</head>
<body>
<div class="card">
<h1>Enter code</h1>
<form method="post" action="<?= $self ?>">
<label for="unlock_code">Two-digit code</label>
<input type="text" id="unlock_code" name="unlock_code" inputmode="numeric" pattern="[0-9]*" maxlength="2" autofocus autocomplete="off" placeholder="••">
<?php if ($error): ?><p class="err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<button type="submit">Unlock</button>
</form>
</div>
</body>
</html>
<?php
    exit;
}
