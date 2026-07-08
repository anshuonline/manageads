<?php
require 'config.php';

$sql = "CREATE TABLE IF NOT EXISTS user_profiles (
    email VARCHAR(255) PRIMARY KEY,
    preferred_languages JSON,
    liked_songs JSON,
    recent_plays JSON,
    listening_preferences JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

// Also try to alter the table if it already exists, ignoring errors if column already exists
$alter_sql = "ALTER TABLE user_profiles ADD COLUMN recent_plays JSON AFTER liked_songs";
$conn->query($alter_sql);

if ($conn->query($sql) === TRUE) {
    echo "Table user_profiles created successfully or already exists.\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}

$settings_sql = "CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value LONGTEXT
)";

if ($conn->query($settings_sql) === TRUE) {
    echo "Table app_settings created successfully or already exists.\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}

$conn->close();
?>
