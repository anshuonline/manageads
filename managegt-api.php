<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$action = $_GET['action'] ?? '';
$json_input = file_get_contents('php://input');
$data = json_decode($json_input, true) ?: [];

// ── Fetch Spotify Playlist (No DB Needed - Fast & Direct) ─────────────────
if ($action === 'fetch_spotify_playlist') {
    $input = $_GET['url'] ?? ($data['url'] ?? ($_GET['id'] ?? ($data['id'] ?? '')));
    $input = trim($input);

    if (empty($input)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Please provide a Spotify playlist URL or ID"]);
        exit();
    }

    // Extract Playlist ID from URL or ID string
    $playlistId = '';
    if (preg_match('/playlist\/([a-zA-Z0-9]+)/', $input, $m)) {
        $playlistId = $m[1];
    } elseif (preg_match('/^spotify:playlist:([a-zA-Z0-9]+)$/', $input, $m)) {
        $playlistId = $m[1];
    } elseif (preg_match('/^[a-zA-Z0-9]{22}$/', $input)) {
        $playlistId = $input;
    }

    if (empty($playlistId)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid Spotify playlist link or ID"]);
        exit();
    }

    $url = "https://open.spotify.com/embed/playlist/" . $playlistId;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($html)) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Could not reach Spotify or playlist is private/not found (HTTP $httpCode)"]);
        exit();
    }

    if (preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $m)) {
        $decoded = json_decode($m[1], true);
        $entity = $decoded['props']['pageProps']['state']['data']['entity'] ?? null;
        
        if ($entity) {
            $title = $entity['title'] ?? 'Spotify Playlist';
            
            // Pick highest resolution cover image
            $coverImage = '';
            if (!empty($entity['visualIdentity']['image']) && is_array($entity['visualIdentity']['image'])) {
                $images = $entity['visualIdentity']['image'];
                $bestImg = $images[0]['url'] ?? '';
                foreach ($images as $img) {
                    if (($img['maxWidth'] ?? 0) >= 300) {
                        $bestImg = $img['url'];
                    }
                }
                $coverImage = $bestImg;
            }

            $trackList = $entity['trackList'] ?? [];
            $tracks = [];
            foreach ($trackList as $item) {
                $trackTitle = trim($item['title'] ?? '');
                $artist = trim($item['subtitle'] ?? '');
                if (!empty($trackTitle)) {
                    $artist = str_replace("\xc2\xa0", ' ', $artist);
                    $tracks[] = [
                        'title' => $trackTitle,
                        'artist' => $artist,
                        'query' => trim($trackTitle . ' ' . $artist)
                    ];
                }
            }

            echo json_encode([
                "status" => "success",
                "id" => $playlistId,
                "title" => $title,
                "coverImage" => $coverImage,
                "total" => count($tracks),
                "tracks" => $tracks
            ]);
            exit();
        } else {
            $pageStatus = $decoded['props']['pageProps']['status'] ?? 'unknown';
            echo json_encode(["status" => "error", "message" => "Spotify playlist not found or private (Status: $pageStatus)"]);
            exit();
        }
    }

    echo json_encode(["status" => "error", "message" => "Could not parse Spotify playlist data"]);
    exit();
}

require_once 'config.php';

// Set Indian Timezone globally for PHP and MySQL
date_default_timezone_set('Asia/Kolkata');
$conn->query("SET time_zone = '+05:30'");

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

// ── App Settings (sections, popups, header, etc.) ──────────────────────────
// One-time table setup: only runs via ?setup=1
if (isset($_GET['setup']) && $_GET['setup'] === '1') {
    $conn->query("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value LONGTEXT
    )");
    echo json_encode(["status" => "success", "message" => "app_settings table created/verified."]);
    $conn->close();
    exit();
}

