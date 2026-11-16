<?php
header('Content-Type: application/json');

$results = [
    'dir' => __DIR__,
    'ads_txt_updated' => [],
    'htaccess_cleaned' => [],
    'errors' => []
];

$sourceAdsTxt = __DIR__ . '/ads.txt';
if (!file_exists($sourceAdsTxt)) {
    echo json_encode(['status' => 'error', 'message' => 'Source ads.txt missing']);
    exit;
}

$adsContent = file_get_contents($sourceAdsTxt);

$possibleAdsLocations = [
    __DIR__ . '/../ads.txt',
    __DIR__ . '/../../ads.txt',
    '/home/u651445555/domains/ganatube.in/public_html/ads.txt',
    '/home/u651445555/domains/ganatube.in/public_html/dist/ganatube/browser/ads.txt',
    __DIR__ . '/../../ganatube.in/public_html/ads.txt',
    __DIR__ . '/../../ganatube.in/public_html/dist/ganatube/browser/ads.txt'
];

foreach ($possibleAdsLocations as $loc) {
    try {
        $parent = dirname($loc);
        if (is_dir($parent)) {
            file_put_contents($loc, $adsContent);
            $results['ads_txt_updated'][] = realpath($loc) ?: $loc;
        }
    } catch (Exception $e) {
        $results['errors'][] = $e->getMessage();
    }
}

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
            $modified = false;
            if (strpos($ht, 'adstxtmanager.com') !== false || strpos($ht, 'Redirect 301 /ads.txt') !== false) {
                $ht = preg_replace('/Redirect 301 \/ads\.txt[^\r\n]*[\r\n]*/', '', $ht);
                $ht = preg_replace('/RewriteRule \^ads\\\.txt\$[^\r\n]*[\r\n]*/', '', $ht);
                file_put_contents($htPath, $ht);
                $modified = true;
            }
            $results['htaccess_cleaned'][] = [
                'path' => realpath($htPath) ?: $htPath,
                'modified' => $modified
            ];
        }
    } catch (Exception $e) {
        $results['errors'][] = $e->getMessage();
    }
}

echo json_encode(['status' => 'success', 'results' => $results], JSON_PRETTY_PRINT);
