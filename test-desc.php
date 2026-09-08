<?php
require 'C:\xampp\htdocs\manageads\config.php';
$res = $conn->query("DESCRIBE user_feedback");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
