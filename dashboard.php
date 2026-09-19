<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . "/config.php";

// Set Indian Timezone globally for PHP and MySQL
date_default_timezone_set('Asia/Kolkata');
$conn->query("SET time_zone = '+05:30'");

if ($conn->connect_error) {
    die("Database connection failed.");
}

$message = "";
$message_type = "success"; // 'success' or 'error'

// Settings logic for Spin & Win Probabilities
$settings_file = __DIR__ . '/settings.json';
if (!file_exists($settings_file)) {
    file_put_contents($settings_file, json_encode([
        'prob_iphone' => 0,
        'prob_airpods' => 1,
        'prob_rs500' => 4,
        'prob_amazon' => 2,
        'prob_gcoins' => 43,
        'prob_betterluck' => 50
    ]));
}
$settings = json_decode(file_get_contents($settings_file), true);

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    $p_iphone = max(0, intval($_POST['prob_iphone'] ?? 0));
    $p_airpods = max(0, intval($_POST['prob_airpods'] ?? 0));
    $p_rs500 = max(0, intval($_POST['prob_rs500'] ?? 0));
    $p_amazon = max(0, intval($_POST['prob_amazon'] ?? 0));
    $p_gcoins = max(0, intval($_POST['prob_gcoins'] ?? 0));
    $p_betterluck = max(0, intval($_POST['prob_betterluck'] ?? 0));
    
    $total = $p_iphone + $p_airpods + $p_rs500 + $p_amazon + $p_gcoins + $p_betterluck;
    
    if ($total === 100) {
        $settings['prob_iphone'] = $p_iphone;
        $settings['prob_airpods'] = $p_airpods;
        $settings['prob_rs500'] = $p_rs500;
        $settings['prob_amazon'] = $p_amazon;
        $settings['prob_gcoins'] = $p_gcoins;
        $settings['prob_betterluck'] = $p_betterluck;
        file_put_contents($settings_file, json_encode($settings));
        $message = "Spin settings updated successfully!";
        $message_type = "success";
    } else {
        $message = "Error: Probabilities must sum exactly to 100%. Current total is $total%.";
        $message_type = "error";
    }
}

// Auto-add new player cover ad placeholder if it doesn't exist
$check = $conn->query("SELECT * FROM ads WHERE placeholder_id = 'player_cover_ad'");
if ($check && $check->num_rows == 0) {
    $conn->query("INSERT INTO ads (placeholder_id, placeholder_name, is_active) VALUES ('player_cover_ad', 'Player Cover Ad', 0)");
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['logout'])) {
        session_destroy();
        header("Location: index.php");
        exit;
    }
    
    // Header Scripts CRUD using ads table
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_header_script') {
            $name = trim($_POST['script_name'] ?? '');
            $code = trim($_POST['custom_code'] ?? '');
            // Unescape any accidental literal backslashes
            if (strpos($code, '\"') !== false || strpos($code, '\r\n') !== false || strpos($code, "\'") !== false) {
                $code = stripslashes(str_replace(['\r\n', '\r', '\n'], "\n", $code));
            }
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $placeholder_id = 'header_script_' . time();
            
            $stmt = $conn->prepare("INSERT INTO ads (placeholder_id, placeholder_name, custom_code, is_active) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssi", $placeholder_id, $name, $code, $is_active);
            if ($stmt->execute()) {
                $message = "Header script added successfully.";
                $message_type = "success";
            } else {
                $message = "Error adding header script: " . $conn->error;
                $message_type = "error";
            }
        }
        
        if ($_POST['action'] === 'update_header_script') {
            $id = trim($_POST['script_id'] ?? '');
            $name = trim($_POST['script_name'] ?? '');
            $code = trim($_POST['custom_code'] ?? '');
            if (strpos($code, '\"') !== false || strpos($code, '\r\n') !== false || strpos($code, "\'") !== false) {
                $code = stripslashes(str_replace(['\r\n', '\r', '\n'], "\n", $code));
            }
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            $stmt = $conn->prepare("UPDATE ads SET placeholder_name = ?, custom_code = ?, is_active = ? WHERE placeholder_id = ?");
            $stmt->bind_param("ssis", $name, $code, $is_active, $id);
            if ($stmt->execute()) {
                $message = "Header script updated successfully.";
                $message_type = "success";
            } else {
                $message = "Error updating header script: " . $conn->error;
                $message_type = "error";
            }
        }
        
        if ($_POST['action'] === 'delete_header_script') {
            $id = trim($_POST['script_id'] ?? '');
            $stmt = $conn->prepare("DELETE FROM ads WHERE placeholder_id = ?");
            $stmt->bind_param("s", $id);
            if ($stmt->execute()) {
                $message = "Header script deleted successfully.";
                $message_type = "success";
            }
        }
        
        if ($_POST['action'] === 'toggle_header_script') {
            $id = trim($_POST['script_id'] ?? '');
            $is_active = intval($_POST['is_active'] ?? 0);
            $stmt = $conn->prepare("UPDATE ads SET is_active = ? WHERE placeholder_id = ?");
            $stmt->bind_param("is", $is_active, $id);
            if ($stmt->execute()) {
                $message = "Header script status updated.";
                $message_type = "success";
            }
        }
        
        if ($_POST['action'] === 'delete_feedback') {
            $id = intval($_POST['feedback_id']);
            $stmt = $conn->prepare("DELETE FROM user_feedback WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $message = "Feedback deleted successfully.";
                $message_type = "success";
            } else {
                $message = "Error deleting feedback.";
                $message_type = "error";
            }
        }
        
        if ($_POST['action'] === 'edit_user_stats') {
            $email = $conn->real_escape_string($_POST['user_email']);
            $chances = intval($_POST['spins_left']);
            $coins = intval($_POST['g_coins']);
            
            $stmt = $conn->prepare("UPDATE user_profiles SET spins_left = ?, g_coins = ? WHERE email = ?");
            $stmt->bind_param("iis", $chances, $coins, $email);
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    $message = "Successfully updated stats for $email. Spins: $chances, Coins: $coins";
                    $message_type = "success";
                } else {
                    $check = $conn->query("SELECT id FROM user_profiles WHERE email = '$email'");
                    if ($check && $check->num_rows > 0) {
                         $message = "Successfully updated stats for $email. (Values were already identical)";
                         $message_type = "success";
                    } else {
                         $message = "Error: User with email '$email' not found in database.";
                         $message_type = "error";
                    }
                }
            } else {
                $message = "Error updating user stats.";
                $message_type = "error";
            }
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        $status = $conn->real_escape_string($_POST['status']);
        $booking_id = intval($_POST['booking_id']);
        
        $stmt = $conn->prepare("UPDATE campaign_bookings SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $booking_id);
        if ($stmt->execute()) {
            $message = "Order status updated successfully to $status.";
            $message_type = "success";
        } else {
            $message = "Failed to update order status.";
            $message_type = "error";
        }
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'delete_user_spin_logs') {
        $del_email = $conn->real_escape_string($_POST['user_email']);
        $stmt = $conn->prepare("DELETE FROM spin_history WHERE user_email = ?");
        $stmt->bind_param("s", $del_email);
        if ($stmt->execute()) {
            $message = "Spin logs deleted successfully for {$del_email}.";
            $message_type = "success";
        } else {
            $message = "Failed to delete spin logs.";
            $message_type = "error";
        }
    }
    
    if (isset($_POST['update_ad'])) {
        $placeholder_id = $conn->real_escape_string($_POST['placeholder_id']);
        $linkUrl = $conn->real_escape_string($_POST['linkUrl'] ?? '');
        $isActive = isset($_POST['isActive']) ? 1 : 0;
        
        $customCode = $conn->real_escape_string($_POST['customCode'] ?? '');
        $pricePerDay = isset($_POST['pricePerDay']) ? intval($_POST['pricePerDay']) : 0;
        
        $imageUploadStarted = false;
        
        // Handle File Upload
        if (isset($_FILES['adImage']) && $_FILES['adImage']['error'] == UPLOAD_ERR_OK) {
            $imageUploadStarted = true;
            $uploadDir = 'uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileInfo = pathinfo($_FILES['adImage']['name']);
            $ext = strtolower($fileInfo['extension']);
            $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($ext, $allowedTypes)) {
                $newFilename = $placeholder_id . '_' . time() . '.' . $ext;
                $targetFile = $uploadDir . $newFilename;
                $sourceFile = $_FILES['adImage']['tmp_name'];
                
                // Image Optimization Logic (GD Library)
                $uploadSuccess = false;
                $info = getimagesize($sourceFile);
                if ($info && $ext !== 'gif' && function_exists('imagecreatefromjpeg')) {
                    if ($info['mime'] == 'image/jpeg') {
                        $image = imagecreatefromjpeg($sourceFile);
                        $uploadSuccess = imagejpeg($image, $targetFile, 75);
                    } elseif ($info['mime'] == 'image/png' && function_exists('imagecreatefrompng')) {
                        $image = imagecreatefrompng($sourceFile);
                        imagealphablending($image, false);
                        imagesavealpha($image, true);
                        $uploadSuccess = imagepng($image, $targetFile, 7);
                    } elseif ($info['mime'] == 'image/webp' && function_exists('imagecreatefromwebp')) {
                        $image = imagecreatefromwebp($sourceFile);
                        $uploadSuccess = imagewebp($image, $targetFile, 75);
                    } else {
                        $uploadSuccess = move_uploaded_file($sourceFile, $targetFile);
                    }
                    if (isset($image) && $image !== false) imagedestroy($image);
                } else {
                    $uploadSuccess = move_uploaded_file($sourceFile, $targetFile);
                }
                
                if ($uploadSuccess) {
                    $oldResult = $conn->query("SELECT image_path FROM ads WHERE placeholder_id = '$placeholder_id'");
                    if ($oldResult && $oldResult->num_rows > 0) {
                        $oldImg = $oldResult->fetch_assoc()['image_path'];
                        if ($oldImg && file_exists($oldImg)) {
                            unlink($oldImg);
                        }
                    }
                    
                    $updateSql = "UPDATE ads SET image_path = '$targetFile', link_url = '$linkUrl', custom_code = '$customCode', is_active = $isActive, price_per_hour = $pricePerDay WHERE placeholder_id = '$placeholder_id'";
                    if ($conn->query($updateSql) === TRUE) {
                        $message = "Ad configuration updated successfully with creative!";
                        $message_type = "success";
                    } else {
                        $message = "Error updating ad: " . $conn->error;
                        $message_type = "error";
                    }
                } else {
                    $message = "Failed to process and upload image.";
                    $message_type = "error";
                }
            } else {
                $message = "Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.";
                $message_type = "error";
            }
        }
        
        if (!$imageUploadStarted) {
            $updateSql = "UPDATE ads SET link_url = '$linkUrl', custom_code = '$customCode', is_active = $isActive, price_per_hour = $pricePerDay WHERE placeholder_id = '$placeholder_id'";
            if ($conn->query($updateSql) === TRUE) {
                $message = "Ad configuration updated successfully!";
                $message_type = "success";
            } else {
                $message = "Error updating ad: " . $conn->error;
                $message_type = "error";
            }
        }
    }
}

// Fetch all ads and scripts
$adsResult = $conn->query("SELECT * FROM ads");
$ads = [];
$header_scripts = [];
$active_ads_count = 0;
while($row = $adsResult->fetch_assoc()) {
    if (strpos($row['placeholder_id'], 'header_script_') === 0) {
        // Auto-heal any corrupted backslash escapes in database
        if (strpos($row['custom_code'], '\"') !== false || strpos($row['custom_code'], '\r\n') !== false || strpos($row['custom_code'], "\'") !== false) {
            $cleaned = stripslashes(str_replace(['\r\n', '\r', '\n'], "\n", $row['custom_code']));
            $upStmt = $conn->prepare("UPDATE ads SET custom_code = ? WHERE placeholder_id = ?");
            if ($upStmt) {
                $upStmt->bind_param("ss", $cleaned, $row['placeholder_id']);
                $upStmt->execute();
            }
            $row['custom_code'] = $cleaned;
        }
        $header_scripts[] = $row;
    } else {
        $ads[] = $row;
        if (!empty($row['is_active'])) {
            $active_ads_count++;
        }
    }
}

