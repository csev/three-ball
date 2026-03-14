<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_auth('setup.php');
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/rules.php';

$pdo = db();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'reset') {
        $pdo->exec('DELETE FROM turns');
        $pdo->exec('DELETE FROM players');
        $pdo->exec('DELETE FROM tournaments');
        $message = 'Tournament reset.';
    }

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? 'Classic Pub 3-Ball');
        $venue = trim($_POST['venue_name'] ?? '');
        $startingPot = post_int('starting_pot', 0);
        $timerSeconds = post_int('timer_seconds', 60);
        $chipsPerPlayer = post_int('chips_per_player', 5);
        $playersText = trim($_POST['players'] ?? '');

        $pdo->beginTransaction();
        try {
            $pdo->exec('DELETE FROM turns');
            $pdo->exec('DELETE FROM players');
            $pdo->exec('DELETE FROM tournaments');

            $stmt = $pdo->prepare('INSERT INTO tournaments (name, venue_name, starting_pot, current_pot, timer_seconds, chips_per_player, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $venue, $startingPot, $startingPot, $timerSeconds, $chipsPerPlayer, 'setup', now_utc()]);
            $tournamentId = (int) $pdo->lastInsertId();

            $names = preg_split('/\R+/', $playersText) ?: [];
            $insert = $pdo->prepare('INSERT INTO players (tournament_id, display_name, queue_position, chips_remaining, created_at) VALUES (?, ?, ?, ?, ?)');
            $queuePosition = 1;
            foreach ($names as $line) {
                $playerName = trim($line);
                if ($playerName === '') {
                    continue;
                }
                $insert->execute([$tournamentId, $playerName, $queuePosition, $chipsPerPlayer, now_utc()]);
                $queuePosition++;
            }

            $pdo->commit();
            $message = 'Tournament created.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $message = 'Error: ' . $e->getMessage();
        }
    }

    if ($action === 'start') {
        $tournament = active_tournament();
        if ($tournament) {
            $players = active_players((int) $tournament['id']);
            if (count($players) > 0) {
                start_turn((int) $tournament['id'], (int) $players[0]['id']);
                redirect_to('control.php');
            }
        }
    }
}

$state = tournament_state();
$formName = $state ? ($state['tournament']['name'] ?? 'Classic Pub 3-Ball Tournament') : 'Classic Pub 3-Ball Tournament';
$formVenue = $state ? ($state['tournament']['venue_name'] ?? 'Classic Pub') : 'Classic Pub';
$formStartingPot = $state ? (int)($state['tournament']['starting_pot'] ?? 720) : 720;
$formTimerSeconds = $state ? (int)($state['tournament']['timer_seconds'] ?? 60) : 60;
$formChipsPerPlayer = $state ? (int)($state['tournament']['chips_per_player'] ?? 5) : 5;
$formPlayers = $state ? implode("\n", array_map(fn($p) => $p['display_name'], $state['players'])) : "Andy\nJoe\nMike Ted\nSteve\nRandy";
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Three-Ball Setup</title>
<style>
body{font-family:Arial,sans-serif;max-width:900px;margin:2rem auto;padding:0 1rem;background:#f7f7f7;color:#222}
.card{background:white;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1rem;box-shadow:0 1px 8px rgba(0,0,0,.08)}
label{display:block;font-weight:bold;margin-top:.75rem}
input,textarea,button{font:inherit}
input,textarea{width:100%;padding:.55rem;margin-top:.25rem}
textarea{min-height:180px}
button{padding:.7rem 1rem;border-radius:10px;border:1px solid #999;background:#fff;cursor:pointer}
.actions{display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem}
.small{color:#666;font-size:.95rem}
</style>
</head>
<body>
<div class="card">
<h1>Three-Ball Tournament Setup</h1>
<?php if ($message): ?>
<p><strong><?= h($message) ?></strong></p>
<?php endif; ?>
<form method="post" onsubmit="return confirm('Are you sure? This will replace the current tournament and all data.');">
<input type="hidden" name="action" value="create">
<label>Tournament Name</label>
<input name="name" value="<?= h($formName) ?>">
<label>Venue Name</label>
<input name="venue_name" value="<?= h($formVenue) ?>">
<label>Starting Pot</label>
<input name="starting_pot" type="number" value="<?= (int)$formStartingPot ?>">
<label>Timer Seconds</label>
<input name="timer_seconds" type="number" value="<?= (int)$formTimerSeconds ?>">
<label>Chips Per Player</label>
<input name="chips_per_player" type="number" value="<?= (int)$formChipsPerPlayer ?>">
<label>Players (one per line)</label>
<textarea name="players"><?= h($formPlayers) ?></textarea>
<div class="actions">
<button type="submit">Create / Replace Tournament</button>
</div>
</form>
</div>

<div class="card">
<h2>Start / Reset</h2>
<form method="post" style="display:inline-block;margin-right:.75rem">
<input type="hidden" name="action" value="start">
<button type="submit">Start Tournament</button>
</form>
<form method="post" style="display:inline-block" onsubmit="return confirm('Are you sure? This will delete all tournament data.');">
<input type="hidden" name="action" value="reset">
<button type="submit">Reset All Data</button>
</form>
<a href="control.php" style="display:inline-block;margin-left:.75rem;padding:.7rem 1rem;border-radius:10px;border:1px solid #999;background:#fff;color:#222;text-decoration:none;font:inherit;cursor:pointer">View Control</a>
</div>

<?php if ($state): ?>
<div class="card">
<h2>Current State</h2>
<p><strong><?= h($state['tournament']['name']) ?></strong> — status: <?= h($state['tournament']['status']) ?></p>
<p>Pot: $<?= h((string)$state['tournament']['current_pot']) ?></p>
<ol>
<?php foreach ($state['players'] as $player): ?>
<li><?= h($player['display_name']) ?> — chips: <?= h((string)$player['chips_remaining']) ?><?= (int)$player['is_eliminated'] ? ' (out)' : '' ?></li>
<?php endforeach; ?>
</ol>
<p class="small"><a href="control.php">Control screen</a> · <a href="display.php">Display screen</a></p>
</div>
<?php endif; ?>
</body>
</html>
