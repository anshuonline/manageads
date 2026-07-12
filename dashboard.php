<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . "/config.php";
if ($conn->connect_error) {
    die("Database connection failed.");
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['logout'])) {
        session_destroy();
        header("Location: index.php");
        exit;
    }
    
    if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        $status = $conn->real_escape_string($_POST['status']);
        $booking_id = intval($_POST['booking_id']);
        
        $stmt = $conn->prepare("UPDATE campaign_bookings SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $booking_id);
        if ($stmt->execute()) {
            $message = "Order status updated successfully.";
        } else {
            $message = "Failed to update order status.";
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
                        $uploadSuccess = imagejpeg($image, $targetFile, 75); // 75% quality
                    } elseif ($info['mime'] == 'image/png' && function_exists('imagecreatefrompng')) {
                        $image = imagecreatefrompng($sourceFile);
                        imagealphablending($image, false);
                        imagesavealpha($image, true);
                        $uploadSuccess = imagepng($image, $targetFile, 7); // 0-9 compression level
                    } elseif ($info['mime'] == 'image/webp' && function_exists('imagecreatefromwebp')) {
                        $image = imagecreatefromwebp($sourceFile);
                        $uploadSuccess = imagewebp($image, $targetFile, 75);
                    } else {
                        $uploadSuccess = move_uploaded_file($sourceFile, $targetFile);
                    }
                    if (isset($image) && $image !== false) imagedestroy($image);
                } else {
                    // Fallback if GD is missing or it's a GIF
                    $uploadSuccess = move_uploaded_file($sourceFile, $targetFile);
                }
                
                if ($uploadSuccess) {
                    // Delete old image if it exists
                    $oldResult = $conn->query("SELECT image_path FROM ads WHERE placeholder_id = '$placeholder_id'");
                    if ($oldResult && $oldResult->num_rows > 0) {
                        $oldImg = $oldResult->fetch_assoc()['image_path'];
                        if ($oldImg && file_exists($oldImg)) {
                            unlink($oldImg);
                        }
                    }
                    
                    // Update DB with new image path
                    $updateSql = "UPDATE ads SET image_path = '$targetFile', link_url = '$linkUrl', custom_code = '$customCode', is_active = $isActive, price_per_hour = $pricePerDay WHERE placeholder_id = '$placeholder_id'";
                    if ($conn->query($updateSql) === TRUE) {
                        $message = "Ad configuration updated successfully!";
                    } else {
                        $message = "Error updating ad: " . $conn->error;
                    }
                } else {
                    $message = "Failed to process and upload image.";
                }
            } else {
                $message = "Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.";
            }
        }
        
        if (!$imageUploadStarted) {
            $updateSql = "UPDATE ads SET link_url = '$linkUrl', custom_code = '$customCode', is_active = $isActive, price_per_hour = $pricePerDay WHERE placeholder_id = '$placeholder_id'";
            if ($conn->query($updateSql) === TRUE) {
                $message = "Ad configuration updated successfully!";
            } else {
                $message = "Error updating ad: " . $conn->error;
            }
        }
    }
}

$adsResult = $conn->query("SELECT * FROM ads");
$ads = [];
while($row = $adsResult->fetch_assoc()) {
    $ads[] = $row;
}

$is_bookings_page = isset($_GET['page']) && $_GET['page'] === 'bookings';
$selected_placeholder = $_GET['placeholder'] ?? ($is_bookings_page ? null : 'bottom_player_banner');
$current_ad = null;
if ($selected_placeholder) {
    foreach($ads as $ad) {
        if ($ad['placeholder_id'] == $selected_placeholder) {
            $current_ad = $ad;
            break;
        }
    }
}

