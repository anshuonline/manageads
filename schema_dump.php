<?php
require 'config.php';

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
?>
