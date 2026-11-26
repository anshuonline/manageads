<?php
// Enable CORS for Angular App
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Handle Preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'config.php';
require_once 'mailer.php';
require_once 'firebase_auth.php';

// Ensure welcome_email_sent column exists
$col_check = $conn->query("SHOW COLUMNS FROM user_profiles LIKE 'welcome_email_sent'");
if ($col_check && $col_check->num_rows == 0) {
    @$conn->query("ALTER TABLE user_profiles ADD COLUMN welcome_email_sent TINYINT(1) DEFAULT 0");
}

// Ensure total_plays column exists
$col_check_plays = $conn->query("SHOW COLUMNS FROM user_profiles LIKE 'total_plays'");
if ($col_check_plays && $col_check_plays->num_rows == 0) {
    @$conn->query("ALTER TABLE user_profiles ADD COLUMN total_plays INT UNSIGNED DEFAULT 0");
}

// Ensure photo_url column exists
$col_check_photo = $conn->query("SHOW COLUMNS FROM user_profiles LIKE 'photo_url'");
if ($col_check_photo && $col_check_photo->num_rows == 0) {
    @$conn->query("ALTER TABLE user_profiles ADD COLUMN photo_url VARCHAR(500) DEFAULT NULL");
}

// Ensure password_hash, security_question, security_answer_hash columns exist
$col_check_pw = $conn->query("SHOW COLUMNS FROM user_profiles LIKE 'password_hash'");
if ($col_check_pw && $col_check_pw->num_rows == 0) {
    @$conn->query("ALTER TABLE user_profiles ADD COLUMN password_hash VARCHAR(64) DEFAULT NULL, ADD COLUMN security_question VARCHAR(255) DEFAULT NULL, ADD COLUMN security_answer_hash VARCHAR(64) DEFAULT NULL");
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'email_register') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $emailRaw = strtolower(trim($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $name = trim($input['name'] ?? '');
    $question = trim($input['security_question'] ?? '');
    $answer = strtolower(trim((string)($input['security_answer'] ?? '')));

    if (!filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Please enter a valid email address.']);
        exit;
    }
    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters.']);
        exit;
    }
    if (empty($question) || empty($answer)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Please select a security question and enter an answer.']);
        exit;
    }

    $email = $conn->real_escape_string($emailRaw);
    $pwHash = md5($password);
    $ansHash = md5($answer);
    $qEsc = $conn->real_escape_string($question);
    $nameEsc = $conn->real_escape_string($name ?: explode('@', $emailRaw)[0]);

    $exists = $conn->query("SELECT email, password_hash FROM user_profiles WHERE email = '$email' LIMIT 1");
    if ($exists && $exists->num_rows > 0) {
        $row = $exists->fetch_assoc();
        if (!empty($row['password_hash'])) {
            http_response_code(409);
            echo json_encode(['status' => 'error', 'message' => 'An account with this email already exists. Please sign in.']);
            exit;
        }
        $conn->query("UPDATE user_profiles SET password_hash = '$pwHash', security_question = '$qEsc', security_answer_hash = '$ansHash', display_name = IF(display_name = '' OR display_name IS NULL, '$nameEsc', display_name) WHERE email = '$email'");
    } else {
        $conn->query("INSERT INTO user_profiles (email, display_name, password_hash, security_question, security_answer_hash, preferred_languages, liked_songs, recent_plays, listening_preferences) VALUES ('$email', '$nameEsc', '$pwHash', '$qEsc', '$ansHash', '[]', '[]', '[]', '{}')");
    }

    echo json_encode([
        'status' => 'success',
        'user' => [
            'email' => $emailRaw,
            'displayName' => $name ?: explode('@', $emailRaw)[0],
            'photoURL' => '',
            'hasPassword' => true,
            'hasSecurityQuestion' => true,
            'passwordHash' => $pwHash
        ]
    ]);
    exit;
}

if ($action === 'email_login') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $emailRaw = strtolower(trim($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if (!filter_var($emailRaw, FILTER_VALIDATE_EMAIL) || empty($password)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Email and password are required.']);
        exit;
    }

    $email = $conn->real_escape_string($emailRaw);
    $res = $conn->query("SELECT email, display_name, photo_url, password_hash, security_question FROM user_profiles WHERE email = '$email' LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'No account found with this email.']);
        exit;
    }

    $row = $res->fetch_assoc();
    if (empty($row['password_hash'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'This account uses Google Sign-In. Sign in with Google and set a password in Profile.']);
        exit;
    }

    if (md5($password) !== $row['password_hash']) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Incorrect password.']);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'user' => [
            'email' => $row['email'],
            'displayName' => $row['display_name'] ?: explode('@', $emailRaw)[0],
            'photoURL' => $row['photo_url'] ?: '',
            'hasPassword' => true,
            'hasSecurityQuestion' => !empty($row['security_question']),
            'passwordHash' => $row['password_hash']
        ]
    ]);
    exit;
}

