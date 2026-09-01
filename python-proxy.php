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

// Build the command: -g gets the direct URL, -f bestaudio gets the best audio stream
// --no-warnings --quiet suppresses extraneous output
$command = "python3 " . escapeshellarg($ytdlp_path) . " -f bestaudio -g --no-warnings --quiet " . escapeshellarg($url) . " 2>&1";

// Execute command
$output = shell_exec($command);

if (!$output) {
    // Try without explicit python3 command
    $command2 = escapeshellarg($ytdlp_path) . " -f bestaudio -g --no-warnings --quiet " . escapeshellarg($url) . " 2>&1";
    $output = shell_exec($command2);
}

$output = trim($output);

if (empty($output)) {
    die(json_encode(['error' => 'Failed to extract URL. Command returned empty.']));
}

// If output is a valid URL, return it
if (filter_var($output, FILTER_VALIDATE_URL)) {
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
