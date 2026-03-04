<?php
require_once __DIR__ . '/../api/dbConnect.php';

$pdo = getPDO();

$password = "TempPass!2026";
$email = "steve@christysigns.com";

$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $pdo->prepare("
    UPDATE tblContacts
    SET passwordHash = :hash
    WHERE contactEmail = :email
    LIMIT 1
");

$stmt->execute([
    "hash" => $hash,
    "email" => $email
]);

echo "Password updated\n";
echo "Hash: " . $hash . "\n";
echo "Length: " . strlen($hash) . "\n";