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
    } else {
        // Auto-create user
        $insert_stmt = $conn->prepare("INSERT IGNORE INTO user_profiles (email, spins_left, g_coins, last_spin_reset) VALUES (?, 3, 0, ?)");
        $today = date('Y-m-d');
        $insert_stmt->bind_param("ss", $email, $today);
        $insert_stmt->execute();
        
        // Fetch again
        $stmt->execute();
        $result = $stmt->get_result();
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
    
    // Read probability from settings
    $settings_file = __DIR__ . '/settings.json';
    $prob = [
        'iphone' => 0,
        'airpods' => 1,
        'rs500' => 4,
        'amazon' => 2,
        'gcoins' => 43,
        'betterluck' => 50
    ];
    if (file_exists($settings_file)) {
        $settings = json_decode(file_get_contents($settings_file), true);
        if (isset($settings['prob_iphone'])) $prob['iphone'] = (int)$settings['prob_iphone'];
        if (isset($settings['prob_airpods'])) $prob['airpods'] = (int)$settings['prob_airpods'];
        if (isset($settings['prob_rs500'])) $prob['rs500'] = (int)$settings['prob_rs500'];
        if (isset($settings['prob_amazon'])) $prob['amazon'] = (int)$settings['prob_amazon'];
        if (isset($settings['prob_gcoins'])) $prob['gcoins'] = (int)$settings['prob_gcoins'];
        if (isset($settings['prob_betterluck'])) $prob['betterluck'] = (int)$settings['prob_betterluck'];
    }
    
    // Ensure total is at least 1 to avoid division by zero
    $total_weight = array_sum($prob);
    if ($total_weight <= 0) {
        $prob['betterluck'] = 100;
        $total_weight = 100;
    }
    
    // Pick a random number between 1 and total_weight
    $rand = rand(1, $total_weight);
    $segment = 5; // default to better luck
    $resultStr = "better_luck";
    $item_name = "";
    $coins_won = 0;
    
    $cumulative = 0;
    
    // Segments Mapping:
    // 0 = iPhone 17 Pro
    // 1 = AirPods
    // 2 = Rs 500 Gift Card
    // 3 = Rs 1000 Amazon
    // 4 = G Coins
    // 5 = Better Luck Next Time
    
    $cumulative += $prob['iphone'];
    if ($rand <= $cumulative) {
        $segment = 0; $resultStr = "win_item"; $item_name = "iPhone 17 Pro";
    } else {
        $cumulative += $prob['airpods'];
        if ($rand <= $cumulative) {
            $segment = 1; $resultStr = "win_item"; $item_name = "AirPods";
        } else {
            $cumulative += $prob['rs500'];
            if ($rand <= $cumulative) {
                $segment = 2; $resultStr = "win_item"; $item_name = "Rs 500 Gift Card";
            } else {
                $cumulative += $prob['amazon'];
                if ($rand <= $cumulative) {
                    $segment = 3; $resultStr = "win_item"; $item_name = "Rs 1000 Amazon Voucher";
                } else {
                    $cumulative += $prob['gcoins'];
                    if ($rand <= $cumulative) {
                        $segment = 4; $resultStr = "win_coins"; $coins_won = rand(10, 50); $g_coins += $coins_won;
                    } else {
                        $segment = 5; $resultStr = "lose";
                    }
                }
            }
        }
    }
    
    $stmt = $conn->prepare("UPDATE user_profiles SET spins_left = ?, g_coins = ? WHERE email = ?");
    $stmt->bind_param("iis", $new_spins, $g_coins, $user['email']);
    $stmt->execute();
    
    // Log history
    $hist_stmt = $conn->prepare("INSERT INTO spin_history (user_email, result, g_coins_won) VALUES (?, ?, ?)");
    // For items, we will store the item_name in the result column as 'win: Item Name' to save creating a new column
    $db_result = $resultStr === "win_item" ? "win: " . $item_name : ($resultStr === "win_coins" ? "win" : "lose");
    $hist_stmt->bind_param("ssi", $user['email'], $db_result, $coins_won);
    $hist_stmt->execute();
    
    echo json_encode([
        "status" => "success",
        "result" => $resultStr,
        "item_name" => $item_name,
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
