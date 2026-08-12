<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

$projectRoot = dirname(__DIR__);
$options = getopt('', array('strict', 'store::', 'sitemap-dir::'));
$strict = array_key_exists('strict', $options);
$storeId = isset($options['store']) ? max(0, (int)$options['store']) : 0;
$sitemapDirectory = isset($options['sitemap-dir'])
    ? rtrim($options['sitemap-dir'], '/\\')
    : $projectRoot . '/upload/sitemaps';

require_once $projectRoot . '/upload/config.php';
define('MULTILINGUAL_SEO_LIBRARY_ONLY', true);
require_once $projectRoot . '/scripts/repair-multilingual-seo.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$database = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
$database->set_charset('utf8mb4');

try {
    $languages = mseoLanguages($database);
    $croatianId = mseoLanguageId($languages, 'hr');
    $englishId = mseoLanguageId($languages, 'en');

    if ($croatianId === 0 || $englishId === 0) {
        throw new RuntimeException('Both active Croatian and English languages are required.');
    }

    $metaPlan = mseoPlanProductMeta($database, $storeId, $croatianId, $englishId);
    $urlPlan = mseoPlanSeoUrls($database, $storeId, $languages, $croatianId, $englishId);
    $ocmodPlan = mseoPlanOcmodSync($database, $projectRoot);
    $duplicates = mseoDuplicateKeywordStats($database, $storeId);
    $sitemaps = mseoAuditSitemaps($sitemapDirectory, $storeId);

    $titleRepairs = 0;
    $descriptionRepairs = 0;
    foreach ($metaPlan as $change) {
        $titleRepairs += $change['repair_title'] ? 1 : 0;
        $descriptionRepairs += $change['repair_description'] ? 1 : 0;
    }

    echo 'Database SEO integrity (store ' . $storeId . ')' . PHP_EOL;
    echo '  Croatian product meta rows needing repair: ' . count($metaPlan) . PHP_EOL;
    echo '    titles: ' . $titleRepairs . PHP_EOL;
    echo '    descriptions: ' . $descriptionRepairs . PHP_EOL;
    echo '  shared HR/EN aliases: ' . count($urlPlan['updates']) . PHP_EOL;
    echo '  missing active entity aliases: ' . count($urlPlan['inserts']) . PHP_EOL;
    echo '  globally duplicated keywords: ' . $duplicates['groups'] . ' groups / ' . $duplicates['extra_rows'] . ' extra rows' . PHP_EOL;
    echo '  hreflang OCMOD: ' . $ocmodPlan['status'] . PHP_EOL;

    echo PHP_EOL . 'Generated sitemap integrity' . PHP_EOL;
    echo '  directory: ' . $sitemapDirectory . PHP_EOL;
    echo '  active files: ' . $sitemaps['files'] . PHP_EOL;
    echo '  parsed URLs: ' . $sitemaps['urls'] . PHP_EOL;
    echo '  unique URLs: ' . $sitemaps['unique_urls'] . PHP_EOL;
    echo '  duplicate entries: ' . $sitemaps['duplicate_entries'] . PHP_EOL;
    echo '  cross-language duplicate URLs: ' . $sitemaps['cross_language_duplicates'] . PHP_EOL;
    echo '  dynamic route URLs: ' . $sitemaps['dynamic_urls'] . PHP_EOL;
    echo '  URLs longer than 120 chars: ' . $sitemaps['long_urls'] . PHP_EOL;
    echo '  URLs longer than 160 chars: ' . $sitemaps['very_long_urls'] . PHP_EOL;
    echo '  malformed XML files: ' . $sitemaps['malformed_files'] . PHP_EOL;

    $issues = count($metaPlan)
        + count($urlPlan['updates'])
        + count($urlPlan['inserts'])
        + $duplicates['groups']
        + $sitemaps['duplicate_entries']
        + $sitemaps['dynamic_urls']
        + $sitemaps['malformed_files'];

    if ($ocmodPlan['status'] !== 'current') {
        $issues++;
    }

    if ($sitemaps['files'] === 0 || $sitemaps['urls'] === 0) {
        $issues++;
    }

    if ($strict && $issues > 0) {
        fwrite(STDERR, PHP_EOL . 'FAIL: SEO integrity issues remain.' . PHP_EOL);
        exit(1);
    }

    echo PHP_EOL . ($issues > 0 ? 'Audit completed with issues to repair.' : 'SEO integrity checks passed.') . PHP_EOL;
} finally {
    $database->close();
}

