<?php
require_once 'config.php';

$sql = "CREATE TABLE IF NOT EXISTS user_playlists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    playlist_id VARCHAR(50) UNIQUE,
    email VARCHAR(255),
    playlist_name VARCHAR(255),
    songs JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

if ($conn->query($sql) === TRUE) {
    echo "Table user_playlists created successfully";
} else {
    echo "Error creating table: " . $conn->error;
}
$conn->close();
?>