// ── Combined App Init (Performance: 4 queries in 1 request) ────────────────
if ($action === 'app_init') {
    $result = [];
    // Sections
    $res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'custom_sections'");
    $result['sections'] = ($res && $res->num_rows > 0) ? json_decode($res->fetch_assoc()['setting_value'], true) : new stdClass();
    // Playlists
    $res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'custom_playlists'");
    $result['playlists'] = ($res && $res->num_rows > 0) ? json_decode($res->fetch_assoc()['setting_value'], true) : new stdClass();
    // Header
    $res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'custom_header'");
    $result['header'] = ($res && $res->num_rows > 0) ? json_decode($res->fetch_assoc()['setting_value'], true) : new stdClass();
    // Popups
    $res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'custom_popups'");
    $result['popups'] = ($res && $res->num_rows > 0) ? json_decode($res->fetch_assoc()['setting_value'], true) : new stdClass();

    echo json_encode($result);
    $conn->close();
    exit();
}

// ── Sections ────────────────────────────────────────────────────────────────

if ($action === 'get_sections') {
    $res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'custom_sections'");
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        echo $row['setting_value'];
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
    $json_data = $conn->real_escape_string(json_encode($data['sectionsData']));
    $sql = "INSERT INTO app_settings (setting_key, setting_value) VALUES ('custom_sections', '$json_data') 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    if ($conn->query($sql) === TRUE) {
        echo json_encode(["status" => "success", "message" => "Sections updated successfully!"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error"]);
    }
    exit();
}

if ($action === 'get_roombots') {
    $res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'roombots_config'");
    if ($res->num_rows > 0) {
        echo $res->fetch_assoc()['setting_value'];
    } else {
        echo json_encode([]);
    }
    exit();
}

if ($action === 'save_roombots' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($data['roombotsData'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid payload"]);
        exit();
    }
    $json_data = $conn->real_escape_string(json_encode($data['roombotsData']));
    $sql = "INSERT INTO app_settings (setting_key, setting_value) VALUES ('roombots_config', '$json_data') 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    if ($conn->query($sql) === TRUE) {
        echo json_encode(["status" => "success", "message" => "RoomBots config updated successfully!"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error"]);
    }
    exit();
}

if ($action === 'get_discovery') {
    $res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'discovery_songs'");
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        echo $row['setting_value'];
    } else {
        echo json_encode([]);
    }
    exit();
}

if ($action === 'save_discovery' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($data['discoveryData'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid payload"]);
        exit();
    }
    $json_data = $conn->real_escape_string(json_encode($data['discoveryData']));
    $sql = "INSERT INTO app_settings (setting_key, setting_value) VALUES ('discovery_songs', '$json_data') 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    if ($conn->query($sql) === TRUE) {
        echo json_encode(["status" => "success", "message" => "Discovery songs updated!"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to write discovery songs to database."]);
    }
    exit();
}

if ($action === 'submit_feedback' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rating = (int)($data['rating'] ?? 0);
    $suggestion = $conn->real_escape_string($data['suggestion'] ?? '');
    $user_name = $conn->real_escape_string($data['user_name'] ?? 'Guest');

    // Get IP
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ip = explode(',', $ip)[0];
    
    // Fetch Location
    $location = 'Unknown';
    if ($ip && $ip !== '::1' && $ip !== '127.0.0.1') {
        $ch = curl_init("http://ip-api.com/json/" . trim($ip));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res) {
            $geo = json_decode($res, true);
            if ($geo && isset($geo['status']) && $geo['status'] === 'success') {
                $location = $geo['city'] . ', ' . $geo['regionName'] . ', ' . $geo['country'];
            }
        }
    }
    
    $location = $conn->real_escape_string($location);

    // Make sure table and column exist, but suppress exceptions if they already do (PHP 8.1+ throws fatal exceptions on SQL errors)
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS user_feedback (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rating INT DEFAULT 0,
            suggestion TEXT,
            user_name VARCHAR(255) DEFAULT 'Guest',
            location VARCHAR(255) DEFAULT 'Unknown',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        $conn->query("ALTER TABLE user_feedback CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $conn->query("ALTER TABLE user_feedback ADD COLUMN location VARCHAR(255) DEFAULT 'Unknown'");
    } catch (Exception $e) {
        // Ignore errors if table/column already exists
    }

    $sql = "INSERT INTO user_feedback (rating, suggestion, user_name, location) VALUES ($rating, '$suggestion', '$user_name', '$location')";
    if ($conn->query($sql) === TRUE) {
        echo json_encode(["status" => "success", "message" => "Feedback saved successfully"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to save feedback", "sql_error" => $conn->error]);
    }
    exit();
}

// ── Submit Feature Request ───────────────────────────────────────────────────
if ($action === 'submit_feature_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($conn->real_escape_string($data['title'] ?? ''));
    $description = trim($conn->real_escape_string($data['description'] ?? ''));
    $category = trim($conn->real_escape_string($data['category'] ?? 'General'));
    $user_name = trim($conn->real_escape_string($data['user_name'] ?? 'Guest'));
    $user_email = trim($conn->real_escape_string($data['user_email'] ?? ''));

    if (empty($title) || empty($description)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Feature title and description are required"]);
        exit();
    }

    // Get IP
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ip = explode(',', $ip)[0];
    
    // Fetch Location
    $location = 'Unknown';
    if ($ip && $ip !== '::1' && $ip !== '127.0.0.1') {
        $ch = curl_init("http://ip-api.com/json/" . trim($ip));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res) {
            $geo = json_decode($res, true);
            if ($geo && isset($geo['status']) && $geo['status'] === 'success') {
                $location = $geo['city'] . ', ' . $geo['regionName'] . ', ' . $geo['country'];
            }
        }
    }
    
    $location = $conn->real_escape_string($location);

    // Auto-create table if not exists
    try {
        $conn->query("CREATE TABLE IF NOT EXISTS feature_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            category VARCHAR(100) DEFAULT 'General',
            user_name VARCHAR(255) DEFAULT 'Guest',
            user_email VARCHAR(255) DEFAULT '',
            status ENUM('pending', 'planned', 'in_progress', 'completed', 'declined') DEFAULT 'pending',
            admin_notes TEXT,
            ip_address VARCHAR(50) DEFAULT '',
            location VARCHAR(255) DEFAULT 'Unknown',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        // Ignore table exists error
    }

    $sql = "INSERT INTO feature_requests (title, description, category, user_name, user_email, ip_address, location) 
            VALUES ('$title', '$description', '$category', '$user_name', '$user_email', '$ip', '$location')";
    
    if ($conn->query($sql) === TRUE) {
        echo json_encode([
            "status" => "success", 
            "message" => "Feature request submitted successfully",
            "id" => $conn->insert_id
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "status" => "error", 
            "message" => "Failed to save feature request", 
            "sql_error" => $conn->error
        ]);
    }
    exit();
}

// ── Playlists ────────────────────────────────────────────────────────────────

if ($action === 'get_playlists') {
    $res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'custom_playlists'");
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        echo $row['setting_value'];
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
    $json_data = $conn->real_escape_string(json_encode($data['playlistsData']));
    $sql = "INSERT INTO app_settings (setting_key, setting_value) VALUES ('custom_playlists', '$json_data') 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    if ($conn->query($sql) === TRUE) {
        echo json_encode(["status" => "success", "message" => "Playlists saved successfully!"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to write playlists to database."]);
    }
    exit();
}

// ── Header ────────────────────────────────────────────────────────────────────

if ($action === 'get_header') {
    $res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'custom_header'");
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        echo $row['setting_value'];
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
    $json_data = $conn->real_escape_string(json_encode($data['headerData']));
    $sql = "INSERT INTO app_settings (setting_key, setting_value) VALUES ('custom_header', '$json_data') 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    if ($conn->query($sql) === TRUE) {
        echo json_encode(["status" => "success", "message" => "Header saved successfully!"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to write header to database."]);
    }
    exit();
}

// ── Custom Popups ─────────────────────────────────────────────────────────────

if ($action === 'get_popups') {
    $res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'custom_popups'");
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        echo $row['setting_value'];
    } else {
        echo json_encode(new stdClass());
    }
    exit();
}

if ($action === 'save_popups' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($data['popupsData'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid payload — missing popupsData"]);
        exit();
    }
    $json_data = $conn->real_escape_string(json_encode($data['popupsData']));
    $sql = "INSERT INTO app_settings (setting_key, setting_value) VALUES ('custom_popups', '$json_data') 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    if ($conn->query($sql) === TRUE) {
        echo json_encode(["status" => "success", "message" => "Popups saved successfully!"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to write popups to database."]);
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

// ── Helper: Ensure Bot Tables Exist ───────────────────────────────────────────
function ensure_bot_tables_exist($conn) {
    static $checked = false;
    if ($checked) return;
    @$conn->query("CREATE TABLE IF NOT EXISTS bot_curated_playlists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        spotify_id VARCHAR(100) NOT NULL UNIQUE,
        spotify_url VARCHAR(255) NOT NULL,
        title VARCHAR(255) NOT NULL,
        type ENUM('playlist', 'album') DEFAULT 'playlist',
        language VARCHAR(50) DEFAULT 'Hindi',
        cover_image TEXT,
        total_songs INT DEFAULT 0,
        songs LONGTEXT,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_lang_status (language, status),
        INDEX idx_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $checked = true;
}

// ── Bot Playlists: Get ────────────────────────────────────────────────────────
if ($action === 'get_bot_playlists') {
    ensure_bot_tables_exist($conn);
    $status = $_GET['status'] ?? 'all';
    $lang = $_GET['lang'] ?? 'all';
    
    $where = [];
    if ($status !== 'all') {
        $st = $conn->real_escape_string($status);
        $where[] = "status = '$st'";
    }
    if ($lang !== 'all') {
        $l = $conn->real_escape_string($lang);
        $where[] = "language = '$l'";
    }
    
    // Also fetch custom_sections to flag inHomeSection
    $secRes = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'custom_sections'");
    $customSections = [];
    if ($secRes && $secRes->num_rows > 0) {
        $customSections = json_decode($secRes->fetch_assoc()['setting_value'], true) ?: [];
    }

    $whereClause = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";
    $sql = "SELECT id, spotify_id, spotify_url, title, type, language, cover_image, total_songs, songs, status, created_at, updated_at 
            FROM bot_curated_playlists $whereClause ORDER BY id DESC LIMIT 200";
    $res = $conn->query($sql);
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['songs'] = json_decode($row['songs'], true) ?: [];
            $pLang = $row['language'] ?: 'Hindi';
            $pId = (int)$row['id'];
            $pTitle = $row['title'];
            
            $inHome = false;
            if (isset($customSections[$pLang]) && is_array($customSections[$pLang])) {
                foreach ($customSections[$pLang] as $cs) {
                    if (($cs['botPlaylistId'] ?? 0) === $pId || strcasecmp($cs['title'] ?? '', $pTitle) === 0) {
                        $inHome = true;
                        break;
                    }
                }
            }
            $row['inHomeSection'] = $inHome;
            $rows[] = $row;
        }
    }
    echo json_encode(["status" => "success", "data" => $rows]);
    exit();
}

// ── Bot Playlists: Approve & Add to Home Section ─────────────────────────────
if ($action === 'approve_bot_playlist' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ensure_bot_tables_exist($conn);
    $id = (int)($data['id'] ?? 0);
    $lang = $conn->real_escape_string($data['language'] ?? 'Hindi');
    $title = isset($data['title']) ? $conn->real_escape_string(trim($data['title'])) : null;
    $addToHomeSection = !isset($data['addToHomeSection']) || !empty($data['addToHomeSection']);
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Missing playlist ID"]);
        exit();
    }
    
    $fetchRes = $conn->query("SELECT title, songs FROM bot_curated_playlists WHERE id = $id");
    if (!$fetchRes || $fetchRes->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Playlist not found"]);
        exit();
    }
    $plData = $fetchRes->fetch_assoc();
    $finalTitle = $title ?: $plData['title'];
    $songs = json_decode($plData['songs'], true) ?: [];
    
    $titleSql = $title ? ", title = '$title'" : "";
    $sql = "UPDATE bot_curated_playlists SET status = 'approved', language = '$lang' $titleSql WHERE id = $id";
    if ($conn->query($sql)) {
        $addedToSection = false;
        
        // Immediately add to Home Feed sections if requested
        if ($addToHomeSection && count($songs) >= 3) {
            $secRes = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'custom_sections'");
            $customSections = [];
            if ($secRes && $secRes->num_rows > 0) {
                $customSections = json_decode($secRes->fetch_assoc()['setting_value'], true) ?: [];
            }
            if (!isset($customSections[$lang])) {
                $customSections[$lang] = [];
            }
            
            // Check if section already exists
            $exists = false;
            foreach ($customSections[$lang] as &$cs) {
                if (strcasecmp($cs['title'] ?? '', $finalTitle) === 0 || ($cs['botPlaylistId'] ?? 0) === $id) {
                    $cs['songs'] = array_slice($songs, 0, 15);
                    $cs['isBot'] = true;
                    $cs['botPlaylistId'] = $id;
                    $exists = true;
                    $addedToSection = true;
                    break;
                }
            }
            unset($cs);
            
            if (!$exists) {
                array_unshift($customSections[$lang], [
                    'title' => $finalTitle,
                    'songs' => array_slice($songs, 0, 15),
                    'isBot' => true,
                    'botPlaylistId' => $id
                ]);
                $addedToSection = true;
            }
            
            // Enforce max 15 sections per language (trimming older bot sections only)
            $maxSections = 15;
            if (count($customSections[$lang]) > $maxSections) {
                $excess = count($customSections[$lang]) - $maxSections;
                $filtered = [];
                $removed = 0;
                for ($i = count($customSections[$lang]) - 1; $i >= 0; $i--) {
                    $s = $customSections[$lang][$i];
                    if (!empty($s['isBot']) && $removed < $excess) {
                        $removed++;
                        continue;
                    }
                    $filtered[] = $s;
                }
                $customSections[$lang] = array_reverse($filtered);
                if (count($customSections[$lang]) > $maxSections) {
                    $customSections[$lang] = array_slice($customSections[$lang], 0, $maxSections);
                }
            }
            
            $secJson = $conn->real_escape_string(json_encode($customSections));
            $conn->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('custom_sections', '$secJson')
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        }
        
        echo json_encode([
            "status" => "success", 
            "message" => "Playlist approved successfully!" . ($addedToSection ? " Added to Home Feed ($lang)." : ""),
            "addedToSection" => $addedToSection
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to approve playlist"]);
    }
    exit();
}

// ── Bot Playlists: Toggle Home Feed Section ───────────────────────────────────
if ($action === 'toggle_home_section' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ensure_bot_tables_exist($conn);
    $id = (int)($data['id'] ?? 0);
    $enable = !empty($data['enable']);
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Missing playlist ID"]);
        exit();
    }
    
    $res = $conn->query("SELECT title, language, songs FROM bot_curated_playlists WHERE id = $id");
    if (!$res || $res->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Playlist not found"]);
        exit();
    }
    $pl = $res->fetch_assoc();
    $lang = $pl['language'] ?: 'Hindi';
    $title = $pl['title'];
    $songs = json_decode($pl['songs'], true) ?: [];
    
    $secRes = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'custom_sections'");
    $customSections = [];
    if ($secRes && $secRes->num_rows > 0) {
        $customSections = json_decode($secRes->fetch_assoc()['setting_value'], true) ?: [];
    }
    if (!isset($customSections[$lang])) {
        $customSections[$lang] = [];
    }
    
    if ($enable) {
        $exists = false;
        foreach ($customSections[$lang] as &$cs) {
            if (strcasecmp($cs['title'] ?? '', $title) === 0 || ($cs['botPlaylistId'] ?? 0) === $id) {
                $cs['songs'] = array_slice($songs, 0, 15);
                $cs['isBot'] = true;
                $cs['botPlaylistId'] = $id;
                $exists = true;
                break;
            }
        }
        unset($cs);
        if (!$exists) {
            array_unshift($customSections[$lang], [
                'title' => $title,
                'songs' => array_slice($songs, 0, 15),
                'isBot' => true,
                'botPlaylistId' => $id
            ]);
        }
        if (count($customSections[$lang]) > 15) {
            $excess = count($customSections[$lang]) - 15;
            $filtered = [];
            $removed = 0;
            for ($i = count($customSections[$lang]) - 1; $i >= 0; $i--) {
                $s = $customSections[$lang][$i];
                if (!empty($s['isBot']) && $removed < $excess) {
                    $removed++;
                    continue;
                }
                $filtered[] = $s;
            }
            $customSections[$lang] = array_reverse($filtered);
            if (count($customSections[$lang]) > 15) {
                $customSections[$lang] = array_slice($customSections[$lang], 0, 15);
            }
        }
    } else {
        $customSections[$lang] = array_values(array_filter($customSections[$lang], function($cs) use ($title, $id) {
            if (($cs['botPlaylistId'] ?? 0) === $id) return false;
            if (strcasecmp($cs['title'] ?? '', $title) === 0 && !empty($cs['isBot'])) return false;
            return true;
        }));
    }
    
    $secJson = $conn->real_escape_string(json_encode($customSections));
    $conn->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('custom_sections', '$secJson')
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                  
    echo json_encode(["status" => "success", "inHomeSection" => $enable]);
    exit();
}

// ── Bot Playlists: Reject / Delete ────────────────────────────────────────────
if ($action === 'reject_bot_playlist' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ensure_bot_tables_exist($conn);
    $id = (int)($data['id'] ?? 0);
    $mode = $data['mode'] ?? 'delete';
    if (!$id) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Missing playlist ID"]);
        exit();
    }
    if ($mode === 'delete') {
        $conn->query("DELETE FROM bot_curated_playlists WHERE id = $id");
        echo json_encode(["status" => "success", "message" => "Playlist deleted"]);
    } else {
        $conn->query("UPDATE bot_curated_playlists SET status = 'rejected' WHERE id = $id");
        echo json_encode(["status" => "success", "message" => "Playlist rejected"]);
    }
    exit();
}

// ── Bot Playlists: Re-shuffle Songs ───────────────────────────────────────────
if ($action === 'shuffle_bot_playlist' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ensure_bot_tables_exist($conn);
    $id = (int)($data['id'] ?? 0);
    $res = $conn->query("SELECT songs FROM bot_curated_playlists WHERE id = $id");
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $songs = json_decode($row['songs'], true) ?: [];
        shuffle($songs);
        $json = $conn->real_escape_string(json_encode($songs));
        $conn->query("UPDATE bot_curated_playlists SET songs = '$json' WHERE id = $id");
        echo json_encode(["status" => "success", "message" => "Songs shuffled", "songs" => $songs]);
    } else {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Playlist not found"]);
    }
    exit();
}

// ── Bot Automation Config: Get ────────────────────────────────────────────────
if ($action === 'get_bot_config') {
    $res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'bot_automation_config'");
    $defaultCatalog = [
        // ── HINDI ──
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DX0XUfTFmNBRM", "name" => "Hot Hits Hindi", "defaultLang" => "Hindi", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DWZNJXX2UeBij", "name" => "Bollywood Butter", "defaultLang" => "Hindi", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DX6mtHgWvv6qT", "name" => "Hindi Romantic Hits", "defaultLang" => "Hindi", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DX8tZ9q9498t6", "name" => "Bollywood Dance Beats", "defaultLang" => "Hindi", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DX7rOY2t2wQUR", "name" => "Retro Classics Bollywood", "defaultLang" => "Hindi", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DXd8hn3lm57wa", "name" => "New Music Hindi", "defaultLang" => "Hindi", "type" => "playlist", "enabled" => true],
        
        // ── PUNJABI ──
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DWTqYqGLu7kTX", "name" => "RAP 91 Punjabi", "defaultLang" => "Punjabi", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DWXVJK4aT7pmk", "name" => "Hot Hits Punjabi", "defaultLang" => "Punjabi", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DWXIH9p38y8bH", "name" => "Punjabi 101", "defaultLang" => "Punjabi", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DX4O5xsX2eM5S", "name" => "Punjabi Swag", "defaultLang" => "Punjabi", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DZ06evO1VbT1Q", "name" => "Diljit Dosanjh Hits", "defaultLang" => "Punjabi", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DZ06evO16uK7Z", "name" => "Karan Aujla Essentials", "defaultLang" => "Punjabi", "type" => "playlist", "enabled" => true],

        // ── BHOJPURI ──
        ["url" => "https://open.spotify.com/playlist/5OpU68bGSGh1Tka774Z1Or", "name" => "Shilpi Raj Hit Songs", "defaultLang" => "Bhojpuri", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DZ06evO3d0Y1F", "name" => "Pawan Singh Superhits", "defaultLang" => "Bhojpuri", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DZ06evO1w4N3P", "name" => "Khesari Lal Yadav Hits", "defaultLang" => "Bhojpuri", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DXb4uJg0t6M5Q", "name" => "Bhojpuri Dhamaka Beats", "defaultLang" => "Bhojpuri", "type" => "playlist", "enabled" => true],

        // ── ENGLISH ──
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M", "name" => "Today's Top Hits", "defaultLang" => "English", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DWUa8ZRTfalHk", "name" => "Pop Rising", "defaultLang" => "English", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DXbYM3nMM0oPk", "name" => "Mega Hit Mix", "defaultLang" => "English", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DX2M1R2ehBRuh", "name" => "All Out 2020s", "defaultLang" => "English", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DX2L0iB23Enbq", "name" => "Viral Hits Global", "defaultLang" => "English", "type" => "playlist", "enabled" => true],

        // ── HARYANVI ──
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DXdK0W61n4n2e", "name" => "Haryanvi Hits", "defaultLang" => "Haryanvi", "type" => "playlist", "enabled" => true],
        ["url" => "https://open.spotify.com/playlist/37i9dQZF1DWYgL55Y9Q54E", "name" => "Desi Haryanvi Swag", "defaultLang" => "Haryanvi", "type" => "playlist", "enabled" => true]
    ];

    if ($res && $res->num_rows > 0) {
        $cfg = json_decode($res->fetch_assoc()['setting_value'], true) ?: [];
        // If config only has legacy 4 sources, automatically expand to the 24+ catalog
        if (empty($cfg['targetSpotifySources']) || count($cfg['targetSpotifySources']) <= 4) {
            $cfg['targetSpotifySources'] = $defaultCatalog;
            $cJson = $conn->real_escape_string(json_encode($cfg));
            $conn->query("UPDATE app_settings SET setting_value = '$cJson' WHERE setting_key = 'bot_automation_config'");
        }
        echo json_encode(["status" => "success", "config" => $cfg]);
    } else {
        $defaultConfig = [
            "isFullyAuto" => false,
            "syncIntervalMinutes" => 10,
            "maxSectionsPerLanguage" => 15,
            "lastRunTime" => null,
            "lastRunStatus" => "Never run",
            "targetSpotifySources" => $defaultCatalog
        ];
        echo json_encode(["status" => "success", "config" => $defaultConfig]);
    }
    exit();
}

// ── Bot Automation Config: Save ───────────────────────────────────────────────
if ($action === 'save_bot_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($data['config'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Missing config payload"]);
        exit();
    }
    $json = $conn->real_escape_string(json_encode($data['config']));
    $sql = "INSERT INTO app_settings (setting_key, setting_value) VALUES ('bot_automation_config', '$json') 
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
    if ($conn->query($sql)) {
        echo json_encode(["status" => "success", "message" => "Bot automation config saved!"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to save bot config"]);
    }
    exit();
}

// ── Public Curated Content (For /curated-playlists page) ──────────────────────
if ($action === 'get_public_curated_content') {
    ensure_bot_tables_exist($conn);
    $sql = "SELECT id, spotify_id, title, type, language, cover_image, total_songs, songs, updated_at 
            FROM bot_curated_playlists WHERE status = 'approved' ORDER BY updated_at DESC LIMIT 150";
    $res = $conn->query($sql);
    $items = [];
    $languages = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['songs'] = json_decode($row['songs'], true) ?: [];
            $lang = $row['language'] ?: 'Hindi';
            if (!in_array($lang, $languages)) {
                $languages[] = $lang;
            }
            $items[] = $row;
        }
    }
    echo json_encode([
        "status" => "success",
        "items" => $items,
        "languages" => $languages
    ]);
    exit();
}

// ── Trigger Manual Bot Run from Admin ─────────────────────────────────────────
if ($action === 'trigger_bot_run') {
    include_once 'spotify-bot-cron.php';
    exit();
}

http_response_code(404);
echo json_encode(["status" => "error", "message" => "Action not found"]);
?>
