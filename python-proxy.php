<?php
/**
 * GanaTube - Hostinger PHP Proxy for yt-dlp
 * This script runs the yt-dlp binary to extract the stream URL and returns it to the Angular app.
 */

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

if (!isset($_GET['videoId'])) {
    die(json_encode(['error' => 'No videoId provided']));
}

$videoId = $_GET['videoId'];
$url = "https://www.youtube.com/watch?v=" . $videoId;

$ytdlp_path = __DIR__ . '/yt-dlp';

if (!file_exists($ytdlp_path)) {
    die(json_encode(['error' => 'yt-dlp not found on server. Please run install-ytdlp.php first.']));
}

$tmpDir = __DIR__ . '/tmp';

// Build the command: -g gets the direct URL, -f bestaudio gets the best audio stream
// Execute command directly as an executable, using local TMPDIR to bypass Hostinger /tmp noexec
$command = "export TMPDIR=" . escapeshellarg($tmpDir) . " && " . escapeshellarg($ytdlp_path) . " -f \"bestaudio[ext=m4a]/bestaudio\" -g --no-warnings --quiet " . escapeshellarg($url) . " 2>&1";
$output = shell_exec($command);

$output = trim($output);

if (empty($output)) {
    die(json_encode(['error' => 'Failed to extract URL. Command returned empty.']));
}

// If output is a valid URL, return or stream it
if (filter_var($output, FILTER_VALIDATE_URL)) {
    
    if (isset($_GET['download']) && $_GET['download'] == '1') {
        // Stream the file through PHP to bypass CORS
        header('Content-Type: audio/mp4');
        header('Access-Control-Allow-Origin: *');
        header('Content-Disposition: attachment; filename="track.m4a"');
        
        // Turn off output buffering for streaming
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Stream using cURL to handle redirects and set User-Agent
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $output);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) {
            echo $data;
            flush();
            return strlen($data);
        });
        curl_exec($ch);
        curl_close($ch);
        exit;
    }

    echo json_encode([
        'videoId' => $videoId,
        'streamUrl' => $output,
        'ext' => 'm4a',
        'message' => 'Extracted via Hostinger yt-dlp wrapper'
    ]);
} else {
    // yt-dlp returned an error message instead of a URL
    echo json_encode([
        'error' => 'Extraction failed',
        'details' => $output
    ]);
}
?>
