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

// ── Header ────────────────────────────────────────────────────────────────────

$header_file = "custom-header.json";

if ($action === 'get_header') {
    if (file_exists($header_file)) {
        echo file_get_contents($header_file);
    } else {
        echo json_encode(new stdClass());
    }
    exit();
}

if ($action === 'save_header' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($data['headerData'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid payload — missing headerData"]);
        exit();
    }
    $success = file_put_contents($header_file, json_encode($data['headerData'], JSON_PRETTY_PRINT));
    if ($success !== false) {
        echo json_encode(["status" => "success", "message" => "Header saved successfully!"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to write header file."]);
    }
    exit();
}

// ── Image Upload ──────────────────────────────────────────────────────────────

if ($action === 'upload_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "No image uploaded or upload error"]);
        exit();
    }

    $upload_dir = __DIR__ . '/uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $tmp_name = $_FILES['image']['tmp_name'];
    $file_info = getimagesize($tmp_name);
    if (!$file_info) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid image file."]);
        exit();
    }

    $mime = $file_info['mime'];
    $width = $file_info[0];
    $height = $file_info[1];

    // Create GD resource
    $image = null;
    switch ($mime) {
        case 'image/jpeg': $image = imagecreatefromjpeg($tmp_name); break;
        case 'image/png':  $image = imagecreatefrompng($tmp_name); break;
        case 'image/webp': $image = imagecreatefromwebp($tmp_name); break;
        case 'image/gif':  $image = imagecreatefromgif($tmp_name); break;
    }

    if (!$image) {
        // If not a supported type or SVG (which can't be processed by GD easily)
        // Just move the file directly without optimization
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $new_filename = uniqid('img_') . '.' . $ext;
        $target_path = $upload_dir . $new_filename;
        move_uploaded_file($tmp_name, $target_path);
    } else {
        // Optimization: Resize if width > 1200
        $max_width = 1200;
        if ($width > $max_width) {
            $ratio = $max_width / $width;
            $new_width = $max_width;
            $new_height = intval($height * $ratio);

            $new_image = imagecreatetruecolor($new_width, $new_height);
            // Handle transparency
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
            $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
            imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);

            imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
            imagedestroy($image);
            $image = $new_image;
        } else {
            // Even if not resized, handle transparency for conversion
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        // Always save as WebP for best compression
        $new_filename = uniqid('img_opt_') . '.webp';
        $target_path = $upload_dir . $new_filename;
        
        // 80 is the quality out of 100
        imagewebp($image, $target_path, 80);
        imagedestroy($image);
    }

    // Return absolute URL assuming manageads.ganatube.in
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    $url = $protocol . $host . '/uploads/' . $new_filename;
    
    echo json_encode(["status" => "success", "imageUrl" => $url]);
    exit();
}

http_response_code(404);
echo json_encode(["status" => "error", "message" => "Action not found"]);
?>
