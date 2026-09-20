<?php
// Enable CORS for Angular App
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// Handle Preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';
require_once 'mailer.php';

// Ensure welcome_email_sent column exists
$col_check = $conn->query("SHOW COLUMNS FROM user_profiles LIKE 'welcome_email_sent'");
if ($col_check && $col_check->num_rows == 0) {
    @$conn->query("ALTER TABLE user_profiles ADD COLUMN welcome_email_sent TINYINT(1) DEFAULT 0");
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'getProfile') {
    $email = isset($_GET['email']) ? $conn->real_escape_string(trim($_GET['email'])) : '';
    $name = isset($_GET['name']) ? $conn->real_escape_string(trim($_GET['name'])) : '';
    
    if (empty($email)) {
        echo json_encode(["status" => "error", "message" => "Email is required"]);
        exit;
    }

    $sql = "SELECT display_name, preferred_languages, liked_songs, recent_plays, listening_preferences, welcome_email_sent FROM user_profiles WHERE email = '$email'";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // If user never received the welcome email (e.g. first-time login), send it!
        if (empty($row['welcome_email_sent']) || (int)$row['welcome_email_sent'] === 0) {
            $displayName = !empty($name) ? $name : (!empty($row['display_name']) ? $row['display_name'] : '');
            GanaTubeMailer::sendWelcomeEmail($email, $displayName);
            $conn->query("UPDATE user_profiles SET welcome_email_sent = 1 WHERE email = '$email'");
        }

        echo json_encode([
            "status" => "success",
            "display_name" => $row['display_name'],
            "preferred_languages" => json_decode($row['preferred_languages']),
            "liked_songs" => json_decode($row['liked_songs']),
            "recent_plays" => json_decode($row['recent_plays']),
            "listening_preferences" => json_decode($row['listening_preferences'])
        ]);
    } else {
        // Auto-create user in DB on first-time signup
        $cleanName = !empty($name) ? "'$name'" : "NULL";
        $insert_sql = "INSERT INTO user_profiles (email, display_name, welcome_email_sent) VALUES ('$email', $cleanName, 1) ON DUPLICATE KEY UPDATE welcome_email_sent = 1";
        $conn->query($insert_sql);
        
        // Dispatch beautiful Hostinger SMTP welcome email
        GanaTubeMailer::sendWelcomeEmail($email, $name);
        
        echo json_encode([
            "status" => "success",
            "message" => "User created, welcome email sent, returning defaults.",
            "display_name" => !empty($name) ? $name : null,
            "preferred_languages" => null,
            "liked_songs" => null,
            "recent_plays" => null,
            "listening_preferences" => null
        ]);
    }
} 
elseif ($action === 'updateProfile') {
    $data = json_decode(file_get_contents("php://input"), true);
    
    $email = isset($data['email']) ? $conn->real_escape_string($data['email']) : '';
    if (empty($email)) {
        echo json_encode(["status" => "error", "message" => "Email is required"]);
        exit;
    }

    $preferred_languages = isset($data['preferred_languages']) ? $conn->real_escape_string(json_encode($data['preferred_languages'])) : '[]';
    $liked_songs = isset($data['liked_songs']) ? $conn->real_escape_string(json_encode($data['liked_songs'])) : '[]';
    $recent_plays = isset($data['recent_plays']) ? $conn->real_escape_string(json_encode($data['recent_plays'])) : '[]';
    $listening_preferences = isset($data['listening_preferences']) ? $conn->real_escape_string(json_encode($data['listening_preferences'])) : '[]';

    $sql = "INSERT INTO user_profiles (email, preferred_languages, liked_songs, recent_plays, listening_preferences) 
            VALUES ('$email', '$preferred_languages', '$liked_songs', '$recent_plays', '$listening_preferences')
            ON DUPLICATE KEY UPDATE 
            preferred_languages = VALUES(preferred_languages), 
            liked_songs = VALUES(liked_songs), 
            recent_plays = VALUES(recent_plays),
            listening_preferences = VALUES(listening_preferences)";

    if ($conn->query($sql) === TRUE) {
        echo json_encode(["status" => "success", "message" => "Profile updated successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Error updating profile: " . $conn->error]);
    }
} 
elseif ($action === 'updateUsername') {
    $data = json_decode(file_get_contents("php://input"), true);
    $email = isset($data['email']) ? $conn->real_escape_string($data['email']) : '';
    $display_name = isset($data['display_name']) ? $conn->real_escape_string($data['display_name']) : '';
    $auto = isset($data['auto']) ? $data['auto'] : false;
    
    if (empty($email) || empty($display_name)) {
        echo json_encode(["status" => "error", "message" => "Email and display_name are required"]);
        exit;
    }

    $base_name = $display_name;
    $success = false;
    $attempts = 0;
    
    while (!$success && $attempts < 10) {
        $sql = "INSERT INTO user_profiles (email, display_name) VALUES ('$email', '$display_name')
                ON DUPLICATE KEY UPDATE display_name = VALUES(display_name)";

        try {
            if ($conn->query($sql) === TRUE) {
                $success = true;
                echo json_encode(["status" => "success", "message" => "Username updated in DB", "display_name" => $display_name]);
                exit;
            }
        } catch (Exception $e) {
            if ($conn->errno == 1062 || (strpos($e->getMessage(), 'Duplicate entry') !== false)) {
                if ($auto) {
                    $display_name = $base_name . '-' . mt_rand(100, 9999);
                    $attempts++;
                } else {
                    echo json_encode(["status" => "error", "message" => "Username is already taken by another user."]);
                    exit;
                }
            } else {
                echo json_encode(["status" => "error", "message" => "Error updating username: " . $e->getMessage()]);
                exit;
            }
        }
    }
    
    if (!$success) {
        echo json_encode(["status" => "error", "message" => "Could not generate a unique username."]);
    }
}
elseif ($action === 'getAllUsers') {
    $sql = "SELECT email, preferred_languages, liked_songs, recent_plays, listening_preferences, created_at, updated_at FROM user_profiles ORDER BY created_at DESC";
    $result = $conn->query($sql);
    $users = [];

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $row['preferred_languages'] = json_decode($row['preferred_languages']);
            $row['liked_songs'] = json_decode($row['liked_songs']);
            $row['recent_plays'] = json_decode($row['recent_plays']);
            $row['listening_preferences'] = json_decode($row['listening_preferences']);
            $users[] = $row;
        }
    }
    
    echo json_encode([
        "status" => "success",
        "data" => $users
    ]);
}
elseif ($action === 'sendWelcomeEmail') {
    $data = json_decode(file_get_contents("php://input"), true);
    $email = isset($data['email']) ? $conn->real_escape_string(trim($data['email'])) : '';
    $name = isset($data['name']) ? $conn->real_escape_string(trim($data['name'])) : '';

    if (empty($email)) {
        echo json_encode(["status" => "error", "message" => "Email is required"]);
        exit;
    }

    $chk = $conn->query("SELECT welcome_email_sent, display_name FROM user_profiles WHERE email = '$email'");
    if ($chk && $chk->num_rows > 0) {
        $row = $chk->fetch_assoc();
        if ((int)$row['welcome_email_sent'] === 1) {
            echo json_encode(["status" => "success", "message" => "Welcome email already sent previously"]);
            exit;
        }
        $displayName = !empty($name) ? $name : (!empty($row['display_name']) ? $row['display_name'] : '');
    } else {
        $displayName = $name;
        $cleanName = !empty($name) ? "'$name'" : "NULL";
        $conn->query("INSERT IGNORE INTO user_profiles (email, display_name) VALUES ('$email', $cleanName)");
    }

    $mailRes = GanaTubeMailer::sendWelcomeEmail($email, $displayName);
    if ($mailRes['success']) {
        $conn->query("UPDATE user_profiles SET welcome_email_sent = 1 WHERE email = '$email'");
        echo json_encode(["status" => "success", "message" => "Welcome email sent successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => $mailRes['error']]);
    }
}
else {
    echo json_encode(["status" => "error", "message" => "Invalid action"]);
}

$conn->close();
?>
