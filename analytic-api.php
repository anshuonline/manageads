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

// One-time DB setup: run once via ?action=setup&setup=1 to create tables & indexes.
// Kept out of normal request flow to avoid DDL metadata locks under high traffic.
if (isset($_GET['setup']) && $_GET['setup'] === '1') {
    $conn->query("CREATE TABLE IF NOT EXISTS daily_analytics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stat_date DATE,
        video_id VARCHAR(50),
        title VARCHAR(255),
        thumbnail VARCHAR(500),
        play_count INT DEFAULT 0,
        like_count INT DEFAULT 0,
        share_count INT DEFAULT 0,
        UNIQUE KEY unique_daily (stat_date, video_id)
    )");
    $conn->query("CREATE TABLE IF NOT EXISTS guest_analytics (
        guest_id VARCHAR(64) PRIMARY KEY,
        first_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_active DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        total_plays INT DEFAULT 0,
        total_time_seconds INT DEFAULT 0,
        last_song_title VARCHAR(255) DEFAULT '',
        last_video_id VARCHAR(50) DEFAULT ''
    )");
    $conn->query("CREATE TABLE IF NOT EXISTS guest_song_analytics (
        video_id VARCHAR(50) PRIMARY KEY,
        title VARCHAR(255),
        thumbnail VARCHAR(500),
        artist VARCHAR(255) DEFAULT '',
        play_count INT DEFAULT 0,
        last_played DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    $conn->query("CREATE TABLE IF NOT EXISTS daily_guest_analytics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        stat_date DATE,
        video_id VARCHAR(50),
        title VARCHAR(255),
        thumbnail VARCHAR(500),
        play_count INT DEFAULT 0,
        UNIQUE KEY unique_daily_guest (stat_date, video_id)
    )");
    // Performance Indexes for high traffic scale
    @$conn->query("ALTER TABLE guest_analytics ADD INDEX idx_last_active (last_active)");
    @$conn->query("ALTER TABLE guest_song_analytics ADD INDEX idx_play_count (play_count)");
    echo json_encode(['status' => 'success', 'message' => 'Tables and indexes created/verified.']);
    $conn->close();
    exit();
}

// ── Schema Auto-Migration Guard (Runs once safely without DDL on hot path) ──
@$conn->query("CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(64) PRIMARY KEY, setting_value LONGTEXT)");
$schema_check = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'guest_schema_v2' LIMIT 1");
if (!$schema_check || $schema_check->num_rows === 0) {
    @$conn->query("ALTER TABLE guest_analytics ADD COLUMN ip_address VARCHAR(45) DEFAULT ''");
    @$conn->query("ALTER TABLE guest_analytics ADD COLUMN location VARCHAR(150) DEFAULT ''");
    @$conn->query("ALTER TABLE guest_analytics ADD COLUMN current_page VARCHAR(255) DEFAULT ''");
    @$conn->query("ALTER TABLE guest_analytics ADD COLUMN last_search VARCHAR(255) DEFAULT ''");
    @$conn->query("CREATE TABLE IF NOT EXISTS ip_cache (
        ip VARCHAR(45) PRIMARY KEY,
        city VARCHAR(100) DEFAULT '',
        region VARCHAR(100) DEFAULT '',
        country VARCHAR(100) DEFAULT '',
        country_code VARCHAR(10) DEFAULT '',
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    @$conn->query("ALTER TABLE guest_analytics ADD INDEX idx_guest_ip (ip_address)");
    @$conn->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('guest_schema_v2', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
}

function getClientIP() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ipList = explode(',', $_SERVER[$header]);
            $ip = trim($ipList[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

// Helper to batch resolve IP locations via cache and fast fallback
function resolveIPsLocations(array $ips, $conn, $limit = 5) {
    if (empty($ips)) return [];
    
    $validIps = [];
    foreach ($ips as $ip) {
        $ip = trim($ip);
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            $validIps[$ip] = true;
        }
    }
    if (empty($validIps)) return [];

    $ipKeys = array_keys($validIps);
    $escaped = array_map(function($i) use ($conn) { return "'" . $conn->real_escape_string($i) . "'"; }, $ipKeys);
    $inClause = implode(',', $escaped);

    $cached = [];
    $res = $conn->query("SELECT * FROM ip_cache WHERE ip IN ($inClause)");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $locParts = array_filter([$row['city'], $row['region'], $row['country']]);
            $locStr = implode(', ', $locParts);
            $cached[$row['ip']] = [
                'city' => $row['city'],
                'region' => $row['region'],
                'country' => $row['country'],
                'country_code' => $row['country_code'],
                'location' => $locStr ?: 'Unknown'
            ];
        }
    }

    $uncached = [];
    foreach ($ipKeys as $ip) {
        if (!isset($cached[$ip])) {
            if ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
                $cached[$ip] = [
                    'city' => 'Localhost',
                    'region' => '',
                    'country' => 'Local Network',
                    'country_code' => 'LOCAL',
                    'location' => 'Localhost / Internal'
                ];
                // Persist to ip_cache so MySQL won't pick them up again as uncached
                $escIp = $conn->real_escape_string($ip);
                @$conn->query("INSERT INTO ip_cache (ip, city, region, country, country_code) VALUES ('$escIp', 'Localhost', '', 'Local Network', 'LOCAL') ON DUPLICATE KEY UPDATE ip = ip");
            } else {
                $uncached[] = $ip;
            }
        }
    }

    // Fast resolution for uncached IPs (1s timeout)
    $count = 0;
    foreach ($uncached as $ip) {
        if ($count >= $limit) break;
        $count++;
        $ctx = stream_context_create(['http' => ['timeout' => 1.0]]);
        $apiRes = @file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,regionName,city,countryCode", false, $ctx);
        if ($apiRes) {
            $data = json_decode($apiRes, true);
            if ($data && ($data['status'] ?? '') === 'success') {
                $city = $conn->real_escape_string($data['city'] ?? '');
                $region = $conn->real_escape_string($data['regionName'] ?? '');
                $country = $conn->real_escape_string($data['country'] ?? '');
                $code = $conn->real_escape_string($data['countryCode'] ?? '');
                
                $conn->query("INSERT INTO ip_cache (ip, city, region, country, country_code) VALUES ('$ip', '$city', '$region', '$country', '$code') ON DUPLICATE KEY UPDATE city = VALUES(city), region = VALUES(region), country = VALUES(country), country_code = VALUES(country_code)");
                
                $locParts = array_filter([$data['city'] ?? '', $data['regionName'] ?? '', $data['country'] ?? '']);
                $cached[$ip] = [
                    'city' => $data['city'] ?? '',
                    'region' => $data['regionName'] ?? '',
                    'country' => $data['country'] ?? '',
                    'country_code' => $data['countryCode'] ?? '',
                    'location' => implode(', ', $locParts) ?: 'Unknown'
                ];
            }
        }
    }

    // Negative cache: persist 'Unknown' for IPs that failed API lookup so they don't re-resolve endlessly
    foreach ($uncached as $failedIp) {
        if (!isset($cached[$failedIp])) {
            $escIp = $conn->real_escape_string($failedIp);
            @$conn->query("INSERT INTO ip_cache (ip, city, region, country, country_code) VALUES ('$escIp', 'Unknown', '', 'Unknown', '') ON DUPLICATE KEY UPDATE ip = ip");
            $cached[$failedIp] = [
                'city' => 'Unknown',
                'region' => '',
                'country' => 'Unknown',
                'country_code' => '',
                'location' => 'Unknown'
            ];
        }
    }

    return $cached;
}

if ($action === 'recordPlay') {
    $video_id = $input['video_id'] ?? null;
    $title = $input['title'] ?? '';
    $thumbnail = $input['thumbnail'] ?? '';
    $artist = $input['artist'] ?? '';
    $is_guest = !empty($input['is_guest']);
    $guest_id = $input['guest_id'] ?? null;
    $current_page = $input['current_page'] ?? ('/play?v=' . $video_id);
    $ip = getClientIP();
    
    if (!$video_id) returnError("video_id required");
    
    // Overall song analytics
    $stmt = $conn->prepare("INSERT INTO song_analytics (video_id, title, thumbnail, play_count) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE play_count = play_count + 1, title = VALUES(title), thumbnail = VALUES(thumbnail)");
    $stmt->bind_param("sss", $video_id, $title, $thumbnail);
    $stmt->execute();
    
    // Log daily play
    $daily_stmt = $conn->prepare("INSERT INTO daily_analytics (stat_date, video_id, title, thumbnail, play_count) VALUES (CURDATE(), ?, ?, ?, 1) ON DUPLICATE KEY UPDATE play_count = play_count + 1, title = VALUES(title), thumbnail = VALUES(thumbnail)");
    $daily_stmt->bind_param("sss", $video_id, $title, $thumbnail);
    $daily_stmt->execute();
    
    // Guest Analytics if unauthenticated
    if ($is_guest) {
        $g_song = $conn->prepare("INSERT INTO guest_song_analytics (video_id, title, thumbnail, artist, play_count) VALUES (?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE play_count = play_count + 1, title = VALUES(title), thumbnail = VALUES(thumbnail), artist = VALUES(artist), last_played = NOW()");
        $g_song->bind_param("ssss", $video_id, $title, $thumbnail, $artist);
        $g_song->execute();
        
        $g_daily = $conn->prepare("INSERT INTO daily_guest_analytics (stat_date, video_id, title, thumbnail, play_count) VALUES (CURDATE(), ?, ?, ?, 1) ON DUPLICATE KEY UPDATE play_count = play_count + 1, title = VALUES(title), thumbnail = VALUES(thumbnail)");
        $g_daily->bind_param("sss", $video_id, $title, $thumbnail);
        $g_daily->execute();
        
        if ($guest_id) {
            $g_usr = $conn->prepare("INSERT INTO guest_analytics (guest_id, first_seen, last_active, total_plays, last_song_title, last_video_id, ip_address, current_page) VALUES (?, NOW(), NOW(), 1, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE total_plays = total_plays + 1, last_song_title = VALUES(last_song_title), last_video_id = VALUES(last_video_id), ip_address = VALUES(ip_address), current_page = VALUES(current_page), last_active = NOW()");
            $g_usr->bind_param("sssss", $guest_id, $title, $video_id, $ip, $current_page);
            $g_usr->execute();
        }
    }
    
    echo json_encode(['status' => 'success']);
} 

elseif ($action === 'recordGuestPing') {
    $guest_id = $input['guest_id'] ?? null;
    $seconds = (int)($input['seconds'] ?? 60);
    $current_page = $input['current_page'] ?? '';
    $last_search = $input['last_search'] ?? '';
    $ip = getClientIP();
    
    if ($guest_id) {
        $stmt = $conn->prepare("INSERT INTO guest_analytics (guest_id, first_seen, last_active, total_plays, total_time_seconds, ip_address, current_page, last_search) VALUES (?, NOW(), NOW(), 0, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE total_time_seconds = total_time_seconds + ?, ip_address = VALUES(ip_address), current_page = IF(VALUES(current_page) != '', VALUES(current_page), current_page), last_search = IF(VALUES(last_search) != '', VALUES(last_search), last_search), last_active = NOW()");
        $stmt->bind_param("sisssi", $guest_id, $seconds, $ip, $current_page, $last_search, $seconds);
        $stmt->execute();
    }
    echo json_encode(['status' => 'success']);
    exit();
}

elseif ($action === 'recordLike') {
    $video_id = $input['video_id'] ?? null;
    $title = $input['title'] ?? '';
    $thumbnail = $input['thumbnail'] ?? '';
    
    if (!$video_id) returnError("video_id required");
    
    $stmt = $conn->prepare("INSERT INTO song_analytics (video_id, title, thumbnail, like_count) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE like_count = like_count + 1, title = VALUES(title), thumbnail = VALUES(thumbnail)");
    $stmt->bind_param("sss", $video_id, $title, $thumbnail);
    $stmt->execute();
    
    // Log daily like
    $daily_stmt = $conn->prepare("INSERT INTO daily_analytics (stat_date, video_id, title, thumbnail, like_count) VALUES (CURDATE(), ?, ?, ?, 1) ON DUPLICATE KEY UPDATE like_count = like_count + 1, title = VALUES(title), thumbnail = VALUES(thumbnail)");
    $daily_stmt->bind_param("sss", $video_id, $title, $thumbnail);
    $daily_stmt->execute();
    
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
    
    // Log daily share
    $daily_stmt = $conn->prepare("INSERT INTO daily_analytics (stat_date, video_id, title, thumbnail, share_count) VALUES (CURDATE(), ?, ?, ?, 1) ON DUPLICATE KEY UPDATE share_count = share_count + 1, title = VALUES(title), thumbnail = VALUES(thumbnail)");
    $daily_stmt->bind_param("sss", $video_id, $title, $thumbnail);
    $daily_stmt->execute();
    
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

elseif ($action === 'getTop100Songs') {
    // Public endpoint for top 100 most played songs (used by discovery page)
    $res = $conn->query("SELECT video_id, title, thumbnail, play_count FROM song_analytics ORDER BY play_count DESC LIMIT 100");
    $top_songs = [];
    if ($res) {
        while($row = $res->fetch_assoc()) {
            $top_songs[] = $row;
        }
    }
    echo json_encode(['status' => 'success', 'data' => $top_songs]);
    exit();
}

elseif ($action === 'validatePwd') {
    // Lightweight admin password validation (shared with managegt / room analytics)
    $pwd = $_GET['pwd'] ?? '';
    $res = $conn->query("SELECT password_hash FROM admin_settings LIMIT 1");
    $authorized = false;
    if ($res && $row = $res->fetch_assoc()) {
        $stored_hash = $row['password_hash'];
        if (md5($pwd) === $stored_hash || $pwd === $stored_hash) {
            $authorized = true;
        }
    }
    echo json_encode(['status' => $authorized ? 'success' : 'error']);
    exit();
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
        'detailed_users' => [],
        'user_growth' => [],
        'summary' => [
            'total_plays' => 0,
            'total_likes' => 0,
            'total_time_seconds' => 0,
            'total_users' => 0,
            'daily_active_users' => 0
        ]
    ];
    
    $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all_time';
    
    // Build the query based on filter
    $most_played_q = "SELECT s.*, COALESCE(g.play_count, 0) as guest_play_count, GREATEST(0, s.play_count - COALESCE(g.play_count, 0)) as user_play_count FROM song_analytics s LEFT JOIN guest_song_analytics g ON s.video_id = g.video_id ORDER BY s.play_count DESC LIMIT 50";
    $most_liked_q = "SELECT * FROM song_analytics ORDER BY like_count DESC LIMIT 50";
    $most_shared_q = "SELECT * FROM song_analytics ORDER BY share_count DESC LIMIT 50";
    
    if ($filter === 'today') {
        $most_played_q = "SELECT * FROM daily_analytics WHERE stat_date = CURDATE() ORDER BY play_count DESC LIMIT 50";
        $most_liked_q = "SELECT * FROM daily_analytics WHERE stat_date = CURDATE() ORDER BY like_count DESC LIMIT 50";
        $most_shared_q = "SELECT * FROM daily_analytics WHERE stat_date = CURDATE() ORDER BY share_count DESC LIMIT 50";
    } elseif ($filter === 'yesterday') {
        $most_played_q = "SELECT * FROM daily_analytics WHERE stat_date = CURDATE() - INTERVAL 1 DAY ORDER BY play_count DESC LIMIT 50";
        $most_liked_q = "SELECT * FROM daily_analytics WHERE stat_date = CURDATE() - INTERVAL 1 DAY ORDER BY like_count DESC LIMIT 50";
        $most_shared_q = "SELECT * FROM daily_analytics WHERE stat_date = CURDATE() - INTERVAL 1 DAY ORDER BY share_count DESC LIMIT 50";
    } elseif ($filter === 'last_7_days') {
        $most_played_q = "SELECT video_id, MAX(title) as title, MAX(thumbnail) as thumbnail, SUM(play_count) as play_count FROM daily_analytics WHERE stat_date >= CURDATE() - INTERVAL 7 DAY GROUP BY video_id ORDER BY play_count DESC LIMIT 50";
        $most_liked_q = "SELECT video_id, MAX(title) as title, MAX(thumbnail) as thumbnail, SUM(like_count) as like_count FROM daily_analytics WHERE stat_date >= CURDATE() - INTERVAL 7 DAY GROUP BY video_id ORDER BY like_count DESC LIMIT 50";
        $most_shared_q = "SELECT video_id, MAX(title) as title, MAX(thumbnail) as thumbnail, SUM(share_count) as share_count FROM daily_analytics WHERE stat_date >= CURDATE() - INTERVAL 7 DAY GROUP BY video_id ORDER BY share_count DESC LIMIT 50";
    }
    
    // Get Most Played
    $res = $conn->query($most_played_q);
    if ($res) while($row = $res->fetch_assoc()) $analytics['most_played'][] = $row;
    
    // Get Most Liked
    $res = $conn->query($most_liked_q);
    if ($res) while($row = $res->fetch_assoc()) $analytics['most_liked'][] = $row;
    
    // Get Most Shared
    $res = $conn->query($most_shared_q);
    if ($res) while($row = $res->fetch_assoc()) $analytics['most_shared'][] = $row;
    
    // Get Top Users
    $res = $conn->query("SELECT * FROM user_analytics ORDER BY total_time_spent_seconds DESC LIMIT 100");
    if ($res) while($row = $res->fetch_assoc()) $analytics['top_users'][] = $row;
    
    // Get Complete User Profiles Details for Detailed Display
    $res = $conn->query("SELECT email, display_name, created_at, updated_at FROM user_profiles ORDER BY created_at DESC LIMIT 100");
    $analytics['detailed_users'] = [];
    if ($res) while($row = $res->fetch_assoc()) $analytics['detailed_users'][] = $row;
    
    // Get User Growth (Signups per day)
    $res = $conn->query("SELECT DATE(created_at) as join_date, COUNT(*) as new_users FROM user_profiles GROUP BY DATE(created_at) ORDER BY join_date ASC");
    if ($res) while($row = $res->fetch_assoc()) $analytics['user_growth'][] = $row;
    
    // Get Total Users
    $res = $conn->query("SELECT COUNT(*) as total FROM user_profiles");
    if ($res && $row = $res->fetch_assoc()) {
        $analytics['summary']['total_users'] = (int)$row['total'];
    }
    
    // Get Daily Active Users (Updated in the last 24 hours)
    $res = $conn->query("SELECT COUNT(*) as active_today FROM user_profiles WHERE updated_at >= NOW() - INTERVAL 1 DAY");
    if ($res && $row = $res->fetch_assoc()) {
        $analytics['summary']['daily_active_users'] = (int)$row['active_today'];
    }
    
    // Get Summary Totals
    $res = $conn->query("SELECT SUM(play_count) as total_plays FROM song_analytics");
    if ($res && $row = $res->fetch_assoc()) {
        $analytics['summary']['total_plays'] = (int)$row['total_plays'];
    }
    
    // Live Total Likes calculation from user_profiles (Optimized calculation)
    // We sum the length of the JSON array stored in 'liked_songs' column
    $res = $conn->query("SELECT SUM(JSON_LENGTH(liked_songs)) as total_likes FROM user_profiles WHERE liked_songs IS NOT NULL AND liked_songs != 'null' AND liked_songs != '[]'");
    if ($res && $row = $res->fetch_assoc()) {
        $analytics['summary']['total_likes'] = (int)$row['total_likes'];
    } else {
        // Fallback
        $analytics['summary']['total_likes'] = 0;
    }
    
    // Get Total User Time Spent
    $res = $conn->query("SELECT SUM(total_time_spent_seconds) as total_time FROM user_analytics");
    if ($res && $row = $res->fetch_assoc()) {
        $analytics['summary']['total_time_seconds'] = (int)$row['total_time'];
    }
    
    // Guest Analytics Summary
    $guest_summary = [
        'total_guests' => 0,
        'active_guests_today' => 0,
        'online_guests_now' => 0,
        'total_guest_plays' => 0,
        'total_guest_time_seconds' => 0
    ];

    $res = $conn->query("SELECT COUNT(*) as total, 
                         SUM(CASE WHEN last_active >= CURDATE() THEN 1 ELSE 0 END) as active_today,
                         SUM(CASE WHEN last_active >= NOW() - INTERVAL 15 MINUTE THEN 1 ELSE 0 END) as online_now,
                         SUM(total_plays) as total_plays,
                         SUM(total_time_seconds) as total_time
                         FROM guest_analytics");
    if ($res && $row = $res->fetch_assoc()) {
        $guest_summary['total_guests'] = (int)$row['total'];
        $guest_summary['active_guests_today'] = (int)($row['active_today'] ?? 0);
        $guest_summary['online_guests_now'] = (int)($row['online_now'] ?? 0);
        $guest_summary['total_guest_plays'] = (int)($row['total_plays'] ?? 0);
        $guest_summary['total_guest_time_seconds'] = (int)($row['total_time'] ?? 0);
    }
    $analytics['guest_summary'] = $guest_summary;

    // "Guests Ka Gana" - Top Songs played by non-logged-in guests
    $guest_songs_q = "SELECT video_id, title, thumbnail, artist, play_count, last_played FROM guest_song_analytics ORDER BY play_count DESC LIMIT 50";
    if ($filter === 'today') {
        $guest_songs_q = "SELECT video_id, MAX(title) as title, MAX(thumbnail) as thumbnail, MAX(title) as artist, SUM(play_count) as play_count FROM daily_guest_analytics WHERE stat_date = CURDATE() GROUP BY video_id ORDER BY play_count DESC LIMIT 50";
    } elseif ($filter === 'yesterday') {
        $guest_songs_q = "SELECT video_id, MAX(title) as title, MAX(thumbnail) as thumbnail, MAX(title) as artist, SUM(play_count) as play_count FROM daily_guest_analytics WHERE stat_date = CURDATE() - INTERVAL 1 DAY GROUP BY video_id ORDER BY play_count DESC LIMIT 50";
    } elseif ($filter === 'last_7_days') {
        $guest_songs_q = "SELECT video_id, MAX(title) as title, MAX(thumbnail) as thumbnail, MAX(title) as artist, SUM(play_count) as play_count FROM daily_guest_analytics WHERE stat_date >= CURDATE() - INTERVAL 7 DAY GROUP BY video_id ORDER BY play_count DESC LIMIT 50";
    }

    $analytics['guest_top_songs'] = [];
    $res = $conn->query($guest_songs_q);
    if ($res) while($row = $res->fetch_assoc()) $analytics['guest_top_songs'][] = $row;

    // Recent Active Guests
    $analytics['recent_guests'] = [];
    $allIps = [];
    $res = $conn->query("SELECT guest_id, first_seen, last_active, total_plays, total_time_seconds, last_song_title, last_video_id, ip_address, current_page, last_search FROM guest_analytics ORDER BY last_active DESC LIMIT 100");
    if ($res) {
        while($row = $res->fetch_assoc()) {
            if (!empty($row['ip_address'])) $allIps[] = $row['ip_address'];
            $analytics['recent_guests'][] = $row;
        }
    }

    // Online Guests (Active in last 15 minutes)
    $analytics['online_guests'] = [];
    $res = $conn->query("SELECT guest_id, first_seen, last_active, total_plays, total_time_seconds, last_song_title, last_video_id, ip_address, current_page, last_search FROM guest_analytics WHERE last_active >= NOW() - INTERVAL 15 MINUTE ORDER BY last_active DESC LIMIT 100");
    if ($res) {
        while($row = $res->fetch_assoc()) {
            if (!empty($row['ip_address'])) $allIps[] = $row['ip_address'];
            $analytics['online_guests'][] = $row;
        }
    }

    // Resolve Geo-Locations using cache
    $ipLocations = resolveIPsLocations($allIps, $conn);

    foreach ($analytics['recent_guests'] as &$g) {
        $ip = $g['ip_address'] ?? '';
        $g['location'] = $ipLocations[$ip]['location'] ?? ($ip ? 'Resolving Location...' : 'Unknown');
        $g['country_code'] = $ipLocations[$ip]['country_code'] ?? '';
        $g['city'] = $ipLocations[$ip]['city'] ?? '';
    }
    unset($g);

    foreach ($analytics['online_guests'] as &$og) {
        $ip = $og['ip_address'] ?? '';
        $og['location'] = $ipLocations[$ip]['location'] ?? ($ip ? 'Resolving Location...' : 'Unknown');
        $og['country_code'] = $ipLocations[$ip]['country_code'] ?? '';
        $og['city'] = $ipLocations[$ip]['city'] ?? '';
    }
    unset($og);

    echo json_encode(['status' => 'success', 'data' => $analytics]);
}

elseif ($action === 'getGuestGeography') {
    $pwd = $_GET['pwd'] ?? '';
    
    // Auth check using admin_settings table (shared with managegt)
    $res = $conn->query("SELECT password_hash FROM admin_settings LIMIT 1");
    $authorized = false;
    if ($res && $row = $res->fetch_assoc()) {
        $stored_hash = $row['password_hash'];
        if (md5($pwd) === $stored_hash || $pwd === $stored_hash) {
            $authorized = true;
        }
    }
    if (!$authorized) returnError("Unauthorized");

    $range = $_GET['range'] ?? 'dau'; // 'dau', 'wau', 'mau', 'yau', 'all'
    
    switch ($range) {
        case 'dau':
            $whereClause = "g.last_active >= NOW() - INTERVAL 1 DAY";
            $rangeLabel = "Daily Active (Last 24 Hours)";
            break;
        case 'wau':
            $whereClause = "g.last_active >= NOW() - INTERVAL 7 DAY";
            $rangeLabel = "Weekly Active (Last 7 Days)";
            break;
        case 'mau':
            $whereClause = "g.last_active >= NOW() - INTERVAL 30 DAY";
            $rangeLabel = "Monthly Active (Last 30 Days)";
            break;
        case 'yau':
            $whereClause = "g.last_active >= NOW() - INTERVAL 1 YEAR";
            $rangeLabel = "Yearly Active (Last 365 Days)";
            break;
        case 'all':
        default:
            $range = 'all';
            $whereClause = "1=1";
            $rangeLabel = "All-Time Active";
            break;
    }

    // Step 1: Pre-resolve up to 8 uncached IPs for this range so geo cache is populated
    $uRes = $conn->query("SELECT DISTINCT g.ip_address FROM guest_analytics g LEFT JOIN ip_cache c ON g.ip_address = c.ip WHERE g.ip_address != '' AND c.ip IS NULL AND $whereClause LIMIT 8");
    $uncachedIps = [];
    if ($uRes) {
        while ($uRow = $uRes->fetch_assoc()) {
            $uncachedIps[] = $uRow['ip_address'];
        }
    }
    if (!empty($uncachedIps)) {
        resolveIPsLocations($uncachedIps, $conn, 8);
    }

    // Step 2: Total Active Audience in this range
    $totalSql = "SELECT COUNT(DISTINCT g.guest_id) as total_guests, COALESCE(SUM(g.total_plays), 0) as total_plays, COALESCE(SUM(g.total_time_seconds), 0) as total_time_seconds FROM guest_analytics g WHERE $whereClause";
    $totalRes = $conn->query($totalSql);
    $totalRow = $totalRes ? $totalRes->fetch_assoc() : null;
    $totalGuests = (int)($totalRow['total_guests'] ?? 0);
    $totalPlays = (int)($totalRow['total_plays'] ?? 0);
    $totalTimeSeconds = (int)($totalRow['total_time_seconds'] ?? 0);

    // Step 3: Top 50 Locations (City / State / Country)
    $geoSql = "SELECT 
        COALESCE(NULLIF(c.country, ''), 'Unknown') AS country,
        COALESCE(c.country_code, '') AS country_code,
        COALESCE(NULLIF(c.city, ''), 'Unknown') AS city,
        COALESCE(c.region, '') AS region,
        COUNT(DISTINCT g.guest_id) AS active_guests,
        COALESCE(SUM(g.total_plays), 0) AS total_plays,
        COALESCE(SUM(g.total_time_seconds), 0) AS total_time_seconds
    FROM guest_analytics g
    LEFT JOIN ip_cache c ON g.ip_address = c.ip
    WHERE $whereClause
    GROUP BY country, country_code, city, region
    ORDER BY active_guests DESC, total_plays DESC
    LIMIT 50";

    $geoRes = $conn->query($geoSql);
    $locations = [];
    $rank = 1;
    if ($geoRes) {
        while ($row = $geoRes->fetch_assoc()) {
            $guestCount = (int)$row['active_guests'];
            $pct = $totalGuests > 0 ? round(($guestCount / $totalGuests) * 100, 1) : 0;
            $locations[] = [
                'rank' => $rank++,
                'country' => $row['country'],
                'country_code' => $row['country_code'],
                'city' => $row['city'],
                'region' => $row['region'],
                'active_guests' => $guestCount,
                'percentage' => $pct,
                'total_plays' => (int)$row['total_plays'],
                'total_time_seconds' => (int)$row['total_time_seconds']
            ];
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'range' => $range,
            'range_label' => $rangeLabel,
            'summary' => [
                'total_active_guests' => $totalGuests,
                'total_plays' => $totalPlays,
                'total_time_seconds' => $totalTimeSeconds,
                'total_cities_count' => count($locations)
            ],
            'locations' => $locations
        ]
    ]);
    exit();
}

else {
    returnError("Invalid Action");
}
$conn->close();
?>
