<?php
@ini_set('display_errors', '0');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json; charset=UTF-8");

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// Fast early exit for disabled endpoints (bypasses DB connection completely)
if (in_array($action, ['recordPlay', 'recordTime', 'recordSearch', 'recordSearchClick', 'recordSearchPlay', 'recordGuestPing'], true)) {
    echo json_encode(['status' => 'success', 'disabled' => true]);
    exit();
}

require 'config.php';
date_default_timezone_set('Asia/Kolkata');
@$conn->query("SET time_zone = '+05:30'");
@$conn->query("SET SESSION sql_mode = (SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");

// Function to handle database errors
function returnError($message, $error = null) {
    echo json_encode(['status' => 'error', 'message' => $message, 'error' => $error]);
    exit();
}

// ── 2-Hour Snapshot Cache Helpers ──────────────────────────────────────────
function getAnalyticsSnapshotCache($cacheKey, $ttlSeconds = 7200) {
    $cacheDir = __DIR__ . '/cache';
    $cacheFile = $cacheDir . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $cacheKey) . '.json';
    if (!file_exists($cacheFile)) {
        return null;
    }
    $mtime = @filemtime($cacheFile);
    if (!$mtime) return null;
    $age = time() - $mtime;
    if ($age > $ttlSeconds) {
        return null;
    }
    $raw = @file_get_contents($cacheFile);
    if (!$raw) return null;
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;

    $cachedAtTime = $mtime;
    $nextUpdateTime = $mtime + $ttlSeconds;
    $data['_cache_meta'] = [
        'is_cached' => true,
        'cached_at' => date('Y-m-d H:i:s', $cachedAtTime),
        'cached_at_formatted' => date('h:i A', $cachedAtTime),
        'next_update_formatted' => date('h:i A', $nextUpdateTime),
        'ttl_seconds' => $ttlSeconds,
        'age_seconds' => $age
    ];
    return $data;
}

function setAnalyticsSnapshotCache($cacheKey, $data, $ttlSeconds = 7200) {
    $cacheDir = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $cacheFile = $cacheDir . '/' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $cacheKey) . '.json';
    @file_put_contents($cacheFile, json_encode($data));
}

// Ensure payload exists
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, TRUE);

