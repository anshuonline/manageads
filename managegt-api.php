<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database configuration
// Change these when deploying to Hostinger
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "managegt_db";

$conn = new mysqli($db_host, $db_user, $db_pass);
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit();
}

// Create database if not exists
$conn->query("CREATE DATABASE IF NOT EXISTS $db_name");
$conn->select_db($db_name);

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
    $hashed = password_hash('admin123', PASSWORD_DEFAULT);
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
        if (password_verify($password, $row['password'])) {
            echo json_encode(["status" => "success", "token" => "gt-auth-token-".time()]);
            exit();
        }
    }
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Invalid credentials"]);
    exit();
}

$file_path = "custom-sections.json";

if ($action === 'get_sections') {
    if (file_exists($file_path)) {
        echo file_get_contents($file_path);
    } else {
        // Return empty object if doesn't exist
        echo json_encode(new stdClass());
    }
    exit();
}

if ($action === 'save_sections' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // A real app should verify the token here. We keep it simple.
    if (!isset($data['sectionsData'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid payload"]);
        exit();
    }
    
    $success = file_put_contents($file_path, json_encode($data['sectionsData'], JSON_PRETTY_PRINT));
    if ($success !== false) {
        echo json_encode(["status" => "success", "message" => "Sections updated successfully!"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to write file."]);
    }
    exit();
}

http_response_code(404);
echo json_encode(["status" => "error", "message" => "Action not found"]);
?>
