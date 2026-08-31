<?php
$conn = new mysqli('localhost', 'root', '', 'manageads');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->query("ALTER TABLE user_feedback ADD COLUMN location VARCHAR(255) DEFAULT 'Unknown'");
echo $conn->error;
echo "Done\n";
?>
