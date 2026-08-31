<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once 'config.php';

$queries = [
    "ALTER TABLE users ADD COLUMN g_coins INT DEFAULT 0",
    "ALTER TABLE users ADD COLUMN spins_left INT DEFAULT 3",
    "ALTER TABLE users ADD COLUMN last_spin_reset DATE DEFAULT NULL"
];

$success = [];
$errors = [];

foreach ($queries as $q) {
    if ($conn->query($q) === TRUE) {
        $success[] = "Successfully executed: $q";
    } else {
        // 1060 is duplicate column name error in MySQL, we can ignore it
        if ($conn->errno == 1060) {
            $success[] = "Column already exists, skipped: $q";
        } else {
            $errors[] = "Error on '$q': " . $conn->error;
        }
    }
}

$conn->close();

echo json_encode([
    'success' => true,
    'messages' => $success,
    'errors' => $errors
]);
?>
