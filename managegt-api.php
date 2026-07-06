<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// DB credentials & $conn are provided by config.php
require_once 'config.php';


// Create admins table if not exists
$table_sql = "CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
)";
$conn->query($table_sql);

// Check if default admin exists, if not create one
$result = $conn->query("SELECT * FROM admins WHERE username = 'admin'");
if ($result->num_rows === 0) {
    $hashed = md5('admin123');
    $conn->query("INSERT INTO admins (username, password) VALUES ('admin', '$hashed')");
}

$action = $_GET['action'] ?? '';
$json_input = file_get_contents('php://input');
$data = json_decode($json_input, true) ?: [];

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $conn->real_escape_string($data['username'] ?? '');
    $password = $data['password'] ?? '';
    
    $res = $conn->query("SELECT * FROM admins WHERE username = '$username'");
    if ($res->num_rows === 1) {
        $row = $res->fetch_assoc();
        if (md5($password) === $row['password']) {
            echo json_encode(["status" => "success", "token" => "gt-auth-token-".time()]);
            exit();
        }
    }
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Invalid credentials"]);
    exit();
}

$sections_file = "custom-sections.json";
$playlists_file = "custom-playlists.json";

// ── Sections ────────────────────────────────────────────────────────────────

if ($action === 'get_sections') {
    if (file_exists($sections_file)) {
        echo file_get_contents($sections_file);
    } else {
        echo json_encode(new stdClass());
    }
    exit();
}

if ($action === 'save_sections' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($data['sectionsData'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid payload"]);
        exit();
    }
    $success = file_put_contents($sections_file, json_encode($data['sectionsData'], JSON_PRETTY_PRINT));
    if ($success !== false) {
        echo json_encode(["status" => "success", "message" => "Sections updated successfully!"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to write sections file."]);
    }
    exit();
}

// ── Playlists ────────────────────────────────────────────────────────────────

if ($action === 'get_playlists') {
    if (file_exists($playlists_file)) {
        echo file_get_contents($playlists_file);
    } else {
        echo json_encode(new stdClass());
    }
    exit();
}

if ($action === 'save_playlists' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($data['playlistsData'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid payload — missing playlistsData"]);
        exit();
    }
    $success = file_put_contents($playlists_file, json_encode($data['playlistsData'], JSON_PRETTY_PRINT));
    if ($success !== false) {
        echo json_encode(["status" => "success", "message" => "Playlists saved successfully!"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to write playlists file."]);
    }
    exit();
}

http_response_code(404);
echo json_encode(["status" => "error", "message" => "Action not found"]);
?>
