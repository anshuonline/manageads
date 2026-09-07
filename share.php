<?php
// share.php
header("Access-Control-Allow-Origin: *");

$v = $_GET['v'] ?? '';
$thumb = $_GET['thumb'] ?? '';

// Handle Thumbnail Generation with Play Icon
if ($thumb) {
    $oembed_url = "https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=" . urlencode($thumb) . "&format=json";
    $context = stream_context_create(['http' => ['timeout' => 3]]);
    $res = @file_get_contents($oembed_url, false, $context);
    
    $bg_url = "https://ui-avatars.com/api/?name=Gana+Tube&background=000&color=fff&size=500";
    if ($res) {
        $data = json_decode($res, true);
        if (!empty($data) && isset($data['thumbnail_url'])) {
            $bg_url = $data['thumbnail_url'];
            // YouTube HQ thumbnail replacement for better quality if possible
            if (strpos($bg_url, 'hqdefault.jpg') !== false) {
                $bg_url = str_replace('hqdefault.jpg', 'maxresdefault.jpg', $bg_url);
            }
        }
    }
    
    // Suppress errors and try to load image
    $bg_image = @imagecreatefromjpeg($bg_url);
    if (!$bg_image) {
        $bg_image = @imagecreatefrompng($bg_url);
    }
    if (!$bg_image) {
        $bg_image = @imagecreatefromstring(@file_get_contents($bg_url));
    }
    
    if (!$bg_image) {
        header("Location: $bg_url");
        exit;
    }
    
    $width = imagesx($bg_image);
    $height = imagesy($bg_image);
    
    // Draw a dark semi-transparent overlay
    imagealphablending($bg_image, true);
    $overlay = imagecolorallocatealpha($bg_image, 0, 0, 0, 60); // 0-127 (127 is fully transparent)
    imagefilledrectangle($bg_image, 0, 0, $width, $height, $overlay);
    
    // Draw a play button (Red circle)
    $cx = $width / 2;
    $cy = $height / 2;
    $r = min($width, $height) / 6;
    
    $white = imagecolorallocate($bg_image, 255, 255, 255);
    $red = imagecolorallocate($bg_image, 220, 38, 38); // Tailwind red-600
    
    // Red circle
    imagefilledellipse($bg_image, $cx, $cy, $r*2, $r*2, $red);
    
    // White triangle
    $triangle = [
        $cx - $r/3, $cy - $r/2.2,
        $cx - $r/3, $cy + $r/2.2,
        $cx + $r/1.8, $cy
    ];
    imagefilledpolygon($bg_image, $triangle, 3, $white);
    
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400'); // Cache for 1 day
    imagejpeg($bg_image, null, 90);
    imagedestroy($bg_image);
    exit;
}

if (!$v) {
    header("Location: https://ganatube.in/");
    exit;
}

$title = "Music";
$description = "Listen on GanaTube";
$target_url = "https://ganatube.in/?play=" . urlencode($v);
$image_url = "https://manageads.ganatube.in/share.php?thumb=" . urlencode($v);

// Fetch song details for OG tags using YouTube oEmbed (Reliable & fast)
$oembed_url = "https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=" . urlencode($v) . "&format=json";
$context = stream_context_create(['http' => ['timeout' => 3]]);
$res = @file_get_contents($oembed_url, false, $context);
if ($res) {
    $data = json_decode($res, true);
    if (!empty($data) && isset($data['title'])) {
        $title = $data['title'];
        $author = str_replace(' - Topic', '', $data['author_name']);
        $description = $author;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?></title>
    
    <!-- Open Graph Meta Tags -->
    <meta property="og:title" content="<?= htmlspecialchars($title) ?>" />
    <meta property="og:description" content="<?= htmlspecialchars($description) ?>" />
    <meta property="og:image" content="<?= htmlspecialchars($image_url) ?>" />
    <meta property="og:url" content="<?= htmlspecialchars($target_url) ?>" />
    <meta property="og:type" content="music.song" />
    <meta property="og:site_name" content="GanaTube" />
    
    <!-- Twitter Card Meta Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($title) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($description) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($image_url) ?>">

    <script>
        // Redirect real users to the Angular app
        window.location.replace("<?= $target_url ?>");
    </script>
</head>
<body style="background:#000; color:#fff; font-family:sans-serif; display:flex; align-items:center; justify-content:center; height:100vh; margin:0;">
    <div style="text-align:center;">
        <img src="<?= htmlspecialchars($image_url) ?>" alt="Thumbnail" style="max-width:300px; border-radius:12px; margin-bottom:20px; box-shadow: 0 10px 25px rgba(0,0,0,0.5);">
        <h2><?= htmlspecialchars($title) ?></h2>
        <p style="color: #888;">Opening in GanaTube...</p>
        <p><a href="<?= $target_url ?>" style="color:#a855f7; text-decoration:none;">Click here if not redirected</a></p>
    </div>
</body>
</html>
