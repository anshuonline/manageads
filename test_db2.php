<?php
require 'config.php';
$res = $conn->query("SELECT first_seen, last_active FROM guest_analytics WHERE guest_id='guest_zla31cor_mu6z3791'");
echo json_encode($res->fetch_assoc());
?>
