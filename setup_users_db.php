<?php
require 'config.php';

$sql = "CREATE TABLE IF NOT EXISTS user_profiles (
    email VARCHAR(255) PRIMARY KEY,
    display_name VARCHAR(255) UNIQUE,
    preferred_languages JSON,
    liked_songs JSON,
    recent_plays JSON,
    listening_preferences JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";

// Check if display_name column exists
$check_col = "SHOW COLUMNS FROM user_profiles LIKE 'display_name'";
$res_col = $conn->query($check_col);

if ($res_col && $res_col->num_rows == 0) {
    // Column doesn't exist, add it
    $conn->query("ALTER TABLE user_profiles ADD COLUMN display_name VARCHAR(255) UNIQUE AFTER email");
} else {
    // Column exists, check if UNIQUE index exists
    $check_idx = "SHOW INDEX FROM user_profiles WHERE Key_name = 'display_name'";
    $res_idx = $conn->query($check_idx);
    
    if ($res_idx && $res_idx->num_rows == 0) {
        // Fix duplicates by appending a random number to duplicate display_names before adding unique index
        $conn->query("UPDATE user_profiles p1
            JOIN (
                SELECT display_name, MIN(email) as min_email
                FROM user_profiles
                GROUP BY display_name
                HAVING COUNT(*) > 1
            ) p2 ON p1.display_name = p2.display_name AND p1.email != p2.min_email
            SET p1.display_name = CONCAT(p1.display_name, '-', FLOOR(RAND() * 10000))");
            
        // Now add the unique index
        $conn->query("ALTER TABLE user_profiles ADD UNIQUE (display_name)");
    }
}

$alter_sql2 = "ALTER TABLE user_profiles ADD COLUMN recent_plays JSON AFTER liked_songs";
$conn->query($alter_sql2);

if ($conn->query($sql) === TRUE) {
    echo "Table user_profiles created successfully or already exists.\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}

$settings_sql = "CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value LONGTEXT
)";

if ($conn->query($settings_sql) === TRUE) {
    echo "Table app_settings created successfully or already exists.\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
}

$conn->close();
?>
