<?php
// The plain-text password submitted by the user
$submittedPassword = 'TempPass!2026';

// The hash retrieved from your database
$storedHash = '$2y$10$3Xv0/1EU7De/6se.Jv9Zau4kWGeEv6M9gpNJ6xUJWHHzDxPXHkSpi';

// Verify the password
if (password_verify($submittedPassword, $storedHash)) {
    echo 'Password is valid!';

    // Optional: Check if the password needs rehashing (e.g., if the cost factor was updated)
    if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
        // Create a new hash and update the user record in the database
        $newHash = password_hash($submittedPassword, PASSWORD_DEFAULT);
        // ... (code to update database) ...
    }

} else {
    echo 'Invalid password.';
}