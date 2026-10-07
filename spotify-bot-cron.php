<?php
/**
 * GanaTube 24/7 Automated Spotify Importer & Section Manager Cron Bot
 * Can be run via Hostinger Cron Job:
 * CLI:  php /home/u.../public_html/manageads/spotify-bot-cron.php
 * HTTP: https://manageads.ganatube.in/spotify-bot-cron.php?token=gt_cron_bot
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
ini_set('max_execution_time', 300); // Allow up to 5 minutes for cron
ini_set('memory_limit', '256M');

require_once 'config.php';

// Disable strict exceptions so queries fail gracefully with error messages rather than raw HTML Fatal Errors
mysqli_report(MYSQLI_REPORT_OFF);

// Helper: Auto-reconnecting database handler to prevent 'MySQL server has gone away'
function get_db_conn() {
    global $db_host, $db_user, $db_pass, $db_name, $conn;
    if ($conn instanceof mysqli) {
        try {
            if (@$conn->ping()) {
                return $conn;
            }
        } catch (Throwable $e) {}
    }
    // Re-connect fresh
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        die(json_encode(["status" => "error", "message" => "Database reconnection failed: " . $conn->connect_error]));
    }
    $conn->set_charset("utf8mb4");
    @$conn->query("SET time_zone = '+05:30'");
    @$conn->query("SET SESSION sql_mode = (SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''))");
    return $conn;
}

// Security check: CLI, internal call, or secret token over HTTP
$isCli = (php_sapi_name() === 'cli');
$token = $_GET['token'] ?? '';
$isInternal = defined('GT_INTERNAL_RUN') || (isset($action) && $action === 'trigger_bot_run');

if (!$isCli && !$isInternal && $token !== 'gt_cron_bot') {
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "Unauthorized access. Invalid or missing token."]);
    exit();
}

// Helper: Ensure bot table exists
function ensure_bot_tables() {
    $c = get_db_conn();
    $c->query("CREATE TABLE IF NOT EXISTS bot_curated_playlists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        spotify_id VARCHAR(100) NOT NULL UNIQUE,
        spotify_url VARCHAR(255) NOT NULL,
        title VARCHAR(255) NOT NULL,
        type ENUM('playlist', 'album') DEFAULT 'playlist',
        language VARCHAR(50) DEFAULT 'Hindi',
        cover_image TEXT,
        total_songs INT DEFAULT 0,
        songs LONGTEXT,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_lang_status (language, status),
        INDEX idx_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

ensure_bot_tables();

// ── 1. Load Bot Automation Config ─────────────────────────────────────────────
$conn = get_db_conn();
$res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'bot_automation_config'");
$config = [];
if ($res && $res->num_rows > 0) {
    $config = json_decode($res->fetch_assoc()['setting_value'], true) ?: [];
}

$isFullyAuto = !empty($config['isFullyAuto']);
$maxSections = (int)($config['maxSectionsPerLanguage'] ?? 15);
if ($maxSections <= 0) $maxSections = 15;

$targetSources = $config['targetSpotifySources'] ?? [
    [
        "url" => "https://open.spotify.com/playlist/37i9dQZF1DX0XUfTFmNBRM",
        "name" => "Hot Hits Hindi",
        "defaultLang" => "Hindi",
        "type" => "playlist",
        "enabled" => true
    ],
    [
        "url" => "https://open.spotify.com/playlist/37i9dQZF1DWTqYqGLu7kTX",
        "name" => "RAP 91 Punjabi",
        "defaultLang" => "Punjabi",
        "type" => "playlist",
        "enabled" => true
    ],
    [
        "url" => "https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M",
        "name" => "Today's Top Hits",
        "defaultLang" => "English",
        "type" => "playlist",
        "enabled" => true
    ],
    [
        "url" => "https://open.spotify.com/playlist/5OpU68bGSGh1Tka774Z1Or",
        "name" => "Shilpi Raj Hit Songs",
        "defaultLang" => "Bhojpuri",
        "type" => "playlist",
        "enabled" => true
    ]
];

// Helper: Scrape Spotify Embed for tracks & cover
function scrape_spotify_entity($url) {
    $type = 'playlist';
    $id = '';
    
    if (preg_match('/album\/([a-zA-Z0-9]+)/', $url, $m)) {
        $type = 'album';
        $id = $m[1];
    } elseif (preg_match('/playlist\/([a-zA-Z0-9]+)/', $url, $m)) {
        $type = 'playlist';
        $id = $m[1];
    } elseif (preg_match('/^[a-zA-Z0-9]{22}$/', trim($url))) {
        $id = trim($url);
    }
    
    if (empty($id)) return null;

    $embedUrl = "https://open.spotify.com/embed/{$type}/" . $id;

    $ch = curl_init($embedUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($html)) return null;

    if (preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $m)) {
        $decoded = json_decode($m[1], true);
        $entity = $decoded['props']['pageProps']['state']['data']['entity'] ?? null;
        if (!$entity) return null;

        $title = $entity['title'] ?? 'Spotify Collection';
        
        // High-res image
        $coverImage = '';
        if (!empty($entity['visualIdentity']['image']) && is_array($entity['visualIdentity']['image'])) {
            $images = $entity['visualIdentity']['image'];
            $best = $images[0]['url'] ?? '';
            foreach ($images as $img) {
                if (($img['maxWidth'] ?? 0) >= 300) {
                    $best = $img['url'];
                }
            }
            $coverImage = $best;
        }

        $rawTracks = $entity['trackList'] ?? [];
        $tracks = [];
        foreach ($rawTracks as $item) {
            $tTitle = trim($item['title'] ?? '');
            $artist = trim($item['subtitle'] ?? '');
            if (!empty($tTitle)) {
                $artist = str_replace("\xc2\xa0", ' ', $artist);
                $tracks[] = [
                    'title' => $tTitle,
                    'artist' => $artist,
                    'query' => trim($tTitle . ' ' . $artist)
                ];
            }
        }

        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'coverImage' => $coverImage,
            'tracks' => $tracks
        ];
    }
    return null;
}

// Helper: Match song on YouTube Music via primary API (ganatube.in/api/songs)
function match_ytmusic_song($query) {
    $encoded = urlencode($query);
    $apiUrl = "https://ganatube.in/api/songs?q={$encoded}&type=song";
    
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GanaTubeBot/2.0');
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && !empty($res)) {
        $data = json_decode($res, true);
        if (is_array($data) && count($data) > 0) {
            $top = $data[0];
            return [
                'videoId' => $top['videoId'] ?? ($top['id'] ?? ''),
                'title' => $top['title'] ?? '',
                'channelTitle' => $top['channelTitle'] ?? ($top['artist'] ?? ''),
                'thumbnail' => $top['thumbnail'] ?? ($top['thumbnailHigh'] ?? ''),
                'thumbnailHigh' => $top['thumbnailHigh'] ?? ($top['thumbnail'] ?? ''),
                'duration' => $top['duration'] ?? '3:30'
            ];
        }
    }
    return null;
}

// ── 2. Run Bot 1: Fetch and Match Playlists ──────────────────────────────────
$log = [];
$processedCount = 0;
$newlyApprovedPlaylists = [];

foreach ($targetSources as $source) {
    if (empty($source['enabled'])) continue;
    $sUrl = $source['url'] ?? '';
    if (empty($sUrl)) continue;

    $scraped = scrape_spotify_entity($sUrl);
    if (!$scraped || empty($scraped['tracks'])) {
        $log[] = "Failed to scrape Spotify: {$sUrl}";
        continue;
    }

    $spotifyId = $scraped['id'];
    $title = $scraped['title'];
    $type = $scraped['type'];
    $cover = $scraped['coverImage'];
    $language = $source['defaultLang'] ?? 'Hindi';

    // Match top 20 songs on YouTube Music
    $matchedSongs = [];
    $candidateTracks = array_slice($scraped['tracks'], 0, 20);

    foreach ($candidateTracks as $track) {
        $matched = match_ytmusic_song($track['query']);
        if ($matched && !empty($matched['videoId'])) {
            $matchedSongs[] = $matched;
        }
        // Small pause to prevent rate-limiting
        usleep(40000); // 40ms
    }

    if (count($matchedSongs) < 3) {
        $log[] = "Insufficient YT matches for {$title} (" . count($matchedSongs) . " songs)";
        continue;
    }

    // Shuffle songs as requested by user
    shuffle($matchedSongs);

    $conn = get_db_conn();
    $status = $isFullyAuto ? 'approved' : 'pending';
    $totalSongs = count($matchedSongs);
    $songsJson = $conn->real_escape_string(json_encode($matchedSongs));
    $safeTitle = $conn->real_escape_string($title);
    $safeCover = $conn->real_escape_string($cover);
    $safeUrl = $conn->real_escape_string($sUrl);
    $safeLang = $conn->real_escape_string($language);

    $sql = "INSERT INTO bot_curated_playlists (spotify_id, spotify_url, title, type, language, cover_image, total_songs, songs, status)
            VALUES ('$spotifyId', '$safeUrl', '$safeTitle', '$type', '$safeLang', '$safeCover', $totalSongs, '$songsJson', '$status')
            ON DUPLICATE KEY UPDATE 
                title = VALUES(title),
                cover_image = VALUES(cover_image),
                total_songs = VALUES(total_songs),
                songs = VALUES(songs),
                language = VALUES(language),
                status = IF(status = 'approved', 'approved', IF(status = 'rejected', 'pending', VALUES(status)))";

    if ($conn->query($sql)) {
        $processedCount++;
        $log[] = "Processed '{$title}' ({$type}, {$language}) -> {$totalSongs} songs [Status: {$status}]";
        if ($status === 'approved') {
            $newlyApprovedPlaylists[] = [
                'title' => $title,
                'language' => $language,
                'songs' => $matchedSongs,
                'coverImage' => $cover
            ];
        }
    } else {
        $log[] = "DB Error for '{$title}': " . $conn->error;
    }
}

// ── 3. Run Bot 2: Section Manager (Enforce Max 15 Sections Per Language) ──────
$sectionLog = [];
$conn = get_db_conn();
$resSec = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key = 'custom_sections'");
$customSections = [];
if ($resSec && $resSec->num_rows > 0) {
    $customSections = json_decode($resSec->fetch_assoc()['setting_value'], true) ?: [];
}

// Fetch all approved playlists to sync to sections
$conn = get_db_conn();
$resApproved = $conn->query("SELECT title, language, songs FROM bot_curated_playlists WHERE status = 'approved' ORDER BY updated_at DESC LIMIT 60");
$approvedByLang = [];
if ($resApproved) {
    while ($row = $resApproved->fetch_assoc()) {
        $l = $row['language'] ?: 'Hindi';
        $row['songs'] = json_decode($row['songs'], true) ?: [];
        $approvedByLang[$l][] = $row;
    }
}

$sectionsModified = false;
foreach ($approvedByLang as $lang => $plList) {
    if (!isset($customSections[$lang])) {
        $customSections[$lang] = [];
    }

    foreach ($plList as $pl) {
        $secTitle = $pl['title'];
        // Check if section already exists
        $exists = false;
        foreach ($customSections[$lang] as $cs) {
            if (strcasecmp($cs['title'] ?? '', $secTitle) === 0 || strcasecmp($cs['title'] ?? '', "Trending " . $secTitle) === 0) {
                $exists = true;
                break;
            }
        }

        if (!$exists && count($pl['songs']) >= 4) {
            // Add new section at the beginning, marked as isBot = true
            array_unshift($customSections[$lang], [
                'title' => $secTitle,
                'songs' => array_slice($pl['songs'], 0, 15),
                'isBot' => true
            ]);
            $sectionsModified = true;
            $sectionLog[] = "Added section '{$secTitle}' to language '{$lang}'";
        }
    }

    // STRICT USER RULE: Enforce MAX 15 sections per language (except dynamic)
    // Only trim older bot sections so manual admin sections are 100% protected!
    if (count($customSections[$lang]) > $maxSections) {
        $excess = count($customSections[$lang]) - $maxSections;
        $filtered = [];
        $removed = 0;
        for ($i = count($customSections[$lang]) - 1; $i >= 0; $i--) {
            $s = $customSections[$lang][$i];
            if (!empty($s['isBot']) && $removed < $excess) {
                $removed++;
                continue; // Trim this older bot section
            }
            $filtered[] = $s;
        }
        $customSections[$lang] = array_reverse($filtered);

        if (count($customSections[$lang]) > $maxSections) {
            $customSections[$lang] = array_slice($customSections[$lang], 0, $maxSections);
        }
        $sectionsModified = true;
        $sectionLog[] = "Enforced limit: trimmed {$removed} older bot sections for '{$lang}' to maintain max {$maxSections}";
    }
}

if ($sectionsModified) {
    $conn = get_db_conn();
    $secJson = $conn->real_escape_string(json_encode($customSections));
    $conn->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('custom_sections', '$secJson')
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
}

// ── 4. Update Bot Last Run Status ─────────────────────────────────────────────
$conn = get_db_conn();
$config['lastRunTime'] = date('Y-m-d H:i:s');
$config['lastRunStatus'] = "Success: processed {$processedCount} playlists. Sections synced.";
$cfgJson = $conn->real_escape_string(json_encode($config));
$conn->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('bot_automation_config', '$cfgJson')
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

$conn->close();

echo json_encode([
    "status" => "success",
    "timestamp" => date('Y-m-d H:i:s'),
    "processed" => $processedCount,
    "isFullyAuto" => $isFullyAuto,
    "maxSectionsPerLanguage" => $maxSections,
    "logs" => $log,
    "sectionLogs" => $sectionLog
], JSON_PRETTY_PRINT);
