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
    <title>Manage Ads - Login</title>
    <style>
        body {
            background-color: #121212;
            color: #ffffff;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-box {
            background: #1e1e1e;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.5);
            text-align: center;
            width: 100%;
            max-width: 400px;
        }
        h2 {
            margin-top: 0;
            color: #ff416c;
        }
        input[type="password"] {
            width: 100%;
            padding: 12px;
            margin: 20px 0;
            border: 1px solid #333;
            background: #222;
            color: #fff;
            border-radius: 6px;
            box-sizing: border-box;
        }
        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(90deg, #ff416c, #ff4b2b);
            border: none;
            color: white;
            font-size: 16px;
            font-weight: bold;
            border-radius: 6px;
            cursor: pointer;
        }
        button:hover {
            opacity: 0.9;
        }
        .error {
            color: #ff4757;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h2>Admin Login</h2>
        <?php if($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <input type="password" name="password" placeholder="Enter Admin Password" required autofocus>
            <button type="submit">Login</button>
        </form>
    </div>
</body>
</html>
