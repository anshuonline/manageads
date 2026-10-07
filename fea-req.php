<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: index.php?redirect=fea-req");
    exit;
}

require_once __DIR__ . "/config.php";

date_default_timezone_set('Asia/Kolkata');
$conn->query("SET time_zone = '+05:30'");

// Auto-create table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS feature_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    category VARCHAR(100) DEFAULT 'General',
    user_name VARCHAR(255) DEFAULT 'Guest',
    user_email VARCHAR(255) DEFAULT '',
    status ENUM('pending', 'planned', 'in_progress', 'completed', 'declined') DEFAULT 'pending',
    admin_notes TEXT,
    ip_address VARCHAR(50) DEFAULT '',
    location VARCHAR(255) DEFAULT 'Unknown',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$message = "";
$message_type = "success";

// Handle Actions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_status') {
        $id = intval($_POST['request_id'] ?? 0);
        $new_status = $_POST['status'] ?? 'pending';
        $allowed = ['pending', 'planned', 'in_progress', 'completed', 'declined'];
        if (in_array($new_status, $allowed)) {
            $notes = trim($_POST['admin_notes'] ?? '');
            $stmt = $conn->prepare("UPDATE feature_requests SET status = ?, admin_notes = ? WHERE id = ?");
            $stmt->bind_param("ssi", $new_status, $notes, $id);
            if ($stmt->execute()) {
                $message = "Status updated to " . ucfirst(str_replace('_', ' ', $new_status)) . "!";
                $message_type = "success";
            }
        }
    }
    
    if ($action === 'delete_request') {
        $id = intval($_POST['request_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM feature_requests WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message = "Feature request #$id deleted successfully.";
            $message_type = "success";
        } else {
            $message = "Error deleting feature request.";
            $message_type = "error";
        }
    }
}

// Fetch stats
$total_count = 0;
$pending_count = 0;
$planned_count = 0;
$in_progress_count = 0;
$completed_count = 0;
$declined_count = 0;

$stats_res = $conn->query("SELECT status, COUNT(*) as cnt FROM feature_requests GROUP BY status");
if ($stats_res) {
    while ($r = $stats_res->fetch_assoc()) {
        $st = $r['status'];
        $cnt = intval($r['cnt']);
        $total_count += $cnt;
        if ($st === 'pending') $pending_count = $cnt;
        if ($st === 'planned') $planned_count = $cnt;
        if ($st === 'in_progress') $in_progress_count = $cnt;
        if ($st === 'completed') $completed_count = $cnt;
        if ($st === 'declined') $declined_count = $cnt;
    }
}

// Filters
$filter_status = $_GET['status'] ?? 'all';
$filter_category = $_GET['category'] ?? 'all';
$search_query = trim($_GET['q'] ?? '');

$where_clauses = [];
if ($filter_status !== 'all' && in_array($filter_status, ['pending', 'planned', 'in_progress', 'completed', 'declined'])) {
    $where_clauses[] = "status = '" . $conn->real_escape_string($filter_status) . "'";
}
if ($filter_category !== 'all' && !empty($filter_category)) {
    $where_clauses[] = "category = '" . $conn->real_escape_string($filter_category) . "'";
}
if (!empty($search_query)) {
    $s = $conn->real_escape_string($search_query);
    $where_clauses[] = "(title LIKE '%$s%' OR description LIKE '%$s%' OR user_name LIKE '%$s%' OR user_email LIKE '%$s%')";
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";
$query = "SELECT * FROM feature_requests $where_sql ORDER BY created_at DESC";
$result = $conn->query($query);
$requests = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
}

