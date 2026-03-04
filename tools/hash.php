<?php
declare(strict_types=1);

header("Content-Type: text/plain; charset=UTF-8");

// Change this ONE time, copy result, then delete this file.
$plaintextPassword = 'TempPass!2026';

$options = ['cost' => 12];

echo password_hash($plaintextPassword, PASSWORD_BCRYPT, $options);