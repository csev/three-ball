<?php

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/lib/auth.php';
require_auth('control.php');
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/rules.php';
$state = tournament_state();
if (!$state) {
    redirect_to('setup.php');
}
$t = $state['tournament'];
$current = $state['current_player'];
$expires = $t['current_turn_expires_at'] ?? null;
$breakStartedAt = $t['break_started_at'] ?? null;
$waitingForBreak = !empty($current) && empty($breakStartedAt);
$isPaused = tournament_paused();
$roundComplete = round_complete();
$hideOut = hide_out_players();
$chipsPerPlayer = (int)($t['chips_per_player'] ?? 5);
$upNext = $state['up_next'] ?? null;
$atEndOfRound = $current && is_current_last_in_round((int)$t['id'], (int)($t['current_cycle_number'] ?? 1), (int)$current['id']);
$upNextLabel = $upNext && !$atEndOfRound ? h($upNext['display_name']) : 'End of Round';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Three-Ball Control</title>
<style>
body{font-family:Arial,sans-serif;background:#111;color:#f5f5f5;margin:0;padding:1rem}
.wrap{max-width:1100px;margin:0 auto}
.grid{display:grid;grid-template-columns:2fr 1fr;gap:1rem}
.card{background:#1e1e1e;border-radius:16px;padding:1rem 1.25rem}
.big{font-size:3rem;font-weight:bold}
.timer{font-size:4rem;font-weight:bold;color:#ffdb4d}
.buttons{display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-top:1rem}
button{padding:1rem;border-radius:14px;border:none;font-size:1.4rem;cursor:pointer}
.score{background:#2e7d32;color:white}
.bad{background:#b71c1c;color:white}
.neutral{background:#455a64;color:white}
.break-btn{background:linear-gradient(135deg,#2196f3,#1976d2);color:white;padding:1.5rem 2.5rem;font-size:2rem;font-weight:bold;border-radius:20px;border:none;cursor:pointer;box-shadow:0 4px 20px rgba(33,150,243,.4);transition:transform .15s,box-shadow .15s}
.break-btn:hover{transform:scale(1.02);box-shadow:0 6px 24px rgba(33,150,243,.5)}
.break-btn:active{transform:scale(.98)}
a{color:#9fd3ff}
table{width:100%;border-collapse:collapse}
td,th{padding:.4rem;border-bottom:1px solid #333;text-align:left}
.small{color:#bbb}
.waiting-break{text-align:center;padding:2.5rem 2rem;background:linear-gradient(180deg,rgba(25,118,210,.15) 0%,rgba(25,118,210,.04) 50%,transparent 100%);border:1px solid rgba(25,118,210,.35);border-radius:24px;margin-bottom:1rem;box-shadow:inset 0 1px 0 rgba(255,255,255,.06)}
.waiting-break .waiting-title{margin:0 0 1rem;font-size:1.3rem;color:#90caf9;font-weight:600;letter-spacing:.1em;text-transform:uppercase}
.waiting-break .instruction{font-size:1.25rem;color:#bbdefb;margin-bottom:1.75rem;letter-spacing:.02em;font-weight:500}
.waiting-break .break-btn{background:linear-gradient(180deg,#42a5f5,#1976d2);color:white;padding:1.5rem 3rem;font-size:2rem;font-weight:bold;border:none;border-radius:16px;cursor:pointer;box-shadow:0 4px 20px rgba(25,118,210,.4);transition:transform .15s,box-shadow .15s}
.waiting-break .break-btn:hover{transform:scale(1.03);box-shadow:0 6px 28px rgba(25,118,210,.5)}
.waiting-break .break-btn:active{transform:scale(.98)}
.waiting-break .timeout-wrap{margin-top:1.5rem;padding-top:1rem;border-top:1px solid rgba(255,255,255,.1)}
.waiting-break .timeout-wrap .neutral{font-size:1rem;padding:.6rem 1rem}
@keyframes soft-pulse{0%,100%{box-shadow:0 4px 20px rgba(25,118,210,.4)}50%{box-shadow:0 4px 28px rgba(25,118,210,.6)}}
.waiting-break .break-btn{animation:soft-pulse 2s ease-in-out infinite}
.waiting-break .break-btn:hover{animation:none}
@keyframes late-flash{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.08)}}
.timer.timer-late{color:#ff1744;animation:late-flash .6s ease-in-out infinite;text-shadow:0 0 20px rgba(255,23,68,.8)}
@media (max-width:900px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="wrap">
<div class="card" style="margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:nowrap">
<div>
<div class="small"><?= h($t['name']) ?></div>
<div class="big"><?= h($current['display_name'] ?? 'No current player') ?></div>
<div>Up next: <?= $upNextLabel ?></div>
</div>
<div class="timer" id="timer">--</div>
</div>

<div class="grid">
<div class="card">
<?php if ($waitingForBreak): ?>
<div class="waiting-break">
<h2 class="waiting-title">Waiting for break</h2>
<div class="instruction">Tap when <?= h($current['display_name']) ?> breaks</div>
<form method="post" action="api/break.php">
<button type="submit" class="break-btn">Break</button>
</form>
<div class="timeout-wrap">
<form method="post" action="api/timeout.php">
<button class="neutral" type="submit">Timeout / No Show</button>
</form>
</div>
</div>
<?php else: ?>
<div class="pot-row" style="display:flex;align-items:center;gap:2rem;flex-wrap:wrap;margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid #333">
<span class="pot-display" style="font-size:1.5rem;font-weight:bold;color:#8df0a1">Pot: $<?= h((string)($state['computed_main_pot'] ?? $t['current_pot'])) ?></span>
<span class="pot-display small" style="font-size:1.1rem;color:#90caf9">First <?= $chipsPerPlayer ?>: $<?= h((string)($state['computed_first_five_pot'] ?? $t['first_five_round_pot'] ?? 0)) ?></span>
</div>
<h2>Enter Score</h2>
<div class="buttons">
<?php foreach ([1,2,3,4,5] as $score): ?>
<form method="post" action="api/submit_score.php">
<input type="hidden" name="score" value="<?= $score ?>">
<button class="<?= $score <= 4 ? 'score' : 'bad' ?>" type="submit"><?= $score ?></button>
</form>
<?php endforeach; ?>
<?php endif; ?>
<?php if ($isPaused && $roundComplete): ?>
<form method="post" action="api/start_next_round.php" style="display:inline-block;margin-right:.75rem;margin-top:1rem">
<button class="neutral" type="submit" style="background:#2e7d32">Start Next Round</button>
</form>
<?php elseif ($isPaused): ?>
<form method="post" action="api/resume.php" style="display:inline-block;margin-right:.75rem;margin-top:1rem">
<button class="neutral" type="submit" style="background:#2e7d32">Resume Tournament</button>
</form>
<?php else: ?>
<form method="post" action="api/pause.php" style="display:inline-block;margin-right:.75rem;margin-top:1rem">
<button class="neutral" type="submit" style="background:#f57c00">Pause Tournament</button>
</form>
<?php endif; ?>
<form method="post" action="api/toggle_hide_out.php" style="display:inline-block;margin-right:.75rem;margin-top:1rem">
<button class="neutral" type="submit"><?= $hideOut ? 'Show Out Players' : 'Hide Out Players' ?></button>
</form>
<form method="get" action="edit.php" style="display:inline-block;margin-right:.75rem;margin-top:1rem">
<button class="neutral" type="submit">Edit</button>
</form>
<form method="get" action="setup.php" onsubmit="return confirm('Are you sure you want to go to Setup?');" style="display:inline-block;margin-right:.75rem;margin-top:1rem">
<button class="neutral" type="submit">Setup</button>
</form>
<form method="get" action="./" target="_blank" style="display:inline-block;margin-top:1rem">
<button class="neutral" type="submit">Public Display</button>
</form>
</div>

<div class="card">
<h2>Recent Turns</h2>
<table>
<thead><tr><th>#</th><th>Player</th><th>Result</th><th>Δ Chips</th></tr></thead>
<tbody>
<?php foreach ($state['recent_turns'] as $turn): ?>
<tr>
<td><?= h((string)$turn['turn_number']) ?></td>
<td><?= h($turn['display_name']) ?></td>
<td><?= $turn['score'] !== null ? h((string)$turn['score']) : h($turn['result_type']) ?></td>
<td><?= h((string)$turn['chip_delta']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<script>
const expiresAt = <?= json_encode($expires) ?>;
const breakStartedAt = <?= json_encode($breakStartedAt) ?>;
const timerEl = document.getElementById('timer');
function tick() {
  if (breakStartedAt) {
    const start = new Date(breakStartedAt).getTime();
    const elapsed = Math.floor((Date.now() - start) / 1000);
    const m = Math.floor(elapsed / 60);
    const s = elapsed % 60;
    timerEl.textContent = `+${m}:${String(s).padStart(2, '0')}`;
    timerEl.className = 'timer';
    return;
  }
  if (!expiresAt) { timerEl.textContent = '--'; timerEl.className = 'timer'; return; }
  const diff = Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000);
  if (diff <= 0) {
    timerEl.textContent = 'LATE';
    timerEl.className = 'timer timer-late';
    return;
  }
  const m = Math.floor(diff / 60);
  const s = diff % 60;
  timerEl.textContent = `${m}:${String(s).padStart(2, '0')}`;
  timerEl.className = 'timer';
}
setInterval(tick, 250);
tick();
</script>
</body>
</html>
