<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/helpers.php';
require_once dirname(__DIR__) . '/lib/rules.php';
header('Content-Type: application/json');
echo json_encode(tournament_state(), JSON_PRETTY_PRINT);
