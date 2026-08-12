<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

if (!defined('MULTILINGUAL_SEO_LIBRARY_ONLY')) {
    mseoMain($argv);
}

function mseoMain(array $argv): void
{
    $options = getopt('', array('apply', 'store::', 'no-ocmod', 'restore:'));
    $apply = array_key_exists('apply', $options);
    $storeId = isset($options['store']) ? max(0, (int)$options['store']) : 0;
    $syncOcmod = !array_key_exists('no-ocmod', $options);
    $projectRoot = dirname(__DIR__);
    $configPath = $projectRoot . '/upload/config.php';

    if (!is_file($configPath)) {
        throw new RuntimeException('Missing OpenCart config: ' . $configPath);
    }

    require_once $configPath;
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $database = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int)DB_PORT);
    $database->set_charset('utf8mb4');
    $lockName = 'multilingual-seo-repair:' . DB_DATABASE . ':' . $storeId;

    try {
        if (!mseoAcquireLock($database, $lockName)) {
            throw new RuntimeException('Another multilingual SEO repair is already running.');
        }

        if (isset($options['restore'])) {
            $backup = mseoReadRecoveryFile((string)$options['restore']);
            mseoRestoreBackup($database, $backup);
            echo 'Restored multilingual SEO data from: ' . $options['restore'] . PHP_EOL;
            return;
        }

        $languages = mseoLanguages($database);
        $croatianId = mseoLanguageId($languages, 'hr');
        $englishId = mseoLanguageId($languages, 'en');

        if ($croatianId === 0 || $englishId === 0) {
            throw new RuntimeException('Both active Croatian and English languages are required.');
        }

        $metaPlan = mseoPlanProductMeta($database, $storeId, $croatianId, $englishId);
        $urlPlan = mseoPlanSeoUrls($database, $storeId, $languages, $croatianId, $englishId);
        $ocmodPlan = $syncOcmod ? mseoPlanOcmodSync($database, $projectRoot) : array('status' => 'skipped');

        mseoPrintPlan($apply, $storeId, $languages, $metaPlan, $urlPlan, $ocmodPlan);

        if (!$apply) {
            echo PHP_EOL . 'No database changes made. Re-run with --apply after reviewing this preview.' . PHP_EOL;
            return;
        }

        $backupPath = mseoWriteRecoveryFile($projectRoot, $storeId, $metaPlan, $urlPlan, $ocmodPlan);
        echo PHP_EOL . 'Recovery backup: ' . $backupPath . PHP_EOL;

        try {
            mseoApplyProductMeta($database, $metaPlan);
            mseoApplySeoUrls($database, $urlPlan);

            if ($syncOcmod) {
                mseoApplyOcmodSync($database, $ocmodPlan);
            }

            $remainingMeta = mseoPlanProductMeta($database, $storeId, $croatianId, $englishId);
            $remainingUrls = mseoPlanSeoUrls($database, $storeId, $languages, $croatianId, $englishId);
            $remainingDuplicates = mseoDuplicateKeywordStats($database, $storeId);
            $ocmodVerified = !$syncOcmod || mseoPlanOcmodSync($database, $projectRoot)['status'] === 'current';

            if (
                $remainingMeta ||
                $remainingUrls['updates'] ||
                $remainingUrls['inserts'] ||
                $remainingDuplicates['groups'] > 0 ||
                !$ocmodVerified
            ) {
                throw new RuntimeException(
                    'Post-apply verification found remaining SEO integrity issues: ' .
                    count($remainingMeta) . ' meta rows, ' .
                    count($remainingUrls['updates']) . ' shared aliases, ' .
                    count($remainingUrls['inserts']) . ' missing aliases, ' .
                    $remainingDuplicates['groups'] . ' duplicate keyword groups, hreflang OCMOD ' .
                    ($ocmodVerified ? 'current' : 'not current') . '.'
                );
            }

        } catch (Throwable $exception) {
            try {
                mseoRestoreBackup($database, mseoReadRecoveryFile($backupPath));
            } catch (Throwable $restoreException) {
                throw new RuntimeException(
                    'Apply failed and automatic restore also failed. Run --restore=' . $backupPath .
                    '. Apply error: ' . $exception->getMessage() .
                    '. Restore error: ' . $restoreException->getMessage(),
                    0,
                    $exception
                );
            }

            throw new RuntimeException('Apply failed; the recovery backup was restored. ' . $exception->getMessage(), 0, $exception);
        }

        echo PHP_EOL . 'Applied all planned multilingual SEO repairs.' . PHP_EOL;

        if ($syncOcmod && $ocmodPlan['status'] === 'update') {
            echo 'Next: refresh OpenCart Extensions > Modifications so the hreflang runtime is rebuilt.' . PHP_EOL;
        }

        echo 'Next: regenerate Boost Sitemap files, then run scripts/audit-seo-integrity.php --strict.' . PHP_EOL;
    } finally {
        try {
            mseoReleaseLock($database, $lockName);
        } catch (Throwable $ignored) {
        }
        $database->close();
    }
}

