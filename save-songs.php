<?php
// save-songs.php
// This script accepts a JSON payload and saves it to curated-songs.json
// Setup CORS headers so the Angular app can communicate with it
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$password = "ganatube-admin-2026"; // Feel free to change this password
$file_path = "curated-songs.json";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get JSON input
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || !isset($data['password']) || $data['password'] !== $password) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Unauthorized: Incorrect password"]);
        exit();
    }

    if (!isset($data['songsData'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid payload: songsData missing"]);
        exit();
    }

    // Save the songsData array to curated-songs.json
    $success = file_put_contents($file_path, json_encode($data['songsData'], JSON_PRETTY_PRINT));

    if ($success !== false) {
        http_response_code(200);
        echo json_encode(["status" => "success", "message" => "Songs successfully published!"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Failed to write file. Check directory permissions."]);
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Only POST allowed"]);
}
?>
