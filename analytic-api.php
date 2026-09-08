<?php
require 'config.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// Function to handle database errors
function returnError($message, $error = null) {
    echo json_encode(['status' => 'error', 'message' => $message, 'error' => $error]);
    exit();
}

// Ensure payload exists
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, TRUE);

if ($action === 'recordPlay') {
    $video_id = $input['video_id'] ?? null;
    $title = $input['title'] ?? '';
    $thumbnail = $input['thumbnail'] ?? '';
    
    if (!$video_id) returnError("video_id required");
    
    $stmt = $conn->prepare("INSERT INTO song_analytics (video_id, title, thumbnail, play_count) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE play_count = play_count + 1, title = VALUES(title), thumbnail = VALUES(thumbnail)");
    $stmt->bind_param("sss", $video_id, $title, $thumbnail);
    $stmt->execute();
    
    echo json_encode(['status' => 'success']);
} 

elseif ($action === 'recordLike') {
    $video_id = $input['video_id'] ?? null;
    $title = $input['title'] ?? '';
    $thumbnail = $input['thumbnail'] ?? '';
    
    if (!$video_id) returnError("video_id required");
    
    $stmt = $conn->prepare("INSERT INTO song_analytics (video_id, title, thumbnail, like_count) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE like_count = like_count + 1, title = VALUES(title), thumbnail = VALUES(thumbnail)");
    $stmt->bind_param("sss", $video_id, $title, $thumbnail);
    $stmt->execute();
    
    echo json_encode(['status' => 'success']);
}

elseif ($action === 'recordShare') {
    $video_id = $input['video_id'] ?? null;
    $title = $input['title'] ?? '';
    $thumbnail = $input['thumbnail'] ?? '';
    
    if (!$video_id) returnError("video_id required");
    
    $stmt = $conn->prepare("INSERT INTO song_analytics (video_id, title, thumbnail, share_count) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE share_count = share_count + 1, title = VALUES(title), thumbnail = VALUES(thumbnail)");
    $stmt->bind_param("sss", $video_id, $title, $thumbnail);
    $stmt->execute();
    
    echo json_encode(['status' => 'success']);
}

elseif ($action === 'recordTime') {
    $email = $input['email'] ?? null;
    $seconds = (int)($input['seconds'] ?? 0);
    $display_name = $input['display_name'] ?? 'Unknown User';
    
    if (!$email || $seconds <= 0) returnError("Valid email and seconds required");
    
    $stmt = $conn->prepare("INSERT INTO user_analytics (email, display_name, total_time_spent_seconds) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE total_time_spent_seconds = total_time_spent_seconds + ?, display_name = VALUES(display_name)");
    $stmt->bind_param("ssii", $email, $display_name, $seconds, $seconds);
    $stmt->execute();
    
    echo json_encode(['status' => 'success']);
}

elseif ($action === 'getAnalytics') {
    $pwd = $_GET['pwd'] ?? '';
    
    // Use same admin password from admin_settings table (shared with managegt)
    $res = $conn->query("SELECT password_hash FROM admin_settings LIMIT 1");
    $authorized = false;
    if ($res && $row = $res->fetch_assoc()) {
        $stored_hash = $row['password_hash'];
        // Check: md5(input) === stored_hash OR input === stored_hash (if user sends pre-hashed)
        if (md5($pwd) === $stored_hash || $pwd === $stored_hash) {
            $authorized = true;
        }
    }
    
    if (!$authorized) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit();
    }
    
    $analytics = [
        'most_played' => [],
        'most_liked' => [],
        'most_shared' => [],
        'top_users' => [],
        'summary' => [
            'total_plays' => 0,
            'total_likes' => 0,
            'total_time_seconds' => 0
        ]
    ];
    
    // Get Most Played
    $res = $conn->query("SELECT * FROM song_analytics ORDER BY play_count DESC LIMIT 10");
    if ($res) while($row = $res->fetch_assoc()) $analytics['most_played'][] = $row;
    
    // Get Most Liked
    $res = $conn->query("SELECT * FROM song_analytics ORDER BY like_count DESC LIMIT 10");
    if ($res) while($row = $res->fetch_assoc()) $analytics['most_liked'][] = $row;
    
    // Get Most Shared
    $res = $conn->query("SELECT * FROM song_analytics ORDER BY share_count DESC LIMIT 10");
    if ($res) while($row = $res->fetch_assoc()) $analytics['most_shared'][] = $row;
    
    // Get Top Users
    $res = $conn->query("SELECT * FROM user_analytics ORDER BY total_time_spent_seconds DESC LIMIT 10");
    if ($res) while($row = $res->fetch_assoc()) $analytics['top_users'][] = $row;
    
    // Get Summary Totals
    $res = $conn->query("SELECT SUM(play_count) as total_plays, SUM(like_count) as total_likes FROM song_analytics");
    if ($res && $row = $res->fetch_assoc()) {
        $analytics['summary']['total_plays'] = (int)$row['total_plays'];
        $analytics['summary']['total_likes'] = (int)$row['total_likes'];
    }
    $res = $conn->query("SELECT SUM(total_time_spent_seconds) as total_time FROM user_analytics");
    if ($res && $row = $res->fetch_assoc()) {
        $analytics['summary']['total_time_seconds'] = (int)$row['total_time'];
    }
    
    echo json_encode(['status' => 'success', 'data' => $analytics]);
}

else {
    returnError("Invalid Action");
}
$conn->close();
?>