function mseoLanguages(mysqli $database): array
{
    $languages = array();
    $result = $database->query(
        "SELECT `language_id`, `code`, `name`, `sort_order` FROM `" . DB_PREFIX . "language` " .
        "WHERE `status` = 1 ORDER BY `sort_order`, `language_id`"
    );

    while ($row = $result->fetch_assoc()) {
        $languages[(int)$row['language_id']] = $row;
    }

    return $languages;
}

function mseoLanguageId(array $languages, string $prefix): int
{
    foreach ($languages as $languageId => $language) {
        if (strpos(strtolower($language['code']), strtolower($prefix)) === 0) {
            return (int)$languageId;
        }
    }

    return 0;
}

function mseoPlanProductMeta(mysqli $database, int $storeId, int $croatianId, int $englishId): array
{
    $table = DB_PREFIX . 'product_description';
    $result = $database->query(
        "SELECT hr.`product_id`, hr.`name` AS hr_name, hr.`description` AS hr_description, " .
        "hr.`meta_title` AS hr_meta_title, hr.`meta_description` AS hr_meta_description, " .
        "en.`name` AS en_name, en.`description` AS en_description, " .
        "en.`meta_title` AS en_meta_title, en.`meta_description` AS en_meta_description, " .
        "hrl.`code` AS hr_language_code, enl.`code` AS en_language_code, " .
        "COALESCE(m.`name`, '') AS manufacturer_name " .
        "FROM `{$table}` hr " .
        "JOIN `{$table}` en ON en.`product_id` = hr.`product_id` AND en.`language_id` = '{$englishId}' " .
        "JOIN `" . DB_PREFIX . "language` hrl ON hrl.`language_id` = hr.`language_id` AND hrl.`status` = 1 " .
        "JOIN `" . DB_PREFIX . "language` enl ON enl.`language_id` = en.`language_id` AND enl.`status` = 1 " .
        "JOIN `" . DB_PREFIX . "product` p ON p.`product_id` = hr.`product_id` AND p.`status` = 1 " .
        "JOIN `" . DB_PREFIX . "product_to_store` p2s ON p2s.`product_id` = p.`product_id` AND p2s.`store_id` = '{$storeId}' " .
        "LEFT JOIN `" . DB_PREFIX . "manufacturer` m ON m.`manufacturer_id` = p.`manufacturer_id` " .
        "WHERE hr.`language_id` = '{$croatianId}' ORDER BY hr.`product_id`"
    );
    $plan = array();

    while ($row = $result->fetch_assoc()) {
        $nameDiffers = mseoComparable($row['hr_name']) !== mseoComparable($row['en_name']);
        $titleBroken = mseoBrokenMeta($row['hr_meta_title']);
        $titleLooksEnglish = $nameDiffers && in_array(
            mseoComparable($row['hr_meta_title']),
            array(
                mseoComparable($row['en_meta_title']),
                mseoComparable($row['en_name']),
                mseoComparable($row['en_name'] . ' | World of Beauty')
            ),
            true
        );
        $descriptionCandidates = array(
            mseoComparable($row['en_meta_description']),
            mseoComparable($row['en_name']),
            mseoComparable($row['hr_name']),
            mseoComparable($row['hr_meta_title'])
        );
        $descriptionBroken = mseoBrokenMeta($row['hr_meta_description']) || in_array(
            mseoComparable($row['hr_meta_description']),
            $descriptionCandidates,
            true
        );
        $descriptionDiffers = mseoComparable($row['hr_description']) !== mseoComparable($row['en_description']);
        $forceEnglishDescription = false;
        $repairTitle = $titleBroken || $titleLooksEnglish || mseoTextLength($row['hr_meta_title']) > 65;
        $repairDescription = $descriptionBroken || (
            $descriptionDiffers && mseoComparable($row['hr_meta_description']) === mseoComparable($row['en_meta_description'])
        ) || mseoTextLength($row['hr_meta_description']) > 160;

        if (!$repairTitle && !$repairDescription) {
            // English metadata is checked independently below.
        } else {
            $title = $repairTitle
                ? mseoProductTitle($row['hr_name'], $row['manufacturer_name'])
                : $row['hr_meta_title'];
            $description = $row['hr_meta_description'];
            if ($repairDescription) {
                $description = $descriptionBroken || (
                    $descriptionDiffers && mseoComparable($row['hr_meta_description']) === mseoComparable($row['en_meta_description'])
                )
                    ? mseoProductDescription($row['hr_name'], $row['hr_description'], true)
                    : mseoTruncate($row['hr_meta_description'], 160);
                if ($description === $row['hr_meta_description']) {
                    $description = mseoProductDescription($row['hr_name'], '', true);
                }
                if (mseoComparable($description) === mseoComparable($row['en_meta_description'])) {
                    $forceEnglishDescription = true;
                }
            }

            $repairTitle = $repairTitle && $title !== $row['hr_meta_title'];
            $repairDescription = $repairDescription && $description !== $row['hr_meta_description'];

            if ($repairTitle || $repairDescription) {
                $plan[$row['product_id'] . ':' . $croatianId] = array(
                    'product_id' => (int)$row['product_id'],
                    'language_id' => $croatianId,
                    'language_code' => $row['hr_language_code'],
                    'name' => $row['hr_name'],
                    'meta_title' => $title,
                    'meta_description' => $description,
                    'old_meta_title' => $row['hr_meta_title'],
                    'old_meta_description' => $row['hr_meta_description'],
                    'repair_title' => $repairTitle,
                    'repair_description' => $repairDescription
                );
            }
        }

        $englishTitleBroken = mseoBrokenMeta($row['en_meta_title']);
        $englishTitleLooksCroatian = $nameDiffers && in_array(
            mseoComparable($row['en_meta_title']),
            array(
                mseoComparable($row['hr_meta_title']),
                mseoComparable($row['hr_name']),
                mseoComparable($row['hr_name'] . ' | World of Beauty')
            ),
            true
        );
        $englishDescriptionBroken = mseoBrokenMeta($row['en_meta_description']) || in_array(
            mseoComparable($row['en_meta_description']),
            array(mseoComparable($row['en_name']), mseoComparable($row['en_meta_title'])),
            true
        );
        $repairEnglishTitle = $englishTitleBroken || $englishTitleLooksCroatian || mseoTextLength($row['en_meta_title']) > 65;
        $repairEnglishDescription = $englishDescriptionBroken || (
            $descriptionDiffers && mseoComparable($row['en_meta_description']) === mseoComparable($row['hr_meta_description'])
        ) || mseoTextLength($row['en_meta_description']) > 160 || $forceEnglishDescription;

        if (!$repairEnglishTitle && !$repairEnglishDescription) {
            continue;
        }

        $englishDescription = $row['en_meta_description'];
        if ($repairEnglishDescription) {
            $englishDescription = $forceEnglishDescription
                ? mseoProductDescription($row['en_name'], '', false)
                : ($englishDescriptionBroken || (
                $descriptionDiffers && mseoComparable($row['en_meta_description']) === mseoComparable($row['hr_meta_description'])
                )
                    ? mseoProductDescription($row['en_name'], $row['en_description'], false)
                    : mseoTruncate($row['en_meta_description'], 160));
            if ($englishDescription === $row['en_meta_description']) {
                $englishDescription = mseoProductDescription($row['en_name'], '', false);
            }
        }

        if (
            $repairEnglishDescription &&
            mseoComparable($englishDescription) === mseoComparable($row['hr_meta_description'])
        ) {
            $hrKey = $row['product_id'] . ':' . $croatianId;
            $hrFallback = mseoProductDescription($row['hr_name'], '', true);
            if (isset($plan[$hrKey])) {
                $plan[$hrKey]['meta_description'] = $hrFallback;
                $plan[$hrKey]['repair_description'] = $hrFallback !== $row['hr_meta_description'];
            } elseif ($hrFallback !== $row['hr_meta_description']) {
                $plan[$hrKey] = array(
                    'product_id' => (int)$row['product_id'],
                    'language_id' => $croatianId,
                    'language_code' => $row['hr_language_code'],
                    'name' => $row['hr_name'],
                    'meta_title' => $row['hr_meta_title'],
                    'meta_description' => $hrFallback,
                    'old_meta_title' => $row['hr_meta_title'],
                    'old_meta_description' => $row['hr_meta_description'],
                    'repair_title' => false,
                    'repair_description' => true
                );
            }
        }

        $englishTitle = $repairEnglishTitle
            ? mseoProductTitle($row['en_name'], $row['manufacturer_name'])
            : $row['en_meta_title'];
        $repairEnglishTitle = $repairEnglishTitle && $englishTitle !== $row['en_meta_title'];
        $repairEnglishDescription = $repairEnglishDescription && $englishDescription !== $row['en_meta_description'];

        if (!$repairEnglishTitle && !$repairEnglishDescription) {
            continue;
        }

        $plan[$row['product_id'] . ':' . $englishId] = array(
            'product_id' => (int)$row['product_id'],
            'language_id' => $englishId,
            'language_code' => $row['en_language_code'],
            'name' => $row['en_name'],
            'meta_title' => $englishTitle,
            'meta_description' => $englishDescription,
            'old_meta_title' => $row['en_meta_title'],
            'old_meta_description' => $row['en_meta_description'],
            'repair_title' => $repairEnglishTitle,
            'repair_description' => $repairEnglishDescription
        );
    }

    $allLanguages = $database->query(
        "SELECT pd.`product_id`, pd.`language_id`, l.`code` AS language_code, pd.`name`, pd.`description`, " .
        "pd.`meta_title`, pd.`meta_description`, COALESCE(m.`name`, '') AS manufacturer_name " .
        "FROM `{$table}` pd " .
        "JOIN `" . DB_PREFIX . "language` l ON l.`language_id` = pd.`language_id` AND l.`status` = 1 " .
        "JOIN `" . DB_PREFIX . "product` p ON p.`product_id` = pd.`product_id` AND p.`status` = 1 " .
        "JOIN `" . DB_PREFIX . "product_to_store` p2s ON p2s.`product_id` = p.`product_id` AND p2s.`store_id` = '{$storeId}' " .
        "LEFT JOIN `" . DB_PREFIX . "manufacturer` m ON m.`manufacturer_id` = p.`manufacturer_id` " .
        "ORDER BY pd.`product_id`, pd.`language_id`"
    );

    while ($row = $allLanguages->fetch_assoc()) {
        $key = $row['product_id'] . ':' . $row['language_id'];
        if (isset($plan[$key])) {
            continue;
        }

        $titleBroken = mseoBrokenMeta($row['meta_title']);
        $descriptionBroken = mseoBrokenMeta($row['meta_description']) || in_array(
            mseoComparable($row['meta_description']),
            array(mseoComparable($row['name']), mseoComparable($row['meta_title'])),
            true
        );
        $repairTitle = $titleBroken || mseoTextLength($row['meta_title']) > 65;
        $repairDescription = $descriptionBroken || mseoTextLength($row['meta_description']) > 160;

        if (!$repairTitle && !$repairDescription) {
            continue;
        }

        $isCroatian = strpos(strtolower($row['language_code']), 'hr') === 0;
        $title = $repairTitle
            ? mseoProductTitle($row['name'], $row['manufacturer_name'])
            : $row['meta_title'];
        $description = $repairDescription
            ? ($descriptionBroken
                ? mseoProductDescription($row['name'], $row['description'], $isCroatian)
                : mseoTruncate($row['meta_description'], 160))
            : $row['meta_description'];
        if ($repairDescription && $description === $row['meta_description']) {
            $description = mseoProductDescription($row['name'], '', $isCroatian);
        }
        $repairTitle = $repairTitle && $title !== $row['meta_title'];
        $repairDescription = $repairDescription && $description !== $row['meta_description'];

        if (!$repairTitle && !$repairDescription) {
            continue;
        }

        $plan[$key] = array(
            'product_id' => (int)$row['product_id'],
            'language_id' => (int)$row['language_id'],
            'language_code' => $row['language_code'],
            'name' => $row['name'],
            'meta_title' => $title,
            'meta_description' => $description,
            'old_meta_title' => $row['meta_title'],
            'old_meta_description' => $row['meta_description'],
            'repair_title' => $repairTitle,
            'repair_description' => $repairDescription
        );
    }

    ksort($plan, SORT_NATURAL);
    return array_values($plan);
}

