<?php
if (file_exists(__DIR__ . '/../debug')) {
    ini_set('display_errors', false);
    error_reporting(E_ALL);
}

require __DIR__ . '/../templates/about.php';
