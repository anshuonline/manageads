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

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'getProfile') {
    $email = isset($_GET['email']) ? $conn->real_escape_string($_GET['email']) : '';
    
    if (empty($email)) {
        echo json_encode(["status" => "error", "message" => "Email is required"]);
        exit;
    }

    $sql = "SELECT preferred_languages, liked_songs, recent_plays, listening_preferences FROM user_profiles WHERE email = '$email'";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        echo json_encode([
            "status" => "success",
            "preferred_languages" => json_decode($row['preferred_languages']),
            "liked_songs" => json_decode($row['liked_songs']),
            "recent_plays" => json_decode($row['recent_plays']),
            "listening_preferences" => json_decode($row['listening_preferences'])
        ]);
    } else {
        echo json_encode([
            "status" => "success",
            "message" => "User not found, returning defaults.",
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
else {
    echo json_encode(["status" => "error", "message" => "Invalid action"]);
}

$conn->close();
?>