function mseoPlanSeoUrls(
    mysqli $database,
    int $storeId,
    array $languages,
    int $croatianId,
    int $englishId
): array {
    $seoTable = DB_PREFIX . 'seo_url';
    $occupied = array();
    $allRows = $database->query(
        "SELECT `seo_url_id`, `language_id`, `query`, `keyword` FROM `{$seoTable}` " .
        "WHERE `store_id` = '{$storeId}' AND `keyword` <> '' ORDER BY `seo_url_id`"
    );

    while ($row = $allRows->fetch_assoc()) {
        $occupied[$row['keyword']][(int)$row['seo_url_id']] = true;
    }

    $updates = array();
    $inserts = array();
    $shared = $database->query(
        "SELECT en.`seo_url_id`, en.`query`, en.`keyword` " .
        "FROM `{$seoTable}` hr JOIN `{$seoTable}` en " .
        "ON en.`store_id` = hr.`store_id` AND en.`query` = hr.`query` AND en.`keyword` = hr.`keyword` " .
        "WHERE hr.`store_id` = '{$storeId}' AND hr.`language_id` = '{$croatianId}' " .
        "AND en.`language_id` = '{$englishId}' " .
        "AND (hr.`query` LIKE 'product_id=%' OR hr.`query` LIKE 'category_id=%' " .
        "OR hr.`query` LIKE 'manufacturer_id=%' OR hr.`query` LIKE 'information_id=%') " .
        "ORDER BY en.`seo_url_id`"
    );

    while ($row = $shared->fetch_assoc()) {
        $seoUrlId = (int)$row['seo_url_id'];
        mseoReleaseKeyword($occupied, $row['keyword'], $seoUrlId);
        $keyword = mseoUniqueKeyword($occupied, $row['keyword'], 'en', mseoQueryId($row['query']));
        mseoOccupyKeyword($occupied, $keyword, $seoUrlId);
        $updates[] = array(
            'seo_url_id' => $seoUrlId,
            'query' => $row['query'],
            'language_id' => $englishId,
            'old_keyword' => $row['keyword'],
            'keyword' => $keyword
        );
    }

    foreach ($languages as $languageId => $language) {
        $languageId = (int)$languageId;
        $languageSuffix = substr(strtolower($language['code']), 0, 2);
        $entities = array(
            array(
                'type' => 'product',
                'id' => 'p.product_id',
                'name' => 'pd.name',
                'from' => "`" . DB_PREFIX . "product` p " .
                    "JOIN `" . DB_PREFIX . "product_to_store` p2s ON p2s.product_id = p.product_id AND p2s.store_id = '{$storeId}' " .
                    "JOIN `" . DB_PREFIX . "product_description` pd ON pd.product_id = p.product_id AND pd.language_id = '{$languageId}'",
                'where' => 'p.status = 1 AND p.date_available <= NOW()',
                'query_prefix' => 'product_id='
            ),
            array(
                'type' => 'category',
                'id' => 'c.category_id',
                'name' => 'cd.name',
                'from' => "`" . DB_PREFIX . "category` c " .
                    "JOIN `" . DB_PREFIX . "category_to_store` c2s ON c2s.category_id = c.category_id AND c2s.store_id = '{$storeId}' " .
                    "JOIN `" . DB_PREFIX . "category_description` cd ON cd.category_id = c.category_id AND cd.language_id = '{$languageId}'",
                'where' => 'c.status = 1',
                'query_prefix' => 'category_id='
            ),
            array(
                'type' => 'information',
                'id' => 'i.information_id',
                'name' => 'id.title',
                'from' => "`" . DB_PREFIX . "information` i " .
                    "JOIN `" . DB_PREFIX . "information_to_store` i2s ON i2s.information_id = i.information_id AND i2s.store_id = '{$storeId}' " .
                    "JOIN `" . DB_PREFIX . "information_description` id ON id.information_id = i.information_id AND id.language_id = '{$languageId}'",
                'where' => 'i.status = 1',
                'query_prefix' => 'information_id='
            ),
            array(
                'type' => 'manufacturer',
                'id' => 'm.manufacturer_id',
                'name' => 'm.name',
                'from' => "`" . DB_PREFIX . "manufacturer` m " .
                    "JOIN `" . DB_PREFIX . "manufacturer_to_store` m2s " .
                    "ON m2s.manufacturer_id = m.manufacturer_id AND m2s.store_id = '{$storeId}'",
                'where' => '1 = 1',
                'query_prefix' => 'manufacturer_id='
            )
        );

        foreach ($entities as $entity) {
            $missing = $database->query(
                "SELECT {$entity['id']} AS entity_id, {$entity['name']} AS entity_name " .
                "FROM {$entity['from']} LEFT JOIN `{$seoTable}` su " .
                "ON su.store_id = '{$storeId}' AND su.language_id = '{$languageId}' " .
                "AND su.query = CONCAT('{$entity['query_prefix']}', {$entity['id']}) " .
                "WHERE {$entity['where']} AND su.seo_url_id IS NULL ORDER BY entity_id"
            );

            while ($row = $missing->fetch_assoc()) {
                $entityId = (int)$row['entity_id'];
                $keyword = mseoUniqueKeyword($occupied, $row['entity_name'], $languageSuffix, $entityId);
                $temporaryId = -1 - count($inserts);
                mseoOccupyKeyword($occupied, $keyword, $temporaryId);
                $inserts[] = array(
                    'type' => $entity['type'],
                    'store_id' => $storeId,
                    'language_id' => $languageId,
                    'query' => $entity['query_prefix'] . $entityId,
                    'keyword' => $keyword
                );
            }
        }
    }

    return array('updates' => $updates, 'inserts' => $inserts);
}

