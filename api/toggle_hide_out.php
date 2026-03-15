<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/helpers.php';

if (!auth_valid()) {
    header('Location: ../control.php');
    exit;
}

set_hide_out_players(!hide_out_players());
header('Location: ../control.php');
exit;
