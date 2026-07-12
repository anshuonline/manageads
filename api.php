<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Content-Type: application/json");

require_once __DIR__ . "/config.php";

if ($conn->connect_error) {
    echo json_encode(["isActive" => false, "imageUrl" => "", "linkUrl" => ""]);
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'prices') {
    $result = $conn->query("SELECT placeholder_id, price_per_hour FROM ads");
    $prices = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $prices[$row['placeholder_id']] = (int)$row['price_per_hour'];
        }
    }
    echo json_encode($prices);
} else {
    $placeholder = $_GET['placeholder'] ?? 'bottom_player_banner';
    $stmt = $conn->prepare("SELECT * FROM ads WHERE placeholder_id = ?");
    $stmt->bind_param("s", $placeholder);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // Construct absolute URL for the image if it's a local path
        $imageUrl = $row['image_path'];
        if ($imageUrl && !filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            // If it's a local path like uploads/img.jpg
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $baseDir = dirname($_SERVER['PHP_SELF']); // e.g. /manageads
            $imageUrl = $protocol . "://" . $host . $baseDir . "/" . ltrim($imageUrl, '/');
        }

        echo json_encode([
            "isActive" => (bool)$row['is_active'],
            "imageUrl" => $imageUrl,
            "linkUrl" => $row['link_url'],
            "customCode" => $row['custom_code'],
            "pricePerHour" => (int)$row['price_per_hour']
        ]);
    } else {
        echo json_encode(["isActive" => false, "imageUrl" => "", "linkUrl" => "", "customCode" => "", "pricePerHour" => 0]);
    }

    $stmt->close();
}

$conn->close();
?>
