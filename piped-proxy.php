<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

if (!isset($_GET['videoId'])) {
    die(json_encode(['error' => 'No videoId provided']));
}

$videoId = $_GET['videoId'];
$instances = [
    'https://api.piped.projectsegfau.lt',
    'https://pipedapi.tokhmi.xyz',
    'https://pipedapi.chocoflan.net',
    'https://pipedapi.kavin.rocks',
    'https://pipedapi.smnz.de',
    'https://pipedapi.r4fo.com',
    'https://piped-api.lunar.icu'
];

foreach ($instances as $instance) {
    $url = $instance . "/streams/" . $videoId;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode == 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['audioStreams']) && count($data['audioStreams']) > 0) {
            echo $response;
            exit;
        }
    }
}

echo json_encode(['error' => 'All piped instances failed or no audio streams found']);
?>
