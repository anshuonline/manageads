<?php
$db_host = "manageads.ganatube.in";
$db_user = "u388169091_un_manageadsdb";
$db_pass = "Ganatube1234@.com";
$db_name = "u388169091_dn_manageadsdb";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    echo "Connection failed: " . $conn->connect_error . "\n";
} else {
    echo "Connected successfully!\n";
    $tables = ['song_analytics', 'user_analytics', 'user_profiles', 'user_feedback', 'user_playlists', 'spin_history', 'indexed_tracks', 'saved_playlists', 'campaign_bookings'];

    $schema = [];
    foreach ($tables as $table) {
        $res = $conn->query("SHOW COLUMNS FROM " . $table);
        if ($res) {
            $cols = [];
            while($row = $res->fetch_assoc()) {
                $cols[] = $row['Field'] . ' (' . $row['Type'] . ')';
            }
            $schema[$table] = $cols;
        }
    }
    echo json_encode($schema, JSON_PRETTY_PRINT);
    $conn->close();
}
?>