function mseoPlanOcmodSync(mysqli $database, string $projectRoot): array
{
    $templatePath = $projectRoot . '/upload/admin/view/template/extension/hbseo/ocmod/ocmod_hb_seourl_ML_3xxx.txt';

    if (!is_file($templatePath)) {
        return array('status' => 'missing-template', 'path' => $templatePath);
    }

    $table = DB_PREFIX . 'modification';
    $result = $database->query(
        "SELECT `modification_id`, `name`, `version`, `xml` FROM `{$table}` " .
        "WHERE `code` = 'huntbee_seo_multi_language_url_ocmod' LIMIT 1"
    );

    if (!$result->num_rows) {
        return array('status' => 'not-installed', 'path' => $templatePath);
    }

    $row = $result->fetch_assoc();
    $xml = file_get_contents($templatePath);

    if ($xml === false) {
        return array('status' => 'missing-template', 'path' => $templatePath);
    }

    $xml = str_replace(
        array('{name}', '{version}'),
        array($row['name'], $row['version']),
        $xml
    );

    return array(
        'status' => hash_equals(hash('sha256', $row['xml']), hash('sha256', $xml)) ? 'current' : 'update',
        'modification_id' => (int)$row['modification_id'],
        'xml' => $xml,
        'old_xml' => $row['xml'],
        'path' => $templatePath
    );
}

