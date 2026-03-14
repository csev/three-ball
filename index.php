<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
$tournament = active_tournament();
if ($tournament) {
    redirect_to('display.php');
}
redirect_to('setup.php');
