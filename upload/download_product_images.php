<?php
/**
 * OpenCart 3 - Download remote product images and convert DB to local relative paths
 * Run: php download_product_images.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

$root = __DIR__;

// --- Load OpenCart config.php to get DB creds ---
if (!file_exists($root . '/config.php')) {
    fwrite(STDERR, "ERROR: config.php not found in $root\n");
    exit(1);
}
require $root . '/config.php';

// OpenCart config.php defines: DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT (optional), DB_PREFIX
$dbHost = defined('DB_HOSTNAME') ? DB_HOSTNAME : 'localhost';
$dbUser = defined('DB_USERNAME') ? DB_USERNAME : '';
$dbPass = defined('DB_PASSWORD') ? DB_PASSWORD : '';
$dbName = defined('DB_DATABASE') ? DB_DATABASE : '';
$dbPort = defined('DB_PORT') ? (int)DB_PORT : 3306;
$dbPref = defined('DB_PREFIX') ? DB_PREFIX : 'oc_';

if (!$dbUser || !$dbName) {
    fwrite(STDERR, "ERROR: DB credentials not found in config.php\n");
    exit(1);
}

// --- Where to store downloaded images ---
$targetDirRel = 'catalog/imported';                       // stored in /image/catalog/imported
$targetDirAbs = $root . '/image/' . $targetDirRel;

// Create folder if missing
if (!is_dir($targetDirAbs) && !mkdir($targetDirAbs, 0755, true)) {
    fwrite(STDERR, "ERROR: Cannot create directory $targetDirAbs\n");
    exit(1);
}

// Optional: only download URLs from this domain (safer)
$allowedHost = ''; // e.g. 'b2b.weelko.com' or leave '' for any

// --- DB connect ---
$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
if ($mysqli->connect_error) {
    fwrite(STDERR, "DB connect error: " . $mysqli->connect_error . "\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

function isRemoteUrl($s) {
    return is_string($s) && (stripos($s, 'http://') === 0 || stripos($s, 'https://') === 0);
}

function safeFilenameFromUrl($url) {
    $path = parse_url($url, PHP_URL_PATH) ?? '';
    $name = basename($path);
    // fallback
    if (!$name || $name === '/' || $name === '.') {
        $name = 'img_' . md5($url) . '.jpg';
    }
    // remove weird chars
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    return $name;
}

function httpGetBinary($url) {
    // Prefer cURL (reliable)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'OC3-ImageDownloader/1.0',
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($data === false || $code >= 400) {
            return [null, "HTTP $code $err"];
        }
        return [$data, null];
    }

    // Fallback: file_get_contents (requires allow_url_fopen)
    $ctx = stream_context_create([
        'http' => ['timeout' => 45, 'follow_location' => 1, 'user_agent' => 'OC3-ImageDownloader/1.0'],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) {
        return [null, "file_get_contents failed"];
    }
    return [$data, null];
}

function looksLikeImage($bytes) {
    if ($bytes === null || strlen($bytes) < 12) return false;
    $sig = substr($bytes, 0, 12);
    // JPEG, PNG, GIF, WEBP basic checks
    if (strncmp($sig, "\xFF\xD8\xFF", 3) === 0) return true;                 // jpg
    if (strncmp($sig, "\x89PNG\r\n\x1A\n", 8) === 0) return true;            // png
    if (strncmp($sig, "GIF8", 4) === 0) return true;                         // gif
    if (strncmp($sig, "RIFF", 4) === 0 && strpos($sig, "WEBP") !== false) return true; // webp
    return true; // some servers don't return standard headers; allow anyway
}

function ensureUniquePath($absPath) {
    if (!file_exists($absPath)) return $absPath;
    $dir = dirname($absPath);
    $base = pathinfo($absPath, PATHINFO_FILENAME);
    $ext  = pathinfo($absPath, PATHINFO_EXTENSION);
    $i = 1;
    do {
        $candidate = $dir . '/' . $base . '_' . $i . ($ext ? '.' . $ext : '');
        $i++;
    } while (file_exists($candidate));
    return $candidate;
}

function processTableColumn($mysqli, $table, $idCol, $imgCol, $dbPref, $targetDirAbs, $targetDirRel, $allowedHost) {
    $fullTable = $dbPref . $table;
    $sql = "SELECT $idCol, $imgCol FROM `$fullTable` WHERE $imgCol LIKE 'http://%' OR $imgCol LIKE 'https://%'";
    $res = $mysqli->query($sql);
    if (!$res) {
        fwrite(STDERR, "ERROR query $fullTable: " . $mysqli->error . "\n");
        return [0, 0];
    }

    $found = 0;
    $done  = 0;

    while ($row = $res->fetch_assoc()) {
        $found++;
        $id  = $row[$idCol];
        $url = $row[$imgCol];

        $host = parse_url($url, PHP_URL_HOST) ?? '';
        if ($allowedHost && strcasecmp($host, $allowedHost) !== 0) {
            echo "SKIP [$fullTable:$id] host=$host (allowed=$allowedHost)\n";
            continue;
        }

        $filename = safeFilenameFromUrl($url);
        // Put into a hashed subfolder to avoid too many files in one folder (optional)
        $sub = substr(md5($url), 0, 2);
        $subAbs = $targetDirAbs . '/' . $sub;
        if (!is_dir($subAbs)) {
            @mkdir($subAbs, 0755, true);
        }

        $saveAbs = $subAbs . '/' . $filename;
        $saveAbs = ensureUniquePath($saveAbs);

        // If already downloaded earlier in run, skip download if file exists
        if (!file_exists($saveAbs)) {
            [$bytes, $err] = httpGetBinary($url);
            if ($bytes === null) {
                echo "FAIL  [$fullTable:$id] $url -> $err\n";
                continue;
            }
            if (!looksLikeImage($bytes)) {
                echo "FAIL  [$fullTable:$id] $url -> not an image\n";
                continue;
            }
            if (file_put_contents($saveAbs, $bytes) === false) {
                echo "FAIL  [$fullTable:$id] cannot write $saveAbs\n";
                continue;
            }
        }

        // Relative path for OpenCart DB is from /image/ (e.g. catalog/imported/ab/file.jpg)
        $saveRel = $targetDirRel . '/' . basename(dirname($saveAbs)) . '/' . basename($saveAbs);

        // Update DB
        $stmt = $mysqli->prepare("UPDATE `$fullTable` SET `$imgCol`=? WHERE `$idCol`=?");
        if (!$stmt) {
            echo "FAIL  [$fullTable:$id] prepare error: " . $mysqli->error . "\n";
            continue;
        }
        $stmt->bind_param('si', $saveRel, $id);
        if (!$stmt->execute()) {
            echo "FAIL  [$fullTable:$id] update error: " . $stmt->error . "\n";
            $stmt->close();
            continue;
        }
        $stmt->close();

        echo "OK    [$fullTable:$id] $url -> $saveRel\n";
        $done++;
    }

    $res->free();
    return [$found, $done];
}

// --- Run ---
echo "Target folder: $targetDirAbs\n";
if ($allowedHost) echo "Allowed host: $allowedHost\n";

$totalFound = 0; $totalDone = 0;

// Main image (product)
[$f1, $d1] = processTableColumn($mysqli, 'product', 'product_id', 'image', $dbPref, $targetDirAbs, $targetDirRel, $allowedHost);
$totalFound += $f1; $totalDone += $d1;

// Additional images (product_image)
[$f2, $d2] = processTableColumn($mysqli, 'product_image', 'product_image_id', 'image', $dbPref, $targetDirAbs, $targetDirRel, $allowedHost);
$totalFound += $f2; $totalDone += $d2;

echo "\nDONE. Found URLs: $totalFound, converted: $totalDone\n";
echo "Now clear OC cache: Admin -> Dashboard (gear) -> Refresh + Modifications -> Refresh.\n";