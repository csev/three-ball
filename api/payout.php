<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

if (!auth_valid()) {
    header('Location: ../control.php');
    exit;
}

$tournament = active_tournament();
if (!$tournament) {
    header('Location: ../control.php');
    exit;
}

$amount = isset($_POST['amount']) ? (int) $_POST['amount'] : 0;
if ($amount <= 0) {
    header('Location: ../control.php');
    exit;
}

$tournamentId = (int) $tournament['id'];
$currentPot = (int) $tournament['current_pot'];
$newPot = max(0, $currentPot - $amount);

$stmt = db()->prepare('UPDATE tournaments SET current_pot = ? WHERE id = ?');
$stmt->execute([$newPot, $tournamentId]);

header('Location: ../control.php');
exit;