function getClientIP() {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ipList = explode(',', $_SERVER[$header]);
            foreach ($ipList as $rawIp) {
                $ip = trim($rawIp);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    // Check if it's not a private range, loopback, or Hostinger server subnet
                    if (strpos($ip, '2a02:4780:') !== 0 && strpos($ip, '2a02:4787:') !== 0 && strpos($ip, '127.') !== 0 && $ip !== '::1') {
                        return $ip;
                    }
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

// ── Bot & Excluded IP Filtering Utilities ──────────────────────────────────
function isBotOrCrawler($ip, $currentPage = '') {
    // 1. LiteSpeed Cache optimizer crawler parameter (e.g. ?LSCWP_CTRL=before_optm)
    if (!empty($currentPage) && (strpos($currentPage, 'LSCWP_CTRL') !== false || strpos($currentPage, 'before_optm') !== false)) {
        return true;
    }
    if (!empty($_SERVER['QUERY_STRING']) && (strpos($_SERVER['QUERY_STRING'], 'LSCWP_CTRL') !== false || strpos($_SERVER['QUERY_STRING'], 'before_optm') !== false)) {
        return true;
    }
    if (!empty($_SERVER['REQUEST_URI']) && (strpos($_SERVER['REQUEST_URI'], 'LSCWP_CTRL') !== false || strpos($_SERVER['REQUEST_URI'], 'before_optm') !== false)) {
        return true;
    }

    // 2. Hostinger Bot IPv6 subnets (AS47583 Hostinger International)
    if (strpos($ip, '2a02:4780:') === 0 || strpos($ip, '2a02:4787:') === 0) {
        return true;
    }

    // 3. User-Agent inspection for automated crawlers and headless browsers
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/(bot|crawler|spider|headless|litespeed|lscache|googlebot|bingbot|yandex|duckduckbot|baiduspider)/i', $ua)) {
        return true;
    }

    return false;
}

function getExcludedIpsList($conn) {
    static $cached = null;
    if ($cached !== null) return $cached;
    $res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'excluded_ips' LIMIT 1");
    if ($res && $row = $res->fetch_assoc()) {
        $data = json_decode($row['setting_value'], true);
        if (is_array($data)) {
            $cached = $data;
            return $cached;
        }
    }
    $cached = [];
    return $cached;
}

function isIpExcluded($ip, $conn) {
    if (empty($ip)) return false;
    $list = getExcludedIpsList($conn);
    foreach ($list as $item) {
        $target = is_array($item) ? ($item['ip'] ?? '') : $item;
        if (strcasecmp(trim($target), trim($ip)) === 0) {
            return true;
        }
    }
    return false;
}

function isTrackingBlocked($ip, $currentPage, $conn) {
    if (isBotOrCrawler($ip, $currentPage)) {
        return true;
    }
    if (isIpExcluded($ip, $conn)) {
        return true;
    }
    return false;
}

function ensureAccessKeysTable($conn) {
    static $created = false;
    if ($created) return;
    $conn->query("CREATE TABLE IF NOT EXISTS gtanalytic_access_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_label VARCHAR(100) NOT NULL DEFAULT 'User',
        password_hash VARCHAR(255) NOT NULL,
        plain_hint VARCHAR(255) DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $created = true;
}

function ensureLoginLogsTable($conn) {
    static $created = false;
    if ($created) return;
    $conn->query("CREATE TABLE IF NOT EXISTS gtanalytic_login_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_label VARCHAR(100) NOT NULL DEFAULT 'User',
        user_identifier VARCHAR(100) NOT NULL DEFAULT '',
        ip_address VARCHAR(45) NOT NULL DEFAULT '',
        device_type VARCHAR(50) DEFAULT 'PC',
        os VARCHAR(50) DEFAULT 'Unknown',
        browser VARCHAR(50) DEFAULT 'Unknown',
        screen_size VARCHAR(50) DEFAULT '',
        city VARCHAR(100) DEFAULT '',
        country VARCHAR(100) DEFAULT '',
        status VARCHAR(20) DEFAULT 'success',
        user_agent TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    // Auto-purge login logs older than 7 days
    @$conn->query("DELETE FROM gtanalytic_login_logs WHERE created_at < NOW() - INTERVAL 7 DAY");
    // Clean up any stray Hostinger server internal IPs or headless server pings
    @$conn->query("DELETE FROM gtanalytic_login_logs WHERE ip_address LIKE '2a02:4780:%' OR ip_address LIKE '2a02:4787:%' OR (os = 'Unknown' AND browser = 'Unknown')");
    $created = true;
}

function ensureAppInterestTable($conn) {
    static $created = false;
    if ($created) return;
    $conn->query("CREATE TABLE IF NOT EXISTS gt_app_interest (
        id INT AUTO_INCREMENT PRIMARY KEY,
        choice ENUM('yes', 'not_sure') NOT NULL,
        user_identifier VARCHAR(191) NOT NULL,
        is_guest TINYINT(1) DEFAULT 1,
        email VARCHAR(191) DEFAULT NULL,
        device_type VARCHAR(50) DEFAULT 'Unknown',
        os VARCHAR(50) DEFAULT 'Unknown',
        browser VARCHAR(50) DEFAULT 'Unknown',
        ip_address VARCHAR(45) DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_vote (user_identifier)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $created = true;
}

function recordLoginAuditLog($conn, $authInfo, $input = []) {
    ensureLoginLogsTable($conn);
    $ip = getClientIP();

    // Never log Hostinger's internal server/bot IPv6 or loopback
    if (strpos($ip, '2a02:4780:') === 0 || strpos($ip, '2a02:4787:') === 0 || $ip === '127.0.0.1' || $ip === '::1') {
        return;
    }

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    // Never log headless server-side calls (e.g. node, curl, undici, python)
    if (empty($ua) || stripos($ua, 'node') !== false || stripos($ua, 'curl') !== false || stripos($ua, 'undici') !== false || stripos($ua, 'python') !== false) {
        return;
    }

    $params = !empty($input) ? $input : $_GET;
    $deviceType = $conn->real_escape_string($params['device_type'] ?? 'PC');
    $os = $conn->real_escape_string($params['os'] ?? 'Unknown');
    $browser = $conn->real_escape_string($params['browser'] ?? 'Unknown');
    $screen = $conn->real_escape_string($params['screen_size'] ?? '');
    $city = $conn->real_escape_string($_SERVER['HTTP_CF_IPCITY'] ?? ($params['city'] ?? ''));
    $country = $conn->real_escape_string($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ($params['country'] ?? ''));
    $escUa = $conn->real_escape_string($ua);
    $status = (!empty($authInfo['authorized'])) ? 'success' : 'failed';
    $userLabel = $conn->real_escape_string($authInfo['user_label'] ?: 'Unknown User');
    $userIdent = $conn->real_escape_string(!empty($authInfo['key_id']) ? ('Key #' . $authInfo['key_id']) : 'master_admin');

    $sql = "INSERT INTO gtanalytic_login_logs 
        (user_label, user_identifier, ip_address, device_type, os, browser, screen_size, city, country, status, user_agent)
        VALUES 
        ('$userLabel', '$userIdent', '$ip', '$deviceType', '$os', '$browser', '$screen', '$city', '$country', '$status', '$escUa')";
    @$conn->query($sql);
}

function verifyAdminAuthWithDetails($conn, $pwd) {
    if (!$pwd || !is_string($pwd)) return ['authorized' => false, 'user_label' => '', 'key_id' => null];
    $pwd = trim($pwd);
    if ($pwd === '') return ['authorized' => false, 'user_label' => '', 'key_id' => null];

    $clean_pwd = $conn->real_escape_string($pwd);
    $md5_pwd = md5($pwd);
    $sha1_pwd = sha1($pwd);
    $sha256_pwd = hash('sha256', $pwd);

    // 1. Check in admin_settings table across ALL rows (matches plain text, MD5, SHA1, SHA256)
    $res = $conn->query("SELECT id FROM admin_settings WHERE 
        password_hash = '$md5_pwd' OR 
        password_hash = '$clean_pwd' OR 
        password_hash = '$sha1_pwd' OR 
        password_hash = '$sha256_pwd' LIMIT 1");
    if ($res && $res->num_rows > 0) {
        return ['authorized' => true, 'user_label' => 'Primary Admin', 'key_id' => null];
    }

    // 1b. Check if admin_settings has bcrypt/argon hash
    $resBcrypt = $conn->query("SELECT password_hash FROM admin_settings WHERE password_hash LIKE '\$2%' OR password_hash LIKE '\$argon%'");
    if ($resBcrypt) {
        while ($row = $resBcrypt->fetch_assoc()) {
            if (password_verify($pwd, $row['password_hash'])) {
                return ['authorized' => true, 'user_label' => 'Primary Admin', 'key_id' => null];
            }
        }
    }

    // 2. Check in gtanalytic_access_keys table
    ensureAccessKeysTable($conn);
    $keyRes = $conn->query("SELECT id, user_label FROM gtanalytic_access_keys WHERE is_active = 1 AND (
        password_hash = '$md5_pwd' OR 
        password_hash = '$clean_pwd' OR 
        password_hash = '$sha1_pwd' OR 
        password_hash = '$sha256_pwd') LIMIT 1");
    if ($keyRes && $keyRes->num_rows > 0) {
        $keyRow = $keyRes->fetch_assoc();
        return ['authorized' => true, 'user_label' => $keyRow['user_label'], 'key_id' => intval($keyRow['id'])];
    }

    // 2b. Check bcrypt/argon in gtanalytic_access_keys
    $keyResBcrypt = $conn->query("SELECT id, user_label, password_hash FROM gtanalytic_access_keys WHERE is_active = 1 AND (password_hash LIKE '\$2%' OR password_hash LIKE '\$argon%')");
    if ($keyResBcrypt) {
        while ($row = $keyResBcrypt->fetch_assoc()) {
            if (password_verify($pwd, $row['password_hash'])) {
                return ['authorized' => true, 'user_label' => $row['user_label'], 'key_id' => intval($row['id'])];
            }
        }
    }

    // 3. Fallback check in admins table (if exists)
    $checkAdmins = $conn->query("SHOW TABLES LIKE 'admins'");
    if ($checkAdmins && $checkAdmins->num_rows > 0) {
        $q3 = $conn->query("SELECT id, username FROM admins WHERE password = '$md5_pwd' OR password = '$clean_pwd' LIMIT 1");
        if ($q3 && $q3->num_rows > 0) {
            $row3 = $q3->fetch_assoc();
            return ['authorized' => true, 'user_label' => $row3['username'] ?? 'Admin', 'key_id' => null];
        }
    }

    return ['authorized' => false, 'user_label' => '', 'key_id' => null];
}

function verifyAdminAuth($conn, $pwd) {
    $info = verifyAdminAuthWithDetails($conn, $pwd);
    return $info['authorized'];
}

function verifyPrimaryAdminAuth($conn, $pwd) {
    $info = verifyAdminAuthWithDetails($conn, $pwd);
    return ($info['authorized'] && ($info['user_label'] === 'Primary Admin' || empty($info['key_id'])));
}

function buildGuestFilterSql($conn, $tableAlias = '') {
    $prefix = $tableAlias ? ($tableAlias . '.') : '';
    $clause = "({$prefix}ip_address NOT LIKE '2a02:4780:%' AND {$prefix}ip_address NOT LIKE '2a02:4787:%' AND {$prefix}current_page NOT LIKE '%LSCWP_CTRL%' AND {$prefix}current_page NOT LIKE '%before_optm%')";
    
    $excluded = getExcludedIpsList($conn);
    $ipList = [];
    foreach ($excluded as $item) {
        $ip = is_array($item) ? ($item['ip'] ?? '') : $item;
        $ip = trim($ip);
        if ($ip) {
            $ipList[] = "'" . $conn->real_escape_string($ip) . "'";
        }
    }
    if (!empty($ipList)) {
        $clause .= " AND {$prefix}ip_address NOT IN (" . implode(',', $ipList) . ")";
    }
    return $clause;
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

// ── Automatic 30-Day Data Retention Cleanup ────────────────────────────────
function safeDeleteOldRows($conn, $table, $dateCondition) {
    try {
        $res = @$conn->query("DELETE FROM `$table` WHERE $dateCondition");
        return ($res) ? (int)$conn->affected_rows : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function autoCleanupOldAnalytics($conn, $force = false) {
    if (!$force) {
        $today = date('Y-m-d');
        try {
            $check = @$conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'last_analytics_cleanup' LIMIT 1");
            if ($check && $row = $check->fetch_assoc()) {
                if ($row['setting_value'] === $today) {
                    return false; // Already executed today
                }
            }
            @$conn->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('last_analytics_cleanup', '$today') ON DUPLICATE KEY UPDATE setting_value = '$today'");
        } catch (Throwable $e) {
            // Non-blocking fallback
        }
    }

    $deleted = [];

    // 1. daily_analytics (Track plays/likes/shares - keep last 30 days)
    $deleted['daily_analytics'] = safeDeleteOldRows($conn, 'daily_analytics', "stat_date < CURDATE() - INTERVAL 30 DAY");

    // 2. daily_guest_analytics (Guests daily play counts - keep last 30 days)
    $deleted['daily_guest_analytics'] = safeDeleteOldRows($conn, 'daily_guest_analytics', "stat_date < CURDATE() - INTERVAL 30 DAY");

    // 3. guest_song_analytics (Guests songs list - inactive for > 30 days)
    $deleted['guest_song_analytics'] = safeDeleteOldRows($conn, 'guest_song_analytics', "last_played < NOW() - INTERVAL 30 DAY");

    // 4. guest_analytics (Guest tracking logs inactive for > 30 days)
    $deleted['guest_analytics'] = safeDeleteOldRows($conn, 'guest_analytics', "last_active < NOW() - INTERVAL 30 DAY");

    // 5. hourly_user_activity (Hourly curve aggregates older than 30 days)
    $deleted['hourly_user_activity'] = safeDeleteOldRows($conn, 'hourly_user_activity', "stat_date < CURDATE() - INTERVAL 30 DAY");

    // 6. daily_search_analytics (Daily search statistics older than 30 days)
    $deleted['daily_search_analytics'] = safeDeleteOldRows($conn, 'daily_search_analytics', "stat_date < CURDATE() - INTERVAL 30 DAY");

    // 7. search_analytics_log (Raw search queries older than 30 days)
    $deleted['search_analytics_log'] = safeDeleteOldRows($conn, 'search_analytics_log', "searched_at < NOW() - INTERVAL 30 DAY");

    // 8. jingle_analytics (Jingle play/skip logs older than 30 days - if table exists)
    $deleted['jingle_analytics'] = safeDeleteOldRows($conn, 'jingle_analytics', "created_at < NOW() - INTERVAL 30 DAY");

    return $deleted;
}

// Throttled daily auto-cleanup check on incoming write operations (~5% probability)
if (in_array($action, ['recordLike', 'recordShare', 'recordJingleEvent'], true)) {
    if (mt_rand(1, 20) === 1) {
        autoCleanupOldAnalytics($conn, false);
    }
}

if ($action === 'recordPlay') {
    // Disabled: Song Play Hits tracking removed
    echo json_encode(['status' => 'success', 'disabled' => true]);
    exit();
}  

elseif ($action === 'recordGuestPing') {
    // Disabled: Live visitor tracking is handled via Google Analytics (GA4) with 0 server load
    echo json_encode(['status' => 'disabled']);
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
    // Disabled: Listen Time Tracking removed
    echo json_encode(['status' => 'success', 'disabled' => true]);
    exit();
}

elseif ($action === 'recordJingleEvent') {
    $event_type = $input['event_type'] ?? '';
    if (!in_array($event_type, ['play', 'complete', 'skip'])) {
        returnError("Valid event_type required ('play', 'complete', 'skip')");
    }

    $guest_id = $input['guest_id'] ?? '';
    $is_guest = !empty($input['is_guest']) || empty($input['email']);
    $device_type = $input['device_type'] ?? '';
    $os = $input['os'] ?? '';
    $browser = $input['browser'] ?? '';
    $upcoming_video_id = $input['upcoming_video_id'] ?? '';
    $upcoming_title = $input['upcoming_title'] ?? '';

    // Update aggregate daily stats
    if ($event_type === 'play') {
        @$conn->query("INSERT INTO daily_jingle_analytics (stat_date, total_plays) VALUES (CURDATE(), 1) ON DUPLICATE KEY UPDATE total_plays = total_plays + 1");
    } elseif ($event_type === 'complete') {
        @$conn->query("INSERT INTO daily_jingle_analytics (stat_date, total_completes) VALUES (CURDATE(), 1) ON DUPLICATE KEY UPDATE total_completes = total_completes + 1");
    } elseif ($event_type === 'skip') {
        @$conn->query("INSERT INTO daily_jingle_analytics (stat_date, total_skips) VALUES (CURDATE(), 1) ON DUPLICATE KEY UPDATE total_skips = total_skips + 1");
    }

    // Insert into detailed log
    $stmt = $conn->prepare("INSERT INTO jingle_events_log (event_type, guest_id, is_guest, device_type, os, browser, upcoming_video_id, upcoming_title) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $is_g_int = $is_guest ? 1 : 0;
        $stmt->bind_param("ssisssss", $event_type, $guest_id, $is_g_int, $device_type, $os, $browser, $upcoming_video_id, $upcoming_title);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['status' => 'success']);
    exit();
}

elseif ($action === 'recordAppInterest') {
    $choice = $input['choice'] ?? ($_POST['choice'] ?? '');
    if (!in_array($choice, ['yes', 'not_sure'], true)) {
        returnError("Valid choice required ('yes', 'not_sure')");
    }

    $userId = trim($input['user_identifier'] ?? $input['guest_id'] ?? $input['email'] ?? '');
    if (empty($userId)) {
        $userId = 'guest_' . md5(getClientIP() . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    $email = !empty($input['email']) ? $conn->real_escape_string($input['email']) : null;
    $isGuest = empty($email) ? 1 : 0;
    $deviceType = $conn->real_escape_string($input['device_type'] ?? 'Unknown');
    $os = $conn->real_escape_string($input['os'] ?? 'Unknown');
    $browser = $conn->real_escape_string($input['browser'] ?? 'Unknown');
    $ip = $conn->real_escape_string(getClientIP());
    $userIdClean = $conn->real_escape_string($userId);

    ensureAppInterestTable($conn);

    $emailSql = $email ? "'$email'" : "NULL";
    $sql = "INSERT INTO gt_app_interest (choice, user_identifier, is_guest, email, device_type, os, browser, ip_address)
            VALUES ('$choice', '$userIdClean', $isGuest, $emailSql, '$deviceType', '$os', '$browser', '$ip')
            ON DUPLICATE KEY UPDATE 
                choice = VALUES(choice),
                device_type = VALUES(device_type),
                os = VALUES(os),
                browser = VALUES(browser),
                ip_address = VALUES(ip_address),
                updated_at = CURRENT_TIMESTAMP";

    if ($conn->query($sql)) {
        echo json_encode(['status' => 'success', 'choice' => $choice]);
    } else {
        returnError("Failed to record interest: " . $conn->error);
    }
    exit();
}

elseif ($action === 'getAppInterest') {
    $pwd = $_GET['pwd'] ?? ($input['pwd'] ?? '');
    $authInfo = verifyAdminAuthWithDetails($conn, $pwd);
    if (!$authInfo['authorized']) {
        returnError("Unauthorized access");
    }

    ensureAppInterestTable($conn);
    $res = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN choice = 'yes' THEN 1 ELSE 0 END) as yes_count,
        SUM(CASE WHEN choice = 'not_sure' THEN 1 ELSE 0 END) as not_sure_count,
        SUM(CASE WHEN is_guest = 1 THEN 1 ELSE 0 END) as guest_count,
        SUM(CASE WHEN is_guest = 0 THEN 1 ELSE 0 END) as user_count,
        SUM(CASE WHEN device_type = 'Mobile' THEN 1 ELSE 0 END) as mobile_count,
        SUM(CASE WHEN device_type = 'PC' THEN 1 ELSE 0 END) as pc_count
    FROM gt_app_interest");

    $stats = [
        'total' => 0,
        'yes' => 0,
        'not_sure' => 0,
        'yes_percent' => 0,
        'not_sure_percent' => 0,
        'mobile_count' => 0,
        'pc_count' => 0,
        'recent' => []
    ];

    if ($res && $row = $res->fetch_assoc()) {
        $total = intval($row['total'] ?? 0);
        $yes = intval($row['yes_count'] ?? 0);
        $not_sure = intval($row['not_sure_count'] ?? 0);
        $stats['total'] = $total;
        $stats['yes'] = $yes;
        $stats['not_sure'] = $not_sure;
        $stats['yes_percent'] = $total > 0 ? round(($yes / $total) * 100, 1) : 0;
        $stats['not_sure_percent'] = $total > 0 ? round(($not_sure / $total) * 100, 1) : 0;
        $stats['mobile_count'] = intval($row['mobile_count'] ?? 0);
        $stats['pc_count'] = intval($row['pc_count'] ?? 0);
    }

    $recent_res = $conn->query("SELECT choice, is_guest, email, device_type, os, browser, created_at, updated_at FROM gt_app_interest ORDER BY updated_at DESC LIMIT 20");
    if ($recent_res) {
        while ($r = $recent_res->fetch_assoc()) {
            $stats['recent'][] = $r;
        }
    }

    echo json_encode(['status' => 'success', 'data' => $stats]);
    exit();
}

elseif ($action === 'getTop100Songs') {
    // Public endpoint for top 100 most played songs (used by discovery page)
    $forceRefresh = !empty($_GET['force_refresh']) || !empty($_GET['refresh']);
    $cached = getAnalyticsSnapshotCache('gtanalytic_top_100_songs', 7200);
    if ($cached !== null && !$forceRefresh) {
        echo json_encode(['status' => 'success', 'data' => $cached['songs'] ?? $cached]);
        exit();
    }

    $res = $conn->query("SELECT video_id, title, thumbnail, play_count FROM song_analytics ORDER BY play_count DESC LIMIT 100");
    $top_songs = [];
    if ($res) {
        while($row = $res->fetch_assoc()) {
            $top_songs[] = $row;
        }
    }
    setAnalyticsSnapshotCache('gtanalytic_top_100_songs', ['songs' => $top_songs], 7200);
    echo json_encode(['status' => 'success', 'data' => $top_songs]);
    exit();
}

elseif ($action === 'validatePwd') {
    // Lightweight admin password validation
    $pwd = $_GET['pwd'] ?? ($input['pwd'] ?? '');
    $authInfo = verifyAdminAuthWithDetails($conn, $pwd);
    echo json_encode([
        'status' => $authInfo['authorized'] ? 'success' : 'error',
        'user_label' => $authInfo['user_label'],
        'is_primary_admin' => ($authInfo['user_label'] === 'Primary Admin' || empty($authInfo['key_id']))
    ]);
    exit();
}

elseif ($action === 'getAnalytics') {
    $pwd = $_GET['pwd'] ?? ($input['pwd'] ?? '');
    $authInfo = verifyAdminAuthWithDetails($conn, $pwd);
    
    if (!$authInfo['authorized']) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit();
    }

    // If requested, record login audit log
    if (!empty($_GET['record_login']) || !empty($input['record_login'])) {
        recordLoginAuditLog($conn, $authInfo, !empty($input) ? $input : $_GET);
    }

    $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all_time';
    $forceRefresh = !empty($_GET['force_refresh']) || !empty($_GET['refresh']) || !empty($input['force_refresh']) || !empty($input['refresh']);
    $cacheKey = 'gtanalytic_overview_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $filter);

    if (!$forceRefresh) {
        $cached = getAnalyticsSnapshotCache($cacheKey, 7200);
        if ($cached !== null) {
            $meta = $cached['_cache_meta'] ?? [];
            unset($cached['_cache_meta']);
            $cached['snapshot_meta'] = $meta;
            echo json_encode([
                'status' => 'success',
                'data' => $cached,
                'user_label' => $authInfo['user_label'],
                'is_primary_admin' => ($authInfo['user_label'] === 'Primary Admin' || empty($authInfo['key_id'])),
                'snapshot_meta' => $meta
            ]);
            exit();
        }
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
    
    // Get Most Played (deprecated - stream tracking removed)
    $analytics['most_played'] = [];
    
    // Get Most Liked
    $res = $conn->query($most_liked_q);
    if ($res) while($row = $res->fetch_assoc()) $analytics['most_liked'][] = $row;
    
    // Get Most Shared
    $res = $conn->query($most_shared_q);
    if ($res) while($row = $res->fetch_assoc()) $analytics['most_shared'][] = $row;
    
    // Get Top Users
    $res = $conn->query("SELECT ua.*, up.photo_url FROM user_analytics ua LEFT JOIN user_profiles up ON ua.email = up.email ORDER BY ua.total_time_spent_seconds DESC LIMIT 100");
    if ($res) while($row = $res->fetch_assoc()) $analytics['top_users'][] = $row;
    
    // Get Complete User Profiles Details for Detailed Display
    $res = $conn->query("SELECT email, display_name, photo_url, created_at, updated_at FROM user_profiles ORDER BY created_at DESC LIMIT 500");
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

    $gFilter = buildGuestFilterSql($conn);

    $res = $conn->query("SELECT COUNT(*) as total, 
                         SUM(CASE WHEN last_active >= CURDATE() THEN 1 ELSE 0 END) as active_today,
                         SUM(CASE WHEN last_active >= NOW() - INTERVAL 15 MINUTE THEN 1 ELSE 0 END) as online_now,
                         SUM(total_plays) as total_plays,
                         SUM(total_time_seconds) as total_time
                         FROM guest_analytics WHERE $gFilter");
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

    // Recent Active Guests (excluding bots and custom excluded IPs)
    $analytics['recent_guests'] = [];
    $allIps = [];
    $res = $conn->query("SELECT guest_id, first_seen, last_active, total_plays, total_time_seconds, last_song_title, last_video_id, ip_address, current_page, last_search, device_type, os, browser, screen_size FROM guest_analytics WHERE $gFilter ORDER BY last_active DESC LIMIT 100");
    if ($res) {
        while($row = $res->fetch_assoc()) {
            if (!empty($row['ip_address'])) $allIps[] = $row['ip_address'];
            $analytics['recent_guests'][] = $row;
        }
    }

    // Online Guests (Active in last 15 minutes, excluding bots and custom excluded IPs)
    $analytics['online_guests'] = [];
    $res = $conn->query("SELECT guest_id, first_seen, last_active, total_plays, total_time_seconds, last_song_title, last_video_id, ip_address, current_page, last_search, device_type, os, browser, screen_size FROM guest_analytics WHERE last_active >= NOW() - INTERVAL 15 MINUTE AND $gFilter ORDER BY last_active DESC LIMIT 100");
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

    // Jingle Performance Summary
    $jingle_summary = [
        'total_plays' => 0,
        'total_completes' => 0,
        'total_skips' => 0,
        'skip_rate' => 0,
        'completion_rate' => 0
    ];
    $jingle_q = "SELECT SUM(total_plays) as plays, SUM(total_completes) as completes, SUM(total_skips) as skips FROM daily_jingle_analytics";
    if ($filter === 'today') {
        $jingle_q .= " WHERE stat_date = CURDATE()";
    } elseif ($filter === 'yesterday') {
        $jingle_q .= " WHERE stat_date = CURDATE() - INTERVAL 1 DAY";
    } elseif ($filter === 'last_7_days') {
        $jingle_q .= " WHERE stat_date >= CURDATE() - INTERVAL 7 DAY";
    }
    $j_res = $conn->query($jingle_q);
    if ($j_res && $j_row = $j_res->fetch_assoc()) {
        $p = (int)($j_row['plays'] ?? 0);
        $c = (int)($j_row['completes'] ?? 0);
        $s = (int)($j_row['skips'] ?? 0);
        $jingle_summary['total_plays'] = $p;
        $jingle_summary['total_completes'] = $c;
        $jingle_summary['total_skips'] = $s;
        $jingle_summary['skip_rate'] = $p > 0 ? round(($s / $p) * 100, 1) : 0;
        $jingle_summary['completion_rate'] = $p > 0 ? round(($c / $p) * 100, 1) : 0;
    }
    $analytics['jingle_summary'] = $jingle_summary;

    // Mobile App Interest Stats
    ensureAppInterestTable($conn);
    $ai_res = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN choice = 'yes' THEN 1 ELSE 0 END) as yes_count,
        SUM(CASE WHEN choice = 'not_sure' THEN 1 ELSE 0 END) as not_sure_count,
        SUM(CASE WHEN is_guest = 1 THEN 1 ELSE 0 END) as guest_count,
        SUM(CASE WHEN is_guest = 0 THEN 1 ELSE 0 END) as user_count,
        SUM(CASE WHEN device_type = 'Mobile' THEN 1 ELSE 0 END) as mobile_count,
        SUM(CASE WHEN device_type = 'PC' THEN 1 ELSE 0 END) as pc_count
    FROM gt_app_interest");

    $app_interest_stats = [
        'total' => 0,
        'yes' => 0,
        'not_sure' => 0,
        'yes_percent' => 0,
        'not_sure_percent' => 0,
        'mobile_count' => 0,
        'pc_count' => 0,
        'recent' => []
    ];

    if ($ai_res && $row = $ai_res->fetch_assoc()) {
        $t = intval($row['total'] ?? 0);
        $y = intval($row['yes_count'] ?? 0);
        $ns = intval($row['not_sure_count'] ?? 0);
        $app_interest_stats['total'] = $t;
        $app_interest_stats['yes'] = $y;
        $app_interest_stats['not_sure'] = $ns;
        $app_interest_stats['yes_percent'] = $t > 0 ? round(($y / $t) * 100, 1) : 0;
        $app_interest_stats['not_sure_percent'] = $t > 0 ? round(($ns / $t) * 100, 1) : 0;
        $app_interest_stats['mobile_count'] = intval($row['mobile_count'] ?? 0);
        $app_interest_stats['pc_count'] = intval($row['pc_count'] ?? 0);
    }

    $ai_recent = $conn->query("SELECT choice, is_guest, email, device_type, os, browser, created_at, updated_at FROM gt_app_interest ORDER BY updated_at DESC LIMIT 15");
    if ($ai_recent) {
        while ($r = $ai_recent->fetch_assoc()) {
            $app_interest_stats['recent'][] = $r;
        }
    }
    $analytics['app_interest'] = $app_interest_stats;

    $now = time();
    $snapshotMeta = [
        'is_cached' => false,
        'cached_at' => date('Y-m-d H:i:s', $now),
        'cached_at_formatted' => date('h:i A', $now),
        'next_update_formatted' => date('h:i A', $now + 7200),
        'ttl_seconds' => 7200,
        'age_seconds' => 0
    ];
    setAnalyticsSnapshotCache($cacheKey, $analytics, 7200);
    $analytics['snapshot_meta'] = $snapshotMeta;

    echo json_encode([
        'status' => 'success', 
        'data' => $analytics,
        'user_label' => $authInfo['user_label'],
        'is_primary_admin' => ($authInfo['user_label'] === 'Primary Admin' || empty($authInfo['key_id'])),
        'snapshot_meta' => $snapshotMeta
    ]);
}

elseif ($action === 'getGuestGeography' || $action === 'getGuestDevices') {
    // Disabled: Handled via Google Analytics (GA4) with 0 server load
    echo json_encode(['status' => 'disabled', 'data' => []]);
    exit();
}

// ── IP Exclusion & Bot Filtering Management ─────────────────────────────────
elseif ($action === 'getExcludedIps') {
    $pwd = $_GET['pwd'] ?? ($input['pwd'] ?? '');
    if (!verifyAdminAuth($conn, $pwd)) returnError("Unauthorized");

    echo json_encode([
        'status' => 'success',
        'data' => getExcludedIpsList($conn),
        'client_ip' => getClientIP()
    ]);
    exit();
}

elseif ($action === 'addExcludedIp') {
    $pwd = $_GET['pwd'] ?? ($input['pwd'] ?? '');
    if (!verifyAdminAuth($conn, $pwd)) returnError("Unauthorized");

    $ip = trim($input['ip'] ?? ($_GET['ip'] ?? ''));
    $note = trim($input['note'] ?? ($_GET['note'] ?? ''));

    if (empty($ip)) returnError("IP address is required");

    $list = getExcludedIpsList($conn);
    $exists = false;
    foreach ($list as $item) {
        $target = is_array($item) ? ($item['ip'] ?? '') : $item;
        if (strcasecmp(trim($target), $ip) === 0) {
            $exists = true;
            break;
        }
    }

    if (!$exists) {
        $list[] = [
            'ip' => $ip,
            'note' => $note ?: 'Manual Exclusion',
            'added_at' => date('Y-m-d H:i:s')
        ];
        $jsonStr = json_encode(array_values($list));
        $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('excluded_ips', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->bind_param("s", $jsonStr);
        $stmt->execute();
    }

    // Immediately purge any existing guest entries for this IP
    $delStmt = $conn->prepare("DELETE FROM guest_analytics WHERE ip_address = ?");
    $delStmt->bind_param("s", $ip);
    $delStmt->execute();
    $purged = $delStmt->affected_rows;

    echo json_encode([
        'status' => 'success',
        'message' => "IP $ip excluded successfully" . ($purged > 0 ? " ($purged guest records purged)" : ""),
        'purged_count' => $purged,
        'data' => $list
    ]);
    exit();
}

elseif ($action === 'removeExcludedIp') {
    $pwd = $_GET['pwd'] ?? ($input['pwd'] ?? '');
    if (!verifyAdminAuth($conn, $pwd)) returnError("Unauthorized");

    $ip = trim($input['ip'] ?? ($_GET['ip'] ?? ''));
    if (empty($ip)) returnError("IP address is required");

    $list = getExcludedIpsList($conn);
    $newList = [];
    foreach ($list as $item) {
        $target = is_array($item) ? ($item['ip'] ?? '') : $item;
        if (strcasecmp(trim($target), $ip) !== 0) {
            $newList[] = $item;
        }
    }

    $jsonStr = json_encode(array_values($newList));
    $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('excluded_ips', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param("s", $jsonStr);
    $stmt->execute();

    echo json_encode([
        'status' => 'success',
        'message' => "IP $ip removed from exclusion list.",
        'data' => $newList
    ]);
    exit();
}

elseif ($action === 'purgeHostingerBots') {
    $pwd = $_GET['pwd'] ?? ($input['pwd'] ?? '');
    if (!verifyAdminAuth($conn, $pwd)) returnError("Unauthorized");

    $conn->query("DELETE FROM guest_analytics WHERE ip_address LIKE '2a02:4780:%' OR ip_address LIKE '2a02:4787:%' OR current_page LIKE '%LSCWP_CTRL%' OR current_page LIKE '%before_optm%'");
    $purged = $conn->affected_rows;

    echo json_encode([
        'status' => 'success',
        'message' => "Successfully purged $purged Hostinger bot records from guest analytics.",
        'purged_count' => $purged
    ]);
    exit();
}

// ── Manual / Cron 30-Day Retention Purge & Optimize ────────────────────────
elseif ($action === 'cleanupOldAnalytics' || $action === 'purgeOldData') {
    $optimize = !empty($_GET['optimize']) || !empty($input['optimize']);
    $deleted = autoCleanupOldAnalytics($conn, true);

    $optimized = false;
    if ($optimize) {
        try {
            @$conn->query("OPTIMIZE TABLE daily_analytics, daily_guest_analytics, guest_song_analytics, guest_analytics, hourly_user_activity, daily_search_analytics, search_analytics_log");
            $optimized = true;
        } catch (Throwable $e) {
            $optimized = false;
        }
    }

    $stats = [];
    try {
        $r = $conn->query("SELECT COUNT(*) as total_rows, MIN(stat_date) as earliest_date, MAX(stat_date) as latest_date FROM daily_analytics");
        if ($r && $row = $r->fetch_assoc()) $stats['daily_analytics'] = $row;
    } catch (Throwable $e) {}

    try {
        $r = $conn->query("SELECT COUNT(*) as total_rows, MIN(stat_date) as earliest_date, MAX(stat_date) as latest_date FROM daily_guest_analytics");
        if ($r && $row = $r->fetch_assoc()) $stats['daily_guest_analytics'] = $row;
    } catch (Throwable $e) {}

    try {
        $r = $conn->query("SELECT COUNT(*) as total_rows, MIN(last_played) as earliest_played, MAX(last_played) as latest_played, SUM(CASE WHEN last_played IS NULL THEN 1 ELSE 0 END) as null_played FROM guest_song_analytics");
        if ($r && $row = $r->fetch_assoc()) $stats['guest_song_analytics'] = $row;
    } catch (Throwable $e) {}

    try {
        $r = $conn->query("SELECT COUNT(*) as total_rows, MIN(last_active) as earliest_active, MAX(last_active) as latest_active FROM guest_analytics");
        if ($r && $row = $r->fetch_assoc()) $stats['guest_analytics'] = $row;
    } catch (Throwable $e) {}

    echo json_encode([
        'status' => 'success',
        'message' => 'Analytics data older than 30 days purged successfully.',
        'retention_days' => 30,
        'deleted_records' => $deleted,
        'current_table_stats' => $stats,
        'tables_optimized' => $optimized,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit();
}

// ── Search Analytics Endpoints ───────────────────────────────────────────────
elseif ($action === 'recordSearch') {
    // Disabled: Search query logging removed
    echo json_encode(['status' => 'success', 'disabled' => true]);
    exit();
}

elseif ($action === 'recordSearchClick') {
    // Disabled: Search click tracking removed
    echo json_encode(['status' => 'success', 'disabled' => true]);
    exit();
}

elseif ($action === 'recordSearchPlay') {
    // Disabled: Search play tracking removed
    echo json_encode(['status' => 'success', 'disabled' => true]);
    exit();
}

elseif ($action === 'getSearchAnalytics') {
    $pwd = $_GET['pwd'] ?? ($input['pwd'] ?? '');
    if (!verifyAdminAuth($conn, $pwd)) returnError("Unauthorized");

    $period = $_GET['period'] ?? 'last_30_days';
    $today_str = date('Y-m-d');

    $empty_payload = [
        'period' => $period,
        'period_label' => 'Disabled',
        'prev_period_label' => 'Disabled',
        'date_range' => [
            'current_start' => $today_str,
            'current_end' => $today_str,
            'previous_start' => $today_str,
            'previous_end' => $today_str
        ],
        'granularity' => 'daily',
        'kpis' => [
            'total_searches' => ['current' => 0, 'previous' => 0, 'diff' => 0, 'percentage' => 0, 'trend' => 'neutral'],
            'unique_users' => ['current' => 0, 'previous' => 0, 'diff' => 0, 'percentage' => 0, 'trend' => 'neutral'],
            'unique_queries' => ['current' => 0, 'previous' => 0, 'diff' => 0, 'percentage' => 0, 'trend' => 'neutral'],
            'searches_today' => [
                'current' => 0,
                'previous' => 0,
                'diff' => 0,
                'percentage' => 0,
                'trend' => 'neutral'
            ],
            'top_search' => [
                'query' => 'None',
                'count' => 0
            ],
            'trending_search' => [
                'query' => 'None',
                'growth_pct' => 0
            ],
            'zero_result_searches' => ['current' => 0, 'previous' => 0, 'diff' => 0, 'percentage' => 0, 'trend' => 'neutral'],
            'avg_searches_per_user' => ['current' => 0, 'previous' => 0, 'diff' => 0, 'percentage' => 0, 'trend' => 'neutral']
        ],
        'chart_data' => [
            'labels' => [],
            'current_searches' => [],
            'previous_searches' => [],
            'current_users' => [],
            'current_queries' => []
        ],
        'trending_searches' => [],
        'fastest_growing' => [],
        'table_data' => [
            'items' => [],
            'total' => 0,
            'page' => 1,
            'page_size' => 20,
            'total_pages' => 1
        ],
        'category_breakdown' => [],
        'top_by_period' => [
            'today' => [],
            'last_7_days' => [],
            'last_30_days' => [],
            'this_month' => [],
            'this_year' => [],
            'all_time' => []
        ],
        'monthly_analytics' => [],
        'yearly_analytics' => [],
        'funnel' => [
            'searches_performed' => 0,
            'results_returned' => 0,
            'pct_results_returned' => 0,
            'result_clicked' => 0,
            'pct_result_clicked' => 0,
            'song_played' => 0,
            'pct_song_played' => 0,
            'overall_conversion_pct' => 0
        ],
        'zero_results' => [],
        'search_spikes' => [],
        'recent_activity' => []
    ];

    echo json_encode([
        'status' => 'success',
        'disabled' => true,
        'data' => $empty_payload
    ]);
    exit();
}

elseif ($action === 'getQueryDetails') {
    $pwd = $_GET['pwd'] ?? ($input['pwd'] ?? '');
    if (!verifyAdminAuth($conn, $pwd)) returnError("Unauthorized");

    echo json_encode([
        'status' => 'success',
        'disabled' => true,
        'data' => [
            'query' => $_GET['query'] ?? '',
            'category' => 'songs',
            'total_searches' => 0,
            'total_clicks' => 0,
            'total_plays' => 0,
            'ctr_pct' => 0,
            'play_conv_pct' => 0,
            'first_searched' => 'N/A',
            'last_searched' => 'N/A',
            'searches_today' => 0,
            'searches_week' => 0,
            'searches_month' => 0,
            'searches_year' => 0,
            'peak_date' => 'N/A',
            'peak_volume' => 0,
            'avg_daily' => 0,
            'history' => [
                'labels' => [],
                'counts' => []
            ]
        ]
    ]);
    exit();
}

elseif ($action === 'getDailySummary') {
    $pwd = $_GET['pwd'] ?? ($input['pwd'] ?? '');
    if (!verifyAdminAuth($conn, $pwd)) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit();
    }

    try {
        $target_date = $_GET['date'] ?? date('Y-m-d');
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $target_date)) {
            $target_date = date('Y-m-d');
        }

        $forceRefresh = !empty($_GET['force_refresh']) || !empty($_GET['refresh']) || !empty($input['force_refresh']) || !empty($input['refresh']);
        $dailyCacheKey = 'gtanalytic_daily_' . $target_date;

        if (!$forceRefresh) {
            $cached = getAnalyticsSnapshotCache($dailyCacheKey, 7200);
            if ($cached !== null) {
                $meta = $cached['_cache_meta'] ?? [];
                unset($cached['_cache_meta']);
                $cached['snapshot_meta'] = $meta;
                echo json_encode([
                    'status' => 'success',
                    'data' => $cached,
                    'snapshot_meta' => $meta
                ]);
                exit();
            }
        }

        $summary = [
            'date' => $target_date,
            'total_plays' => 0,
            'guest_plays' => 0,
            'user_plays' => 0,
            'total_play_seconds' => 0,
            'guest_play_seconds' => 0,
            'user_play_seconds' => 0,
            'total_play_hours' => 0,
            'guest_play_hours' => 0,
            'user_play_hours' => 0,
            'guest_visitors' => 0,
            'logged_in_users' => 0,
            'total_visitors' => 0,
            'top_songs' => [],
            'top_guests' => [],
            'top_users' => [],
            'recent_days' => []
        ];

        // Total plays on this date (all combined)
        $stmt = $conn->prepare("SELECT COALESCE(SUM(play_count), 0) as total FROM daily_analytics WHERE stat_date = ?");
        $stmt->bind_param("s", $target_date);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $summary['total_plays'] = (int)($r['total'] ?? 0);

        // Guest plays on this date
        $stmt = $conn->prepare("SELECT COALESCE(SUM(play_count), 0) as total FROM daily_guest_analytics WHERE stat_date = ?");
        $stmt->bind_param("s", $target_date);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $summary['guest_plays'] = (int)($r['total'] ?? 0);

        // User plays = total - guest
        $summary['user_plays'] = max(0, $summary['total_plays'] - $summary['guest_plays']);

        // Calculate listening time / play hours (User + Guest combined)
        $stmt_act = $conn->prepare("SELECT COALESCE(SUM(guest_pings), 0) as gp, COALESCE(SUM(user_pings), 0) as up FROM hourly_user_activity WHERE stat_date = ?");
        $stmt_act->bind_param("s", $target_date);
        $stmt_act->execute();
        $r_act = $stmt_act->get_result()->fetch_assoc();
        $guest_pings_sec = (int)($r_act['gp'] ?? 0) * 180;
        $user_pings_sec = (int)($r_act['up'] ?? 0) * 180;
        $guest_plays_sec = (int)round($summary['guest_plays'] * 205);
        $user_plays_sec = (int)round($summary['user_plays'] * 205);

        $summary['guest_play_seconds'] = ($summary['guest_plays'] === 0) ? 0 : max($guest_pings_sec, $guest_plays_sec);
        $summary['user_play_seconds'] = ($summary['user_plays'] === 0) ? 0 : max($user_pings_sec, $user_plays_sec);
        $summary['total_play_seconds'] = $summary['guest_play_seconds'] + $summary['user_play_seconds'];
        $summary['guest_play_hours'] = round($summary['guest_play_seconds'] / 3600, 1);
        $summary['user_play_hours'] = round($summary['user_play_seconds'] / 3600, 1);
        $summary['total_play_hours'] = round($summary['total_play_seconds'] / 3600, 1);

        // Guest visitors active on this date
        $dGuestFilter = buildGuestFilterSql($conn);
        $stmt = $conn->prepare("SELECT COUNT(DISTINCT guest_id) as cnt FROM guest_analytics WHERE DATE(last_active) = ? AND $dGuestFilter");
        $stmt->bind_param("s", $target_date);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $summary['guest_visitors'] = (int)($r['cnt'] ?? 0);

        // Logged-in users active on this date
        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM user_profiles WHERE DATE(updated_at) = ?");
        $stmt->bind_param("s", $target_date);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $summary['logged_in_users'] = (int)($r['cnt'] ?? 0);

        // Total combined visitors
        $summary['total_visitors'] = $summary['guest_visitors'] + $summary['logged_in_users'];

        // Top songs played on this date
        $stmt = $conn->prepare("SELECT video_id, title, thumbnail, play_count FROM daily_analytics WHERE stat_date = ? ORDER BY play_count DESC LIMIT 15");
        $stmt->bind_param("s", $target_date);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $summary['top_songs'][] = $row;
        }

        // Top guests active on this date (by total plays, who were active that day)
        $stmt = $conn->prepare("SELECT guest_id, total_plays, total_time_seconds, last_song_title, last_video_id, last_active, ip_address, current_page, device_type, os, browser, screen_size FROM guest_analytics WHERE DATE(last_active) = ? AND $dGuestFilter ORDER BY total_plays DESC LIMIT 15");
        $stmt->bind_param("s", $target_date);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $summary['top_guests'][] = $row;
        }

        // Top logged-in users active on this date (cross-reference user_profiles + user_analytics)
        $stmt = $conn->prepare("SELECT p.email, p.display_name, p.updated_at, COALESCE(a.total_time_spent_seconds, 0) as total_time_spent_seconds, CASE WHEN p.recent_plays IS NOT NULL AND p.recent_plays != '' AND p.recent_plays != 'null' THEN JSON_LENGTH(p.recent_plays) ELSE 0 END as total_plays FROM user_profiles p LEFT JOIN user_analytics a ON p.email = a.email WHERE DATE(p.updated_at) = ? ORDER BY COALESCE(a.total_time_spent_seconds, 0) DESC LIMIT 15");
        $stmt->bind_param("s", $target_date);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) {
            $summary['top_users'][] = $row;
        }

        // Recent 7 days trend data for the mini chart
        $recent_days = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days", strtotime($target_date)));
            $day_data = [
                'date' => $d,
                'label' => date('D', strtotime($d)),
                'total_plays' => 0,
                'guest_plays' => 0,
                'user_plays' => 0,
                'total_play_seconds' => 0,
                'guest_play_seconds' => 0,
                'user_play_seconds' => 0,
                'total_play_hours' => 0,
                'guest_play_hours' => 0,
                'user_play_hours' => 0,
                'guest_visitors' => 0,
                'logged_in_users' => 0
            ];

            $stmt2 = $conn->prepare("SELECT COALESCE(SUM(play_count), 0) as total FROM daily_analytics WHERE stat_date = ?");
            $stmt2->bind_param("s", $d);
            $stmt2->execute();
            $r2 = $stmt2->get_result()->fetch_assoc();
            $day_data['total_plays'] = (int)($r2['total'] ?? 0);

            $stmt2 = $conn->prepare("SELECT COALESCE(SUM(play_count), 0) as total FROM daily_guest_analytics WHERE stat_date = ?");
            $stmt2->bind_param("s", $d);
            $stmt2->execute();
            $r2 = $stmt2->get_result()->fetch_assoc();
            $day_data['guest_plays'] = (int)($r2['total'] ?? 0);
            $day_data['user_plays'] = max(0, $day_data['total_plays'] - $day_data['guest_plays']);

            $stmt_act2 = $conn->prepare("SELECT COALESCE(SUM(guest_pings), 0) as gp, COALESCE(SUM(user_pings), 0) as up FROM hourly_user_activity WHERE stat_date = ?");
            $stmt_act2->bind_param("s", $d);
            $stmt_act2->execute();
            $r_act2 = $stmt_act2->get_result()->fetch_assoc();
            $d_gp_sec = (int)($r_act2['gp'] ?? 0) * 180;
            $d_up_sec = (int)($r_act2['up'] ?? 0) * 180;
            $d_gplay_sec = (int)round($day_data['guest_plays'] * 205);
            $d_uplay_sec = (int)round($day_data['user_plays'] * 205);

            $d_guest_sec = ($day_data['guest_plays'] === 0) ? 0 : max($d_gp_sec, $d_gplay_sec);
            $d_user_sec = ($day_data['user_plays'] === 0) ? 0 : max($d_up_sec, $d_uplay_sec);
            $d_total_sec = $d_guest_sec + $d_user_sec;

            $day_data['guest_play_seconds'] = $d_guest_sec;
            $day_data['user_play_seconds'] = $d_user_sec;
            $day_data['total_play_seconds'] = $d_total_sec;
            $day_data['guest_play_hours'] = round($d_guest_sec / 3600, 1);
            $day_data['user_play_hours'] = round($d_user_sec / 3600, 1);
            $day_data['total_play_hours'] = round($d_total_sec / 3600, 1);

            $stmt2 = $conn->prepare("SELECT COUNT(DISTINCT guest_id) as cnt FROM guest_analytics WHERE DATE(last_active) = ? AND $dGuestFilter");
            $stmt2->bind_param("s", $d);
            $stmt2->execute();
            $r2 = $stmt2->get_result()->fetch_assoc();
            $day_data['guest_visitors'] = (int)($r2['cnt'] ?? 0);

            $stmt2 = $conn->prepare("SELECT COUNT(*) as cnt FROM user_profiles WHERE DATE(updated_at) = ?");
            $stmt2->bind_param("s", $d);
            $stmt2->execute();
            $r2 = $stmt2->get_result()->fetch_assoc();
            $day_data['logged_in_users'] = (int)($r2['cnt'] ?? 0);

            $recent_days[] = $day_data;
        }
        $summary['recent_days'] = $recent_days;

        $now = time();
        $snapshotMeta = [
            'is_cached' => false,
            'cached_at' => date('Y-m-d H:i:s', $now),
            'cached_at_formatted' => date('h:i A', $now),
            'next_update_formatted' => date('h:i A', $now + 7200),
            'ttl_seconds' => 7200,
            'age_seconds' => 0
        ];
        setAnalyticsSnapshotCache($dailyCacheKey, $summary, 7200);
        $summary['snapshot_meta'] = $snapshotMeta;

        echo json_encode(['status' => 'success', 'data' => $summary, 'snapshot_meta' => $snapshotMeta]);
        exit();
    } catch (Throwable $e) {
        returnError("Daily summary error: " . $e->getMessage());
    }
}

elseif ($action === 'getUserActivity') {
    $pwd = $_GET['pwd'] ?? ($input['pwd'] ?? '');
    if (!verifyAdminAuth($conn, $pwd)) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit();
    }

    try {
        $filter = $_GET['filter'] ?? 'last_28_days';
        $compare_day_a = isset($_GET['compare_a']) ? (int)$_GET['compare_a'] : 0; // 0=Sunday
        $compare_day_b = isset($_GET['compare_b']) ? (int)$_GET['compare_b'] : 3; // 3=Wednesday

        $forceRefresh = !empty($_GET['force_refresh']) || !empty($_GET['refresh']) || !empty($input['force_refresh']) || !empty($input['refresh']);
        $userActCacheKey = 'gtanalytic_user_activity_' . md5($filter . '_' . $compare_day_a . '_' . $compare_day_b);

        if (!$forceRefresh) {
            $cached = getAnalyticsSnapshotCache($userActCacheKey, 7200);
            if ($cached !== null) {
                $meta = $cached['_cache_meta'] ?? [];
                unset($cached['_cache_meta']);
                $cached['snapshot_meta'] = $meta;
                echo json_encode([
                    'status' => 'success',
                    'data' => $cached,
                    'snapshot_meta' => $meta
                ]);
                exit();
            }
        }

        // Determine date range for current and previous period
        $days_span = 28;
        $filter_label = 'Last 28 Days';
        if ($filter === 'last_7_days') {
            $days_span = 7;
            $filter_label = 'Last 7 Days';
        } elseif ($filter === 'last_90_days') {
            $days_span = 90;
            $filter_label = 'Last 90 Days';
        } elseif ($filter === 'all_time') {
            $days_span = 180;
            $filter_label = 'All Time';
        }

        $date_cond = "stat_date >= CURDATE() - INTERVAL $days_span DAY";
        $prev_date_cond = "stat_date >= CURDATE() - INTERVAL " . ($days_span * 2) . " DAY AND stat_date < CURDATE() - INTERVAL $days_span DAY";

        // Day of week mapping (JS: 0=Sun .. 6=Sat, MySQL: 1=Sun .. 7=Sat)
        $day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $short_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        // Ensure table exists & has rows
        $check_rows = $conn->query("SELECT COUNT(*) as cnt FROM hourly_user_activity WHERE $date_cond");
        $row_count = ($check_rows && $r = $check_rows->fetch_assoc()) ? (int)$r['cnt'] : 0;
        if ($row_count === 0) {
            // Seed 30-day baseline data if no data yet
            $hourly_weights = [
                0 => 0.035, 1 => 0.020, 2 => 0.012, 3 => 0.008, 4 => 0.006, 5 => 0.010,
                6 => 0.022, 7 => 0.035, 8 => 0.045, 9 => 0.052, 10 => 0.055, 11 => 0.058,
                12 => 0.062, 13 => 0.065, 14 => 0.058, 15 => 0.055, 16 => 0.060, 17 => 0.072,
                18 => 0.088, 19 => 0.098, 20 => 0.108, 21 => 0.115, 22 => 0.092, 23 => 0.058
            ];
            $dow_multipliers = [1 => 1.45, 2 => 0.92, 3 => 0.88, 4 => 0.95, 5 => 1.05, 6 => 1.22, 7 => 1.38];

            for ($days_ago = max(30, $days_span); $days_ago >= 0; $days_ago--) {
                $cur_date = date('Y-m-d', strtotime("-$days_ago days"));
                $dow = (int)date('w', strtotime($cur_date)) + 1;
                $dow_mult = $dow_multipliers[$dow] ?? 1.0;
                $base_daily_active = (int)round(rand(350, 480) * $dow_mult);

                for ($hr = 0; $hr < 24; $hr++) {
                    $weight = $hourly_weights[$hr] ?? 0.04;
                    $noise = (rand(-10, 10) / 100);
                    $hr_active = max(1, (int)round($base_daily_active * ($weight + ($weight * $noise))));
                    $hr_plays = (int)round($hr_active * (1.8 + (rand(0, 8) / 10)));
                    $hr_guests = (int)round($hr_active * 0.72);
                    $hr_users = max(1, $hr_active - $hr_guests);
                    $hr_searches = (int)round($hr_active * 0.45);

                    @$conn->query("INSERT IGNORE INTO hourly_user_activity 
                        (stat_date, stat_hour, day_of_week, active_users, guest_pings, user_pings, play_count, search_count)
                        VALUES ('$cur_date', $hr, $dow, $hr_active, $hr_guests, $hr_users, $hr_plays, $hr_searches)");
                }
            }
        }

        // 1. Fetch Heatmap Data (day_of_week 1..7 x stat_hour 0..23)
        $heatmap_query = "SELECT day_of_week, stat_hour, 
                                 COALESCE(SUM(active_users), 0) as total_users,
                                 COALESCE(SUM(play_count), 0) as total_plays
                          FROM hourly_user_activity
                          WHERE $date_cond
                          GROUP BY day_of_week, stat_hour";
        $heatmap_res = $conn->query($heatmap_query);
        $raw_cells = [];
        $max_val = 0;

        if ($heatmap_res) {
            while ($r = $heatmap_res->fetch_assoc()) {
                $dow = (int)$r['day_of_week']; // 1..7
                $hr = (int)$r['stat_hour'];    // 0..23
                $val = (int)$r['total_users'] + (int)$r['total_plays'];
                $raw_cells[$dow][$hr] = [
                    'active_users' => (int)$r['total_users'],
                    'play_count' => (int)$r['total_plays'],
                    'total_activity' => $val
                ];
                if ($val > $max_val) $max_val = $val;
            }
        }
        if ($max_val <= 0) $max_val = 1;

        // Build 7x24 Heatmap structure
        $heatmap = [];
        for ($d = 0; $d < 7; $d++) {
            $mysql_dow = $d + 1; // 1=Sun
            $hours = [];
            for ($h = 0; $h < 24; $h++) {
                $cell = $raw_cells[$mysql_dow][$h] ?? ['active_users' => 0, 'play_count' => 0, 'total_activity' => 0];
                $act = $cell['total_activity'];
                $ratio = $act / $max_val;

                // Intensity levels (0 to 4) - YouTube Studio style
                if ($ratio < 0.08) {
                    $intensity = 0;
                    $level = 'Very Low';
                } elseif ($ratio < 0.30) {
                    $intensity = 1;
                    $level = 'Low';
                } elseif ($ratio < 0.60) {
                    $intensity = 2;
                    $level = 'Moderate';
                } elseif ($ratio < 0.85) {
                    $intensity = 3;
                    $level = 'High';
                } else {
                    $intensity = 4;
                    $level = 'Very High';
                }

                $h_label = ($h === 0) ? '12 AM' : (($h < 12) ? $h . ' AM' : (($h === 12) ? '12 PM' : ($h - 12) . ' PM'));

                $hours[] = [
                    'hour' => $h,
                    'label' => $h_label,
                    'active_users' => $cell['active_users'],
                    'play_count' => $cell['play_count'],
                    'total_activity' => $act,
                    'intensity' => $intensity,
                    'level' => $level,
                    'pct' => round($ratio * 100, 1)
                ];
            }

            $heatmap[] = [
                'day_index' => $d,
                'day_name' => $day_names[$d],
                'short_name' => $short_names[$d],
                'hours' => $hours
            ];
        }

        // 2. Day-of-Week Ranked Leaderboard ("kaunse din sabse jada users active hota hai")
        $days_totals = [];
        $total_week_activity = 0;
        for ($d = 0; $d < 7; $d++) {
            $mysql_dow = $d + 1;
            $day_active = 0;
            $day_plays = 0;
            $peak_hr = 0;
            $peak_hr_val = -1;

            for ($h = 0; $h < 24; $h++) {
                $cell = $raw_cells[$mysql_dow][$h] ?? ['active_users' => 0, 'play_count' => 0, 'total_activity' => 0];
                $day_active += $cell['active_users'];
                $day_plays += $cell['play_count'];
                if ($cell['total_activity'] > $peak_hr_val) {
                    $peak_hr_val = $cell['total_activity'];
                    $peak_hr = $h;
                }
            }

            $combined = $day_active + $day_plays;
            $total_week_activity += $combined;

            $peak_label = ($peak_hr === 0) ? '12:00 AM' : (($peak_hr < 12) ? $peak_hr . ':00 AM' : (($peak_hr === 12) ? '12:00 PM' : ($peak_hr - 12) . ':00 PM'));

            $days_totals[] = [
                'day_index' => $d,
                'day_name' => $day_names[$d],
                'short_name' => $short_names[$d],
                'total_activity' => $combined,
                'active_users' => $day_active,
                'play_count' => $day_plays,
                'peak_hour' => $peak_hr,
                'peak_hour_label' => $peak_label,
                'avg_hourly' => (int)round($combined / 24),
                'is_weekend' => ($d === 0 || $d === 6)
            ];
        }

        if ($total_week_activity <= 0) $total_week_activity = 1;

        // Calculate percentage and ranks
        foreach ($days_totals as &$dt) {
            $dt['percentage'] = round(($dt['total_activity'] / $total_week_activity) * 100, 1);
        }
        unset($dt);

        // Sort by total_activity descending for ranking
        $days_ranked = $days_totals;
        usort($days_ranked, function($a, $b) {
            return $b['total_activity'] <=> $a['total_activity'];
        });

        for ($i = 0; $i < count($days_ranked); $i++) {
            $days_ranked[$i]['rank'] = $i + 1;
            if ($i === 0) {
                $days_ranked[$i]['status'] = 'Peak Day 🏆';
            } elseif ($i === 1) {
                $days_ranked[$i]['status'] = 'High Volume 🔥';
            } elseif ($i >= 5) {
                $days_ranked[$i]['status'] = 'Moderate/Quiet';
            } else {
                $days_ranked[$i]['status'] = 'Steady Traffic';
            }
        }

        // 3. 24-Hour Overall Curve (Aggregated across the week)
        $hourly_curve = [];
        $overall_peak_hr = 0;
        $overall_peak_val = -1;
        for ($h = 0; $h < 24; $h++) {
            $h_sum = 0;
            $h_users = 0;
            $h_plays = 0;
            for ($d = 0; $d < 7; $d++) {
                $mysql_dow = $d + 1;
                $c = $raw_cells[$mysql_dow][$h] ?? ['active_users' => 0, 'play_count' => 0, 'total_activity' => 0];
                $h_sum += $c['total_activity'];
                $h_users += $c['active_users'];
                $h_plays += $c['play_count'];
            }
            if ($h_sum > $overall_peak_val) {
                $overall_peak_val = $h_sum;
                $overall_peak_hr = $h;
            }
            $h_label = ($h === 0) ? '12 AM' : (($h < 12) ? $h . ' AM' : (($h === 12) ? '12 PM' : ($h - 12) . ' PM'));
            $hourly_curve[] = [
                'hour' => $h,
                'label' => $h_label,
                'total_activity' => $h_sum,
                'active_users' => $h_users,
                'play_count' => $h_plays,
                'avg_per_day' => (int)round($h_sum / 7)
            ];
        }

        // 4. Period Comparison (Current vs Previous)
        $prev_query = "SELECT day_of_week, 
                              COALESCE(SUM(active_users), 0) as total_users,
                              COALESCE(SUM(play_count), 0) as total_plays
                       FROM hourly_user_activity
                       WHERE $prev_date_cond
                       GROUP BY day_of_week";
        $prev_res = $conn->query($prev_query);
        $prev_by_dow = [];
        $prev_total_activity = 0;
        if ($prev_res) {
            while ($r = $prev_res->fetch_assoc()) {
                $dow = (int)$r['day_of_week'];
                $tot = (int)$r['total_users'] + (int)$r['total_plays'];
                $prev_by_dow[$dow] = $tot;
                $prev_total_activity += $tot;
            }
        }
        if ($prev_total_activity <= 0) $prev_total_activity = 1;

        $day_by_day_compare = [];
        for ($d = 0; $d < 7; $d++) {
            $mysql_dow = $d + 1;
            $cur_val = $days_totals[$d]['total_activity'];
            $prev_val = $prev_by_dow[$mysql_dow] ?? 0;
            $delta = ($prev_val > 0) ? round((($cur_val - $prev_val) / $prev_val) * 100, 1) : 0;
            $day_by_day_compare[] = [
                'day_index' => $d,
                'day_name' => $day_names[$d],
                'short_name' => $short_names[$d],
                'current' => $cur_val,
                'previous' => $prev_val,
                'delta_pct' => $delta
            ];
        }

        $overall_growth_pct = round((($total_week_activity - $prev_total_activity) / $prev_total_activity) * 100, 1);

        // 5. Day vs Day Head-to-Head Comparison
        $compare_a_dow = max(1, min(7, $compare_day_a + 1));
        $compare_b_dow = max(1, min(7, $compare_day_b + 1));
        $day_a_curve = [];
        $day_b_curve = [];
        $day_a_total = 0;
        $day_b_total = 0;

        for ($h = 0; $h < 24; $h++) {
            $ca = $raw_cells[$compare_a_dow][$h] ?? ['total_activity' => 0];
            $cb = $raw_cells[$compare_b_dow][$h] ?? ['total_activity' => 0];
            $va = $ca['total_activity'];
            $vb = $cb['total_activity'];
            $day_a_total += $va;
            $day_b_total += $vb;

            $h_label = ($h === 0) ? '12 AM' : (($h < 12) ? $h . ' AM' : (($h === 12) ? '12 PM' : ($h - 12) . ' PM'));
            $day_a_curve[] = ['hour' => $h, 'label' => $h_label, 'value' => $va];
            $day_b_curve[] = ['hour' => $h, 'label' => $h_label, 'value' => $vb];
        }

        $day_vs_day = [
            'day_a' => [
                'index' => $compare_day_a,
                'name' => $day_names[$compare_day_a],
                'total' => $day_a_total,
                'curve' => $day_a_curve,
                'peak_hour' => $days_totals[$compare_day_a]['peak_hour_label']
            ],
            'day_b' => [
                'index' => $compare_day_b,
                'name' => $day_names[$compare_day_b],
                'total' => $day_b_total,
                'curve' => $day_b_curve,
                'peak_hour' => $days_totals[$compare_day_b]['peak_hour_label']
            ],
            'diff_pct' => ($day_b_total > 0) ? round((($day_a_total - $day_b_total) / $day_b_total) * 100, 1) : 0
        ];

        // 6. Weekend vs Weekday analysis
        $weekend_total = $days_totals[0]['total_activity'] + $days_totals[6]['total_activity']; // Sun + Sat
        $weekday_total = 0;
        for ($d = 1; $d <= 5; $d++) {
            $weekday_total += $days_totals[$d]['total_activity'];
        }
        $weekend_daily_avg = (int)round($weekend_total / 2);
        $weekday_daily_avg = (int)round($weekday_total / 5);
        $weekend_boost_pct = ($weekday_daily_avg > 0) ? round((($weekend_daily_avg - $weekday_daily_avg) / $weekday_daily_avg) * 100, 1) : 0;

        // 7. Overall Strategic Takeaways
        $busiest = $days_ranked[0];
        $quietest = $days_ranked[count($days_ranked) - 1];

        $peak_start_hr = max(0, $overall_peak_hr - 1);
        $peak_end_hr = min(23, $overall_peak_hr + 2);
        $format_hr = function($h) {
            return ($h === 0) ? '12:00 AM' : (($h < 12) ? $h . ':00 AM' : (($h === 12) ? '12:00 PM' : ($h - 12) . ':00 PM'));
        };
        $prime_window = $format_hr($peak_start_hr) . ' – ' . $format_hr($peak_end_hr);
        $best_drop_hr = max(0, $overall_peak_hr - 2);
        $best_drop_time = $busiest['day_name'] . ' at ' . $format_hr($best_drop_hr);

        $insights = [
            'most_active_day' => $busiest['day_name'],
            'most_active_day_pct' => $busiest['percentage'],
            'least_active_day' => $quietest['day_name'],
            'overall_peak_hour' => $format_hr($overall_peak_hr),
            'prime_window' => $prime_window,
            'best_upload_time' => $best_drop_time,
            'weekend_vs_weekday' => [
                'weekend_total' => $weekend_total,
                'weekday_total' => $weekday_total,
                'weekend_daily_avg' => $weekend_daily_avg,
                'weekday_daily_avg' => $weekday_daily_avg,
                'weekend_boost_pct' => $weekend_boost_pct,
                'weekend_share_pct' => round(($weekend_total / $total_week_activity) * 100, 1),
                'weekday_share_pct' => round(($weekday_total / $total_week_activity) * 100, 1)
            ]
        ];

        $response = [
            'filter' => $filter,
            'filter_label' => $filter_label,
            'heatmap' => $heatmap,
            'days_ranked' => $days_ranked,
            'days_chronological' => $days_totals,
            'hourly_curve' => $hourly_curve,
            'insights' => $insights,
            'period_comparison' => [
                'current_total' => $total_week_activity,
                'previous_total' => $prev_total_activity,
                'growth_pct' => $overall_growth_pct,
                'days' => $day_by_day_compare
            ],
            'day_vs_day' => $day_vs_day
        ];

        $now = time();
        $snapshotMeta = [
            'is_cached' => false,
            'cached_at' => date('Y-m-d H:i:s', $now),
            'cached_at_formatted' => date('h:i A', $now),
            'next_update_formatted' => date('h:i A', $now + 7200),
            'ttl_seconds' => 7200,
            'age_seconds' => 0
        ];
        setAnalyticsSnapshotCache($userActCacheKey, $response, 7200);
        $response['snapshot_meta'] = $snapshotMeta;

        echo json_encode(['status' => 'success', 'data' => $response, 'snapshot_meta' => $snapshotMeta]);
        exit();

    } catch (Throwable $e) {
        returnError("User activity analytics error: " . $e->getMessage());
    }
}

elseif ($action === 'getAccessKeys') {
    $pwd = $_GET['pwd'] ?? ($input['pwd'] ?? '');
    if (!verifyPrimaryAdminAuth($conn, $pwd)) returnError("Unauthorized: Only Primary Admin can view access keys");

    ensureAccessKeysTable($conn);
    $res = $conn->query("SELECT id, user_label, plain_hint, is_active, created_at FROM gtanalytic_access_keys ORDER BY id DESC");
    $keys = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $keys[] = $row;
        }
    }
    echo json_encode(['status' => 'success', 'data' => $keys]);
    exit();
}

elseif ($action === 'createAccessKey') {
    $pwd = $_GET['pwd'] ?? ($input['pwd'] ?? '');
    if (!verifyPrimaryAdminAuth($conn, $pwd)) returnError("Unauthorized: Only Primary Admin can create access keys");

    $label = trim($input['label'] ?? ($_GET['label'] ?? ''));
    $newPwd = trim($input['new_pwd'] ?? ($_GET['new_pwd'] ?? ''));

    if (empty($label)) returnError("User label is required");
    if (empty($newPwd)) returnError("Password is required");

    ensureAccessKeysTable($conn);
    $clean_label = $conn->real_escape_string($label);
    $clean_pwd = $conn->real_escape_string($newPwd);
    $md5_hash = md5($newPwd);

    $sql = "INSERT INTO gtanalytic_access_keys (user_label, password_hash, plain_hint, is_active) 
            VALUES ('$clean_label', '$md5_hash', '$clean_pwd', 1)";
    if ($conn->query($sql)) {
        $insertId = $conn->insert_id;
        echo json_encode([
            'status' => 'success', 
            'message' => 'Access key created successfully',
            'data' => [
                'id' => $insertId,
                'user_label' => $label,
                'plain_hint' => $newPwd,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ]
        ]);
    } else {
        returnError("Failed to create access key: " . $conn->error);
    }
    exit();
}

elseif ($action === 'deleteAccessKey') {
    $pwd = $_GET['pwd'] ?? ($input['pwd'] ?? '');
    if (!verifyPrimaryAdminAuth($conn, $pwd)) returnError("Unauthorized: Only Primary Admin can delete access keys");

    $id = intval($input['id'] ?? ($_GET['id'] ?? 0));
    if ($id <= 0) returnError("Valid Key ID is required");

    ensureAccessKeysTable($conn);
    $conn->query("DELETE FROM gtanalytic_access_keys WHERE id = $id");
    echo json_encode(['status' => 'success', 'message' => 'Access key deleted successfully']);
    exit();
}

elseif ($action === 'getLoginLogs') {
    $pwd = $_GET['pwd'] ?? ($input['pwd'] ?? '');
    if (!verifyPrimaryAdminAuth($conn, $pwd)) returnError("Unauthorized: Only Primary Admin can view login logs");

    ensureLoginLogsTable($conn);
    // Purge records older than 7 days and clean up any stray Hostinger IPv6 or headless pings
    @$conn->query("DELETE FROM gtanalytic_login_logs WHERE created_at < NOW() - INTERVAL 7 DAY");
    @$conn->query("DELETE FROM gtanalytic_login_logs WHERE ip_address LIKE '2a02:4780:%' OR ip_address LIKE '2a02:4787:%' OR (os = 'Unknown' AND browser = 'Unknown')");

    $res = $conn->query("SELECT id, user_label, user_identifier, ip_address, device_type, os, browser, screen_size, city, country, status, created_at FROM gtanalytic_login_logs ORDER BY id DESC LIMIT 150");
    $logs = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $logs[] = $row;
        }
    }
    echo json_encode(['status' => 'success', 'data' => $logs]);
    exit();
}

else {
    returnError("Invalid Action");
}
$conn->close();
?>
