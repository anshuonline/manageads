<?php require 'config.php'; $conn->query('UPDATE search_analytics_log SET searched_at = DATE_ADD(searched_at, INTERVAL 5 HOUR + 30 MINUTE)'); echo 'Time fixed!'; ?>
