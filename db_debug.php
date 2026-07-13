<?php
require 'config.php';

// Very basic security
$secret = 'GanaTubeLiveDebug2026!';
$key = isset($_POST['key']) ? $_POST['key'] : '';

if ($key !== $secret) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

$query = isset($_POST['query']) ? $_POST['query'] : '';

if (empty($query)) {
    echo json_encode(["error" => "Empty query"]);
    exit;
}

$result = $conn->query($query);

if ($result === TRUE) {
    echo json_encode(["status" => "success", "affected_rows" => $conn->affected_rows]);
} elseif ($result) {
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    echo json_encode(["status" => "success", "data" => $data]);
} else {
    echo json_encode(["status" => "error", "error" => $conn->error]);
}

$conn->close();
?>