function mseoDuplicateKeywordStats(mysqli $database, int $storeId): array
{
    $result = $database->query(
        "SELECT COUNT(*) AS duplicate_groups, COALESCE(SUM(group_count - 1), 0) AS extra_rows FROM (" .
        "SELECT COUNT(*) AS group_count FROM `" . DB_PREFIX . "seo_url` " .
        "WHERE `store_id` = '{$storeId}' AND `keyword` <> '' GROUP BY `keyword` HAVING COUNT(*) > 1" .
        ") duplicate_keywords"
    );
    $row = $result->fetch_assoc();

    return array(
        'groups' => (int)$row['duplicate_groups'],
        'extra_rows' => (int)$row['extra_rows']
    );
}

function mseoPrintPlan(
    bool $apply,
    int $storeId,
    array $languages,
    array $metaPlan,
    array $urlPlan,
    array $ocmodPlan
): void {
    $titleRepairs = 0;
    $descriptionRepairs = 0;
    $repairsByLanguage = array();

    foreach ($metaPlan as $change) {
        $titleRepairs += $change['repair_title'] ? 1 : 0;
        $descriptionRepairs += $change['repair_description'] ? 1 : 0;
        $languageCode = isset($change['language_code']) ? $change['language_code'] : (string)$change['language_id'];
        $repairsByLanguage[$languageCode] = isset($repairsByLanguage[$languageCode])
            ? $repairsByLanguage[$languageCode] + 1
            : 1;
    }

    $missingByType = array();
    foreach ($urlPlan['inserts'] as $insert) {
        $key = $insert['type'] . ':' . $languages[$insert['language_id']]['code'];
        $missingByType[$key] = isset($missingByType[$key]) ? $missingByType[$key] + 1 : 1;
    }

    echo 'Mode: ' . ($apply ? 'APPLY' : 'DRY RUN') . PHP_EOL;
    echo 'Store: ' . $storeId . PHP_EOL;
    echo 'Product meta rows (all active languages): ' . count($metaPlan) . PHP_EOL;
    echo '  meta titles: ' . $titleRepairs . PHP_EOL;
    echo '  meta descriptions: ' . $descriptionRepairs . PHP_EOL;
    foreach ($repairsByLanguage as $languageCode => $count) {
        echo '  ' . $languageCode . ' rows: ' . $count . PHP_EOL;
    }
    echo 'Shared HR/EN aliases to split (HR preserved): ' . count($urlPlan['updates']) . PHP_EOL;
    echo 'Missing active entity aliases to create: ' . count($urlPlan['inserts']) . PHP_EOL;

    foreach ($missingByType as $type => $count) {
        echo '  ' . $type . ': ' . $count . PHP_EOL;
    }

    echo 'Hreflang OCMOD: ' . $ocmodPlan['status'] . PHP_EOL;

    $examples = array_slice($urlPlan['updates'], 0, 5);
    if ($examples) {
        echo PHP_EOL . 'Example EN alias changes:' . PHP_EOL;
        foreach ($examples as $change) {
            echo '  ' . $change['query'] . ': ' . $change['old_keyword'] . ' -> ' . $change['keyword'] . PHP_EOL;
        }
    }
}

