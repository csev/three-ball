<?php

declare(strict_types=1);
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
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Three-Ball Display</title>
<meta http-equiv="refresh" content="5">
<style>
body{font-family:Arial,sans-serif;background:#081018;color:white;margin:0;padding:2rem}
.wrap{max-width:1400px;margin:0 auto}
.header{display:flex;justify-content:space-between;align-items:flex-start;gap:2rem;flex-wrap:wrap;margin-bottom:1.5rem}
.title{font-size:3rem;font-weight:800}
.pot{font-size:2rem;color:#8df0a1}
.upnext{font-size:4rem;font-weight:800;margin:.25rem 0}
.timer{font-size:3rem;color:#ffd54f}
@keyframes late-flash{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.08)}}
.timer.timer-late{color:#ff1744;animation:late-flash .6s ease-in-out infinite;text-shadow:0 0 20px rgba(255,23,68,.8)}
.grid{display:grid;grid-template-columns:2fr 1fr;gap:1.5rem}
.card{background:rgba(255,255,255,.06);border-radius:24px;padding:1.25rem 1.5rem}
table{width:100%;border-collapse:collapse;font-size:1.4rem}
th,td{padding:.6rem .4rem;border-bottom:1px solid rgba(255,255,255,.12);text-align:left}
.out{color:#ff8a80}
.small{font-size:1.1rem;color:#c8d3dd}
@media (max-width:1000px){.grid{grid-template-columns:1fr}.upnext{font-size:2.8rem}}
</style>
</head>
<body>
<div class="wrap">
<div class="header">
<div>
<div class="title"><?= h($t['name']) ?></div>
<div class="small"><?= h($t['venue_name']) ?></div>
<div class="pot">Current Pot: $<?= h((string)$t['current_pot']) ?></div>
</div>
<div>
<div class="small">Now Shooting</div>
<div class="upnext"><?= h($current['display_name'] ?? 'Waiting...') ?></div>
<div class="small">Up Next: <?= h($state['up_next']['display_name'] ?? '—') ?></div>
<div class="timer" id="timer">--</div>
</div>
</div>

<div class="grid">
<div class="card">
<h2 style="margin-top:0">Leaderboard / Queue</h2>
<table>
<thead><tr><th>Pos</th><th>Player</th><th>Chips</th><th>Status</th></tr></thead>
<tbody>
<?php foreach ($state['players'] as $player): ?>
<tr>
<td><?= h((string)$player['queue_position']) ?></td>
<td><?= h($player['display_name']) ?></td>
<td><?= h((string)$player['chips_remaining']) ?></td>
<td class="<?= (int)$player['is_eliminated'] ? 'out' : '' ?>"><?= (int)$player['is_eliminated'] ? 'OUT' : 'IN' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div class="card">
<h2 style="margin-top:0">Recent Results</h2>
<table>
<tbody>
<?php foreach ($state['recent_turns'] as $turn): ?>
<tr>
<td><?= h($turn['display_name']) ?></td>
<td><?= $turn['score'] !== null ? 'Score ' . h((string)$turn['score']) : h(strtoupper($turn['result_type'])) ?></td>
<td><?= $turn['chip_delta'] ? h((string)$turn['chip_delta']) . ' chip' : 'ok' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div>
<script>
const expiresAt = <?= json_encode($expires) ?>;
const breakStartedAt = <?= json_encode($breakStartedAt) ?>;
const timerEl = document.getElementById('timer');
function tick() {
  if (breakStartedAt) {
    timerEl.classList.remove('timer-late');
    const start = new Date(breakStartedAt).getTime();
    const elapsed = Math.floor((Date.now() - start) / 1000);
    const m = Math.floor(elapsed / 60);
    const s = elapsed % 60;
    timerEl.textContent = `+${m}:${String(s).padStart(2, '0')}`;
    return;
  }
  if (!expiresAt) { timerEl.textContent = '--'; timerEl.classList.remove('timer-late'); return; }
  const diff = Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000);
  if (diff <= 0) {
    timerEl.textContent = 'LATE';
    timerEl.classList.add('timer-late');
  } else {
    timerEl.classList.remove('timer-late');
    const m = Math.floor(diff / 60);
    const s = diff % 60;
    timerEl.textContent = `${m}:${String(s).padStart(2, '0')}`;
  }
}
setInterval(tick, 250);
tick();
</script>
</body>
</html>
