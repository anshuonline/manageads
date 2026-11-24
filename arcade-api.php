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

require_once __DIR__ . '/config.php';

date_default_timezone_set('Asia/Kolkata');
@$conn->query("SET time_zone = '+05:30'");

// Auto-create arcade tables if they don't exist yet
@$conn->query("CREATE TABLE IF NOT EXISTS arcade_highscores (
    game_id VARCHAR(32) NOT NULL PRIMARY KEY,
    high_score INT NOT NULL DEFAULT 0,
    player_name VARCHAR(100) NOT NULL DEFAULT 'Cyber Operative',
    user_id VARCHAR(128) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

@$conn->query("CREATE TABLE IF NOT EXISTS arcade_score_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    game_id VARCHAR(32) NOT NULL,
    score INT NOT NULL,
    player_name VARCHAR(100) NOT NULL DEFAULT 'Cyber Operative',
    user_id VARCHAR(128) NULL,
    duration_sec INT DEFAULT 0,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_game_date (game_id, created_at),
    INDEX idx_score (score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Seed default highscore rows for the 3 games if missing
$seedGames = ['snake', 'flappy', 'dino'];
foreach ($seedGames as $g) {
    @$conn->query("INSERT IGNORE INTO arcade_highscores (game_id, high_score, player_name) VALUES ('$g', 0, 'Cyber Operative')");
}

$action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action)) {
    $action = $_POST['action'] ?? '';
}

// Read JSON input if sent
$json_input = file_get_contents('php://input');
$postData = json_decode($json_input, true) ?: [];
if (empty($action) && isset($postData['action'])) {
    $action = $postData['action'];
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. GET ALL-TIME GLOBAL HIGHSCORES
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'get_highscores' || $_SERVER['REQUEST_METHOD'] === 'GET' && empty($action)) {
    $res = $conn->query("SELECT game_id, high_score, player_name, updated_at FROM arcade_highscores");
    $highscores = [
        'snake' => ['score' => 0, 'player_name' => 'Cyber Operative', 'updated_at' => null],
        'flappy' => ['score' => 0, 'player_name' => 'Cyber Operative', 'updated_at' => null],
        'dino' => ['score' => 0, 'player_name' => 'Cyber Operative', 'updated_at' => null],
    ];

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $gid = $row['game_id'];
            if (isset($highscores[$gid])) {
                $highscores[$gid] = [
                    'score' => (int)$row['high_score'],
                    'player_name' => htmlspecialchars_decode($row['player_name']),
                    'updated_at' => $row['updated_at']
                ];
            }
        }
    }

    echo json_encode([
        'status' => 'success',
        'highscores' => $highscores,
        'server_time' => date('Y-m-d H:i:s')
    ]);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. SUBMIT SCORE & CHECK NEW WORLD RECORD
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'submit_score') {
    $game_id = trim($postData['game_id'] ?? ($_POST['game_id'] ?? ''));
    $score = (int)($postData['score'] ?? ($_POST['score'] ?? 0));
    $duration_sec = (int)($postData['duration_sec'] ?? ($_POST['duration_sec'] ?? 0));
    $player_name = trim($postData['player_name'] ?? ($_POST['player_name'] ?? ''));
    $user_id = trim($postData['user_id'] ?? ($_POST['user_id'] ?? ''));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!in_array($game_id, ['snake', 'flappy', 'dino'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid game ID']);
        exit();
    }

    if ($score <= 0) {
        echo json_encode(['status' => 'success', 'recorded' => false, 'message' => 'Score is 0']);
        exit();
    }

    // Rate Limit: Prevent same IP from submitting more than once every 2 seconds
    if (!empty($ip)) {
        $recentCheck = $conn->prepare("SELECT id FROM arcade_score_logs WHERE ip_address = ? AND created_at >= (NOW() - INTERVAL 2 SECOND) LIMIT 1");
        if ($recentCheck) {
            $recentCheck->bind_param("s", $ip);
            $recentCheck->execute();
            $recentRes = $recentCheck->get_result();
            if ($recentRes && $recentRes->num_rows > 0) {
                $recentCheck->close();
                echo json_encode(['status' => 'success', 'rate_limited' => true, 'message' => 'Submissions throttled']);
                exit();
            }
            $recentCheck->close();
        }
    }

    // Anti-Cheat Sanity Checks
    // Dino: max realistic rate ~45m/sec
    // Snake: max realistic rate ~35pts/sec
    // Flappy: max realistic rate ~3 columns/sec
    if ($duration_sec > 0) {
        $rate = $score / $duration_sec;
        if ($game_id === 'flappy' && $rate > 4.0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Anomaly detected: Score rate exceeds physical limit']);
            exit();
        }
        if ($game_id === 'snake' && $rate > 40.0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Anomaly detected: Snake rate exceeds physical limit']);
            exit();
        }
        if ($game_id === 'dino' && $rate > 60.0) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Anomaly detected: Runner speed exceeds physical limit']);
            exit();
        }
    }

    // Clean player name
    if (empty($player_name)) {
        $player_name = 'Cyber Operative';
    } else {
        $player_name = mb_substr($player_name, 0, 40);
    }

    // Insert into score logs
    $stmt = $conn->prepare("INSERT INTO arcade_score_logs (game_id, score, player_name, user_id, duration_sec, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("sissis", $game_id, $score, $player_name, $user_id, $duration_sec, $ip);
        $stmt->execute();
        $stmt->close();
    }

    // Check against global high score
    $is_new_global_record = false;
    $current_record = 0;
    $record_holder = 'Cyber Operative';

    $checkStmt = $conn->prepare("SELECT high_score, player_name FROM arcade_highscores WHERE game_id = ?");
    if ($checkStmt) {
        $checkStmt->bind_param("s", $game_id);
        $checkStmt->execute();
        $checkRes = $checkStmt->get_result();
        if ($checkRow = $checkRes->fetch_assoc()) {
            $current_record = (int)$checkRow['high_score'];
            $record_holder = $checkRow['player_name'];
        }
        $checkStmt->close();
    }

    if ($score > $current_record) {
        $is_new_global_record = true;
        $current_record = $score;
        $record_holder = $player_name;

        $upStmt = $conn->prepare("UPDATE arcade_highscores SET high_score = ?, player_name = ?, user_id = ?, updated_at = NOW() WHERE game_id = ?");
        if ($upStmt) {
            $upStmt->bind_param("isss", $score, $player_name, $user_id, $game_id);
            $upStmt->execute();
            $upStmt->close();
        }
    }

    echo json_encode([
        'status' => 'success',
        'is_new_global_record' => $is_new_global_record,
        'global_high_score' => $current_record,
        'record_holder' => $record_holder,
        'submitted_score' => $score
    ]);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. GET ADMIN STATS (FOR GTANALYTICS DASHBOARD)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'get_admin_stats') {
    // Global highscores
    $highscoresRes = $conn->query("SELECT game_id, high_score, player_name, updated_at FROM arcade_highscores");
    $highscores = [];
    if ($highscoresRes) {
        while ($r = $highscoresRes->fetch_assoc()) {
            $highscores[$r['game_id']] = [
                'score' => (int)$r['high_score'],
                'player_name' => $r['player_name'],
                'updated_at' => $r['updated_at']
            ];
        }
    }

    // Total plays today & all-time per game
    $stats = [
        'total_plays_today' => 0,
        'total_plays_all_time' => 0,
        'games' => [
            'snake' => ['today' => 0, 'all_time' => 0],
            'flappy' => ['today' => 0, 'all_time' => 0],
            'dino' => ['today' => 0, 'all_time' => 0]
        ]
    ];

    $todayRes = $conn->query("SELECT game_id, COUNT(*) as cnt FROM arcade_score_logs WHERE DATE(created_at) = CURDATE() GROUP BY game_id");
    if ($todayRes) {
        while ($r = $todayRes->fetch_assoc()) {
            $gid = $r['game_id'];
            $c = (int)$r['cnt'];
            if (isset($stats['games'][$gid])) {
                $stats['games'][$gid]['today'] = $c;
            }
            $stats['total_plays_today'] += $c;
        }
    }

    $allTimeRes = $conn->query("SELECT game_id, COUNT(*) as cnt FROM arcade_score_logs GROUP BY game_id");
    if ($allTimeRes) {
        while ($r = $allTimeRes->fetch_assoc()) {
            $gid = $r['game_id'];
            $c = (int)$r['cnt'];
            if (isset($stats['games'][$gid])) {
                $stats['games'][$gid]['all_time'] = $c;
            }
            $stats['total_plays_all_time'] += $c;
        }
    }

    // Recent top sessions
    $recentRes = $conn->query("SELECT game_id, score, player_name, created_at FROM arcade_score_logs ORDER BY id DESC LIMIT 10");
    $recent = [];
    if ($recentRes) {
        while ($r = $recentRes->fetch_assoc()) {
            $recent[] = [
                'game_id' => $r['game_id'],
                'score' => (int)$r['score'],
                'player_name' => $r['player_name'],
                'created_at' => $r['created_at']
            ];
        }
    }

    echo json_encode([
        'status' => 'success',
        'highscores' => $highscores,
        'stats' => $stats,
        'recent_plays' => $recent
    ]);
    exit();
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
