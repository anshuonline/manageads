<?php
$j = json_decode(file_get_contents('https://raw.githubusercontent.com/TeamPiped/Piped-Instances/main/instances.json'), true);
$ch=curl_init('https://vid.puffyan.us/api/v1/videos/YALvuUpY_b0');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
echo substr(curl_exec($ch), 0, 100);
