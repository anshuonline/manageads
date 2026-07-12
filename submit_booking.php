<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . "/config.php";

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Database connection failed"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON payload"]);
    exit;
}

$name = $conn->real_escape_string($data['name'] ?? '');
$email = $conn->real_escape_string($data['email'] ?? '');
$brand_name = $conn->real_escape_string($data['brand_name'] ?? '');

$placements = $data['placement_id'] ?? [];
if (is_array($placements)) {
    $placement_id = $conn->real_escape_string(implode(',', $placements));
} else {
    $placement_id = $conn->real_escape_string($placements);
}

$duration_hours = (int)($data['duration_hours'] ?? 0);
$start_date_time = $conn->real_escape_string($data['start_date_time'] ?? '');
$end_date_time = $conn->real_escape_string($data['end_date_time'] ?? '');
$target_audience = $conn->real_escape_string($data['target_audience'] ?? '');
$ad_link = $conn->real_escape_string($data['ad_link'] ?? '');
$ad_description = $conn->real_escape_string($data['ad_description'] ?? '');
$total_price = (int)($data['total_price'] ?? 0);
$agreed_tos = (bool)($data['agreed_tos'] ?? false);

if (!$name || !$email || !$brand_name || empty($placements) || !$duration_hours || !$start_date_time || !$end_date_time || !$total_price || !$ad_link || !$ad_description) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "All fields (including Ad Link and Description) are required"]);
    exit;
}

if (!$agreed_tos) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "You must agree to the strict terms and conditions."]);
    exit;
}

$sql = "INSERT INTO campaign_bookings (name, email, brand_name, placement_id, duration_hours, start_date_time, end_date_time, target_audience, ad_link, ad_description, total_price) 
        VALUES ('$name', '$email', '$brand_name', '$placement_id', $duration_hours, '$start_date_time', '$end_date_time', '$target_audience', '$ad_link', '$ad_description', $total_price)";

if ($conn->query($sql) === TRUE) {
    echo json_encode(["success" => true, "message" => "Booking request submitted successfully"]);
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Error submitting booking: " . $conn->error]);
}

$conn->close();
?>
