<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/helpers.php';

$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? ('?' . $_SERVER['QUERY_STRING']) : '';
$path = app_public_directory_path();
$target = $path . $qs;
header('Location: ' . $target);
exit;
