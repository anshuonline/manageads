<?php
require 'config.php';

$sql = "CREATE TABLE IF NOT EXISTS user_profiles (
    email VARCHAR(255) PRIMARY KEY,
    preferred_languages JSON,
    liked_songs JSON,
    listening_preferences JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE) {
    echo "Table user_profiles created successfully or already exists.\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}
$conn->close();
?>