if ($action === 'set_password_security') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $emailRaw = '';
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHdr = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
    if (!empty($authHdr)) {
        $fbUser = gtRequireFirebaseUser();
        $emailRaw = strtolower(trim($fbUser['email']));
    } else {
        $emailRaw = strtolower(trim($input['email'] ?? ''));
    }

    if (!filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Valid email required.']);
        exit;
    }

    $email = $conn->real_escape_string($emailRaw);
    $password = (string)($input['password'] ?? '');
    $question = trim($input['security_question'] ?? '');
    $answer = strtolower(trim((string)($input['security_answer'] ?? '')));

    $updates = [];
    if (!empty($password)) {
        if (strlen($password) < 6) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Password must be at least 6 characters.']);
            exit;
        }
        $updates[] = "password_hash = '" . md5($password) . "'";
    }
    if (!empty($question) && !empty($answer)) {
        $updates[] = "security_question = '" . $conn->real_escape_string($question) . "'";
        $updates[] = "security_answer_hash = '" . md5($answer) . "'";
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Provide a password or security question & answer.']);
        exit;
    }

    $exists = $conn->query("SELECT email FROM user_profiles WHERE email = '$email' LIMIT 1");
    if (!$exists || $exists->num_rows === 0) {
        $conn->query("INSERT INTO user_profiles (email, preferred_languages, liked_songs, recent_plays, listening_preferences) VALUES ('$email', '[]', '[]', '[]', '{}')");
    }
    $conn->query("UPDATE user_profiles SET " . implode(', ', $updates) . " WHERE email = '$email'");
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'get_security_question') {
    $emailRaw = strtolower(trim($_GET['email'] ?? ''));
    if (!filter_var($emailRaw, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Enter a valid email address.']);
        exit;
    }
    $email = $conn->real_escape_string($emailRaw);
    $res = $conn->query("SELECT security_question FROM user_profiles WHERE email = '$email' LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'No account found with this email.']);
        exit;
    }
    $row = $res->fetch_assoc();
    if (empty($row['security_question'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'No security question set for this account. Please sign in with Google.']);
        exit;
    }
    echo json_encode(['status' => 'success', 'security_question' => $row['security_question']]);
    exit;
}

if ($action === 'reset_password') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $emailRaw = strtolower(trim($input['email'] ?? ''));
    $answer = strtolower(trim((string)($input['security_answer'] ?? '')));
    $newPassword = (string)($input['new_password'] ?? '');

    if (!filter_var($emailRaw, FILTER_VALIDATE_EMAIL) || empty($answer) || strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Valid email, security answer, and password (min 6 chars) required.']);
        exit;
    }

    $email = $conn->real_escape_string($emailRaw);
    $res = $conn->query("SELECT security_answer_hash FROM user_profiles WHERE email = '$email' LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Account not found.']);
        exit;
    }
    $row = $res->fetch_assoc();
    if (empty($row['security_answer_hash']) || md5($answer) !== $row['security_answer_hash']) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Incorrect security answer.']);
        exit;
    }

    $newHash = md5($newPassword);
    $conn->query("UPDATE user_profiles SET password_hash = '$newHash' WHERE email = '$email'");
    echo json_encode(['status' => 'success', 'message' => 'Password updated! You can now sign in.']);
    exit;
}

