<?php
header("Access-Control-Allow-Origin: *");
header("X-Frame-Options: ALLOWALL");

require_once __DIR__ . "/config.php";
if ($conn->connect_error) {
    die("Error");
}

$placeholder = $_GET['placeholder'] ?? 'bottom_player_banner';
$stmt = $conn->prepare("SELECT * FROM ads WHERE placeholder_id = ?");
$stmt->bind_param("s", $placeholder);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    
    if (!$row['is_active']) {
        exit;
    }
    
    echo "<!DOCTYPE html>\n";
    echo "<html lang=\"en\">\n";
    echo "<head>\n";
    echo "<meta charset=\"UTF-8\">\n";
    echo "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no\">\n";
    echo "<style>\n";
    echo "* { box-sizing: border-box; margin: 0; padding: 0; }\n";
    echo "html, body {\n";
    echo "  width: 100%;\n";
    echo "  height: 100%;\n";
    echo "  margin: 0;\n";
    echo "  padding: 0;\n";
    echo "  overflow: hidden;\n";
    echo "  background: transparent;\n";
    echo "  display: flex;\n";
    echo "  align-items: center;\n";
    echo "  justify-content: center;\n";
    echo "}\n";
    echo "#ad-stage {\n";
    echo "  width: 100%;\n";
    echo "  height: 100%;\n";
    echo "  display: flex;\n";
    echo "  align-items: center;\n";
    echo "  justify-content: center;\n";
    echo "  overflow: hidden;\n";
    echo "  position: relative;\n";
    echo "}\n";
    echo "#ad-content {\n";
    echo "  display: inline-flex;\n";
    echo "  align-items: center;\n";
    echo "  justify-content: center;\n";
    echo "  transform-origin: center center;\n";
    echo "  max-width: none;\n";
    echo "  max-height: none;\n";
    echo "  flex-shrink: 0;\n";
    echo "  transition: transform 0.1s ease-out;\n";
    echo "}\n";
    echo "#ad-content > * {\n";
    echo "  margin: auto !important;\n";
    echo "}\n";
    echo "img {\n";
    echo "  max-width: 100%;\n";
    echo "  max-height: 100%;\n";
    echo "  object-fit: contain;\n";
    echo "  display: block;\n";
    echo "}\n";
    echo "</style>\n";
    echo "</head>\n";
    echo "<body>\n";
    echo "<div id=\"ad-stage\">\n";
    echo "<div id=\"ad-content\">\n";
    
    if (!empty($row['custom_code'])) {
        echo $row['custom_code'];
    } else if (!empty($row['image_path'])) {
        $link = htmlspecialchars($row['link_url'] ?? '#');
        $img = htmlspecialchars($row['image_path']);
        // Need absolute url for image if local
        if (!filter_var($img, FILTER_VALIDATE_URL)) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $baseDir = dirname($_SERVER['PHP_SELF']);
            $img = $protocol . "://" . $host . $baseDir . "/" . ltrim($img, '/');
        }
        echo "<a href='$link' target='_blank' rel='noopener noreferrer' style='display:flex;align-items:center;justify-content:center;width:100%;height:100%;text-decoration:none;'>";
        echo "<img src='$img' style='max-width:100%;max-height:100%;object-fit:contain;border-radius:8px;' alt='Sponsored Ad'>";
        echo "</a>";
    }
    
    echo "\n</div>\n";
    echo "</div>\n";

    echo "<script>\n";
    echo "var isAdjusting = false;\n";
    echo "function fitAdToViewport() {\n";
    echo "  if (isAdjusting) return;\n";
    echo "  isAdjusting = true;\n";
    echo "  try {\n";
    echo "    var stage = document.getElementById('ad-stage') || document.body;\n";
    echo "    var content = document.getElementById('ad-content') || document.body;\n";
    echo "    if (!stage || !content) return;\n";
    echo "    content.style.transform = 'none';\n";
    echo "    var naturalW = 0;\n";
    echo "    var naturalH = 0;\n";
    echo "    var children = content.children;\n";
    echo "    for (var i = 0; i < children.length; i++) {\n";
    echo "      var c = children[i];\n";
    echo "      var cw = c.offsetWidth || c.scrollWidth || 0;\n";
    echo "      var ch = c.offsetHeight || c.scrollHeight || 0;\n";
    echo "      if (cw > naturalW) naturalW = cw;\n";
    echo "      if (ch > naturalH) naturalH = ch;\n";
    echo "    }\n";
    echo "    var inners = content.querySelectorAll('iframe, ins, table, div, img, a');\n";
    echo "    for (var j = 0; j < inners.length; j++) {\n";
    echo "      var el = inners[j];\n";
    echo "      var attrW = parseInt(el.getAttribute('width') || el.style.width || '0', 10);\n";
    echo "      var attrH = parseInt(el.getAttribute('height') || el.style.height || '0', 10);\n";
    echo "      var rect = el.getBoundingClientRect();\n";
    echo "      var mw = Math.max(attrW, rect.width, el.offsetWidth || 0, el.scrollWidth || 0);\n";
    echo "      var mh = Math.max(attrH, rect.height, el.offsetHeight || 0, el.scrollHeight || 0);\n";
    echo "      if (mw > naturalW) naturalW = mw;\n";
    echo "      if (mh > naturalH) naturalH = mh;\n";
    echo "    }\n";
    echo "    if (naturalW === 0) naturalW = content.scrollWidth || content.offsetWidth || 0;\n";
    echo "    if (naturalH === 0) naturalH = content.scrollHeight || content.offsetHeight || 0;\n";
    echo "    var stageW = stage.clientWidth || window.innerWidth;\n";
    echo "    var stageH = stage.clientHeight || window.innerHeight;\n";
    echo "    if (naturalW > 0 && stageW > 0) {\n";
    echo "      var scaleX = stageW / naturalW;\n";
    echo "      var scaleY = (stageH > 0 && naturalH > stageH) ? (stageH / naturalH) : 1;\n";
    echo "      var scale = Math.min(scaleX, scaleY);\n";
    echo "      if (scale < 0.99) {\n";
    echo "        scale = Math.floor(scale * 10000) / 10000;\n";
    echo "        content.style.transform = 'scale(' + scale + ')';\n";
    echo "        content.style.transformOrigin = 'center center';\n";
    echo "      } else {\n";
    echo "        content.style.transform = 'none';\n";
    echo "      }\n";
    echo "    }\n";
    echo "  } finally {\n";
    echo "    setTimeout(function() { isAdjusting = false; }, 50);\n";
    echo "  }\n";
    echo "}\n";
    echo "window.addEventListener('load', fitAdToViewport);\n";
    echo "window.addEventListener('resize', fitAdToViewport);\n";
    echo "document.addEventListener('DOMContentLoaded', fitAdToViewport);\n";
    echo "setTimeout(fitAdToViewport, 300);\n";
    echo "</script>\n";
    echo "</body>\n";
    echo "</html>\n";
}

$stmt->close();
$conn->close();
?>
