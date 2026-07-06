<?php
// Production Credentials
$db_host = "localhost";
$db_user = "u388169091_un_manageadsdb";
$db_pass = "Ganatube1234@.com";
$db_name = "u388169091_dn_manageadsdb";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Database connection failed.");
}
?>