function mseoApplyProductMeta(mysqli $database, array $plan): void
{
    if (!$plan) {
        return;
    }

    $table = DB_PREFIX . 'product_description';
    $update = $database->prepare(
        "UPDATE `{$table}` SET `meta_title` = ?, `meta_description` = ? " .
        "WHERE `product_id` = ? AND `language_id` = ?"
    );

    foreach ($plan as $change) {
        $update->bind_param(
            'ssii',
            $change['meta_title'],
            $change['meta_description'],
            $change['product_id'],
            $change['language_id']
        );
        $update->execute();
    }

    $update->close();
}

function mseoApplySeoUrls(mysqli $database, array $plan): void
{
    $table = DB_PREFIX . 'seo_url';

    if ($plan['updates']) {
        $update = $database->prepare("UPDATE `{$table}` SET `keyword` = ? WHERE `seo_url_id` = ?");

        foreach ($plan['updates'] as $change) {
            $update->bind_param('si', $change['keyword'], $change['seo_url_id']);
            $update->execute();
        }

        $update->close();
    }

    if ($plan['inserts']) {
        $insert = $database->prepare(
            "INSERT INTO `{$table}` (`store_id`, `language_id`, `query`, `keyword`) VALUES (?, ?, ?, ?)"
        );

        foreach ($plan['inserts'] as $change) {
            $insert->bind_param(
                'iiss',
                $change['store_id'],
                $change['language_id'],
                $change['query'],
                $change['keyword']
            );
            $insert->execute();
        }

        $insert->close();
    }
}

function mseoApplyOcmodSync(mysqli $database, array $plan): void
{
    if ($plan['status'] === 'current' || $plan['status'] === 'skipped') {
        return;
    }

    if ($plan['status'] !== 'update') {
        throw new RuntimeException('Cannot synchronize hreflang OCMOD: ' . $plan['status']);
    }

    $table = DB_PREFIX . 'modification';
    $update = $database->prepare("UPDATE `{$table}` SET `xml` = ? WHERE `modification_id` = ?");
    $update->bind_param('si', $plan['xml'], $plan['modification_id']);
    $update->execute();
    $update->close();
}