// Unique categories for filter dropdown
$categories_res = $conn->query("SELECT DISTINCT category FROM feature_requests WHERE category != '' ORDER BY category ASC");
$all_categories = [];
if ($categories_res) {
    while ($cr = $categories_res->fetch_assoc()) {
        $all_categories[] = $cr['category'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feature Requests — ManageAds</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: {
                        amoled: '#08080c',
                        cardDark: '#101016'
                    }
                }
            }
        }
    </script>
    <style>
        body {
            background-color: #08080c;
            color: #ffffff;
            font-family: 'Inter', sans-serif;
        }
        .gradient-text {
            background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .glass-panel {
            background: #101016;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .glass-panel:hover {
            border-color: rgba(255, 255, 255, 0.15);
        }
        .glass-input {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff;
            outline: none;
            transition: all 0.2s;
        }
        .glass-input:focus {
            border-color: #a855f7;
            box-shadow: 0 0 0 2px rgba(168, 85, 247, 0.2);
        }
    </style>
</head>
<body class="min-h-screen bg-[#08080c] text-white">

    <!-- Top Navigation Bar -->
    <header class="sticky top-0 z-50 bg-[#08080c]/90 backdrop-blur-xl border-b border-white/[0.08] px-4 sm:px-8 py-3.5">
        <div class="max-w-7xl mx-auto flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="dashboard.php" class="flex items-center gap-2.5 group">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-purple-600 to-pink-600 flex items-center justify-center shadow-lg shadow-purple-600/30 group-hover:scale-105 transition-transform">
                        <i class="fas fa-lightbulb text-white text-sm"></i>
                    </div>
                    <div>
                        <span class="text-base font-bold tracking-tight">Manage<span class="gradient-text">Ads</span></span>
                        <span class="hidden sm:inline-block text-[11px] text-gray-400 ml-2 font-medium bg-white/[0.06] px-2 py-0.5 rounded-full border border-white/[0.06]">Feature Requests</span>
                    </div>
                </a>
            </div>

            <div class="flex items-center gap-3">
                <a href="dashboard.php" class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-300 hover:text-white hover:bg-white/[0.06] border border-white/[0.08] transition">
                    <i class="fas fa-arrow-left text-[11px]"></i>
                    <span>Main Dashboard</span>
                </a>
                <a href="dashboard.php?page=feedback" class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-300 hover:text-white hover:bg-white/[0.06] border border-white/[0.08] transition">
                    <i class="fas fa-comment-dots text-[11px]"></i>
                    <span class="hidden sm:inline">User Feedback</span>
                </a>
                <a href="index.php?logout=1" class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-red-400 hover:bg-red-500/10 border border-red-500/20 transition">
                    <i class="fas fa-sign-out-alt text-[11px]"></i>
                    <span class="hidden sm:inline">Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-8 py-8 space-y-6">

        <!-- Flash Message -->
        <?php if (!empty($message)): ?>
            <div class="p-4 rounded-xl flex items-center gap-3 text-sm font-medium animate-fadeIn <?php echo $message_type === 'success' ? 'bg-emerald-500/10 border border-emerald-500/30 text-emerald-400' : 'bg-red-500/10 border border-red-500/30 text-red-400'; ?>">
                <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?> text-base"></i>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <!-- Header Title Banner -->
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-2">
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight">Feature <span class="gradient-text">Requests</span></h1>
                <p class="text-xs sm:text-sm text-gray-400 mt-1">Review, prioritize, and manage community feature suggestions submitted from GanaTube Socials.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="fea-req.php" class="px-3.5 py-2 rounded-xl text-xs font-semibold bg-white/[0.05] hover:bg-white/[0.1] text-gray-200 border border-white/[0.08] transition flex items-center gap-2">
                    <i class="fas fa-sync-alt text-[10px]"></i>
                    <span>Refresh</span>
                </a>
                <a href="https://ganatube.in/socials" target="_blank" class="px-3.5 py-2 rounded-xl text-xs font-semibold bg-gradient-to-r from-purple-600 to-pink-600 text-white shadow-lg shadow-purple-600/25 hover:opacity-95 transition flex items-center gap-2">
                    <i class="fas fa-external-link-alt text-[10px]"></i>
                    <span>View Public Page</span>
                </a>
            </div>
        </div>

        <!-- Metrics Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3.5">
            <!-- Total -->
            <div class="glass-panel p-4 rounded-2xl">
                <div class="flex items-center justify-between text-gray-400 text-xs font-medium mb-1">
                    <span>Total Requests</span>
                    <i class="fas fa-list text-gray-500"></i>
                </div>
                <div class="text-2xl font-extrabold text-white font-mono"><?php echo $total_count; ?></div>
            </div>

            <!-- Pending -->
            <div class="glass-panel p-4 rounded-2xl border-amber-500/20 bg-amber-500/[0.03]">
                <div class="flex items-center justify-between text-amber-400 text-xs font-medium mb-1">
                    <span>Pending</span>
                    <i class="fas fa-clock text-amber-400"></i>
                </div>
                <div class="text-2xl font-extrabold text-amber-300 font-mono"><?php echo $pending_count; ?></div>
            </div>

            <!-- Planned -->
            <div class="glass-panel p-4 rounded-2xl border-purple-500/20 bg-purple-500/[0.03]">
                <div class="flex items-center justify-between text-purple-400 text-xs font-medium mb-1">
                    <span>Planned</span>
                    <i class="fas fa-map-pin text-purple-400"></i>
                </div>
                <div class="text-2xl font-extrabold text-purple-300 font-mono"><?php echo $planned_count; ?></div>
            </div>

            <!-- In Progress -->
            <div class="glass-panel p-4 rounded-2xl border-blue-500/20 bg-blue-500/[0.03]">
                <div class="flex items-center justify-between text-blue-400 text-xs font-medium mb-1">
                    <span>In Progress</span>
                    <i class="fas fa-code text-blue-400"></i>
                </div>
                <div class="text-2xl font-extrabold text-blue-300 font-mono"><?php echo $in_progress_count; ?></div>
            </div>

            <!-- Completed -->
            <div class="glass-panel p-4 rounded-2xl border-emerald-500/20 bg-emerald-500/[0.03]">
                <div class="flex items-center justify-between text-emerald-400 text-xs font-medium mb-1">
                    <span>Completed</span>
                    <i class="fas fa-check-circle text-emerald-400"></i>
                </div>
                <div class="text-2xl font-extrabold text-emerald-300 font-mono"><?php echo $completed_count; ?></div>
            </div>
        </div>

        <!-- Filters & Search Toolbar -->
        <div class="glass-panel p-4 rounded-2xl space-y-4">
            <form method="GET" action="fea-req.php" class="grid grid-cols-1 md:grid-cols-12 gap-3">
                <!-- Search input -->
                <div class="md:col-span-5 relative">
                    <i class="fas fa-search absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
                    <input type="text" name="q" value="<?php echo htmlspecialchars($search_query); ?>" 
                           placeholder="Search title, description, user, email..." 
                           class="glass-input w-full pl-9 pr-3 py-2 rounded-xl text-xs sm:text-sm">
                </div>

                <!-- Category filter -->
                <div class="md:col-span-3">
                    <select name="category" class="glass-input w-full px-3 py-2 rounded-xl text-xs sm:text-sm bg-[#101016]">
                        <option value="all">All Categories</option>
                        <?php foreach($all_categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filter_category === $cat ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Status filter -->
                <div class="md:col-span-3">
                    <select name="status" class="glass-input w-full px-3 py-2 rounded-xl text-xs sm:text-sm bg-[#101016]">
                        <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses (<?php echo $total_count; ?>)</option>
                        <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending (<?php echo $pending_count; ?>)</option>
                        <option value="planned" <?php echo $filter_status === 'planned' ? 'selected' : ''; ?>>Planned (<?php echo $planned_count; ?>)</option>
                        <option value="in_progress" <?php echo $filter_status === 'in_progress' ? 'selected' : ''; ?>>In Progress (<?php echo $in_progress_count; ?>)</option>
                        <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed (<?php echo $completed_count; ?>)</option>
                        <option value="declined" <?php echo $filter_status === 'declined' ? 'selected' : ''; ?>>Declined (<?php echo $declined_count; ?>)</option>
                    </select>
                </div>

                <!-- Submit / Reset button -->
                <div class="md:col-span-1 flex items-center gap-1.5">
                    <button type="submit" class="w-full py-2 bg-purple-600 hover:bg-purple-500 text-white rounded-xl text-xs font-semibold transition flex items-center justify-center">
                        <i class="fas fa-filter"></i>
                    </button>
                    <?php if(!empty($search_query) || $filter_status !== 'all' || $filter_category !== 'all'): ?>
                        <a href="fea-req.php" class="px-2.5 py-2 bg-white/[0.08] hover:bg-white/[0.15] text-gray-300 rounded-xl text-xs font-semibold transition" title="Clear filters">
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Quick Status Filter Pills -->
            <div class="flex flex-wrap items-center gap-1.5 pt-2 border-t border-white/[0.06]">
                <span class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold mr-1">Filter:</span>
                <a href="fea-req.php?status=all" class="px-2.5 py-1 rounded-lg text-xs font-medium transition <?php echo $filter_status === 'all' ? 'bg-purple-600 text-white font-semibold' : 'bg-white/[0.04] text-gray-400 hover:text-white'; ?>">
                    All (<?php echo $total_count; ?>)
                </a>
                <a href="fea-req.php?status=pending" class="px-2.5 py-1 rounded-lg text-xs font-medium transition <?php echo $filter_status === 'pending' ? 'bg-amber-500/20 text-amber-300 border border-amber-500/40 font-semibold' : 'bg-white/[0.04] text-gray-400 hover:text-white'; ?>">
                    Pending (<?php echo $pending_count; ?>)
                </a>
                <a href="fea-req.php?status=planned" class="px-2.5 py-1 rounded-lg text-xs font-medium transition <?php echo $filter_status === 'planned' ? 'bg-purple-500/20 text-purple-300 border border-purple-500/40 font-semibold' : 'bg-white/[0.04] text-gray-400 hover:text-white'; ?>">
                    Planned (<?php echo $planned_count; ?>)
                </a>
                <a href="fea-req.php?status=in_progress" class="px-2.5 py-1 rounded-lg text-xs font-medium transition <?php echo $filter_status === 'in_progress' ? 'bg-blue-500/20 text-blue-300 border border-blue-500/40 font-semibold' : 'bg-white/[0.04] text-gray-400 hover:text-white'; ?>">
                    In Progress (<?php echo $in_progress_count; ?>)
                </a>
                <a href="fea-req.php?status=completed" class="px-2.5 py-1 rounded-lg text-xs font-medium transition <?php echo $filter_status === 'completed' ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40 font-semibold' : 'bg-white/[0.04] text-gray-400 hover:text-white'; ?>">
                    Completed (<?php echo $completed_count; ?>)
                </a>
                <a href="fea-req.php?status=declined" class="px-2.5 py-1 rounded-lg text-xs font-medium transition <?php echo $filter_status === 'declined' ? 'bg-red-500/20 text-red-300 border border-red-500/40 font-semibold' : 'bg-white/[0.04] text-gray-400 hover:text-white'; ?>">
                    Declined (<?php echo $declined_count; ?>)
                </a>
            </div>
        </div>

        <!-- Requests List -->
        <?php if(count($requests) === 0): ?>
            <div class="glass-panel p-12 rounded-2xl text-center space-y-3">
                <div class="w-14 h-14 rounded-2xl bg-white/[0.04] border border-white/[0.08] flex items-center justify-center mx-auto text-gray-500 text-xl">
                    <i class="fas fa-inbox"></i>
                </div>
                <h3 class="text-base font-semibold text-white">No feature requests found</h3>
                <p class="text-xs text-gray-400 max-w-md mx-auto">
                    <?php if(!empty($search_query) || $filter_status !== 'all'): ?>
                        No requests matched your filter criteria. Try resetting filters.
                    <?php else: ?>
                        When users submit feature requests from <a href="https://ganatube.in/socials" class="text-purple-400 hover:underline">ganatube.in/socials</a>, they will appear here.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach($requests as $req): 
                    $st = $req['status'];
                    $status_badge_class = 'bg-gray-500/10 text-gray-400 border-gray-500/20';
                    if ($st === 'pending') $status_badge_class = 'bg-amber-500/10 text-amber-400 border-amber-500/30';
                    if ($st === 'planned') $status_badge_class = 'bg-purple-500/10 text-purple-400 border-purple-500/30';
                    if ($st === 'in_progress') $status_badge_class = 'bg-blue-500/10 text-blue-400 border-blue-500/30';
                    if ($st === 'completed') $status_badge_class = 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30';
                    if ($st === 'declined') $status_badge_class = 'bg-red-500/10 text-red-400 border-red-500/30';
                ?>
                    <div class="glass-panel p-5 rounded-2xl space-y-4 transition hover:border-white/20">
                        <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3">
                            <div class="space-y-1.5 flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border <?php echo $status_badge_class; ?>">
                                        <?php echo str_replace('_', ' ', $st); ?>
                                    </span>
                                    <span class="px-2 py-0.5 rounded-md text-[11px] font-medium bg-white/[0.05] text-purple-300 border border-purple-500/20">
                                        <i class="fas fa-tag text-[9px] mr-1 opacity-70"></i><?php echo htmlspecialchars($req['category']); ?>
                                    </span>
                                    <span class="text-xs text-gray-500 font-mono">#<?php echo $req['id']; ?></span>
                                </div>
                                <h3 class="text-base sm:text-lg font-bold text-white break-words">
                                    <?php echo htmlspecialchars($req['title']); ?>
                                </h3>
                            </div>

                            <div class="text-xs text-gray-400 flex sm:flex-col sm:items-end gap-2 sm:gap-1 flex-shrink-0">
                                <span class="flex items-center gap-1.5 text-gray-400">
                                    <i class="far fa-clock text-[10px]"></i>
                                    <span><?php echo date('d M Y, h:i A', strtotime($req['created_at'])); ?></span>
                                </span>
                                <?php if (!empty($req['location']) && $req['location'] !== 'Unknown'): ?>
                                    <span class="flex items-center gap-1 text-[11px] text-gray-500">
                                        <i class="fas fa-map-marker-alt text-[9px]"></i>
                                        <span><?php echo htmlspecialchars($req['location']); ?></span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Description -->
                        <div class="bg-black/40 border border-white/[0.04] p-3.5 rounded-xl text-xs sm:text-sm text-gray-300 whitespace-pre-wrap leading-relaxed">
                            <?php echo htmlspecialchars($req['description']); ?>
                        </div>

                        <!-- Admin Notes (if any) -->
                        <?php if (!empty($req['admin_notes'])): ?>
                            <div class="bg-purple-950/20 border border-purple-500/20 p-3 rounded-xl text-xs text-purple-200">
                                <span class="font-bold text-purple-300"><i class="fas fa-sticky-note mr-1.5"></i>Admin Note:</span>
                                <span><?php echo htmlspecialchars($req['admin_notes']); ?></span>
                            </div>
                        <?php endif; ?>

                        <!-- Footer: User Info & Actions -->
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-3 border-t border-white/[0.06]">
                            <!-- Submitter info -->
                            <div class="flex items-center gap-2.5 text-xs text-gray-400">
                                <div class="w-7 h-7 rounded-full bg-gradient-to-br from-purple-500/20 to-pink-500/20 border border-white/10 flex items-center justify-center text-purple-300 text-xs font-bold">
                                    <?php echo strtoupper(substr($req['user_name'] ?? 'G', 0, 1)); ?>
                                </div>
                                <div>
                                    <span class="text-white font-medium"><?php echo htmlspecialchars($req['user_name']); ?></span>
                                    <?php if (!empty($req['user_email'])): ?>
                                        <span class="text-gray-500 mx-1">·</span>
                                        <a href="mailto:<?php echo htmlspecialchars($req['user_email']); ?>" class="text-purple-400 hover:underline">
                                            <?php echo htmlspecialchars($req['user_email']); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Status Change Form & Delete Form -->
                            <div class="flex items-center gap-2 flex-wrap">
                                <!-- Status Update Form -->
                                <form method="POST" action="fea-req.php" class="flex items-center gap-2">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                    <select name="status" onchange="this.form.submit()" class="glass-input px-2.5 py-1.5 rounded-lg text-xs bg-[#101016] font-medium cursor-pointer">
                                        <option value="pending" <?php echo $st === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="planned" <?php echo $st === 'planned' ? 'selected' : ''; ?>>Planned</option>
                                        <option value="in_progress" <?php echo $st === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="completed" <?php echo $st === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="declined" <?php echo $st === 'declined' ? 'selected' : ''; ?>>Declined</option>
                                    </select>
                                </form>

                                <!-- Delete Form (Red Button per AGENTS.md rules!) -->
                                <form method="POST" action="fea-req.php" onsubmit="return confirm('Delete feature request #<?php echo $req['id']; ?> permanently?');">
                                    <input type="hidden" name="action" value="delete_request">
                                    <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                    <button type="submit" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold bg-red-500/10 text-red-400 border border-red-500/20 hover:bg-red-500/20 hover:text-red-300 transition" title="Delete request">
                                        <i class="fas fa-trash-alt text-[10px]"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>

    <!-- Footer -->
    <footer class="mt-16 border-t border-white/[0.08] py-6 text-center text-xs text-gray-500">
        ManageAds &copy; <?php echo date('Y'); ?> — GanaTube Admin Control
    </footer>

</body>
</html>