function mseoAuditSitemaps(string $directory, int $storeId): array
{
    $stats = array(
        'files' => 0,
        'urls' => 0,
        'unique_urls' => 0,
        'duplicate_entries' => 0,
        'cross_language_duplicates' => 0,
        'dynamic_urls' => 0,
        'long_urls' => 0,
        'very_long_urls' => 0,
        'malformed_files' => 0
    );

    if (!is_dir($directory)) {
        return $stats;
    }

    $files = glob($directory . '/sitemap_' . $storeId . '_*.xml');
    if (!$files) {
        return $stats;
    }

    $files = array_values(array_filter($files, static function (string $file): bool {
        $name = basename($file);

        if (!is_file($file) || filesize($file) === 0) {
            return false;
        }

        if (strpos($name, '_category_product') !== false || strpos($name, '_manufacturer_product') !== false) {
            return false;
        }

        if (!preg_match('/_\d+\.xml$/', $name)) {
            $paginated = glob(substr($file, 0, -4) . '_[0-9]*.xml');
            if ($paginated) {
                return false;
            }
        }

        return true;
    }));
    sort($files, SORT_NATURAL);
    $stats['files'] = count($files);
    $occurrences = array();

    foreach ($files as $file) {
        $languageId = mseoSitemapLanguageId(basename($file), $storeId);
        $reader = new XMLReader();
        $previousLibxmlMode = libxml_use_internal_errors(true);
        libxml_clear_errors();

        if (!$reader->open($file, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            $stats['malformed_files']++;
            libxml_clear_errors();
            libxml_use_internal_errors($previousLibxmlMode);
            continue;
        }

        $valid = true;
        try {
            while ($reader->read()) {
                if (
                    $reader->nodeType !== XMLReader::ELEMENT ||
                    $reader->localName !== 'loc' ||
                    $reader->namespaceURI !== 'http://www.sitemaps.org/schemas/sitemap/0.9'
                ) {
                    continue;
                }

                $url = trim($reader->readString());
                if ($url === '') {
                    continue;
                }

                $stats['urls']++;
                $occurrences[$url][] = $languageId;

                if (strpos($url, 'index.php?route=') !== false || strpos($url, 'index.php&amp;route=') !== false) {
                    $stats['dynamic_urls']++;
                }

                $length = strlen($url);
                if ($length > 120) {
                    $stats['long_urls']++;
                }
                if ($length > 160) {
                    $stats['very_long_urls']++;
                }
            }
        } catch (Throwable $exception) {
            $valid = false;
        } finally {
            $reader->close();
        }

        if (!$valid || libxml_get_errors()) {
            $stats['malformed_files']++;
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlMode);
    }

    $stats['unique_urls'] = count($occurrences);
    $stats['duplicate_entries'] = max(0, $stats['urls'] - $stats['unique_urls']);

    foreach ($occurrences as $languageIds) {
        $languageIds = array_values(array_unique(array_filter($languageIds)));
        if (count($languageIds) > 1) {
            $stats['cross_language_duplicates']++;
        }
    }

    return $stats;
}

function mseoSitemapLanguageId(string $fileName, int $storeId): int
{
    if (preg_match('/^sitemap_' . preg_quote((string)$storeId, '/') . '_(\d+)_/', $fileName, $matches)) {
        return (int)$matches[1];
    }

    return 0;
}
