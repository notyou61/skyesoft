<?php

$config = parse_ini_file('/home/notyou64/secure/db.env');

if (!$config) {
    die("Database config parsing failed.");
}

$databaseConnection = new mysqli(
    $config['DB_HOST'],
    $config['DB_USER'],
    $config['DB_PASS'],
    $config['DB_NAME']
);

if ($databaseConnection->connect_errno) {
    die("Database connection failed: " . $databaseConnection->connect_errno);
}

$databaseConnection->set_charset($config['DB_CHARSET']);
