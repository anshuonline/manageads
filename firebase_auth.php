<?php
/**
 * Pure-PHP Firebase ID Token (RS256 JWT) Verifier for project: ganatube-8ec4a
 * Protects user-api.php and playlist-api.php against unauthorized cross-user access.
 */

define('GT_FIREBASE_PROJECT_ID', 'ganatube-8ec4a');
define('GT_FIREBASE_CERTS_URL', 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com');
define('GT_ADMIN_EMAILS', ['helloanshu.dev@gmail.com']);

function gtBase64UrlDecode(string $input): string {
    $remainder = strlen($input) % 4;
    if ($remainder) {
        $input .= str_repeat('=', 4 - $remainder);
    }
    return (string) base64_decode(strtr($input, '-_', '+/'));
}

function gtGetBearerToken(): ?string {
    $header = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
        $all = getallheaders();
        if (is_array($all)) {
            foreach ($all as $k => $v) {
                if (strtolower((string) $k) === 'authorization') {
                    $header = (string) $v;
                    break;
                }
            }
        }
    }

    if (preg_match('/Bearer\s+([^\s]+)/i', $header, $m)) {
        return trim($m[1]);
    }
    return null;
}

function gtFetchGoogleCerts(bool $forceRefresh = false): array {
    $cacheFile = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'gt_firebase_x509_certs.json';

    if (!$forceRefresh && is_file($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }
    }

    $raw = '';
    if (function_exists('curl_init')) {
        $ch = curl_init(GT_FIREBASE_CERTS_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = (string) curl_exec($ch);
        curl_close($ch);
    }
    if ($raw === '') {
        $ctx = stream_context_create(['http' => ['timeout' => 6]]);
        $raw = (string) @file_get_contents(GT_FIREBASE_CERTS_URL, false, $ctx);
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded) && !empty($decoded)) {
        @file_put_contents($cacheFile, $raw);
        return $decoded;
    }

    if (is_file($cacheFile)) {
        $stale = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($stale)) return $stale;
    }
    return [];
}

function gtVerifyFirebaseToken(?string $jwt): ?array {
    if (!$jwt || substr_count($jwt, '.') !== 2) {
        return null;
    }

    [$h64, $p64, $s64] = explode('.', $jwt, 3);
    $header = json_decode(gtBase64UrlDecode($h64), true);
    $payload = json_decode(gtBase64UrlDecode($p64), true);
    $signature = gtBase64UrlDecode($s64);

    if (!is_array($header) || !is_array($payload) || $signature === '') {
        return null;
    }
    if (($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
        return null;
    }

    $now = time();
    $projectId = GT_FIREBASE_PROJECT_ID;
    $expectedIss = 'https://securetoken.google.com/' . $projectId;

    if (($payload['aud'] ?? '') !== $projectId) return null;
    if (($payload['iss'] ?? '') !== $expectedIss) return null;
    if (empty($payload['sub']) || !is_string($payload['sub'])) return null;
    if (empty($payload['exp']) || (int) $payload['exp'] < ($now - 30)) return null;
    if (!empty($payload['iat']) && (int) $payload['iat'] > ($now + 300)) return null;

    $kid = (string) $header['kid'];
    $certs = gtFetchGoogleCerts(false);
    if (empty($certs[$kid])) {
        $certs = gtFetchGoogleCerts(true);
    }
    if (empty($certs[$kid])) {
        return null;
    }

    $pubKey = openssl_pkey_get_public($certs[$kid]);
    if ($pubKey === false) {
        return null;
    }

    if (openssl_verify($h64 . '.' . $p64, $signature, $pubKey, OPENSSL_ALGO_SHA256) !== 1) {
        return null;
    }

    $email = strtolower(trim((string) ($payload['email'] ?? '')));
    if ($email === '') {
        return null;
    }

    return [
        'uid' => (string) $payload['sub'],
        'email' => $email,
        'name' => (string) ($payload['name'] ?? ''),
        'picture' => (string) ($payload['picture'] ?? ''),
    ];
}

function gtRequireFirebaseUser(): array {
    $user = gtVerifyFirebaseToken(gtGetBearerToken());
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'Unauthorized: valid Firebase ID token required',
        ]);
        exit;
    }
    return $user;
}

function gtRequireAdminUser(): array {
    $user = gtRequireFirebaseUser();
    if (!in_array($user['email'], GT_ADMIN_EMAILS, true)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'Forbidden: admin access required',
        ]);
        exit;
    }
    return $user;
}