function mseoAcquireLock(mysqli $database, string $lockName): bool
{
    $statement = $database->prepare('SELECT GET_LOCK(?, 10)');
    $statement->bind_param('s', $lockName);
    $statement->execute();
    $statement->bind_result($acquired);
    $statement->fetch();
    $statement->close();

    return (int)$acquired === 1;
}

function mseoReleaseLock(mysqli $database, string $lockName): void
{
    $statement = $database->prepare('SELECT RELEASE_LOCK(?)');
    $statement->bind_param('s', $lockName);
    $statement->execute();
    $statement->close();
}

function mseoWriteRecoveryFile(
    string $projectRoot,
    int $storeId,
    array $metaPlan,
    array $urlPlan,
    array $ocmodPlan
): string {
    $storageRoot = defined('DIR_STORAGE') ? rtrim(DIR_STORAGE, '/\\') : $projectRoot . '/storage';
    $directory = $storageRoot . '/backup';

    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create recovery backup directory: ' . $directory);
    }

    $data = array(
        'format' => 1,
        'database' => DB_DATABASE,
        'prefix' => DB_PREFIX,
        'store_id' => $storeId,
        'created_at' => gmdate('c'),
        'meta_plan' => $metaPlan,
        'url_plan' => $urlPlan,
        'ocmod_plan' => $ocmodPlan
    );
    $dataJson = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($dataJson === false) {
        throw new RuntimeException('Unable to encode the recovery backup.');
    }

    $wrapper = json_encode(
        array('checksum' => hash('sha256', $dataJson), 'data' => $data),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );

    if ($wrapper === false) {
        throw new RuntimeException('Unable to encode the recovery backup wrapper.');
    }

    $path = $directory . '/multilingual-seo-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';

    if (file_put_contents($path, $wrapper, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the recovery backup: ' . $path);
    }

    chmod($path, 0600);
    return $path;
}

