<?php
session_start();
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: dashboard.php");
    exit;
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $password = $_POST['password'] ?? '';
    
    require_once __DIR__ . "/config.php";
    if (!$conn->connect_error) {
        $result = $conn->query("SELECT password_hash FROM admin_settings WHERE id = 1");
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if (md5($password) === $row['password_hash']) {
                $_SESSION['admin_logged_in'] = true;
                header("Location: dashboard.php");
                exit;
            } else {
                $error = "Incorrect password.";
            }
        }
    } else {
        $error = "Database connection failed.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ManageAds — Admin Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
    <style type="text/tailwindcss">
        @layer components {
            .login-input {
                @apply w-full pl-11 pr-4 py-3.5 bg-white/[0.04] border border-white/10 rounded-xl text-sm text-white placeholder-gray-500 focus:outline-none focus:border-violet-500/60 focus:ring-2 focus:ring-violet-500/20 transition;
            }
        }
    </style>
</head>
<body class="min-h-screen bg-[#08080c] text-white font-sans flex items-center justify-center p-4 relative overflow-hidden">

    <!-- Ambient Background Glows -->
    <div class="absolute -top-32 -left-32 w-[28rem] h-[28rem] bg-violet-600/20 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute -bottom-32 -right-32 w-[28rem] h-[28rem] bg-fuchsia-600/10 rounded-full blur-[120px] pointer-events-none"></div>
    <div class="absolute inset-0 opacity-[0.15] pointer-events-none" style="background-image: radial-gradient(circle at 1px 1px, rgba(255,255,255,0.15) 1px, transparent 0); background-size: 32px 32px;"></div>

    <!-- Login Card -->
    <div class="relative w-full max-w-md">
        <div class="bg-[#101016]/90 backdrop-blur-xl border border-white/[0.08] rounded-3xl shadow-2xl shadow-black/50 p-8 sm:p-10">

            <!-- Brand -->
            <div class="flex flex-col items-center text-center mb-8">
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-violet-600 to-fuchsia-600 flex items-center justify-center shadow-lg shadow-violet-600/30 mb-5">
                    <i class="fas fa-bullhorn text-2xl text-white"></i>
                </div>
                <h1 class="text-2xl font-bold tracking-tight">Manage<span class="bg-gradient-to-r from-violet-400 to-fuchsia-400 bg-clip-text text-transparent">Ads</span></h1>
                <p class="text-sm text-gray-500 mt-2">Admin control panel — sign in to continue</p>
            </div>

            <!-- Error -->
            <?php if($error): ?>
                <div class="mb-6 flex items-center gap-3 px-4 py-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm">
                    <i class="fas fa-circle-exclamation"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <!-- Form -->
            <form method="POST" action="" class="space-y-5">
                <div>
                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Admin Password</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-500 text-sm"></i>
                        </div>
                        <input type="password" name="password" id="passwordInput" placeholder="Enter admin password" required autofocus class="login-input pr-12">
                        <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-500 hover:text-gray-300 transition-colors" aria-label="Toggle password visibility">
                            <i class="fas fa-eye text-sm" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="w-full py-3.5 rounded-xl bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-500 hover:to-fuchsia-500 text-white font-semibold text-sm shadow-lg shadow-violet-600/25 transition-all duration-200 hover:-translate-y-0.5 flex items-center justify-center gap-2">
                    <i class="fas fa-arrow-right-to-bracket"></i> Sign In
                </button>
            </form>
        </div>

        <!-- Footer -->
        <p class="text-center text-xs text-gray-600 mt-6 flex items-center justify-center gap-2">
            <i class="fas fa-shield-halved"></i> Secure admin area · ManageAds Panel
        </p>
    </div>

    <script>
        const toggleBtn = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('passwordInput');
        const toggleIcon = document.getElementById('toggleIcon');

        toggleBtn.addEventListener('click', function() {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';
            toggleIcon.className = isHidden ? 'fas fa-eye-slash text-sm' : 'fas fa-eye text-sm';
        });
    </script>
</body>
</html>
