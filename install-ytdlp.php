<?php
/**
 * GanaTube - Hostinger yt-dlp Setup Script
 * Run this script ONCE in your browser to download and setup the yt-dlp binary.
 */

header('Content-Type: text/plain');

$url = "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux";
$file = __DIR__ . '/yt-dlp';

echo "Starting download from: $url\n";

// Use file_get_contents to download
$binary = @file_get_contents($url);

if ($binary === false) {
    // Fallback to curl if file_get_contents is disabled
    $ch = curl_init($url);
    $fp = fopen($file, 'wb');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);
} else {
    file_put_contents($file, $binary);
}

if (file_exists($file) && filesize($file) > 1000000) {
    echo "yt-dlp downloaded successfully. Size: " . round(filesize($file) / 1024 / 1024, 2) . " MB\n";
    
    // Give execute permissions
    $chmodResult = chmod($file, 0755);
    if ($chmodResult) {
        echo "Execute permissions (755) applied successfully!\n";
    } else {
        echo "Failed to apply permissions via PHP. Attempting via shell...\n";
        shell_exec('chmod +x ' . escapeshellarg($file));
    }
    
    // Create a local tmp directory to bypass Hostinger's /tmp noexec restriction
    $tmpDir = __DIR__ . '/tmp';
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0755, true);
    }
    
    // Test the binary
    echo "\nTesting execution...\n";
    $output = shell_exec("export TMPDIR=" . escapeshellarg($tmpDir) . " && " . escapeshellarg($file) . " --version 2>&1");
    
    echo "Version Output: \n$output\n";
    echo "\nSetup Complete! You can now use python-proxy.php!";
    
} else {
    echo "Failed to download yt-dlp. File size is too small or does not exist.";
}
?>