function mseoReadRecoveryFile(string $path): array
{
    if ($path === '' || !is_file($path)) {
        throw new RuntimeException('Recovery backup does not exist: ' . $path);
    }

    $contents = file_get_contents($path);
    $wrapper = $contents === false ? null : json_decode($contents, true);

    if (!is_array($wrapper) || !isset($wrapper['checksum'], $wrapper['data']) || !is_array($wrapper['data'])) {
        throw new RuntimeException('Recovery backup is invalid: ' . $path);
    }

    $dataJson = json_encode($wrapper['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($dataJson === false || !hash_equals($wrapper['checksum'], hash('sha256', $dataJson))) {
        throw new RuntimeException('Recovery backup checksum failed: ' . $path);
    }

    if (
        !isset($wrapper['data']['database'], $wrapper['data']['prefix']) ||
        $wrapper['data']['database'] !== DB_DATABASE ||
        $wrapper['data']['prefix'] !== DB_PREFIX
    ) {
        throw new RuntimeException('Recovery backup belongs to a different OpenCart database.');
    }

    return $wrapper['data'];
}

function mseoRestoreBackup(mysqli $database, array $backup): void
{
    if (!isset($backup['meta_plan'], $backup['url_plan'], $backup['ocmod_plan'])) {
        throw new RuntimeException('Recovery backup does not contain all required plans.');
    }

    mseoRestoreSeoUrls($database, $backup['url_plan']);
    mseoRestoreProductMeta($database, $backup['meta_plan']);
    mseoRestoreOcmod($database, $backup['ocmod_plan']);
}

function mseoRestoreProductMeta(mysqli $database, array $plan): void
{
    if (!$plan) {
        return;
    }

    $table = DB_PREFIX . 'product_description';
    $update = $database->prepare(
        "UPDATE `{$table}` SET `meta_title` = ?, `meta_description` = ? " .
        "WHERE `product_id` = ? AND `language_id` = ?"
    );

    foreach ($plan as $change) {
        if (!array_key_exists('old_meta_title', $change) || !array_key_exists('old_meta_description', $change)) {
            throw new RuntimeException('Recovery backup is missing original product metadata.');
        }

        $update->bind_param(
            'ssii',
            $change['old_meta_title'],
            $change['old_meta_description'],
            $change['product_id'],
            $change['language_id']
        );
        $update->execute();
    }

    $update->close();
}

function mseoRestoreSeoUrls(mysqli $database, array $plan): void
{
    $table = DB_PREFIX . 'seo_url';

    if (!empty($plan['inserts'])) {
        $delete = $database->prepare(
            "DELETE FROM `{$table}` WHERE `store_id` = ? AND `language_id` = ? AND `query` = ? AND `keyword` = ?"
        );

        foreach ($plan['inserts'] as $change) {
            $delete->bind_param(
                'iiss',
                $change['store_id'],
                $change['language_id'],
                $change['query'],
                $change['keyword']
            );
            $delete->execute();
        }

        $delete->close();
    }

    if (!empty($plan['updates'])) {
        $update = $database->prepare("UPDATE `{$table}` SET `keyword` = ? WHERE `seo_url_id` = ?");

        foreach ($plan['updates'] as $change) {
            $update->bind_param('si', $change['old_keyword'], $change['seo_url_id']);
            $update->execute();
        }

        $update->close();
    }
}

function mseoRestoreOcmod(mysqli $database, array $plan): void
{
    if (!isset($plan['modification_id'], $plan['old_xml'])) {
        return;
    }

    $table = DB_PREFIX . 'modification';
    $update = $database->prepare("UPDATE `{$table}` SET `xml` = ? WHERE `modification_id` = ?");
    $update->bind_param('si', $plan['old_xml'], $plan['modification_id']);
    $update->execute();
    $update->close();
}

function mseoComparable(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', trim(strip_tags($value)));

    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function mseoBrokenMeta(string $value): bool
{
    $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return $value === '' || preg_match('/\{[^}]+\}/', $value) === 1;
}

function mseoTextLength(string $value): int
{
    $value = preg_replace('/\s+/u', ' ', trim(strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function mseoProductTitle(string $name, string $manufacturer = ''): string
{
    $name = preg_replace('/\s+/u', ' ', trim(strip_tags(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    $manufacturer = preg_replace('/\s+/u', ' ', trim(strip_tags(html_entity_decode($manufacturer, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    $base = $name;

    if ($manufacturer !== '' && strpos(mseoComparable($name), mseoComparable($manufacturer)) === false) {
        $base .= ' | ' . $manufacturer;
    }

    $withSiteName = $base . ' | World of Beauty';
    if (mseoTextLength($withSiteName) <= 65) {
        return $withSiteName;
    }

    return mseoTruncate($base, 65);
}

function mseoProductDescription(string $name, string $description, bool $croatian): string
{
    $plainText = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plainText = html_entity_decode($plainText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plainText = preg_replace('/\s+/u', ' ', trim(strip_tags($plainText)));

    if ($plainText === '' || mseoComparable($plainText) === mseoComparable($name)) {
        $plainText = $croatian
            ? $name . ' – profesionalna oprema za salone uz sigurnu kupnju, brzu isporuku i stručnu podršku.'
            : $name . ' – professional salon equipment with secure shopping, fast delivery and expert support.';
    }

    return mseoTruncate($plainText, 160);
}

function mseoTruncate(string $value, int $limit): string
{
    $value = preg_replace('/\s+/u', ' ', trim($value));
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

    if ($length <= $limit) {
        return $value;
    }

    $short = function_exists('mb_substr')
        ? mb_substr($value, 0, $limit - 1, 'UTF-8')
        : substr($value, 0, $limit - 1);
    $space = function_exists('mb_strrpos')
        ? mb_strrpos($short, ' ', 0, 'UTF-8')
        : strrpos($short, ' ');

    if ($space !== false && $space > (int)($limit * 0.7)) {
        $short = function_exists('mb_substr')
            ? mb_substr($short, 0, $space, 'UTF-8')
            : substr($short, 0, $space);
    }

    return rtrim($short, ' ,.;:-') . '…';
}

function mseoSlugify(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

    if ($transliterated !== false) {
        $value = $transliterated;
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim($value, '-');

    return substr($value, 0, 240);
}

function mseoUniqueKeyword(array &$occupied, string $seed, string $languageSuffix, int $entityId): string
{
    $base = mseoSlugify($seed);
    $languageSuffix = mseoSlugify($languageSuffix);

    if ($base === '') {
        $base = 'seo-url-' . max(1, $entityId);
    }

    $candidates = array($base);
    if ($languageSuffix !== '') {
        $candidates[] = mseoKeywordCandidate($base, array($languageSuffix));
    }
    $candidates[] = mseoKeywordCandidate($base, array($languageSuffix, (string)max(1, $entityId)));

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && empty($occupied[$candidate])) {
            return $candidate;
        }
    }

    $suffix = 2;
    do {
        $candidate = mseoKeywordCandidate(
            $base,
            array($languageSuffix, (string)max(1, $entityId), (string)$suffix)
        );
        $suffix++;
    } while (!empty($occupied[$candidate]));

    return $candidate;
}

function mseoKeywordCandidate(string $base, array $suffixParts): string
{
    $suffixParts = array_values(array_filter(array_map('mseoSlugify', $suffixParts)));

    if (!$suffixParts) {
        return substr($base, 0, 240);
    }

    $suffix = implode('-', $suffixParts);
    $stemLength = max(1, 240 - strlen($suffix) - 1);
    $stem = rtrim(substr($base, 0, $stemLength), '-');

    return $stem . '-' . $suffix;
}

function mseoReleaseKeyword(array &$occupied, string $keyword, int $id): void
{
    unset($occupied[$keyword][$id]);

    if (empty($occupied[$keyword])) {
        unset($occupied[$keyword]);
    }
}

function mseoOccupyKeyword(array &$occupied, string $keyword, int $id): void
{
    if (!isset($occupied[$keyword])) {
        $occupied[$keyword] = array();
    }

    $occupied[$keyword][$id] = true;
}

function mseoQueryId(string $query): int
{
    $parts = explode('=', $query, 2);
    return isset($parts[1]) ? (int)$parts[1] : 0;
}
