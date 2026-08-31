<?php
require_once 'config.php';
$res = $conn->query("SHOW COLUMNS FROM users");
if($res) {
    while($row = $res->fetch_assoc()) {
        echo $row['Field'] . ' - ' . $row['Type'] . "\n";
    }
} else {
    echo "Query failed: " . $conn->error;
}
$conn->close();
?>