$bookings = [];
if ($is_bookings_page) {
    $bookingsResult = $conn->query("SELECT * FROM campaign_bookings ORDER BY created_at DESC");
    while($row = $bookingsResult->fetch_assoc()) {
        $bookings[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Ads - Pro Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #09090b;
            color: #fafafa;
        }
        .glass-panel {
            background: rgba(24, 24, 27, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .gradient-text {
            background: linear-gradient(to right, #a855f7, #ec4899);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .btn-gradient {
            background: linear-gradient(to right, #a855f7, #ec4899);
            transition: all 0.3s ease;
        }
        .btn-gradient:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px 0 rgba(168, 85, 247, 0.39);
        }
        .file-upload-wrapper {
            position: relative;
            width: 100%;
            height: 150px;
            border: 2px dashed rgba(168, 85, 247, 0.5);
            border-radius: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(168, 85, 247, 0.05);
        }
        .file-upload-wrapper:hover {
            background: rgba(168, 85, 247, 0.1);
            border-color: #a855f7;
        }
        .file-upload-input {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            opacity: 0;
            cursor: pointer;
        }
        @media print {
            body, html { background: #ffffff !important; color: #000000 !important; }
            aside { display: none !important; }
            main { padding: 0 !important; overflow: visible !important; }
            .glass-panel { background: none !important; border: none !important; box-shadow: none !important; color: #000000 !important; padding: 0 !important; }
            table { width: 100% !important; border-collapse: collapse !important; margin-top: 20px !important; }
            th, td { border: 1px solid #000000 !important; color: #000000 !important; padding: 12px 8px !important; text-align: left !important; }
            th { background-color: #f3f4f6 !important; font-weight: bold !important; -webkit-print-color-adjust: exact; color-adjust: exact; }
            .text-white, .text-gray-400, .text-purple-400, .text-emerald-400 { color: #000000 !important; }
            h1, p { color: #000000 !important; }
            .print-hidden, .no-print { display: none !important; }
            a { text-decoration: none !important; color: #000000 !important; }
            .badge { border: 1px solid #000; padding: 2px 6px; border-radius: 4px; display: inline-block; }
            .action-btn { display: none !important; }
        }
    </style>
</head>
<body class="min-h-screen flex">
    
    <!-- Sidebar -->
    <aside class="w-64 glass-panel border-r flex flex-col">
        <div class="p-6">
            <h2 class="text-2xl font-bold gradient-text tracking-wider">Ads Pro</h2>
        </div>
        <nav class="flex-1 px-4 space-y-2">
            <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-4 mt-4">Placeholders</div>
            <?php foreach($ads as $ad): ?>
                <a href="?placeholder=<?php echo $ad['placeholder_id']; ?>" 
                   class="block px-4 py-3 rounded-lg transition-colors <?php echo !$is_bookings_page && $selected_placeholder == $ad['placeholder_id'] ? 'bg-purple-600/20 text-purple-400 border border-purple-500/30' : 'text-gray-400 hover:bg-white/5 hover:text-white'; ?>">
                    <i class="fas fa-layer-group mr-2"></i> <?php echo htmlspecialchars($ad['placeholder_name']); ?>
                </a>
            <?php endforeach; ?>

            <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-4 mt-8">Leads</div>
            <a href="?page=bookings" 
               class="block px-4 py-3 rounded-lg transition-colors <?php echo $is_bookings_page ? 'bg-emerald-600/20 text-emerald-400 border border-emerald-500/30' : 'text-gray-400 hover:bg-white/5 hover:text-white'; ?>">
                <i class="fas fa-bullhorn mr-2"></i> Campaign Inquiries
            </a>
        </nav>
        <div class="p-4 mt-auto">
            <form method="POST">
                <button type="submit" name="logout" class="w-full py-2 px-4 rounded-lg bg-red-500/10 text-red-400 hover:bg-red-500/20 transition-colors border border-red-500/20">
                    <i class="fas fa-sign-out-alt mr-2"></i> Logout
                </button>
            </form>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 p-8 overflow-y-auto">
        <?php if($message): ?>
            <div class="mb-6 p-4 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center">
                <i class="fas fa-check-circle mr-3 text-lg"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if($is_bookings_page): ?>
            <div class="flex justify-between items-center mb-8">
                <div>
                    <h1 class="text-3xl font-bold text-white mb-2">Campaign Inquiries</h1>
                    <p class="text-gray-400">Review advertising requests submitted via GanaTube.</p>
                </div>
                <button onclick="window.print()" class="px-4 py-2 bg-white/10 hover:bg-white/20 text-white rounded-lg transition-colors flex items-center print-hidden border border-white/20">
                    <i class="fas fa-print mr-2"></i> Print Inquiries
                </button>
            </div>

            <div class="glass-panel p-8 rounded-2xl overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="text-gray-400 border-b border-white/10">
                            <th class="py-4 px-4 font-medium">Date</th>
                            <th class="py-4 px-4 font-medium">Advertiser</th>
                            <th class="py-4 px-4 font-medium">Placements</th>
                            <th class="py-4 px-4 font-medium">Duration (Hours)</th>
                            <th class="py-4 px-4 font-medium">Details</th>
                            <th class="py-4 px-4 font-medium">Total Value</th>
                            <th class="py-4 px-4 font-medium">Status</th>
                            <th class="py-4 px-4 font-medium print-hidden">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(count($bookings) === 0): ?>
                            <tr><td colspan="8" class="py-8 text-center text-gray-500">No campaign inquiries yet.</td></tr>
                        <?php else: ?>
                            <?php foreach($bookings as $b): ?>
                                <tr class="border-b border-white/5 hover:bg-white/5 transition-colors">
                                    <td class="py-4 px-4 text-sm text-gray-300"><?php echo date('M d, Y', strtotime($b['created_at'])); ?></td>
                                    <td class="py-4 px-4">
                                        <div class="font-medium text-white"><?php echo htmlspecialchars($b['name']); ?></div>
                                        <div class="text-xs text-purple-400"><a href="mailto:<?php echo htmlspecialchars($b['email']); ?>"><?php echo htmlspecialchars($b['email']); ?></a></div>
                                        <div class="text-xs text-gray-400 mt-1">Brand: <?php echo htmlspecialchars($b['brand_name']); ?></div>
                                    </td>
                                    <td class="py-4 px-4 text-gray-300 text-sm">
                                        <?php 
                                            $pnames = explode(',', $b['placement_id']);
                                            foreach($pnames as $pname) {
                                                $pname = trim($pname);
                                                if($pname == 'bottom_player_banner') echo '<div class="mb-1 bg-white/10 px-2 py-1 rounded inline-block">Bottom Banner</div> ';
                                                elseif($pname == 'home_feed_banner') echo '<div class="mb-1 bg-white/10 px-2 py-1 rounded inline-block">Home Banner</div> ';
                                                elseif($pname == 'playlist_in_feed_banner') echo '<div class="mb-1 bg-white/10 px-2 py-1 rounded inline-block">Playlist Banner</div> ';
                                                else echo '<div class="mb-1 bg-white/10 px-2 py-1 rounded inline-block">'.htmlspecialchars($pname).'</div> ';
                                            }
                                        ?>
                                    </td>
                                    <td class="py-4 px-4 text-sm">
                                        <div class="text-emerald-400 font-bold"><?php echo htmlspecialchars($b['duration_hours']); ?> Hours</div>
                                        <div class="text-xs text-gray-500">From: <?php echo date('M d, H:i', strtotime($b['start_date_time'])); ?></div>
                                        <div class="text-xs text-gray-500">To: <?php echo date('M d, H:i', strtotime($b['end_date_time'])); ?></div>
                                    </td>
                                    <td class="py-4 px-4 text-xs">
                                        <div class="text-gray-300"><span class="text-gray-500">Audience:</span> <?php echo htmlspecialchars($b['target_audience']); ?></div>
                                        <div class="text-gray-300 mt-1"><span class="text-gray-500">Desc:</span> <?php echo htmlspecialchars($b['ad_description'] ?? 'N/A'); ?></div>
                                        <?php if(!empty($b['ad_link'])): ?>
                                            <div class="mt-1"><a href="<?php echo htmlspecialchars($b['ad_link']); ?>" target="_blank" class="text-blue-400 hover:underline">View Ad Link</a></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-4 font-bold text-white text-lg">₹<?php echo number_format($b['total_price']); ?></td>
                                    <td class="py-4 px-4">
                                        <form method="POST" action="?page=bookings" class="flex flex-col gap-2">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="booking_id" value="<?php echo $b['id']; ?>">
                                            <select name="status" onchange="this.form.submit()" class="bg-black/50 border border-white/20 text-white rounded p-1 text-sm focus:outline-none focus:border-purple-500 transition-colors">
                                                <option value="Pending" <?php echo $b['status'] == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                                <option value="Approved" <?php echo $b['status'] == 'Approved' ? 'selected' : ''; ?>>Approved</option>
                                                <option value="Completed" <?php echo $b['status'] == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                                                <option value="Rejected" <?php echo $b['status'] == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                            </select>
                                        </form>
                                    </td>
                                    <td class="py-4 px-4 text-center print-hidden">
                                        <a href="print_invoice.php?id=<?php echo $b['id']; ?>" target="_blank" class="text-gray-400 hover:text-white transition-colors" title="Print Invoice">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif($current_ad): ?>
            <div class="flex justify-between items-center mb-8">
                <div>
                    <h1 class="text-3xl font-bold text-white mb-2">Edit Placement</h1>
                    <p class="text-gray-400">Configure the ad for <span class="text-purple-400 font-semibold"><?php echo htmlspecialchars($current_ad['placeholder_name']); ?></span></p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Form Section -->
                <div class="lg:col-span-2 glass-panel p-8 rounded-2xl">
                    <form method="POST" enctype="multipart/form-data" class="space-y-6">
                        <input type="hidden" name="placeholder_id" value="<?php echo htmlspecialchars($current_ad['placeholder_id']); ?>">
                        
                        <!-- Status Toggle -->
                        <div class="flex items-center justify-between p-4 bg-white/5 rounded-xl border border-white/10">
                            <div>
                                <h3 class="text-white font-medium">Ad Visibility</h3>
                                <p class="text-sm text-gray-400">Turn this ad placement on or off globally.</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="isActive" class="sr-only peer" <?php echo $current_ad['is_active'] ? 'checked' : ''; ?>>
                                <div class="w-14 h-7 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-purple-500"></div>
                            </label>
                        </div>

                        <!-- Image Upload -->
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Ad Creative (Image)</label>
                            <div class="file-upload-wrapper">
                                <input type="file" name="adImage" class="file-upload-input" accept="image/*" id="adImageInput">
                                <i class="fas fa-cloud-upload-alt text-3xl text-purple-400 mb-2"></i>
                                <span class="text-gray-300 font-medium" id="fileNameDisplay">Drag & drop or click to upload image</span>
                                <?php if($current_ad['placeholder_id'] == 'bottom_player_banner' || $current_ad['placeholder_id'] == 'playlist_in_feed_banner'): ?>
                                    <span class="text-xs text-gray-400 mt-1">Recommended: 728x90 pixels (Leaderboard) in JPG, PNG, WEBP</span>
                                <?php elseif($current_ad['placeholder_id'] == 'home_feed_banner'): ?>
                                    <span class="text-xs text-gray-400 mt-1">Recommended: 970x250 pixels (Billboard) in JPG, PNG, WEBP</span>
                                <?php else: ?>
                                    <span class="text-xs text-gray-500 mt-1">Recommended: JPG, PNG, WEBP</span>
                                <?php endif; ?>
                            </div>
                            <?php if($current_ad['image_path']): ?>
                                <p class="text-xs text-emerald-400 mt-2"><i class="fas fa-check mr-1"></i> Image currently uploaded.</p>
                            <?php endif; ?>
                        </div>

                        <!-- Custom HTML Snippet -->
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-sm font-medium text-gray-300">OR Custom HTML (Google Ads, Scripts)</label>
                                <span class="text-xs text-purple-400 bg-purple-500/10 px-2 py-1 rounded">Overrides Image</span>
                            </div>
                            <textarea name="customCode" rows="4" placeholder="<script async src='https://pagead2.googlesyndication.com/...&#10;Paste full Google Ads snippet here..." class="w-full p-4 bg-white/5 border border-white/10 rounded-xl text-white placeholder-gray-500 focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-colors font-mono text-sm"><?php echo htmlspecialchars($current_ad['custom_code'] ?? ''); ?></textarea>
                            
                            <?php if($current_ad['placeholder_id'] == 'bottom_player_banner'): ?>
                                <p class="text-xs text-emerald-400 mt-2"><i class="fab fa-google mr-1"></i> Google Ads Suggestion: Use <b>Display ads (Horizontal)</b> or <b>Anchor ads</b> for best results at the bottom of the screen.</p>
                            <?php elseif($current_ad['placeholder_id'] == 'home_feed_banner'): ?>
                                <p class="text-xs text-emerald-400 mt-2"><i class="fab fa-google mr-1"></i> Google Ads Suggestion: Use <b>In-feed ads</b> or <b>Display ads (Square/Billboard)</b> so it blends naturally with the home page layout.</p>
                            <?php elseif($current_ad['placeholder_id'] == 'playlist_in_feed_banner'): ?>
                                <p class="text-xs text-emerald-400 mt-2"><i class="fab fa-google mr-1"></i> Google Ads Suggestion: Use <b>In-feed ads</b> or <b>Display ads (Horizontal)</b> to seamlessly fit between the playlist tracks.</p>
                            <?php else: ?>
                                <p class="text-xs text-emerald-400 mt-2"><i class="fab fa-google mr-1"></i> Google Ads Suggestion: Use responsive <b>Display ads</b>.</p>
                            <?php endif; ?>
                        </div>

                        <!-- Price Per Hour -->
                        <div>
                            <label class="block text-sm font-medium text-gray-400 mb-2">Price Per Hour (₹)</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-rupee-sign text-gray-500"></i>
                                </div>
                                <input type="number" name="pricePerDay" value="<?php echo htmlspecialchars($current_ad['price_per_hour'] ?? 0); ?>" placeholder="100" class="w-full pl-10 pr-4 py-3 bg-white/5 border border-white/10 rounded-xl text-white placeholder-gray-500 focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-colors">
                            </div>
                        </div>

                        <!-- Destination URL -->
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-2">Destination URL</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <i class="fas fa-link text-gray-500"></i>
                                </div>
                                <input type="url" name="linkUrl" value="<?php echo htmlspecialchars($current_ad['link_url']); ?>" placeholder="https://example.com/landing-page" class="w-full pl-10 pr-4 py-3 bg-white/5 border border-white/10 rounded-xl text-white placeholder-gray-500 focus:outline-none focus:border-purple-500 focus:ring-1 focus:ring-purple-500 transition-colors">
                            </div>
                        </div>

                        <div class="pt-4">
                            <button type="submit" name="update_ad" class="w-full btn-gradient text-white font-bold py-3 px-6 rounded-xl shadow-lg">
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Preview Section -->
                <div class="lg:col-span-1">
                    <div class="glass-panel p-6 rounded-2xl sticky top-8">
                        <h3 class="text-lg font-medium text-white mb-4">Live Preview</h3>
                        <?php if($current_ad['image_path']): ?>
                            <div class="bg-black/50 rounded-lg p-2 border border-white/5 relative group overflow-hidden">
                                <span class="absolute top-2 right-2 bg-black/60 text-white text-[10px] px-2 py-1 rounded backdrop-blur-sm z-10">SPONSORED</span>
                                <a href="<?php echo htmlspecialchars($current_ad['link_url']); ?>" target="_blank" class="block">
                                    <img src="<?php echo htmlspecialchars($current_ad['image_path']); ?>" alt="Ad Preview" class="w-full h-auto rounded object-cover">
                                </a>
                                <!-- Simulated Player Context for Bottom Banner -->
                                <?php if($current_ad['placeholder_id'] == 'bottom_player_banner'): ?>
                                    <div class="mt-2 bg-[#181818] h-12 rounded flex items-center px-3 border border-white/10">
                                        <div class="w-8 h-8 bg-white/10 rounded-full mr-3"></div>
                                        <div class="flex-1">
                                            <div class="h-2 w-24 bg-white/20 rounded mb-1"></div>
                                            <div class="h-1.5 w-16 bg-white/10 rounded"></div>
                                        </div>
                                        <div class="flex space-x-2 text-white/40">
                                            <i class="fas fa-backward"></i>
                                            <i class="fas fa-play text-white"></i>
                                            <i class="fas fa-forward"></i>
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-2 text-center">Preview simulates ad above music player.</p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="bg-white/5 border border-dashed border-white/20 rounded-xl h-48 flex items-center justify-center text-gray-500 flex-col">
                                <i class="far fa-image text-4xl mb-3 opacity-50"></i>
                                <p>No image uploaded yet</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="glass-panel p-8 rounded-2xl text-center">
                <h2 class="text-xl text-gray-400">Please select a placeholder from the sidebar.</h2>
            </div>
        <?php endif; ?>
    </main>

    <script>
        // Simple script to show selected filename
        const fileInput = document.getElementById('adImageInput');
        const fileNameDisplay = document.getElementById('fileNameDisplay');
        
        if(fileInput) {
            fileInput.addEventListener('change', function(e) {
                if(e.target.files.length > 0) {
                    fileNameDisplay.textContent = e.target.files[0].name;
                    fileNameDisplay.classList.add('text-purple-400');
                }
            });
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>
