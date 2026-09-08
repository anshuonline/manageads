<?php
require 'config.php';

// 1. Table for Song Analytics
$sql1 = "CREATE TABLE IF NOT EXISTS song_analytics (
    video_id VARCHAR(50) PRIMARY KEY,
    title VARCHAR(255),
    thumbnail VARCHAR(255),
    play_count INT DEFAULT 0,
    like_count INT DEFAULT 0,
    share_count INT DEFAULT 0,
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql1) === TRUE) {
    echo "Table song_analytics created successfully or already exists.\n";
} else {
    echo "Error creating table song_analytics: " . $conn->error . "\n";
}

// 2. Table for User Analytics
$sql2 = "CREATE TABLE IF NOT EXISTS user_analytics (
    email VARCHAR(255) PRIMARY KEY,
    display_name VARCHAR(255),
    total_time_spent_seconds INT DEFAULT 0,
    last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql2) === TRUE) {
    echo "Table user_analytics created successfully or already exists.\n";
} else {
    echo "Error creating table user_analytics: " . $conn->error . "\n";
}

$conn->close();
?>
