<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_auth('edit.php');
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/rules.php';

$state = tournament_state();
if (!$state) {
    redirect_to('setup.php');
}

$t = $state['tournament'];
$players = $state['players'];
$scoresByRound = player_scores_by_round((int) $t['id']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit Tournament — Three-Ball</title>
<style>
body{font-family:Arial,sans-serif;background:#111;color:#f5f5f5;margin:0;padding:1rem}
.wrap{max-width:1400px;margin:0 auto}
h1{font-size:1.5rem;margin:0 0 1rem}
.card{background:#1e1e1e;border-radius:16px;padding:1.25rem;margin-bottom:1rem}
.edit-wrap{overflow-x:auto}
.edit-table{width:max-content;border-collapse:collapse;font-size:1rem}
.edit-table th,.edit-table td{border:1px solid #333;padding:.35rem .5rem}
.edit-table th{background:#2a2a2a;text-align:center;font-weight:600}
.edit-table input{width:2.5rem;padding:.25rem;font-size:1rem;text-align:center;background:#2a2a2a;border:1px solid #444;color:#fff;border-radius:4px}
.edit-table input.chips{width:3rem}
.edit-table input.pot-amt{width:4rem}
.edit-table input:focus{outline:none;border-color:#1976d2}
.edit-table .col-frozen,.edit-table .col-frozen-2,.edit-table .col-frozen-3,.edit-table .col-frozen-4,.edit-table .col-frozen-5,.edit-table .col-frozen-6{position:sticky;background:#1e1e1e;z-index:2}
.edit-table .col-frozen{left:0;min-width:2.5rem;box-shadow:2px 0 4px rgba(0,0,0,.3)}
.edit-table .col-frozen-2{left:2.5rem;min-width:6rem;box-shadow:2px 0 4px rgba(0,0,0,.3)}
.edit-table .col-frozen-3{left:8.5rem;min-width:2.5rem;box-shadow:2px 0 4px rgba(0,0,0,.3)}
.edit-table .col-frozen-4{left:11rem;min-width:3.5rem;box-shadow:2px 0 4px rgba(0,0,0,.3)}
.edit-table .col-frozen-5{left:14.5rem;min-width:3.5rem;box-shadow:2px 0 4px rgba(0,0,0,.3)}
.edit-table .col-frozen-6{left:18rem;min-width:2.5rem;box-shadow:2px 0 4px rgba(0,0,0,.3)}
.col-round{min-width:2.5rem}
.out{color:#ff8a80}
.actions{display:flex;gap:1rem;align-items:center;margin-top:1rem;flex-wrap:wrap}
button,a.btn{display:inline-block;padding:.6rem 1rem;border-radius:10px;font-size:1rem;cursor:pointer;text-decoration:none;border:none;background:#455a64;color:white;font:inherit}
button.primary{background:#2e7d32}
button.primary:hover,a.btn:hover{opacity:.9}
</style>
</head>
<body>
<div class="wrap">
<h1>Edit Tournament — <?= h($t['name']) ?></h1>
<p style="color:#999;font-size:.9rem">Edit chips, First 5 $, Main $, and round scores. Chips = 0 means OUT. Scores: 1–5 or TO (timeout). Pot origins set the starting pool; computed pot = origin minus total awarded.</p>

<form method="post" action="api/save_edit.php">
<div class="card" style="margin-bottom:1rem">
<div style="display:flex;flex-wrap:wrap;gap:2rem;align-items:center;padding-bottom:1rem;border-bottom:1px solid #333">
<label style="display:flex;align-items:center;gap:.5rem">Main Pot Origin: $<input type="number" name="starting_pot" min="0" value="<?= (int)($t['starting_pot'] ?? 0) ?>" style="width:5rem;padding:.4rem;font-size:1rem;background:#2a2a2a;border:1px solid #444;color:#fff;border-radius:6px"></label>
<label style="display:flex;align-items:center;gap:.5rem">First 5 Pot Origin: $<input type="number" name="starting_first_five_round_pot" min="0" value="<?= (int)($t['starting_first_five_round_pot'] ?? $t['first_five_round_pot'] ?? 0) ?>" style="width:5rem;padding:.4rem;font-size:1rem;background:#2a2a2a;border:1px solid #444;color:#fff;border-radius:6px"></label>
<label style="display:flex;align-items:center;gap:.5rem">Current Round: <input type="number" name="current_cycle_number" min="1" max="15" value="<?= (int)($t['current_cycle_number'] ?? 1) ?>" style="width:4rem;padding:.4rem;font-size:1rem;background:#2a2a2a;border:1px solid #444;color:#fff;border-radius:6px"></label>
</div>
</div>
<div class="card">
<div class="edit-wrap">
<table class="edit-table">
<thead>
<tr>
<th class="col-frozen">Pos</th>
<th class="col-frozen col-frozen-2">Player</th>
<th class="col-frozen col-frozen-3">Chips</th>
<th class="col-frozen col-frozen-4">First 5 $</th>
<th class="col-frozen col-frozen-5">Main $</th>
<th class="col-frozen col-frozen-6">Status</th>
<?php for ($r = 1; $r <= 15; $r++): ?><th class="col-round"><?= $r ?></th><?php endfor; ?>
</tr>
</thead>
<tbody>
<?php foreach ($players as $player):
    $pid = (int) $player['id'];
    $chips = (int) $player['chips_remaining'];
    $isOut = (int) $player['is_eliminated'];
    $scores = $scoresByRound[$pid] ?? [];
?>
<tr>
<td class="col-frozen"><?= h((string)$player['queue_position']) ?></td>
<td class="col-frozen col-frozen-2"><?= h($player['display_name']) ?></td>
<td class="col-frozen col-frozen-3"><input type="number" name="chips_<?= $pid ?>" class="chips" min="0" value="<?= $chips ?>" data-pid="<?= $pid ?>"></td>
<td class="col-frozen col-frozen-4"><input type="number" name="first_five_amount_<?= $pid ?>" class="pot-amt" min="0" value="<?= (int)($player['first_five_amount'] ?? 0) ?>"></td>
<td class="col-frozen col-frozen-5"><input type="number" name="main_pot_amount_<?= $pid ?>" class="pot-amt" min="0" value="<?= (int)($player['main_pot_amount'] ?? 0) ?>"></td>
<td class="col-frozen col-frozen-6 status-cell <?= $isOut ? 'out' : '' ?>" data-pid="<?= $pid ?>"><?= $chips > 0 ? 'IN' : 'OUT' ?></td>
<?php for ($r = 1; $r <= 15; $r++):
    $val = $scores[$r] ?? '';
?><td class="col-round"><input type="text" name="score_<?= $pid ?>_<?= $r ?>" value="<?= h($val) ?>" placeholder="—" maxlength="3" title="1–5 or TO"></td>
<?php endfor; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div class="actions">
<button type="submit" class="primary">Save</button>
<a href="control.php" class="btn">Back to Control</a>
</div>
</div>
</form>
</div>
<script>
document.querySelectorAll('input.chips').forEach(function(inp) {
  inp.addEventListener('input', function() {
    var pid = this.dataset.pid;
    var val = parseInt(this.value, 10) || 0;
    var cell = document.querySelector('.status-cell[data-pid="' + pid + '"]');
    if (cell) cell.textContent = val > 0 ? 'IN' : 'OUT';
    if (cell) cell.classList.toggle('out', val <= 0);
  });
});
</script>
</body>
</html>
