<?php
echo "<h3>Force Git Update...</h3><pre>";
echo "Fetching from GitHub...\n";
system("git fetch origin main 2>&1");
echo "\nResetting to latest commit...\n";
system("git reset --hard origin/main 2>&1");
echo "\nDone! Website updated successfully.";
echo "</pre>";
?>
