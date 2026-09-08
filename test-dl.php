<?php
$ch = curl_init("https://manageads.ganatube.in/python-proxy.php?videoId=TR6u3vBESI0&download=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$data = curl_exec($ch);
echo "Size: " . strlen($data) . "\n";
echo "First 100 bytes: " . substr($data, 0, 100) . "\n";
