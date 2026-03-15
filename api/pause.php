<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

if (!auth_valid()) {
    header('Location: ../control.php');
    exit;
}

$_SESSION['tournament_paused'] = true;
header('Location: ../control.php');
exit;
