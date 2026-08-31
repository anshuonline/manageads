<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'config.php';

// Function to authenticate user based on email (similar to user-api.php)
function authenticate($conn, $email) {
    $stmt = $conn->prepare("SELECT email, g_coins, spins_left, last_spin_reset FROM user_profiles WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return null;
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);
$email = $_GET['email'] ?? ($input['email'] ?? '');

if (empty($email)) {
    echo json_encode(["status" => "error", "message" => "Email is required"]);
    exit;
}

$user = authenticate($conn, $email);
if (!$user) {
    echo json_encode(["status" => "error", "message" => "User not found"]);
    exit;
}

$today = date('Y-m-d');
$last_reset = $user['last_spin_reset'];

// Reset daily spins to 3 if last reset was not today
if ($last_reset !== $today) {
    $stmt = $conn->prepare("UPDATE user_profiles SET spins_left = 3, last_spin_reset = ? WHERE email = ?");
    $stmt->bind_param("ss", $today, $user['email']);
    $stmt->execute();
    $user['spins_left'] = 3;
    $user['last_spin_reset'] = $today;
}

if ($action === 'status') {
    echo json_encode([
        "status" => "success",
        "g_coins" => (int)$user['g_coins'],
        "spins_left" => (int)$user['spins_left']
    ]);
} elseif ($action === 'spin' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    if ((int)$user['spins_left'] <= 0) {
        echo json_encode(["status" => "error", "message" => "No spins left"]);
        exit;
    }
    
    // Deduct 1 spin
    $new_spins = (int)$user['spins_left'] - 1;
    $g_coins = (int)$user['g_coins'];
    
    // 80% lose, 20% win
    $rand = rand(1, 100);
    $win = ($rand <= 20);
    $coins_won = 0;
    
    // Wheel segments based on user image:
    // Segments: 
    // 0 = G Coins
    // 1 = Better luck next time
    // 2 = Rs 500 Gift Card
    // 3 = Rs 1000 Amazon
    // 4 = iPhone 17 Pro
    // 5 = AirPods
    // We will just force landing on 0 or 1.
    // 0 (G Coins) = win
    // 1 (Better luck next time) = lose
    
    if ($win) {
        $coins_won = rand(10, 50);
        $g_coins += $coins_won;
        $segment = 0;
    } else {
        $segment = 1;
    }
    
    $stmt = $conn->prepare("UPDATE user_profiles SET spins_left = ?, g_coins = ? WHERE email = ?");
    $stmt->bind_param("iis", $new_spins, $g_coins, $user['email']);
    $stmt->execute();
    
    echo json_encode([
        "status" => "success",
        "result" => $win ? "win" : "lose",
        "coins_won" => $coins_won,
        "segment" => $segment,
        "g_coins" => $g_coins,
        "spins_left" => $new_spins
    ]);
} elseif ($action === 'add_chance' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $new_spins = (int)$user['spins_left'] + 1;
    $stmt = $conn->prepare("UPDATE user_profiles SET spins_left = ? WHERE email = ?");
    $stmt->bind_param("is", $new_spins, $user['email']);
    $stmt->execute();
    
    echo json_encode([
        "status" => "success",
        "spins_left" => $new_spins
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Invalid action"]);
}

$conn->close();
?>
