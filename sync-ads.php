<?php
header('Content-Type: application/json');

$results = [
    'htaccess_cleaned' => [],
    'errors' => []
];

$pubRedirect = "Redirect 301 /ads.txt https://adstxt-ssp.pubfuture.com/site/6afaae206fecc7003d0ad28b/ganatube.in/ads.txt";

$possibleHtaccess = [
    __DIR__ . '/../.htaccess',
    __DIR__ . '/../../.htaccess',
    '/home/u651445555/domains/ganatube.in/public_html/.htaccess',
    '/home/u651445555/domains/ganatube.in/public_html/dist/ganatube/browser/.htaccess',
    __DIR__ . '/../../ganatube.in/public_html/.htaccess',
    __DIR__ . '/../../ganatube.in/public_html/dist/ganatube/browser/.htaccess'
];

foreach ($possibleHtaccess as $htPath) {
    try {
        if (file_exists($htPath)) {
            $ht = file_get_contents($htPath);
            // Remove Ezoic redirects
            $ht = preg_replace('/Redirect 301 \/ads\.txt https:\/\/srv\.adstxtmanager\.com[^\r\n]*[\r\n]*/', '', $ht);
            $ht = preg_replace('/RewriteRule \^ads\\\.txt\$ https:\/\/srv\.adstxtmanager\.com[^\r\n]*[\r\n]*/', '', $ht);
            // Add PubFuture redirect
            if (strpos($ht, 'adstxt-ssp.pubfuture.com') === false) {
                $ht = $pubRedirect . "\n" . $ht;
            }
            file_put_contents($htPath, $ht);
            $results['htaccess_cleaned'][] = realpath($htPath) ?: $htPath;
        }
    } catch (Exception $e) {
        $results['errors'][] = $e->getMessage();
    }
}

// Remove static ads.txt
$possibleAdsLocations = [
    __DIR__ . '/../ads.txt',
    __DIR__ . '/../../ads.txt',
    '/home/u651445555/domains/ganatube.in/public_html/ads.txt',
    '/home/u651445555/domains/ganatube.in/public_html/dist/ganatube/browser/ads.txt',
    __DIR__ . '/../../ganatube.in/public_html/ads.txt',
    __DIR__ . '/../../ganatube.in/public_html/dist/ganatube/browser/ads.txt'
];
foreach ($possibleAdsLocations as $loc) {
    if (file_exists($loc)) @unlink($loc);
}

echo json_encode(['status' => 'success', 'results' => $results], JSON_PRETTY_PRINT);
