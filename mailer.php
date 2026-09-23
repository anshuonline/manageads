<?php
// GanaTube Mailer Engine using Hostinger SMTP

class GanaTubeMailer {
    private static $smtp_host = 'ssl://smtp.hostinger.com';
    private static $smtp_port = 465;
    private static $smtp_user = 'support@ganatube.in';
    private static $smtp_pass = 'Ganatube1234@.com';
    private static $from_name = 'GanaTube';
    private static $from_email = 'support@ganatube.in';

    /**
     * Send email via direct SMTP socket connection to Hostinger
     */
    public static function sendMail($to, $subject, $htmlContent, $toName = '') {
        $timeout = 10;
        $fp = @fsockopen(self::$smtp_host, self::$smtp_port, $errno, $errstr, $timeout);
        if (!$fp) {
            return ['success' => false, 'error' => "SMTP Connection Failed: $errstr ($errno)"];
        }

        stream_set_timeout($fp, $timeout);

        $read = function() use ($fp) {
            $data = '';
            while ($line = fgets($fp, 512)) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $data;
        };

        $write = function($cmd) use ($fp) {
            fputs($fp, $cmd . "\r\n");
        };

        // 1. Initial Greeting
        $init = $read();
        if (substr($init, 0, 3) !== '220') {
            fclose($fp);
            return ['success' => false, 'error' => "Invalid initial response: $init"];
        }

        // 2. EHLO
        $write("EHLO ganatube.in");
        $ehlo = $read();

        // 3. AUTH LOGIN
        $write("AUTH LOGIN");
        $authPrompt = $read();
        if (substr($authPrompt, 0, 3) !== '334') {
            fclose($fp);
            return ['success' => false, 'error' => "AUTH LOGIN rejected: $authPrompt"];
        }

        // Send Username
        $write(base64_encode(self::$smtp_user));
        $userPrompt = $read();
        if (substr($userPrompt, 0, 3) !== '334') {
            fclose($fp);
            return ['success' => false, 'error' => "Username rejected: $userPrompt"];
        }

        // Send Password
        $write(base64_encode(self::$smtp_pass));
        $authRes = $read();
        if (substr($authRes, 0, 3) !== '235') {
            fclose($fp);
            return ['success' => false, 'error' => "Authentication failed: $authRes"];
        }

        // 4. MAIL FROM
        $write("MAIL FROM: <" . self::$from_email . ">");
        $fromRes = $read();
        if (substr($fromRes, 0, 3) !== '250') {
            fclose($fp);
            return ['success' => false, 'error' => "MAIL FROM rejected: $fromRes"];
        }

        // 5. RCPT TO
        $write("RCPT TO: <$to>");
        $rcptRes = $read();
        if (substr($rcptRes, 0, 3) !== '250' && substr($rcptRes, 0, 3) !== '251') {
            fclose($fp);
            return ['success' => false, 'error' => "RCPT TO rejected: $rcptRes"];
        }

        // 6. DATA
        $write("DATA");
        $dataPrompt = $read();
        if (substr($dataPrompt, 0, 3) !== '354') {
            fclose($fp);
            return ['success' => false, 'error' => "DATA rejected: $dataPrompt"];
        }

        // 7. Message Payload
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $toHeader = !empty($toName) ? "=?UTF-8?B?" . base64_encode($toName) . "?= <$to>" : "<$to>";
        $fromHeader = "=?UTF-8?B?" . base64_encode(self::$from_name) . "?= <" . self::$from_email . ">";
        $msgId = '<' . md5(uniqid(time())) . '@ganatube.in>';
        $date = date('r');

        $headers = [
            "Date: $date",
            "From: $fromHeader",
            "Reply-To: $fromHeader",
            "To: $toHeader",
            "Subject: $encodedSubject",
            "Message-ID: $msgId",
            "X-Mailer: GanaTube Mail Engine v2.0",
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
            "Content-Transfer-Encoding: base64"
        ];

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlContent));
        $write($payload);
        $write(".");

        $completeRes = $read();
        $write("QUIT");
        fclose($fp);

        if (substr($completeRes, 0, 3) === '250') {
            return ['success' => true, 'message' => 'Email sent successfully'];
        } else {
            return ['success' => false, 'error' => "Email send rejected: $completeRes"];
        }
    }

    /**
     * Fetch top streaming songs directly from GanaTube Analytics database
     */
    public static function getTopStreamedSongs($limit = 4) {
        global $conn;
        $songs = [];

        // 1. If DB connection is already active (e.g. running inside user-api.php in production)
        if (!empty($conn) && !$conn->connect_error) {
            // Priority A: Daily trending songs (Today + Yesterday - changes dynamically every day!)
            $dailySql = "SELECT d.video_id, MAX(d.title) as title, MAX(d.thumbnail) as thumbnail, SUM(d.play_count) as play_count, COALESCE(MAX(g.artist), '') as artist 
                         FROM daily_analytics d 
                         LEFT JOIN guest_song_analytics g ON d.video_id = g.video_id 
                         WHERE d.stat_date >= CURDATE() - INTERVAL 1 DAY 
                           AND d.title IS NOT NULL AND d.title != '' AND d.video_id IS NOT NULL AND d.video_id != ''
                         GROUP BY d.video_id 
                         ORDER BY play_count DESC 
                         LIMIT " . (int)$limit;
            $resDaily = @$conn->query($dailySql);
            if ($resDaily && $resDaily->num_rows >= $limit) {
                while ($row = $resDaily->fetch_assoc()) {
                    $songs[] = $row;
                }
            }

            // Priority B: Weekly trending songs (Last 7 Days) if today has fewer plays
            if (count($songs) < $limit) {
                $weeklySql = "SELECT d.video_id, MAX(d.title) as title, MAX(d.thumbnail) as thumbnail, SUM(d.play_count) as play_count, COALESCE(MAX(g.artist), '') as artist 
                              FROM daily_analytics d 
                              LEFT JOIN guest_song_analytics g ON d.video_id = g.video_id 
                              WHERE d.stat_date >= CURDATE() - INTERVAL 7 DAY 
                                AND d.title IS NOT NULL AND d.title != '' AND d.video_id IS NOT NULL AND d.video_id != ''
                              GROUP BY d.video_id 
                              ORDER BY play_count DESC 
                              LIMIT " . (int)$limit;
                $resWeekly = @$conn->query($weeklySql);
                if ($resWeekly && $resWeekly->num_rows >= $limit) {
                    $songs = [];
                    while ($row = $resWeekly->fetch_assoc()) {
                        $songs[] = $row;
                    }
                }
            }

            // Priority C: All-time top streaming songs in song_analytics
            if (count($songs) < $limit) {
                $sql = "SELECT s.video_id, s.title, s.thumbnail, s.play_count, COALESCE(g.artist, '') as artist 
                        FROM song_analytics s 
                        LEFT JOIN guest_song_analytics g ON s.video_id = g.video_id 
                        WHERE s.title IS NOT NULL AND s.title != '' AND s.video_id IS NOT NULL AND s.video_id != ''
                        ORDER BY s.play_count DESC 
                        LIMIT " . (int)$limit;
                $res = @$conn->query($sql);
                if ($res && $res->num_rows > 0) {
                    $songs = [];
                    while ($row = $res->fetch_assoc()) {
                        $songs[] = $row;
                    }
                }
            }
        }

        // 2. If DB was not connected (e.g. standalone test or CLI), fetch from live analytics API
        if (count($songs) < $limit) {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 4,
                    'header' => "User-Agent: GanaTubeMailer/2.0\r\n"
                ]
            ]);
            $apiJson = @file_get_contents('https://manageads.ganatube.in/analytic-api.php?action=getTop100Songs', false, $ctx);
            if ($apiJson) {
                $data = json_decode($apiJson, true);
                if (!empty($data['data']) && is_array($data['data'])) {
                    $songs = array_slice($data['data'], 0, $limit);
                }
            }
        }

        // 3. Fallback to top curated tracks if both sources fail
        if (empty($songs)) {
            $songs = [
                ['video_id' => '1DmRufUbnKs', 'title' => 'Bigdi Meri Bana De', 'play_count' => 379, 'thumbnail' => 'https://yt3.googleusercontent.com/BZlydHAFiZAwXsC9ggN-zcZAOsT2a-8CouWwc0SSA-Aq-v4ozoBTAjJGjEc6dTzej0X9D0F0Fz0R1g6O=w600-h600-l90-rj'],
                ['video_id' => 'uVIhqzSKCLs', 'title' => 'Bijuria (From "Sunny Sanskari Ki Tulsi Kumari")', 'play_count' => 216, 'thumbnail' => 'https://yt3.googleusercontent.com/jjD27XfHkUOIEPYcaBtxu__FvcJmPX8bAE1Gf-9tUuciBZXRKyyrhCSEiYgEGOdBRxCyk2RUHGF_OBw=w600-h600-l90-rj'],
                ['video_id' => 'WHKkC5JQSgY', 'title' => 'Saree', 'play_count' => 195, 'thumbnail' => 'https://yt3.googleusercontent.com/ryseiMP7kZM3BOAyoti7Ns0Qe0utpdnKoE4jsL-3KZflsymgjUh-U93NeRNCB9o0BF80fppiExthB1xi=w600-h600-l90-rj'],
                ['video_id' => '7uILDZdMv1U', 'title' => 'Ghur Ghur Almora', 'play_count' => 64, 'thumbnail' => 'https://yt3.googleusercontent.com/od4kLKpGJOvaiIeT6WfZIkkr6XEsimX-_rTrd3D19WUtnO0DCTbkcWvFkU5Rrcsdkx07sYaM8o-gbNAl=w600-h600-l90-rj']
            ];
        }

        return $songs;
    }

    /**
     * Build the Ultra-Modern AMOLED Dark Purple & Pink Welcome Email Template
     * Featuring Recommended Songs, Popular Artists, and Clickable Interactive Controls
     */
    public static function getWelcomeEmailHtml($name = '') {
        $displayName = !empty($name) ? htmlspecialchars(trim($name), ENT_QUOTES, 'UTF-8') : 'Music Lover';

        // Fetch real-time top streaming songs from GanaTube analytics
        $topSongs = self::getTopStreamedSongs(4);
        $trackRowsHtml = '';
        $totalSongs = count($topSongs);

        foreach ($topSongs as $idx => $song) {
            $vid = htmlspecialchars($song['video_id'] ?? '', ENT_QUOTES, 'UTF-8');
            $rawTitle = trim($song['title'] ?? 'Trending Track');
            $title = htmlspecialchars($rawTitle, ENT_QUOTES, 'UTF-8');
            $playCount = (int)($song['play_count'] ?? 0) + rand(125000, 9850000);
            $rawArtist = trim($song['artist'] ?? '');
            
            if (!empty($rawArtist)) {
                $subtitle = htmlspecialchars($rawArtist, ENT_QUOTES, 'UTF-8');
                if ($playCount > 0) {
                    $subtitle .= ' &bull; 🔥 ' . number_format($playCount) . ' plays';
                }
            } elseif ($playCount > 0) {
                $subtitle = '🔥 ' . number_format($playCount) . ' Streams &bull; #' . ($idx + 1) . ' on GanaTube';
            } else {
                $subtitle = '🔥 Top Streamed on GanaTube';
            }

            // Thumbnail fallback
            $thumb = !empty($song['thumbnail']) 
                ? htmlspecialchars($song['thumbnail'], ENT_QUOTES, 'UTF-8') 
                : 'https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg';

            $paddingBottom = ($idx === $totalSongs - 1) ? '0' : '10px';

            $trackRowsHtml .= '
                <!-- Track ' . ($idx + 1) . ': ' . $title . ' -->
                <tr>
                  <td style="padding-bottom: ' . $paddingBottom . ';">
                    <a href="https://ganatube.in/?play=' . $vid . '" target="_blank" style="display: block; text-decoration: none; background: #13101e; border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 16px; padding: 12px 14px;">
                      <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
                        <tr>
                          <!-- Thumbnail -->
                          <td width="48" valign="middle" style="width: 48px; min-width: 48px;">
                            <img src="' . $thumb . '" alt="' . $title . '" width="48" height="48" style="display: block; width: 48px; height: 48px; border-radius: 10px; object-fit: cover; border: 0;" />
                          </td>
                          <!-- Song Info -->
                          <td style="padding: 0 14px;" valign="middle">
                            <div style="font-size: 15px; font-weight: 700; color: #ffffff; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 320px;">' . $title . '</div>
                            <div style="font-size: 12px; color: rgba(255, 255, 255, 0.55); margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 320px;">' . $subtitle . '</div>
                          </td>
                          <!-- Circular Play Icon Button -->
                          <td width="42" align="right" valign="middle" style="width: 42px; min-width: 42px;">
                            <table role="presentation" border="0" cellspacing="0" cellpadding="0" align="right" style="margin: 0;">
                              <tr>
                                <td align="center" valign="middle" width="38" height="38" style="width: 38px; height: 38px; min-width: 38px; min-height: 38px; border-radius: 50%; background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%); box-shadow: 0 4px 14px rgba(236, 72, 153, 0.45); text-align: center; vertical-align: middle;">
                                  <span style="color: #ffffff !important; font-size: 15px; line-height: 38px; font-weight: bold; font-family: Arial, Helvetica, sans-serif; display: inline-block; margin-left: 2px; text-decoration: none;">&#9658;&#xFE0E;</span>
                                </td>
                              </tr>
                            </table>
                          </td>
                        </tr>
                      </table>
                    </a>
                  </td>
                </tr>';
        }
        
        return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="dark">
  <meta name="supported-color-schemes" content="dark">
  <title>Welcome to GanaTube</title>
  <style>
    :root {
      color-scheme: dark;
      supported-color-schemes: dark;
    }
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
    body { margin: 0; padding: 0; width: 100% !important; background-color: #000000; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    a { color: #ec4899; text-decoration: none; }
    .track-row:hover { background-color: #1a1529 !important; border-color: rgba(236, 72, 153, 0.4) !important; }
    .artist-card:hover { transform: translateY(-2px); }
  </style>
</head>
<body style="margin: 0; padding: 0; background-color: #000000; color: #ffffff;">

  <!-- Outer Canvas -->
  <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #000000; padding: 24px 8px;">
    <tr>
      <td align="center">

        <!-- Main Card Container (Max 600px) -->
        <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 600px; background-color: #0a0812; border: 1px solid rgba(168, 85, 247, 0.28); border-radius: 24px; overflow: hidden; box-shadow: 0 25px 60px rgba(0, 0, 0, 0.9), 0 0 50px rgba(168, 85, 247, 0.18);">
          
          <!-- Top Signature Gradient Bar -->
          <tr>
            <td height="4" style="background: linear-gradient(90deg, #a855f7 0%, #ec4899 50%, #a855f7 100%); font-size: 0; line-height: 0;">&nbsp;</td>
          </tr>

          <!-- Header / Brand Section -->
          <tr>
            <td align="center" style="padding: 36px 24px 16px 24px;">
              <a href="https://ganatube.in" target="_blank" style="text-decoration: none; display: inline-block;">
                <img 
                  src="https://i.ibb.co/zVFjH9J5/ganatubenewlogo.png" 
                  alt="GanaTube" 
                  width="185" 
                  style="display: block; width: 185px; max-width: 100%; height: auto; border: 0; outline: none; margin: 0 auto;" 
                />
              </a>
              <div style="font-size: 11px; letter-spacing: 3px; text-transform: uppercase; color: rgba(255, 255, 255, 0.45); margin-top: 12px; font-weight: 600;">
                Pure High-Fidelity Music Streaming
              </div>
            </td>
          </tr>

          <!-- Welcome Badge -->
          <tr>
            <td align="center" style="padding: 0 24px;">
              <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                <tr>
                  <td align="center" style="background: rgba(168, 85, 247, 0.14); border: 1px solid rgba(236, 72, 153, 0.35); border-radius: 999px; padding: 6px 18px;">
                    <span style="font-size: 11px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; color: #ec4899;">
                      ✨ EXCLUSIVE ACCESS UNLOCKED
                    </span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Main Greeting Hero -->
          <tr>
            <td style="padding: 22px 32px 8px 32px; text-align: center;">
              <h1 style="margin: 0; font-size: 26px; font-weight: 800; color: #ffffff; letter-spacing: -0.5px; line-height: 1.3;">
                Hey ' . $displayName . ', Your Stage is Set! 🎧
              </h1>
              <p style="margin: 14px 0 0 0; font-size: 15px; line-height: 1.6; color: rgba(255, 255, 255, 0.72); font-weight: 400;">
                Welcome to <strong style="color: #ffffff;">GanaTube</strong> — your ad-free, high-fidelity music universe. We’ve curated top trending hits and favorite artists to get your soundtrack started instantly.
              </p>
            </td>
          </tr>

          <!-- Primary CTA Button -->
          <tr>
            <td align="center" style="padding: 16px 24px 28px 24px;">
              <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                <tr>
                  <td align="center" style="border-radius: 14px; background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%); box-shadow: 0 10px 30px rgba(236, 72, 153, 0.45);">
                    <a href="https://ganatube.in" target="_blank" style="display: inline-block; padding: 16px 36px; font-size: 15px; font-weight: 800; color: #ffffff; text-decoration: none; letter-spacing: 0.5px; border-radius: 14px;">
                      ▶ Open GanaTube & Start Playing &rarr;
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Divider -->
          <tr>
            <td style="padding: 0 28px;"><div style="height: 1px; background: rgba(255, 255, 255, 0.08);"></div></td>
          </tr>

          <!-- ══════════════════════════════════════════════
               SECTION 1: TOP STREAMING ON GANATUBE (ANALYTICS)
               ══════════════════════════════════════════════ -->
          <tr>
            <td style="padding: 28px 28px 12px 28px;">
              <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>
                  <td>
                    <div style="font-size: 12px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; color: #ec4899; margin-bottom: 4px;">
                      🔥 TOP STREAMING ON GANATUBE
                    </div>
                    <div style="font-size: 18px; font-weight: 800; color: #ffffff; letter-spacing: -0.3px;">
                      Most Played Right Now &bull; Tap to Play
                    </div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Track List (Dynamic from Analytics) -->
          <tr>
            <td style="padding: 0 28px 20px 28px;">
              <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
                ' . $trackRowsHtml . '
              </table>
            </td>
          </tr>

          <!-- Divider -->
          <tr>
            <td style="padding: 0 28px;"><div style="height: 1px; background: rgba(255, 255, 255, 0.08);"></div></td>
          </tr>

          <!-- ══════════════════════════════════════════════
               SECTION 2: POPULAR ARTISTS (CLICK TO EXPLORE)
               ══════════════════════════════════════════════ -->
          <tr>
            <td style="padding: 26px 28px 14px 28px;">
              <div style="font-size: 12px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; color: #a855f7; margin-bottom: 4px;">
                🌟 FEATURED ARTISTS
              </div>
              <div style="font-size: 18px; font-weight: 800; color: #ffffff; letter-spacing: -0.3px;">
                Explore Discographies & Albums
              </div>
            </td>
          </tr>

          <!-- Artist Grid (4 Artists) -->
          <tr>
            <td style="padding: 0 24px 24px 24px;">
              <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>

                  <!-- Artist 1: Arijit Singh -->
                  <td width="25%" align="center" valign="top" style="padding: 0 4px;">
                    <a href="https://ganatube.in/artist/Arijit%20Singh" target="_blank" style="text-decoration: none; display: block;">
                      <table role="presentation" border="0" cellspacing="0" cellpadding="0" align="center">
                        <tr>
                          <td align="center">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b7/Arijit_Singh_performance_at_Chandigarh_2025.jpg/500px-Arijit_Singh_performance_at_Chandigarh_2025.jpg" alt="Arijit Singh" width="70" height="70" style="display: block; width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 2px solid #a855f7; box-shadow: 0 6px 15px rgba(168, 85, 247, 0.35);" />
                          </td>
                        </tr>
                        <tr>
                          <td align="center" style="padding-top: 8px;">
                            <div style="font-size: 13px; font-weight: 700; color: #ffffff; line-height: 1.2;">Arijit Singh</div>
                            <div style="font-size: 11px; color: #ec4899; margin-top: 2px; font-weight: 600;">Explore &rarr;</div>
                          </td>
                        </tr>
                      </table>
                    </a>
                  </td>

                  <!-- Artist 2: Shreya Ghoshal -->
                  <td width="25%" align="center" valign="top" style="padding: 0 4px;">
                    <a href="https://ganatube.in/artist/Shreya%20Ghoshal" target="_blank" style="text-decoration: none; display: block;">
                      <table role="presentation" border="0" cellspacing="0" cellpadding="0" align="center">
                        <tr>
                          <td align="center">
                            <img src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRV2uQTlBVTPRmPczCJ3ebYPPCiNXdskveCjApGGsiYHwhT8wFhNWrShJg-mjpRrnzFyUia504oAXU38CiDUN1pHbTZlcaNTA-AATVEBTWi-w&s=10" alt="Shreya Ghoshal" width="70" height="70" style="display: block; width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 2px solid #ec4899; box-shadow: 0 6px 15px rgba(236, 72, 153, 0.35);" />
                          </td>
                        </tr>
                        <tr>
                          <td align="center" style="padding-top: 8px;">
                            <div style="font-size: 13px; font-weight: 700; color: #ffffff; line-height: 1.2;">Shreya Ghoshal</div>
                            <div style="font-size: 11px; color: #ec4899; margin-top: 2px; font-weight: 600;">Explore &rarr;</div>
                          </td>
                        </tr>
                      </table>
                    </a>
                  </td>

                  <!-- Artist 3: Diljit Dosanjh -->
                  <td width="25%" align="center" valign="top" style="padding: 0 4px;">
                    <a href="https://ganatube.in/artist/Diljit%20Dosanjh" target="_blank" style="text-decoration: none; display: block;">
                      <table role="presentation" border="0" cellspacing="0" cellpadding="0" align="center">
                        <tr>
                          <td align="center">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/e/e2/Diljit_Dosanjh.jpg" alt="Diljit Dosanjh" width="70" height="70" style="display: block; width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 2px solid #a855f7; box-shadow: 0 6px 15px rgba(168, 85, 247, 0.35);" />
                          </td>
                        </tr>
                        <tr>
                          <td align="center" style="padding-top: 8px;">
                            <div style="font-size: 13px; font-weight: 700; color: #ffffff; line-height: 1.2;">Diljit Dosanjh</div>
                            <div style="font-size: 11px; color: #ec4899; margin-top: 2px; font-weight: 600;">Explore &rarr;</div>
                          </td>
                        </tr>
                      </table>
                    </a>
                  </td>

                  <!-- Artist 4: Taylor Swift -->
                  <td width="25%" align="center" valign="top" style="padding: 0 4px;">
                    <a href="https://ganatube.in/artist/Taylor%20Swift" target="_blank" style="text-decoration: none; display: block;">
                      <table role="presentation" border="0" cellspacing="0" cellpadding="0" align="center">
                        <tr>
                          <td align="center">
                            <img src="https://upload.wikimedia.org/wikipedia/commons/thumb/b/b1/Taylor_Swift_at_the_2023_MTV_Video_Music_Awards_%283%29.png/500px-Taylor_Swift_at_the_2023_MTV_Video_Music_Awards_%283%29.png" alt="Taylor Swift" width="70" height="70" style="display: block; width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 2px solid #ec4899; box-shadow: 0 6px 15px rgba(236, 72, 153, 0.35);" />
                          </td>
                        </tr>
                        <tr>
                          <td align="center" style="padding-top: 8px;">
                            <div style="font-size: 13px; font-weight: 700; color: #ffffff; line-height: 1.2;">Taylor Swift</div>
                            <div style="font-size: 11px; color: #ec4899; margin-top: 2px; font-weight: 600;">Explore &rarr;</div>
                          </td>
                        </tr>
                      </table>
                    </a>
                  </td>

                </tr>
              </table>
            </td>
          </tr>

          <!-- Divider -->
          <tr>
            <td style="padding: 0 28px;"><div style="height: 1px; background: rgba(255, 255, 255, 0.08);"></div></td>
          </tr>

          <!-- ══════════════════════════════════════════════
               SECTION 3: DISCOVERY VIBES & GENRES (CLICKABLE PILLS)
               ══════════════════════════════════════════════ -->
          <tr>
            <td style="padding: 24px 28px 8px 28px; text-align: center;">
              <div style="font-size: 12px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; color: rgba(255, 255, 255, 0.5); margin-bottom: 12px;">
                ⚡ QUICK JUMP INTO YOUR VIBE
              </div>
              <table role="presentation" border="0" cellspacing="0" cellpadding="0" align="center">
                <tr>
                  <td style="padding: 4px;">
                    <a href="https://ganatube.in/search?q=Trending%20Songs" target="_blank" style="display: inline-block; background: #161224; border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 999px; padding: 8px 16px; font-size: 12px; font-weight: 700; color: #ffffff; text-decoration: none;">
                      🔥 Trending
                    </a>
                  </td>
                  <td style="padding: 4px;">
                    <a href="https://ganatube.in/rooms" target="_blank" style="display: inline-block; background: #161224; border: 1px solid rgba(236, 72, 153, 0.3); border-radius: 999px; padding: 8px 16px; font-size: 12px; font-weight: 700; color: #ec4899; text-decoration: none;">
                      👥 Live Rooms
                    </a>
                  </td>
                  <td style="padding: 4px;">
                    <a href="https://ganatube.in/search?q=Lofi%20Chill" target="_blank" style="display: inline-block; background: #161224; border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 999px; padding: 8px 16px; font-size: 12px; font-weight: 700; color: #ffffff; text-decoration: none;">
                      🌙 Lo-Fi Chill
                    </a>
                  </td>
                  <td style="padding: 4px;">
                    <a href="https://ganatube.in/search?q=Party%20Hits" target="_blank" style="display: inline-block; background: #161224; border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 999px; padding: 8px 16px; font-size: 12px; font-weight: 700; color: #ffffff; text-decoration: none;">
                      🎉 Party Hits
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- ══════════════════════════════════════════════
               SECTION 4: CORE VALUE PILLARS
               ══════════════════════════════════════════════ -->
          <tr>
            <td style="padding: 20px 28px;">
              <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>
                  <td width="48%" style="background: #110e1c; border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 14px; padding: 14px;">
                    <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                      <tr>
                        <td width="30" valign="top" style="font-size: 18px;">🚫</td>
                        <td style="padding-left: 8px;">
                          <div style="font-size: 13px; font-weight: 700; color: #ffffff;">Zero Video Ads</div>
                          <div style="font-size: 11px; color: rgba(255, 255, 255, 0.5); margin-top: 2px;">Pure uninterrupted tracks</div>
                        </td>
                      </tr>
                    </table>
                  </td>
                  <td width="4%">&nbsp;</td>
                  <td width="48%" style="background: #110e1c; border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 14px; padding: 14px;">
                    <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                      <tr>
                        <td width="30" valign="top" style="font-size: 18px;">🎧</td>
                        <td style="padding-left: 8px;">
                          <div style="font-size: 13px; font-weight: 700; color: #ffffff;">Listen Together</div>
                          <div style="font-size: 11px; color: rgba(255, 255, 255, 0.5); margin-top: 2px;">Sync playback in live rooms</div>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Support Concierge Box -->
          <tr>
            <td style="padding: 4px 28px 26px 28px;">
              <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 16px; padding: 16px 20px;">
                <tr>
                  <td align="center">
                    <p style="margin: 0; font-size: 13px; color: rgba(255, 255, 255, 0.65); line-height: 1.5;">
                      Got feedback, questions or song recommendations? We would love to hear from you.
                      <br>
                      Write directly to our team anytime at <a href="mailto:support@ganatube.in" style="color: #ec4899; text-decoration: none; font-weight: 700;">support@ganatube.in</a>
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Divider -->
          <tr>
            <td height="1" style="background: rgba(255, 255, 255, 0.06); font-size: 0; line-height: 0;">&nbsp;</td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding: 28px 24px; text-align: center;">
              <div style="font-size: 12px; color: rgba(255, 255, 255, 0.4); line-height: 1.6;">
                You received this email because you signed up on <a href="https://ganatube.in" style="color: rgba(255, 255, 255, 0.65); text-decoration: underline;">ganatube.in</a>.
                <br>
                Made with <span style="color: #ec4899;">&hearts;</span> for music lovers across India and the world.
              </div>
              <div style="font-size: 11px; color: rgba(255, 255, 255, 0.25); margin-top: 14px; letter-spacing: 0.5px;">
                &copy; ' . date('Y') . ' GanaTube Media. All rights reserved.
              </div>
            </td>
          </tr>

        </table>
        <!-- End Container -->

      </td>
    </tr>
  </table>

</body>
</html>';
    }

    /**
     * Send Welcome Email to New User
     */
    public static function sendWelcomeEmail($email, $displayName = '') {
        $subject = "Welcome to GanaTube, " . (!empty($displayName) ? $displayName : "Music Lover") . "! 🎧 Ad-Free Music Awaits";
        $html = self::getWelcomeEmailHtml($displayName);
        return self::sendMail($email, $subject, $html, $displayName);
    }
}
?>
