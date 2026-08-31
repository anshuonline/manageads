<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once 'config.php';

$query = "CREATE TABLE IF NOT EXISTS spin_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    result VARCHAR(50) NOT NULL,
    g_coins_won INT DEFAULT 0,
    spin_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_email) REFERENCES user_profiles(email) ON DELETE CASCADE
)";

if ($conn->query($query) === TRUE) {
    echo json_encode(["status" => "success", "message" => "Table spin_history created successfully."]);
} else {
    echo json_encode(["status" => "error", "message" => "Error creating table: " . $conn->error]);
}

$conn->close();
?>