if ($action === 'getProfile') {
    $fbUser = gtRequireAuthUser($conn);
    $email = $conn->real_escape_string($fbUser['email']);
    $name = isset($_GET['name']) ? $conn->real_escape_string(trim($_GET['name'])) : $conn->real_escape_string($fbUser['name']);
    $photo = isset($_GET['photo']) ? $conn->real_escape_string(trim($_GET['photo'])) : $conn->real_escape_string($fbUser['picture']);

    $sql = "SELECT display_name, photo_url, preferred_languages, liked_songs, recent_plays, listening_preferences, welcome_email_sent, total_plays FROM user_profiles WHERE email = '$email'";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // Update photo_url if a fresh one is provided
        if (!empty($photo) && (empty($row['photo_url']) || $row['photo_url'] !== $photo)) {
            $conn->query("UPDATE user_profiles SET photo_url = '$photo' WHERE email = '$email'");
            $row['photo_url'] = $photo;
        }

        // Update display_name if DB currently has empty name and a fresh name is provided
        if (!empty($name) && empty($row['display_name'])) {
            $conn->query("UPDATE user_profiles SET display_name = '$name' WHERE email = '$email'");
            $row['display_name'] = $name;
        }

        // If user never received the welcome email (e.g. first-time login), send it!
        if (empty($row['welcome_email_sent']) || (int)$row['welcome_email_sent'] === 0) {
            $displayName = !empty($name) ? $name : (!empty($row['display_name']) ? $row['display_name'] : '');
            try {
                GanaTubeMailer::sendWelcomeEmail($email, $displayName);
            } catch (Throwable $e) {}
            $conn->query("UPDATE user_profiles SET welcome_email_sent = 1 WHERE email = '$email'");
        }

        $recentPlays = json_decode($row['recent_plays']);
        $recentCount = is_array($recentPlays) ? count($recentPlays) : 0;
        if (is_array($recentPlays) && $recentCount > 100) {
            $recentPlays = array_slice($recentPlays, 0, 100);
        }

        $totalPlays = isset($row['total_plays']) ? (int)$row['total_plays'] : 0;
        if ($totalPlays < $recentCount) {
            $totalPlays = $recentCount;
            @$conn->query("UPDATE user_profiles SET total_plays = $totalPlays WHERE email = '$email'");
        }

        echo json_encode([
            "status" => "success",
            "display_name" => $row['display_name'],
            "photo_url" => $row['photo_url'] ?? null,
            "preferred_languages" => json_decode($row['preferred_languages']),
            "liked_songs" => json_decode($row['liked_songs']),
            "recent_plays" => $recentPlays,
            "listening_preferences" => json_decode($row['listening_preferences']),
            "total_plays" => $totalPlays
        ]);
    } else {
        // Auto-create user in DB on first-time signup
        $cleanName = !empty($name) ? "'$name'" : "NULL";
        $cleanPhoto = !empty($photo) ? "'$photo'" : "NULL";
        $insert_sql = "INSERT INTO user_profiles (email, display_name, photo_url, welcome_email_sent) VALUES ('$email', $cleanName, $cleanPhoto, 1) ON DUPLICATE KEY UPDATE welcome_email_sent = 1, photo_url = COALESCE(VALUES(photo_url), photo_url)";
        $conn->query($insert_sql);
        
        // Dispatch beautiful Hostinger SMTP welcome email
        try {
            GanaTubeMailer::sendWelcomeEmail($email, $name);
        } catch (Throwable $e) {}
        
        echo json_encode([
            "status" => "success",
            "message" => "User created, welcome email sent, returning defaults.",
            "display_name" => !empty($name) ? $name : null,
            "photo_url" => !empty($photo) ? $photo : null,
            "preferred_languages" => null,
            "liked_songs" => null,
            "recent_plays" => null,
            "listening_preferences" => null
        ]);
    }
} 
elseif ($action === 'updateProfile') {
    $fbUser = gtRequireAuthUser($conn);
    $data = json_decode(file_get_contents("php://input"), true);
    $email = $conn->real_escape_string($fbUser['email']);

    $preferred_languages = isset($data['preferred_languages']) ? $conn->real_escape_string(json_encode($data['preferred_languages'])) : '[]';
    $liked_songs = isset($data['liked_songs']) ? $conn->real_escape_string(json_encode($data['liked_songs'])) : '[]';
    
    // Server-side safeguard: Cap recent plays to 100 to prevent database bloat and PHP memory exhaustion
    $recent_plays_array = isset($data['recent_plays']) && is_array($data['recent_plays']) ? $data['recent_plays'] : [];
    if (count($recent_plays_array) > 100) {
        $recent_plays_array = array_slice($recent_plays_array, 0, 100);
    }
    $recent_plays = $conn->real_escape_string(json_encode($recent_plays_array));
    
    $listening_preferences = isset($data['listening_preferences']) ? $conn->real_escape_string(json_encode($data['listening_preferences'])) : '[]';

    $increment_play = isset($data['increment_play']) && $data['increment_play'] ? true : false;
    $total_plays_input = isset($data['total_plays']) ? (int)$data['total_plays'] : 0;

    $play_update = "";
    if ($increment_play) {
        $play_update = ", total_plays = GREATEST(COALESCE(total_plays, 0) + 1, $total_plays_input)";
    } elseif ($total_plays_input > 0) {
        $play_update = ", total_plays = GREATEST(COALESCE(total_plays, 0), $total_plays_input)";
    }

    $initial_plays = $increment_play ? max(1, $total_plays_input) : $total_plays_input;

    $sql = "INSERT INTO user_profiles (email, preferred_languages, liked_songs, recent_plays, listening_preferences, total_plays) 
            VALUES ('$email', '$preferred_languages', '$liked_songs', '$recent_plays', '$listening_preferences', $initial_plays)
            ON DUPLICATE KEY UPDATE 
            preferred_languages = VALUES(preferred_languages), 
            liked_songs = VALUES(liked_songs), 
            recent_plays = VALUES(recent_plays),
            listening_preferences = VALUES(listening_preferences)" . $play_update;

    if ($conn->query($sql) === TRUE) {
        echo json_encode(["status" => "success", "message" => "Profile updated successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Error updating profile: " . $conn->error]);
    }
} 
elseif ($action === 'recordPlay') {
    $fbUser = gtRequireFirebaseUser();
    $email = $conn->real_escape_string($fbUser['email']);
    $conn->query("UPDATE user_profiles SET total_plays = GREATEST(COALESCE(total_plays, 0) + 1, 1) WHERE email = '$email'");
    echo json_encode(["status" => "success"]);
    exit;
} 
elseif ($action === 'updateUsername') {
    $fbUser = gtRequireFirebaseUser();
    $data = json_decode(file_get_contents("php://input"), true);
    $email = $conn->real_escape_string($fbUser['email']);
    $display_name = isset($data['display_name']) ? $conn->real_escape_string($data['display_name']) : '';
    $auto = isset($data['auto']) ? $data['auto'] : false;
    
    if (empty($display_name)) {
        echo json_encode(["status" => "error", "message" => "display_name is required"]);
        exit;
    }

    $base_name = $display_name;
    $success = false;
    $attempts = 0;
    
    while (!$success && $attempts < 10) {
        $sql = "INSERT INTO user_profiles (email, display_name) VALUES ('$email', '$display_name')
                ON DUPLICATE KEY UPDATE display_name = VALUES(display_name)";

        try {
            if ($conn->query($sql) === TRUE) {
                $success = true;
                echo json_encode(["status" => "success", "message" => "Username updated in DB", "display_name" => $display_name]);
                exit;
            }
        } catch (Exception $e) {
            if ($conn->errno == 1062 || (strpos($e->getMessage(), 'Duplicate entry') !== false)) {
                if ($auto) {
                    $display_name = $base_name . '-' . mt_rand(100, 9999);
                    $attempts++;
                } else {
                    echo json_encode(["status" => "error", "message" => "Username is already taken by another user."]);
                    exit;
                }
            } else {
                echo json_encode(["status" => "error", "message" => "Error updating username: " . $e->getMessage()]);
                exit;
            }
        }
    }
    
    if (!$success) {
        echo json_encode(["status" => "error", "message" => "Could not generate a unique username."]);
    }
}
elseif ($action === 'getAllUsers') {
    gtRequireAdminUser();
    $sql = "SELECT email, display_name, photo_url, preferred_languages, liked_songs, recent_plays, listening_preferences, created_at, updated_at FROM user_profiles ORDER BY created_at DESC";
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
elseif ($action === 'sendWelcomeEmail') {
    $fbUser = gtRequireFirebaseUser();
    $data = json_decode(file_get_contents("php://input"), true);
    $email = $conn->real_escape_string($fbUser['email']);
    $name = isset($data['name']) ? $conn->real_escape_string(trim($data['name'])) : $conn->real_escape_string($fbUser['name']);

    $force = !empty($data['force']);
    $chk = $conn->query("SELECT welcome_email_sent, display_name FROM user_profiles WHERE email = '$email'");
    if ($chk && $chk->num_rows > 0) {
        $row = $chk->fetch_assoc();
        if (!$force && (int)$row['welcome_email_sent'] === 1) {
            echo json_encode(["status" => "success", "message" => "Welcome email already sent previously"]);
            exit;
        }
        $displayName = !empty($name) ? $name : (!empty($row['display_name']) ? $row['display_name'] : '');
    } else {
        $displayName = $name;
        $cleanName = !empty($name) ? "'$name'" : "NULL";
        $conn->query("INSERT IGNORE INTO user_profiles (email, display_name) VALUES ('$email', $cleanName)");
    }

    $mailRes = GanaTubeMailer::sendWelcomeEmail($email, $displayName);
    if ($mailRes['success']) {
        $conn->query("UPDATE user_profiles SET welcome_email_sent = 1 WHERE email = '$email'");
        echo json_encode(["status" => "success", "message" => "Welcome email sent successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => $mailRes['error']]);
    }
}
else {
    echo json_encode(["status" => "error", "message" => "Invalid action"]);
}

$conn->close();
?>
