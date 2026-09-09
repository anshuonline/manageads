<?php
$conn = mysqli_init();
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
if (!$conn->real_connect('manageads.ganatube.in', 'u388169091_un_manageadsdb', 'Ganatube1234@.com', 'u388169091_dn_manageadsdb')) {
    echo 'Error connecting to manageads.ganatube.in: ' . mysqli_connect_error() . "\n";
} else {
    echo "Connected successfully to manageads.ganatube.in\n";
    $conn->close();
}

$conn = mysqli_init();
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
if (!$conn->real_connect('91.108.106.85', 'u388169091_un_manageadsdb', 'Ganatube1234@.com', 'u388169091_dn_manageadsdb')) {
    echo 'Error connecting to 91.108.106.85: ' . mysqli_connect_error() . "\n";
} else {
    echo "Connected successfully to 91.108.106.85\n";
    $conn->close();
}

$conn = mysqli_init();
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
if (!$conn->real_connect('93.127.173.6', 'u388169091_un_manageadsdb', 'Ganatube1234@.com', 'u388169091_dn_manageadsdb')) {
    echo 'Error connecting to 93.127.173.6: ' . mysqli_connect_error() . "\n";
} else {
    echo "Connected successfully to 93.127.173.6\n";
    $conn->close();
}
?>
