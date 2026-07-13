<?php
// Enable CORS for Angular App
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// Handle Preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';

// Auto-create table if it doesn't exist
$createTableSql = "CREATE TABLE IF NOT EXISTS user_playlists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    playlist_id VARCHAR(50) UNIQUE,
    email VARCHAR(255),
    playlist_name VARCHAR(255),
    is_public TINYINT(1) DEFAULT 0,
    songs JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
$conn->query($createTableSql);

$createSavedSql = "CREATE TABLE IF NOT EXISTS saved_playlists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255),
    playlist_id VARCHAR(50),
    saved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_save (email, playlist_id)
)";
$conn->query($createSavedSql);

$action = isset($_GET['action']) ? $_GET['action'] : '';

// Function to generate a unique playlist ID
function generatePlaylistId() {
    return 'pl_' . substr(md5(uniqid(mt_rand(), true)), 0, 16);
}

if ($action === 'getPlaylists') {
    $email = isset($_GET['email']) ? $conn->real_escape_string($_GET['email']) : '';
    
    if (empty($email)) {
        echo json_encode(["status" => "error", "message" => "Email is required"]);
        exit;
    }

    $sql = "
        SELECT p.playlist_id, p.playlist_name, p.is_public, p.songs, p.created_at, p.updated_at, 1 as is_owner, p.email as owner_email, u.display_name
        FROM user_playlists p 
        LEFT JOIN user_profiles u ON p.email = u.email
        WHERE p.email = '$email'
        UNION
        SELECT p.playlist_id, p.playlist_name, p.is_public, p.songs, s.saved_at as created_at, p.updated_at, 0 as is_owner, p.email as owner_email, u.display_name
        FROM saved_playlists s
        JOIN user_playlists p ON s.playlist_id = p.playlist_id
        LEFT JOIN user_profiles u ON p.email = u.email
        WHERE s.email = '$email'
        ORDER BY created_at DESC
    ";
    $result = $conn->query($sql);

    $playlists = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $ownerName = !empty($row['display_name']) ? $row['display_name'] : explode('@', $row['owner_email'])[0];
            $playlists[] = [
                "playlist_id" => $row['playlist_id'],
                "playlist_name" => $row['playlist_name'],
                "is_public" => (bool)$row['is_public'],
                "songs" => json_decode($row['songs']),
                "is_owner" => (bool)$row['is_owner'],
                "owner_email" => $row['owner_email'],
                "owner" => $ownerName,
                "created_at" => $row['created_at'],
                "updated_at" => $row['updated_at']
            ];
        }
    }
    
    echo json_encode([
        "status" => "success",
        "data" => $playlists
    ]);
} 
elseif ($action === 'getPublicPlaylist') {
    $playlist_id = isset($_GET['playlist_id']) ? $conn->real_escape_string($_GET['playlist_id']) : '';
    $email = isset($_GET['email']) ? $conn->real_escape_string($_GET['email']) : '';
    
    if (empty($playlist_id)) {
        echo json_encode(["status" => "error", "message" => "Playlist ID is required"]);
        exit;
    }

    $sql = "SELECT p.playlist_id, p.email, p.playlist_name, p.is_public, p.songs, p.created_at, p.updated_at, u.display_name 
            FROM user_playlists p 
            LEFT JOIN user_profiles u ON p.email = u.email 
            WHERE p.playlist_id = '$playlist_id'";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        if ((bool)$row['is_public'] || $row['email'] === $email) {
            $ownerName = !empty($row['display_name']) ? $row['display_name'] : explode('@', $row['email'])[0];
            echo json_encode([
                "status" => "success",
                "data" => [
                    "playlist_id" => $row['playlist_id'],
                    "playlist_name" => $row['playlist_name'],
                    "is_public" => (bool)$row['is_public'],
                    "songs" => json_decode($row['songs']),
                    "owner" => $ownerName, 
                    "owner_email" => $row['email'],
                    "created_at" => $row['created_at'],
                    "updated_at" => $row['updated_at']
                ]
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "This playlist is private"]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Playlist not found"]);
    }
}
elseif ($action === 'getAllPublicPlaylists') {
    // Optional: filter by search query
    $query = isset($_GET['q']) ? $conn->real_escape_string($_GET['q']) : '';
    
    $sql = "SELECT p.playlist_id, p.email, p.playlist_name, p.is_public, p.songs, p.created_at, p.updated_at, u.display_name 
            FROM user_playlists p 
            LEFT JOIN user_profiles u ON p.email = u.email 
            WHERE p.is_public = 1";
            
    if (!empty($query)) {
        $sql .= " AND p.playlist_name LIKE '%$query%'";
    }
    
    $sql .= " ORDER BY p.created_at DESC LIMIT 50";
    
    $result = $conn->query($sql);
    
    $playlists = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $ownerName = !empty($row['display_name']) ? $row['display_name'] : explode('@', $row['email'])[0];
            $playlists[] = [
                "playlist_id" => $row['playlist_id'],
                "playlist_name" => $row['playlist_name'],
                "is_public" => (bool)$row['is_public'],
                "songs" => json_decode($row['songs']),
                "owner" => $ownerName,
                "created_at" => $row['created_at'],
                "updated_at" => $row['updated_at']
            ];
        }
    }
    
    echo json_encode([
        "status" => "success",
        "data" => $playlists
    ]);
}
elseif ($action === 'createPlaylist') {
    $data = json_decode(file_get_contents("php://input"), true);
    
    $email = isset($data['email']) ? $conn->real_escape_string($data['email']) : '';
    $playlist_name = isset($data['playlist_name']) ? $conn->real_escape_string($data['playlist_name']) : 'New Playlist';
    $is_public = isset($data['is_public']) ? (int)$data['is_public'] : 0;
    $songs = isset($data['songs']) ? $conn->real_escape_string(json_encode($data['songs'])) : '[]';
    
    if (empty($email)) {
        echo json_encode(["status" => "error", "message" => "Email is required"]);
        exit;
    }

    $playlist_id = generatePlaylistId();
    $sql = "INSERT INTO user_playlists (playlist_id, email, playlist_name, is_public, songs) 
            VALUES ('$playlist_id', '$email', '$playlist_name', $is_public, '$songs')";

    if ($conn->query($sql) === TRUE) {
        echo json_encode([
            "status" => "success", 
            "message" => "Playlist created successfully",
            "data" => [
                "playlist_id" => $playlist_id,
                "playlist_name" => $playlist_name,
                "is_public" => (bool)$is_public,
                "songs" => json_decode(stripslashes($songs)),
                "created_at" => date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s")
            ]
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Error creating playlist: " . $conn->error]);
    }
} 
elseif ($action === 'updatePlaylist') {
    $data = json_decode(file_get_contents("php://input"), true);
    
    $playlist_id = isset($data['playlist_id']) ? $conn->real_escape_string($data['playlist_id']) : '';
    $email = isset($data['email']) ? $conn->real_escape_string($data['email']) : '';
    
    if (empty($playlist_id) || empty($email)) {
        echo json_encode(["status" => "error", "message" => "Playlist ID and Email are required"]);
        exit;
    }

    // Prepare update fields dynamically based on what was sent
    $updates = [];
    if (isset($data['playlist_name'])) {
        $updates[] = "playlist_name = '" . $conn->real_escape_string($data['playlist_name']) . "'";
    }
    if (isset($data['is_public'])) {
        $updates[] = "is_public = " . (int)$data['is_public'];
    }
    if (isset($data['songs'])) {
        $updates[] = "songs = '" . $conn->real_escape_string(json_encode($data['songs'])) . "'";
    }

    if (empty($updates)) {
        echo json_encode(["status" => "error", "message" => "No fields to update"]);
        exit;
    }

    $sql = "UPDATE user_playlists SET " . implode(', ', $updates) . " WHERE playlist_id = '$playlist_id' AND email = '$email'";

    if ($conn->query($sql) === TRUE) {
        // Fetch updated data to return
        $res = $conn->query("SELECT * FROM user_playlists WHERE playlist_id = '$playlist_id'");
        $row = $res->fetch_assoc();
        
        echo json_encode([
            "status" => "success", 
            "message" => "Playlist updated successfully",
            "data" => [
                "playlist_id" => $row['playlist_id'],
                "playlist_name" => $row['playlist_name'],
                "is_public" => (bool)$row['is_public'],
                "songs" => json_decode($row['songs']),
                "created_at" => $row['created_at'],
                "updated_at" => $row['updated_at']
            ]
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Error updating playlist: " . $conn->error]);
    }
} 
elseif ($action === 'deletePlaylist') {
    $data = json_decode(file_get_contents("php://input"), true);
    
    $playlist_id = isset($data['playlist_id']) ? $conn->real_escape_string($data['playlist_id']) : '';
    $email = isset($data['email']) ? $conn->real_escape_string($data['email']) : '';
    
    if (empty($playlist_id) || empty($email)) {
        echo json_encode(["status" => "error", "message" => "Playlist ID and Email are required"]);
        exit;
    }

    $sql = "DELETE FROM user_playlists WHERE playlist_id = '$playlist_id' AND email = '$email'";

    if ($conn->query($sql) === TRUE) {
        if ($conn->affected_rows > 0) {
            echo json_encode(["status" => "success", "message" => "Playlist deleted successfully"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Playlist not found or you don't have permission"]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Error deleting playlist: " . $conn->error]);
    }
}
elseif ($action === 'getPublicPlaylists') {
    // Optional endpoint for future feature: Get all public playlists
    $sql = "SELECT p.playlist_id, p.playlist_name, p.email, p.songs, p.created_at, p.updated_at, u.display_name 
            FROM user_playlists p 
            LEFT JOIN user_profiles u ON p.email = u.email 
            WHERE p.is_public = 1 
            ORDER BY p.created_at DESC LIMIT 50";
    $result = $conn->query($sql);
    $playlists = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $ownerName = !empty($row['display_name']) ? $row['display_name'] : explode('@', $row['email'])[0];
            $playlists[] = [
                "playlist_id" => $row['playlist_id'],
                "playlist_name" => $row['playlist_name'],
                "creator" => $ownerName,
                "songs" => json_decode($row['songs']),
                "created_at" => $row['created_at'],
                "updated_at" => $row['updated_at']
            ];
        }
    }
    echo json_encode([
        "status" => "success",
        "data" => $playlists
    ]);
}
elseif ($action === 'savePlaylist') {
    $data = json_decode(file_get_contents("php://input"), true);
    
    $playlist_id = isset($data['playlist_id']) ? $conn->real_escape_string($data['playlist_id']) : '';
    $email = isset($data['email']) ? $conn->real_escape_string($data['email']) : '';
    
    if (empty($playlist_id) || empty($email)) {
        echo json_encode(["status" => "error", "message" => "Playlist ID and Email are required"]);
        exit;
    }

    $sql = "INSERT INTO saved_playlists (email, playlist_id) VALUES ('$email', '$playlist_id')";

    if ($conn->query($sql) === TRUE) {
        echo json_encode(["status" => "success", "message" => "Playlist saved successfully"]);
    } else {
        if ($conn->errno == 1062) {
            echo json_encode(["status" => "success", "message" => "Playlist already saved"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Error saving playlist: " . $conn->error]);
        }
    }
}
elseif ($action === 'unsavePlaylist') {
    $data = json_decode(file_get_contents("php://input"), true);
    
    $playlist_id = isset($data['playlist_id']) ? $conn->real_escape_string($data['playlist_id']) : '';
    $email = isset($data['email']) ? $conn->real_escape_string($data['email']) : '';
    
    if (empty($playlist_id) || empty($email)) {
        echo json_encode(["status" => "error", "message" => "Playlist ID and Email are required"]);
        exit;
    }

    $sql = "DELETE FROM saved_playlists WHERE email = '$email' AND playlist_id = '$playlist_id'";

    if ($conn->query($sql) === TRUE) {
        echo json_encode(["status" => "success", "message" => "Playlist unsaved successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Error unsaving playlist: " . $conn->error]);
    }
}
else {
    echo json_encode(["status" => "error", "message" => "Invalid action"]);
}

$conn->close();
?>
