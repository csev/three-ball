<?php

declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/rules.php';
$state = tournament_state();
if (!$state) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Three-Ball — Not started</title>
<style>
body{font-family:Arial,sans-serif;background:#081018;color:#e8eef4;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem}
.card{background:rgba(255,255,255,.08);border-radius:20px;padding:2rem;max-width:28rem;text-align:center}
h1{margin:0 0 .75rem;font-size:1.75rem}
p{margin:0 0 1.25rem;line-height:1.5;color:#b8c5d0}
a{color:#90caf9;font-weight:bold}
</style>
</head>
<body>
<div class="card">
<h1>No tournament loaded</h1>
<p>Create or start a tournament in setup to show the public display here.</p>
<p><a href="setup.php">Go to setup</a></p>
</div>
</body>
</html>
<?php
    exit;
}
$t = $state['tournament'];
$tournamentId = (int) $t['id'];
$currentCycle = (int)($t['current_cycle_number'] ?? 1);
$maxRound = tournament_max_round_column($tournamentId, $currentCycle);
$current = $state['current_player'];
$expires = $t['current_turn_expires_at'] ?? null;
$breakStartedAt = $t['break_started_at'] ?? null;
$isPaused = tournament_paused();
$chipsPerPlayer = (int)($t['chips_per_player'] ?? 5);
$displayUrlForQr = app_public_root_absolute() . '?view=1';
$showQrCode = !isset($_GET['view']) || $_GET['view'] !== '1';
$isViewMode = isset($_GET['view']) && $_GET['view'] === '1';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Three-Ball Display</title>
<style>
body{font-family:Arial,sans-serif;background:#081018;color:white;margin:0;padding:2rem}
.wrap{max-width:1800px;margin:0 auto}
.header{display:flex;justify-content:space-between;align-items:flex-start;gap:2rem;flex-wrap:wrap;margin-bottom:1.5rem}
.title{font-size:3.8rem;font-weight:800}
.pot{font-size:2.5rem;color:#8df0a1}
.upnext{font-size:5rem;font-weight:800;margin:.25rem 0}
.upnext-secondary{font-size:2.5rem;font-weight:800;margin:.25rem 0}
.timer{font-size:3.8rem;color:#ffd54f}
@keyframes late-flash{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(1.08)}}
.timer.timer-late{color:#ff1744;animation:late-flash .6s ease-in-out infinite;text-shadow:0 0 20px rgba(255,23,68,.8)}
.card{background:rgba(255,255,255,.06);border-radius:24px;padding:1.25rem 1.5rem}
.leaderboard-wrap{overflow-x:auto;margin-top:.5rem}
.leaderboard{width:max-content;border-collapse:collapse;font-size:1.7rem}
.leaderboard th,.leaderboard td{padding:.6rem .5rem;border-bottom:1px solid rgba(255,255,255,.12);text-align:left;white-space:nowrap}
/* Only Player and Chips stay fixed when scrolling horizontally — solid bg so scrolled content doesn't bleed through */
.leaderboard .col-frozen-2,.leaderboard .col-frozen-3{position:sticky;background-color:#0d1520;z-index:2;box-shadow:2px 0 4px rgba(0,0,0,.3);isolation:isolate;border-bottom:1px solid rgba(255,255,255,.12)}
.leaderboard .col-frozen-2{left:0;min-width:6rem}
.leaderboard .col-frozen-3{left:6rem;min-width:2.5rem}
.leaderboard th.col-round{min-width:2.5rem;text-align:center}
.leaderboard td.col-round.lowest{color:gold}
/* Remove top border to prevent double line at thead/tbody (only use border-bottom) */
.leaderboard th,.leaderboard td{border-top:none}
th,td{padding:.6rem .4rem;border-bottom:1px solid rgba(255,255,255,.12);text-align:left}
.out{color:#ff8a80}
.small{font-size:1.25rem;color:#c8d3dd}
.score-1,.score-2,.score-3,.score-4{color:#8df0a1}
.score-5{color:#ff8a80}
.leaderboard tr.active-player td{background:rgba(33,150,243,.25)}
.leaderboard tr.active-player .col-frozen,.leaderboard tr.active-player .col-frozen-2,.leaderboard tr.active-player .col-frozen-3,.leaderboard tr.active-player .col-frozen-4,.leaderboard tr.active-player .col-frozen-5,.leaderboard tr.active-player .col-frozen-6{background:rgba(33,150,243,.35)}
/* Sticky cols need solid backgrounds so scrolled content doesn't show through */
.leaderboard tr.active-player .col-frozen-2,.leaderboard tr.active-player .col-frozen-3{background:#1a3d5c}
.paused-banner{background:rgba(245,124,0,.95);color:#000;font-size:3rem;font-weight:800;text-align:center;padding:1.5rem;box-shadow:0 4px 20px rgba(0,0,0,.4)}
.qr-wrap{display:flex;align-items:center}
.qr-wrap img{width:260px;height:260px;background:white;padding:10px;border-radius:10px}
@media (max-width:1000px){.grid{grid-template-columns:1fr}.upnext{font-size:2.8rem}}
/* view=1: mobile phone – smaller header */
body.view-mode{padding:0.75rem}
body.view-mode .header{margin-bottom:0.75rem;gap:0.75rem}
body.view-mode .title{font-size:1.6rem}
body.view-mode .pot{font-size:1.2rem}
body.view-mode .upnext{font-size:2.2rem}
body.view-mode .upnext-secondary{font-size:1.2rem}
body.view-mode .timer{font-size:1.8rem}
body.view-mode .small{font-size:0.95rem}
</style>
</head>
<body<?= $isViewMode ? ' class="view-mode"' : '' ?>>
<div id="paused-banner" class="paused-banner" style="display:<?= $isPaused ? 'block' : 'none' ?>">PAUSED</div>
<div class="wrap">
<div class="header">
<div>
<div class="title" id="disp-title"><?= h($t['name']) ?></div>
<div class="pot" id="disp-main-pot">Current Pot: $<?= h((string)($state['computed_main_pot'] ?? $t['current_pot'])) ?></div>
<div class="pot" id="disp-first-pot">First <?= $chipsPerPlayer ?> Pot: $<?= h((string)($state['computed_first_five_pot'] ?? $t['first_five_round_pot'] ?? 0)) ?></div>
<div class="small" id="disp-round">Current Round: <?= h((string)($t['current_cycle_number'] ?? 1)) ?></div>
</div>
<?php
$upNext = $state['up_next'] ?? null;
$atEndOfRound = $current && is_current_last_in_round((int)$t['id'], (int)($t['current_cycle_number'] ?? 1), (int)$current['id']);
$showUpNext = $upNext && !$atEndOfRound;
?>
<div class="qr-wrap" style="gap:1.5rem">
<div>
<div class="small">Now Shooting</div>
<div class="upnext" id="disp-current-name"><?= h($current['display_name'] ?? 'Waiting...') ?></div>
<div class="timer" id="timer">--</div>
<div class="upnext-secondary" id="disp-up-label"><?= $showUpNext ? 'Up Next' : '' ?></div>
<div class="upnext-secondary" id="disp-up-name"><?= $showUpNext ? h($upNext['display_name']) : 'End of Round' ?></div>
</div>
<?php if ($showQrCode): ?>
<a href="<?= h($displayUrlForQr) ?>" title="Open display"><img src="https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=<?= rawurlencode($displayUrlForQr) ?>" alt="QR: Display" width="260" height="260"></a>
<?php endif; ?>
</div>
</div>

<?php
$scoresByRound = player_scores_by_round($tournamentId);
$displayPlayers = hide_out_players()
    ? array_values(array_filter($state['players'], fn($p) => !(int)($p['is_eliminated'] ?? 0)))
    : $state['players'];
/* Per-round min score for gold highlighting (only numeric 1–5 count) */
$minByRound = [];
for ($r = 1; $r <= $maxRound; $r++) {
    $scoresInRound = [];
    foreach ($displayPlayers as $player) {
        $val = $scoresByRound[(int)$player['id']][$r] ?? '';
        if ($val !== '' && !in_array($val, ['TO', 'T.V.'], true) && ctype_digit((string)$val)) {
            $scoresInRound[] = (int) $val;
        }
    }
    $minByRound[$r] = $scoresInRound ? min($scoresInRound) : null;
}
$structureKeyInit = $maxRound . ':' . implode(',', array_map(fn($p) => (string) (int) $p['id'], $displayPlayers));
?>
<div class="card">
<h2 style="margin-top:0">Leaderboard / Queue</h2>
<div class="leaderboard-wrap">
<table class="leaderboard">
<thead>
<tr>
<th class="col-frozen">Pos</th>
<th class="col-frozen col-frozen-2">Player</th>
<th class="col-frozen col-frozen-3">Chips</th>
<th class="col-frozen col-frozen-4">First <?= $chipsPerPlayer ?> $</th>
<th class="col-frozen col-frozen-5">Main $</th>
<th class="col-frozen col-frozen-6">Status</th>
<?php for ($r = 1; $r <= $maxRound; $r++): ?><th class="col-round" id="round-col-<?= $r ?>"><?= $r ?></th><?php endfor; ?>
</tr>
</thead>
<tbody id="leaderboard-body">
<?php
$upNextForRow = $showUpNext ? $upNext : null;
foreach ($displayPlayers as $player):
    $pid = (int) $player['id'];
    $scores = $scoresByRound[$pid] ?? [];
    $isActive = $current && (int)$current['id'] === $pid;
    $isUpNext = $upNextForRow && (int)$upNextForRow['id'] === $pid;
    $rowClass = $isActive ? 'active-player' : ($isUpNext ? 'up-next-player' : '');
?>
<tr data-player-id="<?= $pid ?>"<?= $rowClass ? ' class="' . h($rowClass) . '"' : '' ?>>
<td class="col-frozen"><?= h((string)$player['queue_position']) ?></td>
<td class="col-frozen col-frozen-2"><?= h($player['display_name']) ?></td>
<td class="col-frozen col-frozen-3"><?= h((string)$player['chips_remaining']) ?></td>
<td class="col-frozen col-frozen-4">$<?= h((string)($player['first_five_amount'] ?? 0)) ?></td>
<td class="col-frozen col-frozen-5">$<?= h((string)($player['main_pot_amount'] ?? 0)) ?></td>
<td class="col-frozen col-frozen-6 <?= (int)$player['is_eliminated'] ? 'out' : '' ?>"><?= (int)$player['is_eliminated'] ? 'OUT' : 'IN' ?></td>
<?php for ($r = 1; $r <= $maxRound; $r++):
    $val = $scores[$r] ?? '';
    $scoreClass = in_array((string)$val, ['1','2','3']) ? ' score-1' : ((string)$val === '4' ? ' score-4' : ((string)$val === '5' ? ' score-5' : ''));
    $isLowest = $minByRound[$r] !== null && $val !== '' && !in_array($val, ['TO', 'T.V.'], true) && ctype_digit((string)$val) && (int)$val === $minByRound[$r];
?><td class="col-round<?= $scoreClass ?><?= $isLowest ? ' lowest' : '' ?>"><?= h($val) ?></td>
<?php endfor; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
</div>
<script>
(function () {
  var STATE_URL = <?= json_encode(app_public_directory_path() . 'api/state.php?display=1') ?>;
  var expiresAt = <?= json_encode($expires) ?>;
  var breakStartedAt = <?= json_encode($breakStartedAt) ?>;
  var timerEl = document.getElementById('timer');
  var chipsPerPlayer = <?= (int) $chipsPerPlayer ?>;
  var lastStructureKey = <?= json_encode($structureKeyInit) ?>;

  function tick() {
    if (breakStartedAt) {
      timerEl.classList.remove('timer-late');
      var start = new Date(breakStartedAt).getTime();
      var elapsed = Math.floor((Date.now() - start) / 1000);
      var m = Math.floor(elapsed / 60);
      var s = elapsed % 60;
      timerEl.textContent = '+' + m + ':' + String(s).padStart(2, '0');
      return;
    }
    if (!expiresAt) { timerEl.textContent = '--'; timerEl.classList.remove('timer-late'); return; }
    var diff = Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000);
    if (diff <= 0) {
      timerEl.textContent = 'LATE';
      timerEl.classList.add('timer-late');
    } else {
      timerEl.classList.remove('timer-late');
      var dm = Math.floor(diff / 60);
      var ds = diff % 60;
      timerEl.textContent = dm + ':' + String(ds).padStart(2, '0');
    }
  }

  function minForRound(minByRound, r) {
    if (!minByRound) return null;
    return minByRound[r] !== undefined && minByRound[r] !== null ? minByRound[r] : minByRound[String(r)];
  }

  function scoreClasses(val, r, minByRound) {
    var v = String(val === undefined || val === null ? '' : val);
    var cls = 'col-round';
    if (['1', '2', '3'].indexOf(v) !== -1) cls += ' score-1';
    else if (v === '4') cls += ' score-4';
    else if (v === '5') cls += ' score-5';
    var low = minForRound(minByRound, r);
    if (low !== null && low !== undefined && v !== '' && v !== 'TO' && v !== 'T.V.' && /^\d+$/.test(v) && parseInt(v, 10) === low) cls += ' lowest';
    return cls;
  }

  function structureKey(d) {
    if (!d || !d.display) return '';
    return d.display.max_round + ':' + d.display.players.map(function (p) { return p.id; }).join(',');
  }

  function scrollCurrentRoundIntoView(cycle) {
    var wrap = document.querySelector('.leaderboard-wrap');
    var cell = document.getElementById('round-col-' + cycle);
    if (!wrap || !cell) return;
    cell.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'end' });
  }

  function rebuildLeaderboard(data) {
    var t = data.tournament;
    var d = data.display;
    var scoresByRound = data.player_scores_by_round;
    var minByRound = d.min_by_round;
    var curId = data.current_player ? parseInt(data.current_player.id, 10) : null;
    var upId = d.show_up_next && data.up_next ? parseInt(data.up_next.id, 10) : null;

    var theadRow = document.querySelector('.leaderboard thead tr');
    theadRow.querySelectorAll('.col-round').forEach(function (th) { th.remove(); });
    var r;
    for (r = 1; r <= d.max_round; r++) {
      var th = document.createElement('th');
      th.className = 'col-round';
      th.id = 'round-col-' + r;
      th.textContent = String(r);
      theadRow.appendChild(th);
    }

    var tbody = document.getElementById('leaderboard-body');
    tbody.innerHTML = '';
    d.players.forEach(function (p) {
      var pid = parseInt(p.id, 10);
      var tr = document.createElement('tr');
      tr.setAttribute('data-player-id', String(pid));
      if (curId && curId === pid) tr.classList.add('active-player');
      if (upId && upId === pid) tr.classList.add('up-next-player');

      function addTd(text, cls) {
        var td = document.createElement('td');
        if (cls) td.className = cls;
        td.textContent = text;
        return td;
      }

      tr.appendChild(addTd(String(p.queue_position), 'col-frozen'));
      tr.appendChild(addTd(p.display_name || '', 'col-frozen col-frozen-2'));
      tr.appendChild(addTd(String(p.chips_remaining), 'col-frozen col-frozen-3'));
      tr.appendChild(addTd('$' + String(p.first_five_amount != null ? p.first_five_amount : 0), 'col-frozen col-frozen-4'));
      tr.appendChild(addTd('$' + String(p.main_pot_amount != null ? p.main_pot_amount : 0), 'col-frozen col-frozen-5'));
      var out = parseInt(p.is_eliminated, 10) === 1;
      tr.appendChild(addTd(out ? 'OUT' : 'IN', 'col-frozen col-frozen-6' + (out ? ' out' : '')));

      var rowScores = scoresByRound[pid] || scoresByRound[String(pid)] || {};
      for (r = 1; r <= d.max_round; r++) {
        var val = rowScores[r] !== undefined ? rowScores[r] : rowScores[String(r)];
        var td = document.createElement('td');
        td.className = scoreClasses(val, r, minByRound);
        td.textContent = val === undefined || val === null ? '' : String(val);
        tr.appendChild(td);
      }
      tbody.appendChild(tr);
    });
  }

  function updateLeaderboardCells(data) {
    var d = data.display;
    var scoresByRound = data.player_scores_by_round;
    var minByRound = d.min_by_round;
    var curId = data.current_player ? parseInt(data.current_player.id, 10) : null;
    var upId = d.show_up_next && data.up_next ? parseInt(data.up_next.id, 10) : null;
    var tbody = document.getElementById('leaderboard-body');
    if (!tbody) return;

    var i;
    for (i = 0; i < d.players.length; i++) {
      var p = d.players[i];
      var pid = parseInt(p.id, 10);
      var row = tbody.querySelector('tr[data-player-id="' + pid + '"]');
      if (!row) {
        rebuildLeaderboard(data);
        scrollCurrentRoundIntoView(parseInt(data.tournament.current_cycle_number, 10));
        return;
      }
      row.className = '';
      if (curId && curId === pid) row.classList.add('active-player');
      if (upId && upId === pid) row.classList.add('up-next-player');

      var cells = row.children;
      cells[0].textContent = String(p.queue_position);
      cells[1].textContent = p.display_name || '';
      cells[2].textContent = String(p.chips_remaining);
      cells[3].textContent = '$' + String(p.first_five_amount != null ? p.first_five_amount : 0);
      cells[4].textContent = '$' + String(p.main_pot_amount != null ? p.main_pot_amount : 0);
      var out = parseInt(p.is_eliminated, 10) === 1;
      cells[5].textContent = out ? 'OUT' : 'IN';
      cells[5].className = 'col-frozen col-frozen-6' + (out ? ' out' : '');

      var rowScores = scoresByRound[pid] || scoresByRound[String(pid)] || {};
      for (var r = 1; r <= d.max_round; r++) {
        var val = rowScores[r] !== undefined ? rowScores[r] : rowScores[String(r)];
        var td = cells[5 + r];
        if (!td) continue;
        td.className = scoreClasses(val, r, minByRound);
        td.textContent = val === undefined || val === null ? '' : String(val);
      }
    }
  }

  function applyState(data) {
    if (!data) return;
    var t = data.tournament;
    var d = data.display;
    expiresAt = t.current_turn_expires_at || null;
    breakStartedAt = t.break_started_at || null;

    var pausedEl = document.getElementById('paused-banner');
    if (pausedEl) pausedEl.style.display = parseInt(t.paused, 10) === 1 ? 'block' : 'none';

    document.getElementById('disp-title').textContent = t.name || '';
    document.getElementById('disp-main-pot').textContent = 'Current Pot: $' + String(data.computed_main_pot != null ? data.computed_main_pot : t.current_pot);
    document.getElementById('disp-first-pot').textContent = 'First ' + chipsPerPlayer + ' Pot: $' + String(data.computed_first_five_pot != null ? data.computed_first_five_pot : (t.first_five_round_pot || 0));
    document.getElementById('disp-round').textContent = 'Current Round: ' + String(t.current_cycle_number != null ? t.current_cycle_number : 1);

    var cur = data.current_player;
    document.getElementById('disp-current-name').textContent = cur && cur.display_name ? cur.display_name : 'Waiting...';

    var upLabel = document.getElementById('disp-up-label');
    var upName = document.getElementById('disp-up-name');
    if (d.show_up_next && data.up_next) {
      upLabel.textContent = 'Up Next';
      upName.textContent = data.up_next.display_name || '';
    } else {
      upLabel.textContent = '';
      upName.textContent = 'End of Round';
    }

    if (!d) return;
    var sk = structureKey(data);
    if (sk !== lastStructureKey) {
      lastStructureKey = sk;
      var wrap = document.querySelector('.leaderboard-wrap');
      var sl = wrap ? wrap.scrollLeft : 0;
      rebuildLeaderboard(data);
      if (wrap) wrap.scrollLeft = sl;
      scrollCurrentRoundIntoView(parseInt(t.current_cycle_number, 10));
      return;
    }
    updateLeaderboardCells(data);
  }

  function poll() {
    fetch(STATE_URL, { cache: 'no-store' }).then(function (r) {
      if (!r.ok) return;
      return r.json();
    }).then(function (data) {
      if (!data) return;
      applyState(data);
    }).catch(function () {});
  }

  setInterval(tick, 250);
  tick();
  setInterval(poll, 5000);
  poll();

  scrollCurrentRoundIntoView(<?= (int) $currentCycle ?>);
})();
</script>
</body>
</html>
