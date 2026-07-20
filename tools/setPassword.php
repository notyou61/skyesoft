<?php
require_once __DIR__ . '/../api/dbConnect.php';

$pdo = getPDO();

// Production bootstrap credentials
$email = "steve@christysigns.com";
$password = "Steven1!";

$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $pdo->prepare("
    UPDATE tblContacts
    SET
        passwordHash = :hash,
        isActive = 1
    WHERE contactEmail = :email
    LIMIT 1
");

$stmt->execute([
    "hash"  => $hash,
    "email" => $email
]);

echo "=========================================\n";
echo "Skyesoft Bootstrap Password Updated\n";
echo "=========================================\n";
echo "Email:    {$email}\n";
echo "Password: {$password}\n";
echo "Hash:     {$hash}\n";
echo "Length:   " . strlen($hash) . "\n";