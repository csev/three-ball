<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/rules.php';

$state = tournament_state();

if (isset($_GET['display']) && ($_GET['display'] === '1' || $_GET['display'] === 'true') && $state !== null) {
    $t = $state['tournament'];
    $tid = (int) $t['id'];
    $cycle = (int) ($t['current_cycle_number'] ?? 1);
    $maxR = tournament_max_round_column($tid, $cycle);
    $players = $state['players'];
    if (hide_out_players()) {
        $players = array_values(array_filter($players, fn($p) => !(int) ($p['is_eliminated'] ?? 0)));
    }
    $scoresByRound = $state['player_scores_by_round'];
    $minByRound = [];
    for ($r = 1; $r <= $maxR; $r++) {
        $scoresInRound = [];
        foreach ($players as $player) {
            $val = $scoresByRound[(int) $player['id']][$r] ?? '';
            if ($val !== '' && !in_array($val, ['TO', 'T.V.'], true) && ctype_digit((string) $val)) {
                $scoresInRound[] = (int) $val;
            }
        }
        $minByRound[$r] = $scoresInRound ? min($scoresInRound) : null;
    }
    $cur = $state['current_player'];
    $atEnd = $cur && is_current_last_in_round($tid, $cycle, (int) $cur['id']);
    $state['display'] = [
        'max_round' => $maxR,
        'show_up_next' => !empty($state['up_next']) && !$atEnd,
        'players' => $players,
        'min_by_round' => $minByRound,
    ];
}

header('Content-Type: application/json; charset=utf-8');
if (isset($_GET['display'])) {
    header('Cache-Control: no-store');
}
$flags = isset($_GET['display']) ? 0 : JSON_PRETTY_PRINT;
echo json_encode($state, $flags);
