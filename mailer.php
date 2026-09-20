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
     * Build the AMOLED Dark Purple & Pink Welcome Email Template
     */
    public static function getWelcomeEmailHtml($name = '') {
        $displayName = !empty($name) ? htmlspecialchars(trim($name), ENT_QUOTES, 'UTF-8') : 'Music Lover';
        
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
    .btn-gradient:hover { opacity: 0.92; }
  </style>
</head>
<body style="margin: 0; padding: 0; background-color: #000000; color: #ffffff;">

  <!-- Outer Canvas -->
  <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #000000; padding: 30px 10px;">
    <tr>
      <td align="center">

        <!-- Main Container (Max 600px) -->
        <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 600px; background-color: #0c0a14; border: 1px solid rgba(168, 85, 247, 0.25); border-radius: 24px; overflow: hidden; box-shadow: 0 20px 50px rgba(0, 0, 0, 0.8), 0 0 40px rgba(168, 85, 247, 0.15);">
          
          <!-- Top Glow Bar -->
          <tr>
            <td height="4" style="background: linear-gradient(90deg, #a855f7 0%, #ec4899 50%, #a855f7 100%); font-size: 0; line-height: 0;">&nbsp;</td>
          </tr>

          <!-- Header / Brand Section -->
          <tr>
            <td align="center" style="padding: 40px 30px 20px 30px;">
              <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                <tr>
                  <td align="center">
                    <!-- Brand Logo -->
                    <a href="https://ganatube.in" target="_blank" style="text-decoration: none; display: inline-block;">
                      <img 
                        src="https://i.ibb.co/zVFjH9J5/ganatubenewlogo.png" 
                        alt="GanaTube" 
                        width="190" 
                        style="display: block; width: 190px; max-width: 100%; height: auto; border: 0; outline: none; text-decoration: none; margin: 0 auto;" 
                      />
                    </a>
                    <div style="font-size: 11px; letter-spacing: 3px; text-transform: uppercase; color: rgba(255, 255, 255, 0.45); margin-top: 12px; font-weight: 600;">
                      Pure High-Fidelity Music
                    </div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Welcome Badge -->
          <tr>
            <td align="center" style="padding: 0 30px;">
              <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                <tr>
                  <td align="center" style="background: rgba(168, 85, 247, 0.12); border: 1px solid rgba(168, 85, 247, 0.35); border-radius: 999px; padding: 6px 18px;">
                    <span style="font-size: 11px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: #ec4899;">
                      ✨ OFFICIAL WELCOME
                    </span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Main Greeting & Message -->
          <tr>
            <td style="padding: 24px 36px 10px 36px; text-align: center;">
              <h1 style="margin: 0; font-size: 26px; font-weight: 800; color: #ffffff; letter-spacing: -0.5px; line-height: 1.3;">
                Hey ' . $displayName . ', Welcome to the Vibe! 🎧
              </h1>
              <p style="margin: 16px 0 0 0; font-size: 15px; line-height: 1.6; color: rgba(255, 255, 255, 0.75); font-weight: 400;">
                Your account is now active on <strong style="color: #ffffff;">GanaTube</strong>. Experience high-fidelity, ad-free music streaming designed for crystal-clear acoustics, ambient themes, and real-time social listening.
              </p>
            </td>
          </tr>

          <!-- Feature Highlights Cards -->
          <tr>
            <td style="padding: 24px 30px;">
              <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0">
                
                <!-- Feature 1 & 2 Row -->
                <tr>
                  <!-- Card 1: Unlimited Music -->
                  <td width="48%" valign="top" style="background: #141120; border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 16px; padding: 18px 16px;">
                    <div style="font-size: 22px; margin-bottom: 8px;">🎵</div>
                    <div style="font-size: 14px; font-weight: 700; color: #ffffff; margin-bottom: 4px;">Lossless Audio</div>
                    <div style="font-size: 12px; line-height: 1.5; color: rgba(255, 255, 255, 0.55);">
                      Stream millions of tracks and trending releases in pure high definition without interruptions.
                    </div>
                  </td>

                  <td width="4%">&nbsp;</td>

                  <!-- Card 2: Listen Together -->
                  <td width="48%" valign="top" style="background: #141120; border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 16px; padding: 18px 16px;">
                    <div style="font-size: 22px; margin-bottom: 8px;">👥</div>
                    <div style="font-size: 14px; font-weight: 700; color: #ffffff; margin-bottom: 4px;">Live Rooms</div>
                    <div style="font-size: 12px; line-height: 1.5; color: rgba(255, 255, 255, 0.55);">
                      Host virtual listening rooms, sync songs millisecond-accurate with friends, and chat in live audio lounges.
                    </div>
                  </td>
                </tr>

                <!-- Spacer -->
                <tr><td height="12" colspan="3" style="font-size: 0; line-height: 0;">&nbsp;</td></tr>

                <!-- Feature 3 & 4 Row -->
                <tr>
                  <!-- Card 3: Offline Mode & Cast -->
                  <td width="48%" valign="top" style="background: #141120; border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 16px; padding: 18px 16px;">
                    <div style="font-size: 22px; margin-bottom: 8px;">⚡</div>
                    <div style="font-size: 14px; font-weight: 700; color: #ffffff; margin-bottom: 4px;">Offline & Cast</div>
                    <div style="font-size: 12px; line-height: 1.5; color: rgba(255, 255, 255, 0.55);">
                      Download songs locally for offline travel mode or cast directly to your Google Cast TV speakers.
                    </div>
                  </td>

                  <td width="4%">&nbsp;</td>

                  <!-- Card 4: Spin Wheel -->
                  <td width="48%" valign="top" style="background: #141120; border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 16px; padding: 18px 16px;">
                    <div style="font-size: 22px; margin-bottom: 8px;">🎰</div>
                    <div style="font-size: 14px; font-weight: 700; color: #ffffff; margin-bottom: 4px;">Daily Spin Wheel</div>
                    <div style="font-size: 12px; line-height: 1.5; color: rgba(255, 255, 255, 0.55);">
                      Spin daily to earn G-Coins, unlock achievements, and discover curated algorithmic playlists.
                    </div>
                  </td>
                </tr>

              </table>
            </td>
          </tr>

          <!-- Primary CTA Button -->
          <tr>
            <td align="center" style="padding: 10px 30px 30px 30px;">
              <table role="presentation" border="0" cellspacing="0" cellpadding="0">
                <tr>
                  <td align="center" style="border-radius: 14px; background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%); box-shadow: 0 8px 25px rgba(236, 72, 153, 0.45);">
                    <a href="https://ganatube.in" target="_blank" style="display: inline-block; padding: 16px 36px; font-size: 15px; font-weight: 800; color: #ffffff; text-decoration: none; letter-spacing: 0.5px; border-radius: 14px;">
                      Start Listening Now &rarr;
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Support Banner -->
          <tr>
            <td style="padding: 0 30px 30px 30px;">
              <table role="presentation" width="100%" border="0" cellspacing="0" cellpadding="0" style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 16px; padding: 16px 20px;">
                <tr>
                  <td align="center">
                    <p style="margin: 0; font-size: 13px; color: rgba(255, 255, 255, 0.65); line-height: 1.5;">
                      Got feedback, questions or song recommendations? We would love to hear from you.
                      <br>
                      Write directly to us anytime at <a href="mailto:support@ganatube.in" style="color: #ec4899; text-decoration: none; font-weight: 700;">support@ganatube.in</a>
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
            <td style="padding: 30px; text-align: center;">
              <div style="font-size: 12px; color: rgba(255, 255, 255, 0.4); line-height: 1.6;">
                You received this email because you signed up on <a href="https://ganatube.in" style="color: rgba(255, 255, 255, 0.6); text-decoration: underline;">ganatube.in</a>.
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
