<?php
$videoId = "9cHq63r1vHQ";

$instances = [
    'https://api.cobalt.tools/'
];

foreach ($instances as $instance) {
    echo "Testing Cobalt v10 $instance...\n";
    $url = $instance;
    
    $payload = json_encode([
        'url' => 'https://www.youtube.com/watch?v=' . $videoId,
        'audioFormat' => 'mp3',
        'isAudioOnly' => true,
        'youtubeVideoCodec' => 'vp9'
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    ]);
    
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP Code: $httpcode\n";
    if ($httpcode == 200) {
        $data = json_decode($response, true);
        if (isset($data['url'])) {
            echo "SUCCESS: Found audio URL: " . substr($data['url'], 0, 50) . "...\n";
        } else {
            echo "FAILED: No url in JSON.\n";
            print_r($data);
        }
    } else {
        echo "FAILED: " . substr($response, 0, 100) . "...\n";
    }
    echo "--------------------------\n";
}
?>