// Identify current page / view
$page = $_GET['page'] ?? '';
$is_overview_page = empty($page) && empty($_GET['placeholder']);
$is_bookings_page = $page === 'bookings';
$is_header_scripts_page = $page === 'header_scripts';
$is_feedback_page = $page === 'feedback';
$is_spin_stats_page = $page === 'spin_stats';
$is_manage_users_page = $page === 'manage_users';

$selected_placeholder = $_GET['placeholder'] ?? null;
$current_ad = null;
if ($selected_placeholder) {
    foreach($ads as $ad) {
        if ($ad['placeholder_id'] == $selected_placeholder) {
            $current_ad = $ad;
            break;
        }
    }
}

// Inquiries / Bookings data
$bookings = [];
$pending_inquiries_count = 0;
$total_booking_revenue = 0;
$bookingsResult = $conn->query("SELECT * FROM campaign_bookings ORDER BY created_at DESC");
if ($bookingsResult) {
    while($row = $bookingsResult->fetch_assoc()) {
        $bookings[] = $row;
        if ($row['status'] === 'Pending') {
            $pending_inquiries_count++;
        }
        if ($row['status'] === 'Approved' || $row['status'] === 'Completed') {
            $total_booking_revenue += intval($row['total_price'] ?? 0);
        }
    }
}

// Feedback data
$feedbacks = [];
$avg_rating = 0;
$total_feedbacks = 0;
$feedbackResult = $conn->query("SELECT * FROM user_feedback ORDER BY created_at DESC");
if ($feedbackResult) {
    $total_rating_sum = 0;
    while($row = $feedbackResult->fetch_assoc()) {
        $feedbacks[] = $row;
        $total_rating_sum += (int)$row['rating'];
    }
    $total_feedbacks = count($feedbacks);
    if ($total_feedbacks > 0) {
        $avg_rating = round($total_rating_sum / $total_feedbacks, 1);
    }
}

// Spin data
$spin_stats = [];
$spin_page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$spin_limit = 20;
$spin_offset = ($spin_page - 1) * $spin_limit;
$spin_total_pages = 1;
$total_spins = 0;
$total_g_coins_won = 0;

$filter_email = isset($_GET['filter_email']) ? $conn->real_escape_string(trim($_GET['filter_email'])) : '';
$where_clause = $filter_email ? " WHERE user_email = '{$filter_email}' " : "";

$sumRes = $conn->query("SELECT COUNT(*) as cnt, SUM(g_coins_won) as total_coins FROM spin_history" . $where_clause);
if ($sumRes) {
    $row = $sumRes->fetch_assoc();
    $total_rows = $row['cnt'] ?? 0;
    $total_spins = $total_rows;
    $total_g_coins_won = $row['total_coins'] ?? 0;
    $spin_total_pages = ceil($total_rows / $spin_limit);
    if ($spin_total_pages < 1) $spin_total_pages = 1;
}

