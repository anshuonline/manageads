<?php
/**
 * Simple Deploy Script
 * Place this file in your server's root folder and visit: manageads.ganatube.in/deploy.php?key=ganatube123
 * Or set it up as a Webhook in GitHub.
 */

// Basic security to prevent random people from triggering deployment
$secret_key = 'ganatube123';

// Check if a webhook payload is sent (GitHub Webhooks POST request) or manual GET request
$is_webhook = ($_SERVER['REQUEST_METHOD'] === 'POST');

if (!$is_webhook && (!isset($_GET['key']) || $_GET['key'] !== $secret_key)) {
    http_response_code(403);
    die("Unauthorized.");
}

$dir = __DIR__;
chdir($dir);

$output = [];
$output[] = "Starting Deployment at " . date('Y-m-d H:i:s');
$output[] = "Directory: " . $dir;
$output[] = "--------------------------------------";

// Forcefully fetch and overwrite local divergent changes to exactly match GitHub
$commands = [
    'git fetch origin 2>&1',
    'git reset --hard origin/main 2>&1',
    'git clean -fd 2>&1', // Optional: removes untracked files causing conflicts
    'git pull origin main 2>&1'
];

foreach ($commands as $cmd) {
    $output[] = "> $cmd";
    $result = shell_exec($cmd);
    $output[] = $result ? trim($result) : "[No Output / Success]";
    $output[] = "--------------------------------------";
}

$output[] = "Deployment Finished.";

$log = implode("\n", $output);

if ($is_webhook) {
    // For GitHub webhook, just return a success response
    echo json_encode(["status" => "success", "log" => "Deployment triggered"]);
} else {
    // For manual browser refresh
    echo "<pre>" . htmlspecialchars($log) . "</pre>";
}
?>
