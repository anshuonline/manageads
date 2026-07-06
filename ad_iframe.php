<?php
header("Access-Control-Allow-Origin: *");
header("X-Frame-Options: ALLOWALL");

require_once __DIR__ . "/config.php";
if ($conn->connect_error) {
    die("Error");
}

$placeholder = $_GET['placeholder'] ?? 'bottom_player_banner';
$stmt = $conn->prepare("SELECT * FROM ads WHERE placeholder_id = ?");
$stmt->bind_param("s", $placeholder);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    
    if (!$row['is_active']) {
        exit;
    }
    
    echo "<!DOCTYPE html>";
    echo "<html>";
    echo "<head><style>body { margin: 0; padding: 0; overflow: hidden; background: transparent; }</style></head>";
    echo "<body>";
    
    if (!empty($row['custom_code'])) {
        echo $row['custom_code'];
    } else if (!empty($row['image_path'])) {
        $link = htmlspecialchars($row['link_url'] ?? '#');
        $img = htmlspecialchars($row['image_path']);
        // Need absolute url for image if local
        if (!filter_var($img, FILTER_VALIDATE_URL)) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $baseDir = dirname($_SERVER['PHP_SELF']);
            $img = $protocol . "://" . $host . $baseDir . "/" . ltrim($img, '/');
        }
        echo "<a href='$link' target='_blank'><img src='$img' style='width:100%;height:100%;object-fit:cover;border-radius:8px;display:block;' alt='Ad'></a>";
    }
    
    echo "</body>";
    echo "</html>";
}

$stmt->close();
$conn->close();
?>