if ($is_spin_stats_page) {
    $spinResult = $conn->query("SELECT * FROM spin_history" . $where_clause . " ORDER BY spin_time DESC LIMIT $spin_limit OFFSET $spin_offset");
    if ($spinResult) {
        while($row = $spinResult->fetch_assoc()) {
            $spin_stats[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-black">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ManageAds — Admin Control Center</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace']
                    },
                    colors: {
                        amoled: '#000000',
                        surface: '#0a0a10',
                        'surface-card': '#101018',
                        'surface-hover': '#161622',
                        brand: {
                            purple: '#a855f7',
                            pink: '#ec4899',
                            violet: '#8b5cf6'
                        }
                    }
                }
            }
        }
    </script>
    <style>
        /* AMOLED Base Styles */
        body {
            background-color: #000000;
            color: #ffffff;
            font-family: 'Plus Jakarta Sans', sans-serif;
            overflow-x: hidden;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #000000;
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(168, 85, 247, 0.25);
            border-radius: 9999px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(236, 72, 153, 0.5);
        }

        /* Gradient Accents */
        .gradient-brand-text {
            background: linear-gradient(135deg, #c084fc 0%, #ec4899 50%, #f43f5e 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .btn-gradient-brand {
            background: linear-gradient(135deg, #9333ea 0%, #ec4899 100%);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        .btn-gradient-brand:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 25px -5px rgba(236, 72, 153, 0.35), 0 8px 10px -6px rgba(147, 51, 234, 0.3);
            filter: brightness(1.08);
        }

        /* Card Surfaces */
        .amoled-card {
            background: #0b0b12;
            border: 1px solid rgba(255, 255, 255, 0.07);
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.85);
        }
        .amoled-card:hover {
            border-color: rgba(168, 85, 247, 0.2);
        }

        /* Dropdown Transitions */
        .menu-dropdown-content {
            transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.25s ease;
            overflow: hidden;
        }
        .menu-dropdown-content.collapsed {
            max-height: 0 !important;
            opacity: 0;
            pointer-events: none;
        }
        .menu-dropdown-content.expanded {
            max-height: 500px;
            opacity: 1;
        }

        .chevron-icon {
            transition: transform 0.25s ease;
        }
        .chevron-rotated {
            transform: rotate(180deg);
        }

        /* Drag & Drop Upload Zone */
        .upload-zone {
            border: 2px dashed rgba(168, 85, 247, 0.35);
            background: linear-gradient(180deg, rgba(168, 85, 247, 0.03) 0%, rgba(236, 72, 153, 0.03) 100%);
            transition: all 0.2s ease;
        }
        .upload-zone:hover {
            border-color: #ec4899;
            background: linear-gradient(180deg, rgba(168, 85, 247, 0.08) 0%, rgba(236, 72, 153, 0.08) 100%);
        }

        /* Print Media Styles */
        @media print {
            body, html { background: #ffffff !important; color: #000000 !important; }
            aside, header, .no-print, .print-hidden { display: none !important; }
            main { padding: 0 !important; margin: 0 !important; width: 100% !important; }
            .amoled-card { background: #ffffff !important; border: 1px solid #e5e7eb !important; color: #000000 !important; box-shadow: none !important; }
            table { width: 100% !important; border-collapse: collapse !important; color: #000000 !important; }
            th, td { border: 1px solid #d1d5db !important; color: #000000 !important; padding: 10px 8px !important; }
            th { background-color: #f3f4f6 !important; }
            .text-white, .text-gray-400, .text-gray-300, .gradient-brand-text { color: #000000 !important; -webkit-text-fill-color: initial !important; }
        }
    </style>
</head>
<body class="h-full bg-black text-white flex flex-col antialiased selection:bg-purple-500 selection:text-white">

    <!-- Subtle Background Ambient Glows -->
    <div class="fixed top-0 left-1/4 w-[36rem] h-[36rem] bg-purple-600/10 rounded-full blur-[140px] pointer-events-none -z-10"></div>
    <div class="fixed bottom-0 right-1/4 w-[36rem] h-[36rem] bg-pink-600/10 rounded-full blur-[140px] pointer-events-none -z-10"></div>

    <div class="flex h-full overflow-hidden">
        
        <!-- Mobile Sidebar Backdrop Overlay -->
        <div id="sidebarBackdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-40 hidden md:hidden"></div>

        <!-- ======================================================== -->
        <!-- SIDEBAR NAVIGATION WITH MULTI-DROPDOWNS                   -->
        <!-- ======================================================== -->
        <aside id="mainSidebar" class="fixed md:static inset-y-0 left-0 z-50 w-72 bg-[#050508] border-r border-white/[0.08] flex flex-col transition-transform duration-300 transform -translate-x-full md:translate-x-0 h-full select-none">
            
            <!-- Brand / Logo Header -->
            <div class="p-5 border-b border-white/[0.08] flex items-center justify-between">
                <a href="dashboard.php" class="flex items-center gap-3.5 group">
                    <div class="w-11 h-11 rounded-xl bg-gradient-to-tr from-purple-600 to-pink-500 flex items-center justify-center shadow-lg shadow-purple-600/30 group-hover:scale-105 transition-transform">
                        <i class="fas fa-bullhorn text-white text-lg"></i>
                    </div>
                    <div>
                        <div class="text-lg font-extrabold tracking-tight flex items-center gap-1">
                            <span class="text-white">Manage</span><span class="gradient-brand-text font-black">Ads</span>
                        </div>
                        <span class="text-[10px] text-gray-500 font-mono tracking-widest uppercase">Admin Pro v3.0</span>
                    </div>
                </a>
                <button onclick="toggleMobileSidebar()" class="md:hidden text-gray-400 hover:text-white p-2">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>

            <!-- Quick Filter in Sidebar -->
            <div class="px-4 pt-4 pb-2">
                <div class="relative">
                    <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
                    <input type="text" id="sidebarMenuSearch" placeholder="Quick find menu..." oninput="filterSidebarMenus(this.value)" class="w-full bg-white/[0.04] border border-white/[0.08] rounded-xl pl-9 pr-3 py-2 text-xs text-white placeholder-gray-500 focus:outline-none focus:border-purple-500/60 focus:ring-1 focus:ring-purple-500/30 transition">
                </div>
            </div>

            <!-- Scrollable Menus Container -->
            <nav class="flex-1 overflow-y-auto px-3 py-3 space-y-1.5" id="sidebarNav">

                <!-- 1. Overview -->
                <div class="sidebar-item">
                    <a href="dashboard.php" class="flex items-center justify-between px-3.5 py-2.5 rounded-xl text-sm font-medium transition-all <?php echo $is_overview_page ? 'bg-gradient-to-r from-purple-600/20 to-pink-600/20 text-white border border-purple-500/30 shadow-sm' : 'text-gray-400 hover:text-white hover:bg-white/[0.04]'; ?>">
                        <div class="flex items-center gap-3">
                            <div class="w-7 h-7 rounded-lg bg-purple-500/10 border border-purple-500/20 flex items-center justify-center text-purple-400">
                                <i class="fas fa-gauge-high text-xs"></i>
                            </div>
                            <span>Overview Dashboard</span>
                        </div>
                        <span class="text-[10px] bg-white/10 px-2 py-0.5 rounded-full text-gray-300 font-mono">Live</span>
                    </a>
                </div>

                <!-- ==================================================== -->
                <!-- DROPDOWN 1: AD PLACEMENTS & BANNERS                  -->
                <!-- ==================================================== -->
                <div class="sidebar-group pt-2">
                    <button type="button" onclick="toggleDropdown('dropdown-placements')" class="w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-semibold uppercase tracking-wider text-gray-400 hover:text-white hover:bg-white/[0.03] transition-colors group">
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-layer-group text-purple-400 text-sm"></i>
                            <span>Ad Placements</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-purple-500/20 text-purple-300 border border-purple-500/30 font-mono"><?php echo count($ads); ?></span>
                            <i class="fas fa-chevron-down text-[10px] text-gray-500 chevron-icon" id="chevron-dropdown-placements"></i>
                        </div>
                    </button>
                    
                    <div id="dropdown-placements" class="menu-dropdown-content space-y-1 pl-3 mt-1 <?php echo $current_ad ? 'expanded' : 'collapsed'; ?>">
                        <?php foreach($ads as $ad): 
                            $is_current = ($selected_placeholder === $ad['placeholder_id']);
                            $is_active = !empty($ad['is_active']);
                        ?>
                            <a href="?placeholder=<?php echo urlencode($ad['placeholder_id']); ?>" class="flex items-center justify-between px-3 py-2 rounded-lg text-xs font-medium transition-all <?php echo $is_current ? 'bg-purple-600/25 text-white border border-purple-500/40 shadow-sm' : 'text-gray-400 hover:text-white hover:bg-white/[0.03]'; ?>">
                                <div class="flex items-center gap-2.5 truncate">
                                    <span class="w-2 h-2 rounded-full <?php echo $is_active ? 'bg-emerald-400 shadow-sm shadow-emerald-400/80' : 'bg-gray-600'; ?>"></span>
                                    <span class="truncate"><?php echo htmlspecialchars($ad['placeholder_name']); ?></span>
                                </div>
                                <span class="text-[9px] font-mono <?php echo $is_active ? 'text-emerald-400' : 'text-gray-600'; ?>">
                                    <?php echo $is_active ? 'ON' : 'OFF'; ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ==================================================== -->
                <!-- DROPDOWN 2: INTEGRATIONS & SCRIPTS                   -->
                <!-- ==================================================== -->
                <div class="sidebar-group pt-2">
                    <button type="button" onclick="toggleDropdown('dropdown-scripts')" class="w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-semibold uppercase tracking-wider text-gray-400 hover:text-white hover:bg-white/[0.03] transition-colors group">
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-code text-pink-400 text-sm"></i>
                            <span>Scripts & Code</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-pink-500/20 text-pink-300 border border-pink-500/30 font-mono"><?php echo count($header_scripts); ?></span>
                            <i class="fas fa-chevron-down text-[10px] text-gray-500 chevron-icon" id="chevron-dropdown-scripts"></i>
                        </div>
                    </button>
                    
                    <div id="dropdown-scripts" class="menu-dropdown-content space-y-1 pl-3 mt-1 <?php echo $is_header_scripts_page ? 'expanded' : 'collapsed'; ?>">
                        <a href="?page=header_scripts" class="flex items-center justify-between px-3 py-2 rounded-lg text-xs font-medium transition-all <?php echo $is_header_scripts_page ? 'bg-pink-600/25 text-white border border-pink-500/40 shadow-sm' : 'text-gray-400 hover:text-white hover:bg-white/[0.03]'; ?>">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-terminal text-pink-400 text-[11px]"></i>
                                <span>Header Custom Snippets</span>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- ==================================================== -->
                <!-- DROPDOWN 3: SPIN & GAMIFICATION                      -->
                <!-- ==================================================== -->
                <div class="sidebar-group pt-2">
                    <button type="button" onclick="toggleDropdown('dropdown-spin')" class="w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-semibold uppercase tracking-wider text-gray-400 hover:text-white hover:bg-white/[0.03] transition-colors group">
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-gamepad text-yellow-400 text-sm"></i>
                            <span>Spin & Rewards</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-yellow-500/20 text-yellow-300 border border-yellow-500/30 font-mono"><?php echo number_format($total_spins); ?></span>
                            <i class="fas fa-chevron-down text-[10px] text-gray-500 chevron-icon" id="chevron-dropdown-spin"></i>
                        </div>
                    </button>
                    
                    <div id="dropdown-spin" class="menu-dropdown-content space-y-1 pl-3 mt-1 <?php echo ($is_spin_stats_page || $is_manage_users_page) ? 'expanded' : 'collapsed'; ?>">
                        <a href="?page=spin_stats" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium transition-all <?php echo $is_spin_stats_page ? 'bg-yellow-600/25 text-white border border-yellow-500/40 shadow-sm' : 'text-gray-400 hover:text-white hover:bg-white/[0.03]'; ?>">
                            <i class="fas fa-chart-line text-yellow-400 text-[11px]"></i>
                            <span>Spin Statistics & Odds</span>
                        </a>
                        <a href="?page=manage_users" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium transition-all <?php echo $is_manage_users_page ? 'bg-yellow-600/25 text-white border border-yellow-500/40 shadow-sm' : 'text-gray-400 hover:text-white hover:bg-white/[0.03]'; ?>">
                            <i class="fas fa-users-gear text-yellow-400 text-[11px]"></i>
                            <span>Manage Users & Balances</span>
                        </a>
                    </div>
                </div>

                <!-- ==================================================== -->
                <!-- DROPDOWN 4: CAMPAIGN INQUIRIES & SALES               -->
                <!-- ==================================================== -->
                <div class="sidebar-group pt-2">
                    <button type="button" onclick="toggleDropdown('dropdown-campaigns')" class="w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-semibold uppercase tracking-wider text-gray-400 hover:text-white hover:bg-white/[0.03] transition-colors group">
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-handshake text-emerald-400 text-sm"></i>
                            <span>Campaigns & Leads</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if($pending_inquiries_count > 0): ?>
                                <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-rose-500 text-white font-bold animate-pulse font-mono"><?php echo $pending_inquiries_count; ?> new</span>
                            <?php else: ?>
                                <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 font-mono"><?php echo count($bookings); ?></span>
                            <?php endif; ?>
                            <i class="fas fa-chevron-down text-[10px] text-gray-500 chevron-icon" id="chevron-dropdown-campaigns"></i>
                        </div>
                    </button>
                    
                    <div id="dropdown-campaigns" class="menu-dropdown-content space-y-1 pl-3 mt-1 <?php echo $is_bookings_page ? 'expanded' : 'collapsed'; ?>">
                        <a href="?page=bookings" class="flex items-center justify-between px-3 py-2 rounded-lg text-xs font-medium transition-all <?php echo $is_bookings_page ? 'bg-emerald-600/25 text-white border border-emerald-500/40 shadow-sm' : 'text-gray-400 hover:text-white hover:bg-white/[0.03]'; ?>">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-envelope-open-text text-emerald-400 text-[11px]"></i>
                                <span>Advertiser Inquiries</span>
                            </div>
                            <?php if($pending_inquiries_count > 0): ?>
                                <span class="w-2 h-2 rounded-full bg-rose-500 animate-ping"></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>

                <!-- ==================================================== -->
                <!-- DROPDOWN 5: USER FEEDBACK & REVIEWS                  -->
                <!-- ==================================================== -->
                <div class="sidebar-group pt-2">
                    <button type="button" onclick="toggleDropdown('dropdown-feedback')" class="w-full flex items-center justify-between px-3.5 py-2.5 rounded-xl text-xs font-semibold uppercase tracking-wider text-gray-400 hover:text-white hover:bg-white/[0.03] transition-colors group">
                        <div class="flex items-center gap-2.5">
                            <i class="fas fa-comment-dots text-violet-400 text-sm"></i>
                            <span>User Feedback</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-violet-500/20 text-violet-300 border border-violet-500/30 font-mono"><?php echo $avg_rating; ?>★</span>
                            <i class="fas fa-chevron-down text-[10px] text-gray-500 chevron-icon" id="chevron-dropdown-feedback"></i>
                        </div>
                    </button>
                    
                    <div id="dropdown-feedback" class="menu-dropdown-content space-y-1 pl-3 mt-1 <?php echo $is_feedback_page ? 'expanded' : 'collapsed'; ?>">
                        <a href="?page=feedback" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-xs font-medium transition-all <?php echo $is_feedback_page ? 'bg-violet-600/25 text-white border border-violet-500/40 shadow-sm' : 'text-gray-400 hover:text-white hover:bg-white/[0.03]'; ?>">
                            <i class="fas fa-star text-violet-400 text-[11px]"></i>
                            <span>Reviews & Suggestions</span>
                        </a>
                    </div>
                </div>

            </nav>

            <!-- Bottom Quick Status & Logout Area -->
            <div class="p-4 border-t border-white/[0.08] bg-black/40 space-y-3">
                <div class="flex items-center justify-between text-xs px-2 py-1.5 rounded-lg bg-white/[0.03] border border-white/[0.05]">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                        <span class="text-gray-400">MySQL Server</span>
                    </div>
                    <span class="font-mono text-emerald-400 text-[10px]">Connected</span>
                </div>

                <div class="flex gap-2">
                    <a href="https://ganatube.in" target="_blank" class="flex-1 py-2 px-3 rounded-xl bg-white/[0.05] hover:bg-white/[0.1] text-xs font-semibold text-gray-300 hover:text-white border border-white/[0.08] transition flex items-center justify-center gap-2">
                        <i class="fas fa-arrow-up-right-from-square text-[10px]"></i>
                        <span>Live Site</span>
                    </a>
                    <form method="POST" class="inline">
                        <button type="submit" name="logout" title="Sign out" class="py-2 px-3 rounded-xl bg-red-500/10 hover:bg-red-500/20 text-xs font-semibold text-red-400 border border-red-500/20 transition flex items-center justify-center">
                            <i class="fas fa-power-off"></i>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        <!-- ======================================================== -->
        <!-- MAIN CONTENT VIEWPORT                                    -->
        <!-- ======================================================== -->
        <div class="flex-1 flex flex-col h-full overflow-hidden bg-black">
            
            <!-- Sticky Top Header -->
            <header class="h-16 border-b border-white/[0.08] bg-[#07070b]/80 backdrop-blur-xl px-6 flex items-center justify-between shrink-0 z-30">
                
                <div class="flex items-center gap-4">
                    <!-- Mobile Hamburger Button -->
                    <button onclick="toggleMobileSidebar()" class="md:hidden text-gray-400 hover:text-white p-2 -ml-2 rounded-lg hover:bg-white/5 transition">
                        <i class="fas fa-bars text-lg"></i>
                    </button>

                    <!-- Breadcrumbs -->
                    <div class="flex items-center gap-2 text-xs font-medium text-gray-400">
                        <a href="dashboard.php" class="hover:text-purple-400 transition flex items-center gap-1.5">
                            <i class="fas fa-home text-[11px]"></i>
                            <span>ManageAds</span>
                        </a>
                        <span class="text-gray-600">/</span>
                        <?php if($current_ad): ?>
                            <span class="text-gray-500">Placements</span>
                            <span class="text-gray-600">/</span>
                            <span class="text-white font-semibold flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-purple-400"></span>
                                <?php echo htmlspecialchars($current_ad['placeholder_name']); ?>
                            </span>
                        <?php elseif($is_bookings_page): ?>
                            <span class="text-white font-semibold">Campaign Inquiries</span>
                        <?php elseif($is_header_scripts_page): ?>
                            <span class="text-white font-semibold">Custom Header Scripts</span>
                        <?php elseif($is_spin_stats_page): ?>
                            <span class="text-white font-semibold">Spin & Win Statistics</span>
                        <?php elseif($is_manage_users_page): ?>
                            <span class="text-white font-semibold">Manage Users & Balances</span>
                        <?php elseif($is_feedback_page): ?>
                            <span class="text-white font-semibold">User Feedback & Ratings</span>
                        <?php else: ?>
                            <span class="text-white font-semibold">Overview</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right Header Actions -->
                <div class="flex items-center gap-3.5">
                    <!-- Live IST Clock -->
                    <div class="hidden lg:flex items-center gap-2 px-3 py-1.5 rounded-lg bg-white/[0.03] border border-white/[0.06] text-xs font-mono text-gray-400">
                        <i class="far fa-clock text-purple-400"></i>
                        <span id="liveClockIST">IST --:--:--</span>
                    </div>

                    <!-- Quick Refresh -->
                    <button onclick="window.location.reload()" title="Refresh page" class="p-2 rounded-xl bg-white/[0.04] hover:bg-white/[0.08] text-gray-400 hover:text-white border border-white/[0.06] transition">
                        <i class="fas fa-rotate text-xs"></i>
                    </button>

                    <!-- User Pill -->
                    <div class="flex items-center gap-2.5 pl-2 border-l border-white/[0.08]">
                        <div class="w-8 h-8 rounded-full bg-gradient-to-r from-purple-500 to-pink-500 flex items-center justify-center text-xs font-bold text-white shadow-md">
                            A
                        </div>
                        <div class="hidden sm:block text-left leading-tight">
                            <div class="text-xs font-semibold text-white">Super Admin</div>
                            <div class="text-[10px] text-gray-500 font-mono">root@manageads</div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Main Scrollable Body Area -->
            <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">

                <!-- Dynamic Alert / Toast Notification -->
                <?php if($message): ?>
                    <div class="mb-6 p-4 rounded-2xl border flex items-center justify-between backdrop-blur-xl animate-fade-in <?php echo $message_type === 'success' ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-300' : 'bg-rose-500/10 border-rose-500/30 text-rose-300'; ?>">
                        <div class="flex items-center gap-3 text-sm">
                            <div class="w-8 h-8 rounded-xl flex items-center justify-center <?php echo $message_type === 'success' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400'; ?>">
                                <i class="fas <?php echo $message_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
                            </div>
                            <span class="font-medium"><?php echo htmlspecialchars($message); ?></span>
                        </div>
                        <button onclick="this.parentElement.remove()" class="text-gray-400 hover:text-white text-sm p-1">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <!-- ==================================================== -->
                <!-- VIEW 1: EXECUTIVE OVERVIEW DASHBOARD                 -->
                <!-- ==================================================== -->
                <?php if($is_overview_page): ?>
                    <div class="max-w-7xl mx-auto space-y-8">
                        
                        <!-- Welcome Hero -->
                        <div class="amoled-card p-8 rounded-3xl relative overflow-hidden">
                            <div class="absolute -right-12 -bottom-12 w-64 h-64 bg-gradient-to-br from-purple-600/20 to-pink-600/20 rounded-full blur-3xl pointer-events-none"></div>
                            
                            <div class="relative z-10 max-w-2xl">
                                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-purple-500/10 border border-purple-500/30 text-purple-300 text-xs font-semibold mb-4">
                                    <i class="fas fa-shield-halved text-[10px]"></i>
                                    <span>ManageAds System Control</span>
                                </div>
                                <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-white mb-3">
                                    Welcome back, <span class="gradient-brand-text">Admin</span>
                                </h1>
                                <p class="text-gray-400 text-sm sm:text-base leading-relaxed">
                                    Monitor your live ad placements, incoming advertiser leads, game prize probabilities, and global custom scripts all from one AMOLED unified dashboard.
                                </p>
                            </div>
                        </div>

                        <!-- KPI Summary Grid -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                            
                            <!-- Card 1: Active Ads -->
                            <div class="amoled-card p-6 rounded-2xl">
                                <div class="flex items-center justify-between mb-4">
                                    <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Active Ad Slots</span>
                                    <div class="w-10 h-10 rounded-xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center text-purple-400">
                                        <i class="fas fa-rectangle-ad"></i>
                                    </div>
                                </div>
                                <div class="flex items-baseline gap-2">
                                    <span class="text-3xl font-extrabold text-white"><?php echo $active_ads_count; ?></span>
                                    <span class="text-xs text-gray-500 font-mono">/ <?php echo count($ads); ?> Total</span>
                                </div>
                                <div class="mt-4 pt-4 border-t border-white/[0.05] flex items-center justify-between text-xs">
                                    <a href="?placeholder=bottom_player_banner" class="text-purple-400 hover:text-purple-300 transition flex items-center gap-1 font-medium">
                                        <span>Manage slots</span>
                                        <i class="fas fa-chevron-right text-[10px]"></i>
                                    </a>
                                    <span class="text-emerald-400 font-mono"><?php echo count($ads) > 0 ? round(($active_ads_count/count($ads))*100) : 0; ?>% Live</span>
                                </div>
                            </div>

                            <!-- Card 2: Campaign Leads -->
                            <div class="amoled-card p-6 rounded-2xl">
                                <div class="flex items-center justify-between mb-4">
                                    <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Campaign Inquiries</span>
                                    <div class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                                        <i class="fas fa-handshake"></i>
                                    </div>
                                </div>
                                <div class="flex items-baseline gap-2">
                                    <span class="text-3xl font-extrabold text-white"><?php echo count($bookings); ?></span>
                                    <?php if($pending_inquiries_count > 0): ?>
                                        <span class="text-xs px-2 py-0.5 rounded-full bg-rose-500/20 text-rose-400 border border-rose-500/30 font-medium">
                                            <?php echo $pending_inquiries_count; ?> Pending
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-4 pt-4 border-t border-white/[0.05] flex items-center justify-between text-xs">
                                    <a href="?page=bookings" class="text-emerald-400 hover:text-emerald-300 transition flex items-center gap-1 font-medium">
                                        <span>Review inquiries</span>
                                        <i class="fas fa-chevron-right text-[10px]"></i>
                                    </a>
                                    <span class="text-gray-400 font-mono">₹<?php echo number_format($total_booking_revenue); ?></span>
                                </div>
                            </div>

                            <!-- Card 3: Total Spins -->
                            <div class="amoled-card p-6 rounded-2xl">
                                <div class="flex items-center justify-between mb-4">
                                    <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total Spins Played</span>
                                    <div class="w-10 h-10 rounded-xl bg-yellow-500/10 border border-yellow-500/20 flex items-center justify-center text-yellow-400">
                                        <i class="fas fa-gift"></i>
                                    </div>
                                </div>
                                <div class="flex items-baseline gap-2">
                                    <span class="text-3xl font-extrabold text-white"><?php echo number_format($total_spins); ?></span>
                                    <span class="text-xs text-yellow-400 flex items-center gap-1">
                                        <i class="fas fa-coins text-[10px]"></i> <?php echo number_format($total_g_coins_won); ?>
                                    </span>
                                </div>
                                <div class="mt-4 pt-4 border-t border-white/[0.05] flex items-center justify-between text-xs">
                                    <a href="?page=spin_stats" class="text-yellow-400 hover:text-yellow-300 transition flex items-center gap-1 font-medium">
                                        <span>Spin logs</span>
                                        <i class="fas fa-chevron-right text-[10px]"></i>
                                    </a>
                                    <span class="text-gray-400 font-mono">Game Active</span>
                                </div>
                            </div>

                            <!-- Card 4: Feedback Rating -->
                            <div class="amoled-card p-6 rounded-2xl">
                                <div class="flex items-center justify-between mb-4">
                                    <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider">User Rating</span>
                                    <div class="w-10 h-10 rounded-xl bg-pink-500/10 border border-pink-500/20 flex items-center justify-center text-pink-400">
                                        <i class="fas fa-star"></i>
                                    </div>
                                </div>
                                <div class="flex items-baseline gap-2">
                                    <span class="text-3xl font-extrabold text-white"><?php echo $avg_rating; ?></span>
                                    <span class="text-xs text-yellow-400">/ 5.0</span>
                                    <span class="text-xs text-gray-500 font-mono">(<?php echo $total_feedbacks; ?>)</span>
                                </div>
                                <div class="mt-4 pt-4 border-t border-white/[0.05] flex items-center justify-between text-xs">
                                    <a href="?page=feedback" class="text-pink-400 hover:text-pink-300 transition flex items-center gap-1 font-medium">
                                        <span>View feedback</span>
                                        <i class="fas fa-chevron-right text-[10px]"></i>
                                    </a>
                                    <span class="text-gray-400 font-mono">Community</span>
                                </div>
                            </div>

                        </div>

                        <!-- Quick Shortcuts & Active Placements Grid -->
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            
                            <!-- Left: Live Placements Snapshot -->
                            <div class="lg:col-span-2 amoled-card p-6 sm:p-7 rounded-3xl">
                                <div class="flex items-center justify-between mb-6">
                                    <div>
                                        <h2 class="text-lg font-bold text-white">Live Ad Placements</h2>
                                        <p class="text-xs text-gray-400">Click any placement to edit its image or custom HTML snippet.</p>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <?php foreach($ads as $ad): 
                                        $is_active = !empty($ad['is_active']);
                                        $has_img = !empty($ad['image_path']) && file_exists($ad['image_path']);
                                        $has_code = !empty($ad['custom_code']);
                                    ?>
                                        <a href="?placeholder=<?php echo urlencode($ad['placeholder_id']); ?>" class="p-4 rounded-2xl bg-white/[0.02] hover:bg-white/[0.05] border border-white/[0.06] hover:border-purple-500/40 transition group">
                                            <div class="flex items-start justify-between mb-3">
                                                <div class="flex items-center gap-2">
                                                    <span class="w-2.5 h-2.5 rounded-full <?php echo $is_active ? 'bg-emerald-400 shadow-sm shadow-emerald-400/80' : 'bg-gray-600'; ?>"></span>
                                                    <span class="text-xs font-mono font-medium <?php echo $is_active ? 'text-emerald-400' : 'text-gray-500'; ?>">
                                                        <?php echo $is_active ? 'Active' : 'Disabled'; ?>
                                                    </span>
                                                </div>
                                                <span class="text-xs text-purple-400 font-mono font-semibold">₹<?php echo intval($ad['price_per_hour'] ?? 0); ?>/hr</span>
                                            </div>

                                            <h3 class="font-bold text-sm text-white group-hover:text-purple-300 transition mb-1">
                                                <?php echo htmlspecialchars($ad['placeholder_name']); ?>
                                            </h3>
                                            
                                            <div class="flex items-center gap-2 mt-3 text-[11px] text-gray-400">
                                                <?php if($has_code): ?>
                                                    <span class="px-2 py-0.5 rounded bg-purple-500/10 text-purple-300 border border-purple-500/20 font-mono">Custom HTML</span>
                                                <?php elseif($has_img): ?>
                                                    <span class="px-2 py-0.5 rounded bg-pink-500/10 text-pink-300 border border-pink-500/20 font-mono">Image Creative</span>
                                                <?php else: ?>
                                                    <span class="text-gray-600">No creative set</span>
                                                <?php endif; ?>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Right: Quick Navigation Hub -->
                            <div class="amoled-card p-6 sm:p-7 rounded-3xl space-y-4">
                                <h2 class="text-lg font-bold text-white mb-2">Quick Actions</h2>
                                
                                <a href="?page=header_scripts" class="flex items-center justify-between p-3.5 rounded-xl bg-white/[0.02] hover:bg-white/[0.05] border border-white/[0.06] hover:border-pink-500/30 transition group">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-lg bg-pink-500/10 border border-pink-500/20 flex items-center justify-center text-pink-400">
                                            <i class="fas fa-code text-xs"></i>
                                        </div>
                                        <div>
                                            <div class="text-xs font-bold text-white group-hover:text-pink-300 transition">Add Header Script</div>
                                            <div class="text-[10px] text-gray-500">Inject tracking or ad tags</div>
                                        </div>
                                    </div>
                                    <i class="fas fa-arrow-right text-xs text-gray-600 group-hover:text-white transition"></i>
                                </a>

                                <a href="?page=spin_stats" class="flex items-center justify-between p-3.5 rounded-xl bg-white/[0.02] hover:bg-white/[0.05] border border-white/[0.06] hover:border-yellow-500/30 transition group">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-lg bg-yellow-500/10 border border-yellow-500/20 flex items-center justify-center text-yellow-400">
                                            <i class="fas fa-sliders text-xs"></i>
                                        </div>
                                        <div>
                                            <div class="text-xs font-bold text-white group-hover:text-yellow-300 transition">Tune Spin Odds</div>
                                            <div class="text-[10px] text-gray-500">Adjust 100% prize distribution</div>
                                        </div>
                                    </div>
                                    <i class="fas fa-arrow-right text-xs text-gray-600 group-hover:text-white transition"></i>
                                </a>

                                <a href="?page=manage_users" class="flex items-center justify-between p-3.5 rounded-xl bg-white/[0.02] hover:bg-white/[0.05] border border-white/[0.06] hover:border-indigo-500/30 transition group">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-lg bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400">
                                            <i class="fas fa-user-plus text-xs"></i>
                                        </div>
                                        <div>
                                            <div class="text-xs font-bold text-white group-hover:text-indigo-300 transition">Reward User Spins</div>
                                            <div class="text-[10px] text-gray-500">Add free spins by user email</div>
                                        </div>
                                    </div>
                                    <i class="fas fa-arrow-right text-xs text-gray-600 group-hover:text-white transition"></i>
                                </a>

                                <a href="?page=bookings" class="flex items-center justify-between p-3.5 rounded-xl bg-white/[0.02] hover:bg-white/[0.05] border border-white/[0.06] hover:border-emerald-500/30 transition group">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-lg bg-emerald-500/10 border border-emerald-500/20 flex items-center justify-center text-emerald-400">
                                            <i class="fas fa-file-invoice text-xs"></i>
                                        </div>
                                        <div>
                                            <div class="text-xs font-bold text-white group-hover:text-emerald-300 transition">Manage Inquiries</div>
                                            <div class="text-[10px] text-gray-500">Approve & generate invoice</div>
                                        </div>
                                    </div>
                                    <i class="fas fa-arrow-right text-xs text-gray-600 group-hover:text-white transition"></i>
                                </a>

                            </div>

                        </div>

                    </div>

                <!-- ==================================================== -->
                <!-- VIEW 2: EDIT AD PLACEMENT                            -->
                <!-- ==================================================== -->
                <?php elseif($current_ad): ?>
                    <div class="max-w-7xl mx-auto space-y-6">
                        
                        <!-- Page Title Header -->
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <div class="inline-flex items-center gap-2 text-xs font-mono text-purple-400 mb-1">
                                    <span>SLOT_ID: <?php echo htmlspecialchars($current_ad['placeholder_id']); ?></span>
                                </div>
                                <h1 class="text-2xl sm:text-3xl font-extrabold text-white">
                                    Configure <span class="gradient-brand-text"><?php echo htmlspecialchars($current_ad['placeholder_name']); ?></span>
                                </h1>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-xs px-3 py-1.5 rounded-xl border font-mono <?php echo !empty($current_ad['is_active']) ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30' : 'bg-gray-500/10 text-gray-400 border-gray-500/30'; ?>">
                                    Status: <?php echo !empty($current_ad['is_active']) ? 'Active & Live' : 'Disabled'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                            
                            <!-- Edit Form (2 Columns) -->
                            <div class="lg:col-span-2 amoled-card p-6 sm:p-8 rounded-3xl">
                                <form method="POST" enctype="multipart/form-data" class="space-y-6">
                                    <input type="hidden" name="placeholder_id" value="<?php echo htmlspecialchars($current_ad['placeholder_id']); ?>">

                                    <!-- Visibility Toggle -->
                                    <div class="flex items-center justify-between p-4 rounded-2xl bg-white/[0.02] border border-white/[0.08]">
                                        <div>
                                            <div class="text-sm font-bold text-white">Ad Placement Visibility</div>
                                            <div class="text-xs text-gray-400">Toggle this ad slot globally across GanaTube.</div>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="isActive" class="sr-only peer" <?php echo !empty($current_ad['is_active']) ? 'checked' : ''; ?>>
                                            <div class="w-12 h-6 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                                        </label>
                                    </div>

                                    <!-- Creative Upload Box -->
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-300 uppercase tracking-wider mb-2">
                                            Ad Creative (Image Upload)
                                        </label>
                                        <div class="upload-zone relative p-8 rounded-2xl text-center cursor-pointer flex flex-col items-center justify-center">
                                            <input type="file" name="adImage" id="adImageInput" accept="image/*" class="absolute inset-0 opacity-0 cursor-pointer w-full h-full z-10">
                                            
                                            <div class="w-12 h-12 rounded-2xl bg-purple-500/10 border border-purple-500/20 flex items-center justify-center text-purple-400 mb-3">
                                                <i class="fas fa-cloud-arrow-up text-lg"></i>
                                            </div>
                                            
                                            <p class="text-sm font-semibold text-white" id="fileNameDisplay">
                                                Click or Drag & Drop image file here
                                            </p>
                                            
                                            <p class="text-xs text-gray-400 mt-1 font-mono">
                                                Supports: WEBP, PNG, JPG (Auto-optimized to 75% quality)
                                            </p>

                                            <?php if($current_ad['placeholder_id'] == 'bottom_player_banner' || $current_ad['placeholder_id'] == 'playlist_in_feed_banner'): ?>
                                                <span class="text-[11px] text-purple-400/90 mt-2 bg-purple-500/10 px-2.5 py-0.5 rounded-full border border-purple-500/20">
                                                    Recommended size: 728 x 90 px (Leaderboard)
                                                </span>
                                            <?php elseif($current_ad['placeholder_id'] == 'home_feed_banner'): ?>
                                                <span class="text-[11px] text-purple-400/90 mt-2 bg-purple-500/10 px-2.5 py-0.5 rounded-full border border-purple-500/20">
                                                    Recommended size: 970 x 250 px (Billboard Banner)
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if(!empty($current_ad['image_path']) && file_exists($current_ad['image_path'])): ?>
                                            <div class="flex items-center gap-2 mt-2 text-xs text-emerald-400">
                                                <i class="fas fa-circle-check"></i>
                                                <span>Active file stored: <code><?php echo htmlspecialchars($current_ad['image_path']); ?></code></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Custom Code (Google Ads / Scripts) -->
                                    <div>
                                        <div class="flex items-center justify-between mb-2">
                                            <label class="block text-xs font-semibold text-gray-300 uppercase tracking-wider">
                                                Custom HTML / Google Ads Script
                                            </label>
                                            <span class="text-[10px] bg-purple-500/20 text-purple-300 px-2 py-0.5 rounded border border-purple-500/30 font-mono">
                                                Overrides Image If Set
                                            </span>
                                        </div>
                                        <textarea name="customCode" rows="4" placeholder="<ins class='adsbygoogle' ...></ins>&#10;<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>" class="w-full p-4 bg-white/[0.03] border border-white/[0.08] rounded-2xl text-white font-mono text-xs placeholder-gray-600 focus:outline-none focus:border-purple-500/60 focus:ring-1 focus:ring-purple-500/30 transition"><?php echo htmlspecialchars($current_ad['custom_code'] ?? ''); ?></textarea>
                                        
                                        <!-- Helpful Google Ads Tips -->
                                        <div class="mt-2 text-xs text-gray-400 flex items-start gap-2 bg-white/[0.02] p-3 rounded-xl border border-white/[0.05]">
                                            <i class="fab fa-google text-yellow-400 mt-0.5"></i>
                                            <div>
                                                <?php if($current_ad['placeholder_id'] == 'bottom_player_banner'): ?>
                                                    <strong>Google Ad Tip:</strong> Use <b>Horizontal Display Ads</b> or <b>Anchor Ads</b> for the sticky bottom music player bar.
                                                <?php elseif($current_ad['placeholder_id'] == 'home_feed_banner'): ?>
                                                    <strong>Google Ad Tip:</strong> Use <b>Billboard / Large Horizontal Banner</b> for best engagement on the home page.
                                                <?php elseif($current_ad['placeholder_id'] == 'playlist_in_feed_banner'): ?>
                                                    <strong>Google Ad Tip:</strong> Use <b>In-Feed Ads</b> to seamlessly match between song list tracks.
                                                <?php else: ?>
                                                    <strong>Google Ad Tip:</strong> Use Responsive Display Ads.
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Price & Destination URL Grid -->
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-300 uppercase tracking-wider mb-2">Price Per Hour (₹)</label>
                                            <div class="relative">
                                                <i class="fas fa-rupee-sign absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
                                                <input type="number" name="pricePerDay" value="<?php echo htmlspecialchars($current_ad['price_per_hour'] ?? 0); ?>" placeholder="100" class="w-full pl-9 pr-4 py-3 bg-white/[0.03] border border-white/[0.08] rounded-xl text-white text-sm focus:outline-none focus:border-purple-500/60 focus:ring-1 focus:ring-purple-500/30 transition">
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-xs font-semibold text-gray-300 uppercase tracking-wider mb-2">Destination URL</label>
                                            <div class="relative">
                                                <i class="fas fa-link absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
                                                <input type="url" name="linkUrl" value="<?php echo htmlspecialchars($current_ad['link_url'] ?? ''); ?>" placeholder="https://example.com/landing" class="w-full pl-9 pr-4 py-3 bg-white/[0.03] border border-white/[0.08] rounded-xl text-white text-sm focus:outline-none focus:border-purple-500/60 focus:ring-1 focus:ring-purple-500/30 transition">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Submit Button -->
                                    <div class="pt-4">
                                        <button type="submit" name="update_ad" class="w-full btn-gradient-brand py-3.5 px-6 rounded-xl font-bold text-sm text-white shadow-lg flex items-center justify-center gap-2">
                                            <i class="fas fa-floppy-disk"></i>
                                            <span>Save Ad Configuration</span>
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Live Creative Preview Card (1 Column) -->
                            <div class="lg:col-span-1">
                                <div class="amoled-card p-6 rounded-3xl sticky top-20 space-y-4">
                                    <div class="flex items-center justify-between">
                                        <h3 class="text-sm font-bold text-white uppercase tracking-wider">Live Preview</h3>
                                        <span class="text-[10px] font-mono text-gray-500">Real-time Simulation</span>
                                    </div>

                                    <?php if(!empty($current_ad['image_path']) && file_exists($current_ad['image_path'])): ?>
                                        <div class="rounded-2xl p-2.5 bg-black/60 border border-white/[0.08] relative group overflow-hidden">
                                            <span class="absolute top-4 right-4 bg-black/80 text-white text-[9px] px-2 py-0.5 rounded-full border border-white/20 z-10 font-mono tracking-wider">SPONSORED</span>
                                            <a href="<?php echo htmlspecialchars($current_ad['link_url']); ?>" target="_blank" class="block">
                                                <img src="<?php echo htmlspecialchars($current_ad['image_path']); ?>?t=<?php echo time(); ?>" alt="Creative Preview" class="w-full h-auto rounded-xl object-cover">
                                            </a>

                                            <!-- Bottom Music Player Contextual Mockup -->
                                            <?php if($current_ad['placeholder_id'] == 'bottom_player_banner'): ?>
                                                <div class="mt-3 bg-[#111116] h-12 rounded-xl flex items-center px-3 border border-white/[0.08]">
                                                    <div class="w-7 h-7 rounded-lg bg-gradient-to-tr from-purple-500 to-pink-500 mr-3"></div>
                                                    <div class="flex-1">
                                                        <div class="h-2 w-20 bg-white/20 rounded mb-1"></div>
                                                        <div class="h-1.5 w-12 bg-white/10 rounded"></div>
                                                    </div>
                                                    <div class="flex space-x-2 text-white/40 text-xs">
                                                        <i class="fas fa-backward"></i>
                                                        <i class="fas fa-play text-white"></i>
                                                        <i class="fas fa-forward"></i>
                                                    </div>
                                                </div>
                                                <p class="text-[10px] text-gray-500 mt-2 text-center font-mono">Simulates banner directly above mini music player.</p>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="border border-dashed border-white/10 rounded-2xl h-52 flex flex-col items-center justify-center text-gray-500 p-6 text-center">
                                            <i class="far fa-image text-3xl mb-2 text-purple-400/40"></i>
                                            <span class="text-xs font-medium">No Creative Uploaded</span>
                                            <p class="text-[10px] text-gray-600 mt-1">Upload an image or paste custom HTML code on the left to see live mockup.</p>
                                        </div>
                                    <?php endif; ?>

                                    <div class="pt-2 text-xs text-gray-400 space-y-1.5 border-t border-white/[0.06]">
                                        <div class="flex justify-between">
                                            <span>Current Target:</span>
                                            <span class="text-white font-mono truncate max-w-[150px]"><?php echo htmlspecialchars($current_ad['link_url'] ?: 'None'); ?></span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span>Hourly Rate:</span>
                                            <span class="text-emerald-400 font-mono">₹<?php echo intval($current_ad['price_per_hour'] ?? 0); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>

                    </div>

                <!-- ==================================================== -->
                <!-- VIEW 3: CAMPAIGN INQUIRIES & ORDERS                  -->
                <!-- ==================================================== -->
                <?php elseif($is_bookings_page): ?>
                    <div class="max-w-7xl mx-auto space-y-6">
                        
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <h1 class="text-2xl sm:text-3xl font-extrabold text-white">
                                    Campaign <span class="gradient-brand-text">Inquiries</span>
                                </h1>
                                <p class="text-xs sm:text-sm text-gray-400 mt-1">Review advertiser booking requests submitted from GanaTube.</p>
                            </div>
                            <button onclick="window.print()" class="no-print self-start sm:self-auto px-4 py-2.5 rounded-xl bg-white/[0.05] hover:bg-white/[0.1] text-white border border-white/[0.1] text-xs font-semibold flex items-center gap-2 transition">
                                <i class="fas fa-print text-purple-400"></i>
                                <span>Print Inquiries</span>
                            </button>
                        </div>

                        <!-- Table Card -->
                        <div class="amoled-card rounded-3xl overflow-hidden">
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse">
                                    <thead>
                                        <tr class="text-xs uppercase tracking-wider text-gray-400 border-b border-white/[0.08] bg-white/[0.01]">
                                            <th class="py-4 px-5 font-semibold">Date & ID</th>
                                            <th class="py-4 px-5 font-semibold">Advertiser & Brand</th>
                                            <th class="py-4 px-5 font-semibold">Placements</th>
                                            <th class="py-4 px-5 font-semibold">Schedule</th>
                                            <th class="py-4 px-5 font-semibold">Total Price</th>
                                            <th class="py-4 px-5 font-semibold">Status</th>
                                            <th class="py-4 px-5 font-semibold text-right no-print">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/[0.05] text-xs">
                                        <?php if(count($bookings) === 0): ?>
                                            <tr>
                                                <td colspan="7" class="py-12 text-center text-gray-500 font-medium">
                                                    <i class="fas fa-inbox text-3xl mb-2 block opacity-40"></i>
                                                    No campaign inquiries submitted yet.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach($bookings as $b): ?>
                                                <tr class="hover:bg-white/[0.02] transition-colors">
                                                    <td class="py-4 px-5 font-mono text-gray-400">
                                                        <div class="text-white font-bold">#<?php echo $b['id']; ?></div>
                                                        <div class="text-[10px] text-gray-500"><?php echo date('M d, Y', strtotime($b['created_at'])); ?></div>
                                                    </td>
                                                    <td class="py-4 px-5">
                                                        <div class="font-bold text-white text-sm"><?php echo htmlspecialchars($b['name']); ?></div>
                                                        <div class="text-purple-400 font-mono text-[11px]"><a href="mailto:<?php echo htmlspecialchars($b['email']); ?>"><?php echo htmlspecialchars($b['email']); ?></a></div>
                                                        <div class="text-gray-400 mt-1">Brand: <span class="text-gray-200"><?php echo htmlspecialchars($b['brand_name']); ?></span></div>
                                                    </td>
                                                    <td class="py-4 px-5">
                                                        <div class="flex flex-wrap gap-1 max-w-[200px]">
                                                            <?php 
                                                                $pnames = explode(',', $b['placement_id']);
                                                                foreach($pnames as $pname) {
                                                                    $pname = trim($pname);
                                                                    echo '<span class="px-2 py-0.5 rounded-full bg-white/[0.05] border border-white/[0.08] text-[10px] font-mono text-gray-300">'.htmlspecialchars($pname).'</span>';
                                                                }
                                                            ?>
                                                        </div>
                                                    </td>
                                                    <td class="py-4 px-5">
                                                        <div class="text-emerald-400 font-bold font-mono"><?php echo htmlspecialchars($b['duration_hours']); ?> Hours</div>
                                                        <div class="text-[10px] text-gray-500">From: <?php echo date('M d, H:i', strtotime($b['start_date_time'])); ?></div>
                                                        <div class="text-[10px] text-gray-500">To: <?php echo date('M d, H:i', strtotime($b['end_date_time'])); ?></div>
                                                    </td>
                                                    <td class="py-4 px-5 font-extrabold text-white font-mono text-sm">
                                                        ₹<?php echo number_format($b['total_price']); ?>
                                                    </td>
                                                    <td class="py-4 px-5">
                                                        <form method="POST" action="?page=bookings" class="inline">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="booking_id" value="<?php echo $b['id']; ?>">
                                                            <select name="status" onchange="this.form.submit()" class="bg-[#12121c] border border-white/[0.1] text-white rounded-lg px-2.5 py-1 text-xs font-medium focus:outline-none focus:border-purple-500 transition">
                                                                <option value="Pending" <?php echo $b['status'] == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                                                <option value="Approved" <?php echo $b['status'] == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                                                <option value="Completed" <?php echo $b['status'] == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                                                <option value="Rejected" <?php echo $b['status'] == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                                            </select>
                                                        </form>
                                                    </td>
                                                    <td class="py-4 px-5 text-right no-print">
                                                        <a href="print_invoice.php?id=<?php echo $b['id']; ?>" target="_blank" class="p-2 rounded-lg bg-white/[0.04] hover:bg-white/[0.08] text-gray-400 hover:text-white border border-white/[0.08] transition inline-flex items-center" title="Print Tax Invoice">
                                                            <i class="fas fa-receipt text-xs"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    </div>

                <!-- ==================================================== -->
                <!-- VIEW 4: SPIN STATISTICS & PROBABILITIES             -->
                <!-- ==================================================== -->
                <?php elseif($is_spin_stats_page): ?>
                    <div class="max-w-7xl mx-auto space-y-6">
                        
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <h1 class="text-2xl sm:text-3xl font-extrabold text-white">
                                    Spin & Win <span class="gradient-brand-text">Statistics</span>
                                </h1>
                                <p class="text-xs sm:text-sm text-gray-400 mt-1">Configure win probability odds and monitor player history logs.</p>
                            </div>
                            <div class="flex items-center gap-4 bg-white/[0.03] px-5 py-2.5 rounded-2xl border border-white/[0.08]">
                                <div>
                                    <div class="text-[10px] text-gray-400 uppercase font-mono">Total Spins</div>
                                    <div class="text-lg font-extrabold text-white font-mono"><?php echo number_format($total_spins); ?></div>
                                </div>
                                <div class="w-px h-8 bg-white/10"></div>
                                <div>
                                    <div class="text-[10px] text-gray-400 uppercase font-mono">G Coins Won</div>
                                    <div class="text-lg font-extrabold text-yellow-400 font-mono flex items-center gap-1">
                                        <i class="fas fa-coins text-xs"></i> <?php echo number_format($total_g_coins_won); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Odds Configuration Card -->
                        <div class="amoled-card p-6 sm:p-8 rounded-3xl border border-purple-500/25">
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-xl bg-purple-500/10 border border-purple-500/30 flex items-center justify-center text-purple-400">
                                        <i class="fas fa-dice"></i>
                                    </div>
                                    <div>
                                        <h2 class="text-base font-bold text-white">Prize Distribution Odds</h2>
                                        <p class="text-xs text-gray-400">All probabilities combined must equal exactly 100%.</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="text-xs font-semibold text-gray-400">Sum Total: <span id="probTotal" class="text-emerald-400 font-bold font-mono text-sm">100</span>%</div>
                                </div>
                            </div>

                            <form method="POST" id="probForm" class="space-y-4">
                                <input type="hidden" name="action" value="update_settings">
                                
                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                                    <div class="p-3.5 rounded-xl bg-white/[0.02] border border-white/[0.06]">
                                        <label class="block text-[11px] font-semibold text-gray-400 mb-1">iPhone 17 Pro (%)</label>
                                        <input type="number" name="prob_iphone" required min="0" max="100" value="<?php echo htmlspecialchars($settings['prob_iphone'] ?? 0); ?>" class="prob-input w-full px-3 py-2 bg-white/[0.04] border border-white/10 rounded-lg text-white font-mono text-sm focus:outline-none focus:border-purple-500">
                                    </div>
                                    <div class="p-3.5 rounded-xl bg-white/[0.02] border border-white/[0.06]">
                                        <label class="block text-[11px] font-semibold text-gray-400 mb-1">AirPods (%)</label>
                                        <input type="number" name="prob_airpods" required min="0" max="100" value="<?php echo htmlspecialchars($settings['prob_airpods'] ?? 1); ?>" class="prob-input w-full px-3 py-2 bg-white/[0.04] border border-white/10 rounded-lg text-white font-mono text-sm focus:outline-none focus:border-purple-500">
                                    </div>
                                    <div class="p-3.5 rounded-xl bg-white/[0.02] border border-white/[0.06]">
                                        <label class="block text-[11px] font-semibold text-gray-400 mb-1">Rs 500 Gift (%)</label>
                                        <input type="number" name="prob_rs500" required min="0" max="100" value="<?php echo htmlspecialchars($settings['prob_rs500'] ?? 4); ?>" class="prob-input w-full px-3 py-2 bg-white/[0.04] border border-white/10 rounded-lg text-white font-mono text-sm focus:outline-none focus:border-purple-500">
                                    </div>
                                    <div class="p-3.5 rounded-xl bg-white/[0.02] border border-white/[0.06]">
                                        <label class="block text-[11px] font-semibold text-gray-400 mb-1">Rs 1000 Amazon (%)</label>
                                        <input type="number" name="prob_amazon" required min="0" max="100" value="<?php echo htmlspecialchars($settings['prob_amazon'] ?? 2); ?>" class="prob-input w-full px-3 py-2 bg-white/[0.04] border border-white/10 rounded-lg text-white font-mono text-sm focus:outline-none focus:border-purple-500">
                                    </div>
                                    <div class="p-3.5 rounded-xl bg-white/[0.02] border border-white/[0.06]">
                                        <label class="block text-[11px] font-semibold text-gray-400 mb-1">G Coins (%)</label>
                                        <input type="number" name="prob_gcoins" required min="0" max="100" value="<?php echo htmlspecialchars($settings['prob_gcoins'] ?? 43); ?>" class="prob-input w-full px-3 py-2 bg-white/[0.04] border border-white/10 rounded-lg text-white font-mono text-sm focus:outline-none focus:border-purple-500">
                                    </div>
                                    <div class="p-3.5 rounded-xl bg-white/[0.02] border border-white/[0.06]">
                                        <label class="block text-[11px] font-semibold text-gray-400 mb-1">Better Luck (%)</label>
                                        <input type="number" name="prob_betterluck" required min="0" max="100" value="<?php echo htmlspecialchars($settings['prob_betterluck'] ?? 50); ?>" class="prob-input w-full px-3 py-2 bg-white/[0.04] border border-white/10 rounded-lg text-white font-mono text-sm focus:outline-none focus:border-purple-500">
                                    </div>
                                </div>

                                <div class="flex justify-end pt-2">
                                    <button type="submit" id="saveProbBtn" class="btn-gradient-brand px-6 py-2.5 rounded-xl font-bold text-xs text-white shadow-lg flex items-center gap-2">
                                        <i class="fas fa-check"></i>
                                        <span>Update Probabilities</span>
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Spin History Table with Filter -->
                        <div class="amoled-card p-6 sm:p-8 rounded-3xl space-y-5">
                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                <form method="GET" class="flex w-full sm:w-auto gap-2">
                                    <input type="hidden" name="page" value="spin_stats">
                                    <div class="relative w-full sm:w-64">
                                        <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
                                        <input type="email" name="filter_email" value="<?php echo htmlspecialchars($filter_email); ?>" placeholder="Filter by Gmail..." class="w-full pl-9 pr-3 py-2 bg-white/[0.03] border border-white/[0.08] rounded-xl text-xs text-white placeholder-gray-500 focus:outline-none focus:border-purple-500">
                                    </div>
                                    <button type="submit" class="px-4 py-2 bg-white/[0.08] hover:bg-white/[0.12] text-xs font-semibold text-white rounded-xl transition">
                                        Filter
                                    </button>
                                    <?php if(!empty($filter_email)): ?>
                                        <a href="?page=spin_stats" class="px-3 py-2 bg-white/[0.04] hover:bg-white/[0.08] text-xs text-gray-400 rounded-xl transition flex items-center">
                                            Clear
                                        </a>
                                    <?php endif; ?>
                                </form>

                                <?php if(!empty($filter_email) && count($spin_stats) > 0): ?>
                                    <form method="POST" onsubmit="return confirm('Delete ALL spin logs for <?php echo htmlspecialchars($filter_email); ?>?');">
                                        <input type="hidden" name="action" value="delete_user_spin_logs">
                                        <input type="hidden" name="user_email" value="<?php echo htmlspecialchars($filter_email); ?>">
                                        <button type="submit" class="px-4 py-2 bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 rounded-xl text-xs font-bold transition flex items-center gap-2">
                                            <i class="fas fa-trash-alt"></i> Delete Logs for User
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-collapse text-xs">
                                    <thead>
                                        <tr class="text-gray-400 border-b border-white/[0.08] uppercase tracking-wider text-[11px]">
                                            <th class="py-3.5 px-4 font-semibold">User Email</th>
                                            <th class="py-3.5 px-4 font-semibold">Outcome</th>
                                            <th class="py-3.5 px-4 font-semibold">Prize Won</th>
                                            <th class="py-3.5 px-4 font-semibold text-right">Time (IST)</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/[0.04]">
                                        <?php if(count($spin_stats) === 0): ?>
                                            <tr><td colspan="4" class="py-8 text-center text-gray-500">No spin entries recorded yet.</td></tr>
                                        <?php else: ?>
                                            <?php foreach($spin_stats as $s): ?>
                                                <tr class="hover:bg-white/[0.02] transition-colors">
                                                    <td class="py-3.5 px-4 text-white font-mono"><?php echo htmlspecialchars($s['user_email']); ?></td>
                                                    <td class="py-3.5 px-4">
                                                        <?php if($s['result'] === 'win' || strpos($s['result'], 'win:') === 0): ?>
                                                            <span class="px-2 py-0.5 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded-full font-bold uppercase text-[10px]">Win</span>
                                                        <?php else: ?>
                                                            <span class="px-2 py-0.5 bg-gray-500/10 text-gray-400 border border-gray-500/20 rounded-full font-bold uppercase text-[10px]">Lose</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-3.5 px-4 font-bold <?php echo ($s['result'] === 'win' || strpos($s['result'], 'win:') === 0) ? 'text-yellow-400' : 'text-gray-500'; ?>">
                                                        <?php 
                                                            if ($s['result'] === 'win') {
                                                                echo htmlspecialchars($s['g_coins_won']) . ' G Coins';
                                                            } else if (strpos($s['result'], 'win:') === 0) {
                                                                echo htmlspecialchars(substr($s['result'], 5));
                                                            } else {
                                                                echo 'Better Luck Next Time';
                                                            }
                                                        ?>
                                                    </td>
                                                    <td class="py-3.5 px-4 text-gray-400 text-right font-mono text-[11px]">
                                                        <?php echo date('M d, Y h:i A', strtotime($s['spin_time'])); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Pagination -->
                            <?php if($spin_total_pages > 1): ?>
                                <div class="flex items-center justify-between pt-4 border-t border-white/[0.06] text-xs">
                                    <span class="text-gray-400 font-mono">Page <?php echo $spin_page; ?> of <?php echo $spin_total_pages; ?></span>
                                    <div class="flex gap-2">
                                        <?php if($spin_page > 1): ?>
                                            <a href="?page=spin_stats&p=<?php echo $spin_page - 1; ?><?php echo !empty($filter_email) ? '&filter_email='.urlencode($filter_email) : ''; ?>" class="px-3 py-1.5 rounded-lg bg-white/[0.04] hover:bg-white/[0.08] text-white border border-white/[0.08] transition">
                                                &larr; Prev
                                            </a>
                                        <?php endif; ?>
                                        <?php if($spin_page < $spin_total_pages): ?>
                                            <a href="?page=spin_stats&p=<?php echo $spin_page + 1; ?><?php echo !empty($filter_email) ? '&filter_email='.urlencode($filter_email) : ''; ?>" class="px-3 py-1.5 rounded-lg bg-white/[0.04] hover:bg-white/[0.08] text-white border border-white/[0.08] transition">
                                                Next &rarr;
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                        </div>

                    </div>

                <!-- ==================================================== -->
                <!-- VIEW 5: MANAGE USERS & BALANCES                      -->
                <!-- ==================================================== -->
                <?php elseif($is_manage_users_page): ?>
                    <div class="max-w-4xl mx-auto space-y-6">
                        
                        <div>
                            <h1 class="text-2xl sm:text-3xl font-extrabold text-white">
                                Manage User <span class="gradient-brand-text">Balances</span>
                            </h1>
                            <p class="text-xs sm:text-sm text-gray-400 mt-1">Directly grant extra spin attempts or G Coins to any registered GanaTube user.</p>
                        </div>

                        <div class="amoled-card p-6 sm:p-8 rounded-3xl">
                            <form method="POST" class="space-y-6">
                                <input type="hidden" name="action" value="edit_user_stats">
                                
                                <div>
                                    <label class="block text-xs font-semibold text-gray-300 uppercase tracking-wider mb-2">User's Gmail Account</label>
                                    <div class="relative">
                                        <i class="fas fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
                                        <input type="email" name="user_email" required placeholder="user@gmail.com" class="w-full pl-9 pr-4 py-3 bg-white/[0.03] border border-white/[0.08] rounded-xl text-white text-sm focus:outline-none focus:border-purple-500/60 focus:ring-1 focus:ring-purple-500/30 transition font-mono">
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-300 uppercase tracking-wider mb-2">Spins Left</label>
                                        <div class="relative">
                                            <i class="fas fa-gift absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
                                            <input type="number" name="spins_left" required min="0" max="9999" value="5" class="w-full pl-9 pr-4 py-3 bg-white/[0.03] border border-white/[0.08] rounded-xl text-white text-sm focus:outline-none focus:border-purple-500/60 focus:ring-1 focus:ring-purple-500/30 transition font-mono">
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-300 uppercase tracking-wider mb-2">G Coins Balance</label>
                                        <div class="relative">
                                            <i class="fas fa-coins absolute left-4 top-1/2 -translate-y-1/2 text-yellow-500 text-xs"></i>
                                            <input type="number" name="g_coins" required min="0" max="9999999" value="100" class="w-full pl-9 pr-4 py-3 bg-white/[0.03] border border-white/[0.08] rounded-xl text-white text-sm focus:outline-none focus:border-purple-500/60 focus:ring-1 focus:ring-purple-500/30 transition font-mono">
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="w-full btn-gradient-brand py-3.5 px-6 rounded-xl font-bold text-sm text-white shadow-lg flex items-center justify-center gap-2">
                                    <i class="fas fa-floppy-disk"></i>
                                    <span>Update User Stats</span>
                                </button>
                            </form>
                        </div>

                    </div>

                <!-- ==================================================== -->
                <!-- VIEW 6: USER FEEDBACK & REVIEWS                      -->
                <!-- ==================================================== -->
                <?php elseif($is_feedback_page): ?>
                    <div class="max-w-7xl mx-auto space-y-6">
                        
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <h1 class="text-2xl sm:text-3xl font-extrabold text-white">
                                    User <span class="gradient-brand-text">Feedback</span>
                                </h1>
                                <p class="text-xs sm:text-sm text-gray-400 mt-1">Review feedback, bug reports, and suggestions submitted by users.</p>
                            </div>
                            <div class="flex items-center gap-4 bg-white/[0.03] px-5 py-2.5 rounded-2xl border border-white/[0.08]">
                                <div>
                                    <div class="text-[10px] text-gray-400 uppercase font-mono">Average Rating</div>
                                    <div class="text-lg font-extrabold text-yellow-400 flex items-center gap-1 font-mono">
                                        <i class="fas fa-star text-xs"></i> <?php echo $avg_rating; ?> / 5.0
                                    </div>
                                </div>
                                <div class="w-px h-8 bg-white/10"></div>
                                <div>
                                    <div class="text-[10px] text-gray-400 uppercase font-mono">Submissions</div>
                                    <div class="text-lg font-extrabold text-white font-mono"><?php echo $total_feedbacks; ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Feedback Cards Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php if(count($feedbacks) === 0): ?>
                                <div class="md:col-span-2 amoled-card p-12 text-center rounded-3xl text-gray-500 font-medium">
                                    <i class="far fa-comments text-3xl mb-2 block opacity-40"></i>
                                    No user feedback submitted yet.
                                </div>
                            <?php else: ?>
                                <?php foreach($feedbacks as $f): ?>
                                    <div class="amoled-card p-5 sm:p-6 rounded-3xl relative flex flex-col justify-between group">
                                        <div>
                                            <div class="flex items-start justify-between mb-3">
                                                <div>
                                                    <div class="font-bold text-white text-sm"><?php echo htmlspecialchars($f['user_name'] ?? 'Anonymous Guest'); ?></div>
                                                    <div class="text-[10px] text-gray-500 font-mono mt-0.5">
                                                        <i class="fas fa-location-dot text-purple-400 mr-1"></i><?php echo htmlspecialchars($f['location'] ?? 'India'); ?> · <?php echo date('M d, Y h:i A', strtotime($f['created_at'])); ?>
                                                    </div>
                                                </div>
                                                <div class="text-yellow-400 text-xs flex gap-0.5">
                                                    <?php for($i=1; $i<=5; $i++): ?>
                                                        <i class="fa-star <?php echo $i <= $f['rating'] ? 'fas' : 'far opacity-30'; ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                            </div>

                                            <p class="text-xs text-gray-300 leading-relaxed bg-white/[0.02] p-3.5 rounded-2xl border border-white/[0.04]">
                                                <?php echo nl2br(htmlspecialchars($f['suggestion'])); ?>
                                            </p>
                                        </div>

                                        <div class="mt-4 pt-3 border-t border-white/[0.05] flex justify-end">
                                            <form method="POST" onsubmit="return confirm('Delete this feedback entry?');">
                                                <input type="hidden" name="action" value="delete_feedback">
                                                <input type="hidden" name="feedback_id" value="<?php echo $f['id']; ?>">
                                                <button type="submit" class="px-3 py-1.5 rounded-lg bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 text-xs font-semibold transition flex items-center gap-1.5">
                                                    <i class="fas fa-trash-alt text-[10px]"></i>
                                                    <span>Delete</span>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                    </div>

                <!-- ==================================================== -->
                <!-- VIEW 7: CUSTOM HEADER SCRIPTS                        -->
                <!-- ==================================================== -->
                <?php elseif($is_header_scripts_page): ?>
                    <div class="max-w-7xl mx-auto space-y-6">
                        
                        <div>
                            <h1 class="text-2xl sm:text-3xl font-extrabold text-white">
                                Custom <span class="gradient-brand-text">Header Scripts</span>
                            </h1>
                            <p class="text-xs sm:text-sm text-gray-400 mt-1">Inject custom analytics, tracking pixels, or third-party ad networks globally into the GanaTube frontend &lt;head&gt;.</p>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                            
                            <!-- Add Snippet Form -->
                            <div class="lg:col-span-1 amoled-card p-6 sm:p-7 rounded-3xl h-fit">
                                <h2 class="text-base font-bold text-white mb-4 flex items-center gap-2">
                                    <i class="fas fa-plus text-xs text-pink-400"></i>
                                    <span>Add New Snippet</span>
                                </h2>
                                
                                <form method="POST" class="space-y-4">
                                    <input type="hidden" name="action" value="add_header_script">
                                    
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-300 uppercase tracking-wider mb-2">Snippet Title / Label</label>
                                        <input type="text" name="script_name" required placeholder="e.g. Adsterra Popunder" class="w-full px-3.5 py-2.5 bg-white/[0.03] border border-white/[0.08] rounded-xl text-xs text-white placeholder-gray-500 focus:outline-none focus:border-pink-500 transition">
                                    </div>

                                    <div>
                                        <label class="block text-xs font-semibold text-gray-300 uppercase tracking-wider mb-2">Code Snippet (&lt;script&gt;)</label>
                                        <textarea name="custom_code" required rows="6" placeholder="<script>&#10;  // Your script here&#10;</script>" class="w-full p-3.5 bg-white/[0.03] border border-white/[0.08] rounded-xl text-xs text-white font-mono placeholder-gray-600 focus:outline-none focus:border-pink-500 transition"></textarea>
                                    </div>

                                    <div class="flex items-center justify-between p-3 rounded-xl bg-white/[0.02] border border-white/[0.06]">
                                        <span class="text-xs font-medium text-white">Enable Instantly</span>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="is_active" class="sr-only peer" checked>
                                            <div class="w-10 h-5 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-pink-600"></div>
                                        </label>
                                    </div>

                                    <button type="submit" class="w-full btn-gradient-brand py-3 px-4 rounded-xl font-bold text-xs text-white shadow-lg flex items-center justify-center gap-2">
                                        <i class="fas fa-plus"></i>
                                        <span>Add Global Snippet</span>
                                    </button>
                                </form>
                            </div>

                            <!-- List Snippets -->
                            <div class="lg:col-span-2 space-y-4">
                                <div class="flex items-center justify-between">
                                    <h2 class="text-base font-bold text-white">Configured Snippets (<?php echo count($header_scripts); ?>)</h2>
                                </div>

                                <?php if(count($header_scripts) === 0): ?>
                                    <div class="amoled-card p-12 text-center rounded-3xl text-gray-500">
                                        <i class="fas fa-code text-3xl mb-2 block opacity-40"></i>
                                        No custom header snippets added yet.
                                    </div>
                                <?php else: ?>
                                    <?php foreach($header_scripts as $script): 
                                        $is_active = !empty($script['is_active']);
                                    ?>
                                        <div class="amoled-card p-5 sm:p-6 rounded-3xl space-y-3">
                                            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                                <div>
                                                    <div class="text-[10px] font-mono text-pink-400 font-bold"><?php echo htmlspecialchars($script['placeholder_id']); ?></div>
                                                    <h3 class="text-sm font-bold text-white"><?php echo htmlspecialchars($script['placeholder_name']); ?></h3>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <!-- Edit Toggle -->
                                                    <button type="button" onclick="document.getElementById('edit-snippet-<?php echo htmlspecialchars($script['placeholder_id']); ?>').classList.toggle('hidden');" class="p-2 rounded-xl bg-white/[0.05] hover:bg-white/[0.1] text-gray-300 hover:text-white border border-white/[0.08] text-xs transition" title="Edit Snippet">
                                                        <i class="fas fa-edit"></i>
                                                    </button>

                                                    <!-- Toggle Status -->
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="toggle_header_script">
                                                        <input type="hidden" name="script_id" value="<?php echo htmlspecialchars($script['placeholder_id']); ?>">
                                                        <input type="hidden" name="is_active" value="<?php echo $is_active ? '0' : '1'; ?>">
                                                        <button type="submit" class="text-[11px] font-semibold px-3 py-1 rounded-full border transition <?php echo $is_active ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30 hover:bg-emerald-500/20' : 'bg-gray-500/10 text-gray-400 border-gray-500/30 hover:bg-gray-500/20'; ?>">
                                                            <?php echo $is_active ? 'Active' : 'Disabled'; ?>
                                                        </button>
                                                    </form>

                                                    <!-- Delete -->
                                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this header script?');">
                                                        <input type="hidden" name="action" value="delete_header_script">
                                                        <input type="hidden" name="script_id" value="<?php echo htmlspecialchars($script['placeholder_id']); ?>">
                                                        <button type="submit" class="p-2 rounded-xl bg-red-500/10 hover:bg-red-500/20 text-red-400 border border-red-500/20 text-xs transition" title="Delete Snippet">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>

                                            <div class="bg-black/80 p-3.5 rounded-2xl border border-white/[0.06] overflow-x-auto">
                                                <pre class="text-[11px] font-mono text-gray-300"><?php echo htmlspecialchars($script['custom_code']); ?></pre>
                                            </div>

                                            <!-- Collapsible Edit Form -->
                                            <div id="edit-snippet-<?php echo htmlspecialchars($script['placeholder_id']); ?>" class="hidden pt-3 border-t border-white/[0.06] space-y-3">
                                                <form method="POST" class="space-y-3">
                                                    <input type="hidden" name="action" value="update_header_script">
                                                    <input type="hidden" name="script_id" value="<?php echo htmlspecialchars($script['placeholder_id']); ?>">
                                                    <input type="hidden" name="is_active" value="<?php echo $is_active ? '1' : '0'; ?>">
                                                    <div>
                                                        <label class="block text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-1">Snippet Title</label>
                                                        <input type="text" name="script_name" value="<?php echo htmlspecialchars($script['placeholder_name']); ?>" required class="w-full px-3 py-2 bg-white/[0.03] border border-white/[0.08] rounded-xl text-xs text-white focus:outline-none focus:border-pink-500 transition">
                                                    </div>
                                                    <div>
                                                        <label class="block text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-1">Code Snippet</label>
                                                        <textarea name="custom_code" required rows="5" class="w-full p-3 bg-white/[0.03] border border-white/[0.08] rounded-xl text-xs text-white font-mono focus:outline-none focus:border-pink-500 transition"><?php echo htmlspecialchars($script['custom_code']); ?></textarea>
                                                    </div>
                                                    <div class="flex justify-end gap-2">
                                                        <button type="button" onclick="document.getElementById('edit-snippet-<?php echo htmlspecialchars($script['placeholder_id']); ?>').classList.add('hidden');" class="px-3 py-1.5 rounded-xl bg-white/[0.05] hover:bg-white/[0.1] text-xs text-gray-300 transition">Cancel</button>
                                                        <button type="submit" class="px-4 py-1.5 rounded-xl btn-gradient-brand font-bold text-xs text-white shadow transition">Save Changes</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                        </div>

                    </div>
                <?php endif; ?>

            </main>
        </div>

    </div>

    <!-- Client-side Interactive Scripts -->
    <script>
        // Collapsible Dropdowns State Handler
        function toggleDropdown(id) {
            const content = document.getElementById(id);
            const chevron = document.getElementById('chevron-' + id);
            
            if (!content) return;
            
            const isCollapsed = content.classList.contains('collapsed');
            if (isCollapsed) {
                content.classList.remove('collapsed');
                content.classList.add('expanded');
                if (chevron) chevron.classList.add('chevron-rotated');
                localStorage.setItem('dropdown_' + id, 'open');
            } else {
                content.classList.remove('expanded');
                content.classList.add('collapsed');
                if (chevron) chevron.classList.remove('chevron-rotated');
                localStorage.setItem('dropdown_' + id, 'closed');
            }
        }

        // Restore Dropdown State from LocalStorage
        document.addEventListener('DOMContentLoaded', () => {
            const dropdowns = ['dropdown-placements', 'dropdown-scripts', 'dropdown-spin', 'dropdown-campaigns', 'dropdown-feedback'];
            dropdowns.forEach(id => {
                const content = document.getElementById(id);
                const chevron = document.getElementById('chevron-' + id);
                if (!content) return;

                // Check if currently active page is within this dropdown
                if (content.classList.contains('expanded')) {
                    if (chevron) chevron.classList.add('chevron-rotated');
                    return;
                }

                const savedState = localStorage.getItem('dropdown_' + id);
                if (savedState === 'open') {
                    content.classList.remove('collapsed');
                    content.classList.add('expanded');
                    if (chevron) chevron.classList.add('chevron-rotated');
                }
            });

            // Live IST Clock
            function updateClock() {
                const clockEl = document.getElementById('liveClockIST');
                if (!clockEl) return;
                const now = new Date();
                // Format in Asia/Kolkata
                const timeStr = now.toLocaleTimeString('en-US', { timeZone: 'Asia/Kolkata', hour12: true });
                clockEl.textContent = 'IST ' + timeStr;
            }
            setInterval(updateClock, 1000);
            updateClock();

            // Dynamic File Input Listener
            const fileInput = document.getElementById('adImageInput');
            const fileNameDisplay = document.getElementById('fileNameDisplay');
            if (fileInput && fileNameDisplay) {
                fileInput.addEventListener('change', function(e) {
                    if (e.target.files && e.target.files.length > 0) {
                        fileNameDisplay.textContent = 'Selected: ' + e.target.files[0].name;
                        fileNameDisplay.classList.add('text-purple-400');
                    }
                });
            }

            // Real-time Probability Sum Calculator
            const probInputs = document.querySelectorAll('.prob-input');
            const probTotalSpan = document.getElementById('probTotal');
            const saveProbBtn = document.getElementById('saveProbBtn');

            if (probInputs.length > 0 && probTotalSpan && saveProbBtn) {
                function calcProbTotal() {
                    let sum = 0;
                    probInputs.forEach(input => {
                        sum += parseInt(input.value) || 0;
                    });
                    probTotalSpan.textContent = sum;
                    if (sum === 100) {
                        probTotalSpan.className = 'text-emerald-400 font-bold font-mono text-sm';
                        saveProbBtn.disabled = false;
                        saveProbBtn.classList.remove('opacity-40', 'cursor-not-allowed');
                    } else {
                        probTotalSpan.className = 'text-rose-400 font-bold font-mono text-sm';
                        saveProbBtn.disabled = true;
                        saveProbBtn.classList.add('opacity-40', 'cursor-not-allowed');
                    }
                }
                probInputs.forEach(input => input.addEventListener('input', calcProbTotal));
                calcProbTotal();
            }
        });

        // Mobile Sidebar Drawer Toggle
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('mainSidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            if (!sidebar || !backdrop) return;

            const isOpen = !sidebar.classList.contains('-translate-x-full');
            if (isOpen) {
                sidebar.classList.add('-translate-x-full');
                backdrop.classList.add('hidden');
            } else {
                sidebar.classList.remove('-translate-x-full');
                backdrop.classList.remove('hidden');
            }
        }

        // Search Filter for Sidebar Menu Items
        function filterSidebarMenus(query) {
            query = query.toLowerCase().trim();
            const groups = document.querySelectorAll('.sidebar-group, .sidebar-item');
            groups.forEach(group => {
                const text = group.textContent.toLowerCase();
                if (!query || text.includes(query)) {
                    group.style.display = '';
                    // Expand dropdown if matches
                    const dropdown = group.querySelector('.menu-dropdown-content');
                    if (dropdown && query) {
                        dropdown.classList.remove('collapsed');
                        dropdown.classList.add('expanded');
                    }
                } else {
                    group.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>
