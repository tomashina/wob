<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/upload/config.php';
$mainCategoryContent = require $projectRoot . '/scripts/main-category-seo-content.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$database = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int) DB_PORT);
$database->set_charset('utf8mb4');

function seoUpsertSetting(mysqli $database, $code, $key, $value, $storeId = 0)
{
    $table = DB_PREFIX . 'setting';
    $select = $database->prepare(
        "SELECT `setting_id` FROM `{$table}` WHERE `store_id` = ? AND `code` = ? AND `key` = ? LIMIT 1"
    );
    $select->bind_param('iss', $storeId, $code, $key);
    $select->execute();
    $select->bind_result($settingId);
    $found = $select->fetch();
    $select->close();

    if ($found) {
        $update = $database->prepare(
            "UPDATE `{$table}` SET `value` = ?, `serialized` = 0 WHERE `setting_id` = ?"
        );
        $update->bind_param('si', $value, $settingId);
        $update->execute();
        return;
    }

    $insert = $database->prepare(
        "INSERT INTO `{$table}` (`store_id`, `code`, `key`, `value`, `serialized`) VALUES (?, ?, ?, ?, 0)"
    );
    $insert->bind_param('isss', $storeId, $code, $key, $value);
    $insert->execute();
}

function seoIsBrokenMeta($value, $checkBuyBest = false)
{
    $value = trim(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'));

    if ($value === '' || preg_match('/\{[^}]+\}/', $value)) {
        return true;
    }

    return $checkBuyBest && preg_match('/^buy best\b/i', $value);
}

function seoPlainText($html)
{
    $text = html_entity_decode((string) $html, ENT_QUOTES, 'UTF-8');
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    return preg_replace('/\s+/u', ' ', trim(strip_tags($text)));
}

function seoTruncate($text, $limit)
{
    $text = preg_replace('/\s+/u', ' ', trim((string) $text));

    if (mb_strlen($text, 'UTF-8') <= $limit) {
        return $text;
    }

    $short = mb_substr($text, 0, $limit - 1, 'UTF-8');
    $space = mb_strrpos($short, ' ', 0, 'UTF-8');

    if ($space !== false && $space > (int) ($limit * 0.7)) {
        $short = mb_substr($short, 0, $space, 'UTF-8');
    }

    return rtrim($short, ' ,.;:-') . '…';
}

function seoLanguages(mysqli $database)
{
    $languages = array();
    $result = $database->query(
        "SELECT `language_id`, `code` FROM `" . DB_PREFIX . "language` WHERE `status` = 1"
    );

    while ($row = $result->fetch_assoc()) {
        $languages[(int) $row['language_id']] = strtolower($row['code']);
    }

    return $languages;
}

function seoEnsureRouteAliases(mysqli $database, array $languages, array $aliases)
{
    $storeIds = array(0);
    $storeResult = $database->query("SELECT `store_id` FROM `" . DB_PREFIX . "store`");

    while ($store = $storeResult->fetch_assoc()) {
        $storeIds[] = (int) $store['store_id'];
    }

    $storeIds = array_values(array_unique($storeIds));
    $table = DB_PREFIX . 'seo_url';
    $upsert = $database->prepare(
        "INSERT INTO `{$table}` (`store_id`, `language_id`, `query`, `keyword`) VALUES (?, ?, ?, ?) " .
        "ON DUPLICATE KEY UPDATE `keyword` = VALUES(`keyword`)"
    );
    $updated = 0;

    foreach ($storeIds as $storeId) {
        foreach ($languages as $languageId => $code) {
            foreach ($aliases as $query => $keywords) {
                if (is_array($keywords)) {
                    $croatian = strpos(strtolower($code), 'hr') === 0;
                    $keyword = $croatian ? $keywords['hr'] : $keywords['default'];
                } else {
                    $keyword = $keywords;
                }

                $upsert->bind_param('iiss', $storeId, $languageId, $query, $keyword);
                $upsert->execute();
                $updated++;
            }
        }
    }

    return $updated;
}

function seoConfigureSearchMeta(mysqli $database, array $languages)
{
    foreach ($languages as $languageId => $code) {
        $croatian = strpos($code, 'hr') === 0;
        $values = $croatian ? array(
            'hb_metatags_pgtitle' => 'Stranica {page} – {meta}',
            'hb_metatags_pgdesc' => '{meta} Stranica {page}.',
            'hb_metatags_srtitle' => 'Rezultati za „{tag}” | Stranica {page} | World of Beauty',
            'hb_metatags_srdesc' => 'Pronađeno je {total} proizvoda za pojam „{tag}” na stranici {page}.',
            'hb_metatags_tgtitle' => 'Proizvodi: {tag} | Stranica {page} | World of Beauty',
            'hb_metatags_tgdesc' => 'Istražite {total} proizvoda označenih pojmom „{tag}” na stranici {page}.'
        ) : array(
            'hb_metatags_pgtitle' => 'Page {page} – {meta}',
            'hb_metatags_pgdesc' => '{meta} Page {page}.',
            'hb_metatags_srtitle' => 'Results for “{tag}” | Page {page} | World of Beauty',
            'hb_metatags_srdesc' => '{total} products found for “{tag}” on page {page}.',
            'hb_metatags_tgtitle' => 'Products: {tag} | Page {page} | World of Beauty',
            'hb_metatags_tgdesc' => 'Explore {total} products tagged “{tag}” on page {page}.'
        );

        foreach ($values as $key => $value) {
            seoUpsertSetting($database, 'hb_metatags', $key . $languageId, $value);
        }
    }
}

function seoConfigureStructuredData(mysqli $database)
{
    $values = array(
        'hb_snippets_prod_enable' => '1',
        'hb_snippets_bc_enable' => '1',
        'hb_snippets_bc_type' => 'smart',
        'hb_snippets_list_enable' => '1',
        'hb_snippets_kg_enable' => '1',
        'hb_snippets_search_enable' => '1',
        'hb_snippets_og_enable' => '1',
        'hb_snippets_tc_enable' => '1',
        'hb_snippets_local_enable' => '0',
        'hb_snippets_local_country' => 'HR'
    );

    foreach ($values as $key => $value) {
        seoUpsertSetting($database, 'hb_snippets', $key, $value);
    }
}

function seoOptimizeProductQuickEdit(mysqli $database)
{
    $table = DB_PREFIX . 'setting';
    $installed = $database->query(
        "SELECT 1 FROM `{$table}` WHERE `store_id` = 0 AND `code` = 'module_product_quick_edit' " .
        "AND `key` = 'module_product_quick_edit_installed' AND `value` = '1' LIMIT 1"
    );

    if (!$installed->num_rows) {
        return 0;
    }

    // Loading 100 image/category rows at once makes the Quick Edit screen
    // needlessly expensive. The extension already supports both cache layers.
    $values = array(
        'module_product_quick_edit_items_per_page' => '25',
        'module_product_quick_edit_server_side_caching' => '1',
        'module_product_quick_edit_client_side_caching' => '1'
    );
    $select = $database->prepare(
        "SELECT `value` FROM `{$table}` WHERE `store_id` = 0 AND `code` = 'module_product_quick_edit' AND `key` = ? LIMIT 1"
    );
    $updated = 0;

    foreach ($values as $key => $value) {
        $select->bind_param('s', $key);
        $select->execute();
        $select->bind_result($currentValue);
        $found = $select->fetch();
        $select->free_result();

        if (!$found || (string)$currentValue !== $value) {
            seoUpsertSetting($database, 'module_product_quick_edit', $key, $value);
            $updated++;
        }
    }

    $select->close();
    return $updated;
}

function seoFixHomepageHeadingHierarchy(mysqli $database)
{
    $table = DB_PREFIX . 'module';
    $select = $database->prepare(
        "SELECT `module_id`, `setting` FROM `{$table}` WHERE `code` = 'basel_content' AND `name` = 'Grenke' LIMIT 1"
    );
    $select->execute();
    $select->bind_result($moduleId, $rawSetting);

    if (!$select->fetch()) {
        $select->close();
        return false;
    }
    $select->close();

    $setting = json_decode($rawSetting, true);
    if (!is_array($setting) || empty($setting['columns'])) {
        return false;
    }

    $encoded = json_encode($setting);
    $fixed = str_ireplace(
        array('&lt;h1', '&lt;/h1&gt;', '<h1', '</h1>'),
        array('&lt;h2', '&lt;/h2&gt;', '<h2', '</h2>'),
        $encoded
    );

    if ($fixed === $encoded) {
        return false;
    }

    $update = $database->prepare("UPDATE `{$table}` SET `setting` = ? WHERE `module_id` = ?");
    $update->bind_param('si', $fixed, $moduleId);
    $update->execute();
    return true;
}

function seoCreateWebpAsset($source, $destination, $maxWidth, $maxHeight)
{
    if (!function_exists('imagewebp') || !is_file($source)) {
        return false;
    }

    if (is_file($destination) && filemtime($destination) >= filemtime($source) && filesize($destination) > 0) {
        return true;
    }

    $info = @getimagesize($source);
    if (!$info || empty($info[0]) || empty($info[1])) {
        return false;
    }

    if ($info[2] === IMAGETYPE_PNG) {
        $original = @imagecreatefrompng($source);
    } elseif ($info[2] === IMAGETYPE_JPEG) {
        $original = @imagecreatefromjpeg($source);
    } elseif ($info[2] === IMAGETYPE_WEBP) {
        $original = @imagecreatefromwebp($source);
    } else {
        return false;
    }

    if (!$original) {
        return false;
    }

    $scale = min(1, $maxWidth / $info[0], $maxHeight / $info[1]);
    $width = max(1, (int)round($info[0] * $scale));
    $height = max(1, (int)round($info[1] * $scale));
    $canvas = imagecreatetruecolor($width, $height);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
    imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
    imagecopyresampled($canvas, $original, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);

    $directory = dirname($destination);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        imagedestroy($original);
        imagedestroy($canvas);
        return false;
    }

    $saved = imagewebp($canvas, $destination, 82);
    imagedestroy($original);
    imagedestroy($canvas);
    return $saved;
}

function seoOptimizeHomepageImages(mysqli $database)
{
    $table = DB_PREFIX . 'module';
    $result = $database->query(
        "SELECT `module_id`, `setting` FROM `{$table}` WHERE `code` IN ('basel_layerslider', 'basel_content')"
    );
    $optimized = 0;
    $updatedModules = 0;

    while ($row = $result->fetch_assoc()) {
        $setting = json_decode($row['setting'], true);
        if (!is_array($setting)) {
            continue;
        }

        $changed = false;
        $walker = function (&$value) use (&$walker, &$changed, &$optimized) {
            if (is_array($value)) {
                foreach ($value as &$child) {
                    $walker($child);
                }
                unset($child);
                return;
            }

            if (!is_string($value) || !preg_match('#^catalog/(sllider|banner-main-category|banneri)/.+\.(png|jpe?g)$#i', $value)) {
                return;
            }

            if (strpos($value, 'catalog/sllider/') === 0) {
                $maxWidth = 1360;
                $maxHeight = 580;
            } elseif (strpos($value, 'catalog/banneri/') === 0) {
                $maxWidth = 1140;
                $maxHeight = 760;
            } else {
                $maxWidth = 900;
                $maxHeight = 900;
            }

            $pathInfo = pathinfo($value);
            $optimizedRelative = ($pathInfo['dirname'] !== '.' ? $pathInfo['dirname'] . '/' : '') . $pathInfo['filename'] . '-optimized.webp';
            if (seoCreateWebpAsset(DIR_IMAGE . $value, DIR_IMAGE . $optimizedRelative, $maxWidth, $maxHeight)) {
                $value = $optimizedRelative;
                $changed = true;
                $optimized++;
            }
        };
        $walker($setting);

        if ($changed) {
            $encoded = json_encode($setting, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $moduleId = (int)$row['module_id'];
            $update = $database->prepare("UPDATE `{$table}` SET `setting` = ? WHERE `module_id` = ?");
            $update->bind_param('si', $encoded, $moduleId);
            $update->execute();
            $updatedModules++;
        }
    }

    return array('images' => $optimized, 'modules' => $updatedModules);
}

function seoRepairCategoryMeta(mysqli $database, array $languages)
{
    $table = DB_PREFIX . 'category_description';
    $result = $database->query(
        "SELECT `category_id`, `language_id`, `name`, `meta_title`, `meta_description` FROM `{$table}`"
    );
    $update = $database->prepare(
        "UPDATE `{$table}` SET `meta_title` = ?, `meta_description` = ? WHERE `category_id` = ? AND `language_id` = ?"
    );
    $updated = 0;

    while ($row = $result->fetch_assoc()) {
        $languageId = (int) $row['language_id'];
        $code = isset($languages[$languageId]) ? $languages[$languageId] : '';
        $croatian = strpos($code, 'hr') === 0;
        $title = $row['meta_title'];
        $description = $row['meta_description'];
        $changed = false;

        if (seoIsBrokenMeta($title, true)) {
            $title = seoTruncate($row['name'] . ' | World of Beauty', 65);
            $changed = true;
        }

        if (seoIsBrokenMeta($description)) {
            $description = $croatian
                ? 'Istražite ' . $row['name'] . ' za profesionalne salone. Kvalitetna oprema, sigurna online kupnja, brza isporuka i stručna podrška.'
                : 'Explore ' . $row['name'] . ' for professional salons. Quality equipment, secure shopping, fast delivery and expert support.';
            $description = seoTruncate($description, 160);
            $changed = true;
        }

        if ($changed) {
            $categoryId = (int) $row['category_id'];
            $update->bind_param('ssii', $title, $description, $categoryId, $languageId);
            $update->execute();
            $updated++;
        }
    }

    return $updated;
}

function seoApplyMainCategoryContent(mysqli $database, array $languages, array $content)
{
    $categoryTable = DB_PREFIX . 'category';
    $descriptionTable = DB_PREFIX . 'category_description';
    $select = $database->prepare(
        "SELECT cd.`name`, cd.`description`, cd.`meta_title`, cd.`meta_description` " .
        "FROM `{$categoryTable}` c " .
        "INNER JOIN `{$descriptionTable}` cd ON cd.`category_id` = c.`category_id` " .
        "WHERE c.`category_id` = ? AND c.`parent_id` = 0 AND c.`status` = 1 AND cd.`language_id` = ? LIMIT 1"
    );
    $update = $database->prepare(
        "UPDATE `{$descriptionTable}` SET `description` = ?, `meta_title` = ?, `meta_description` = ? " .
        "WHERE `category_id` = ? AND `language_id` = ?"
    );
    $updated = 0;

    foreach ($content as $categoryId => $translations) {
        foreach ($languages as $languageId => $code) {
            if (strpos($code, 'hr') === 0) {
                $locale = 'hr';
            } elseif (strpos($code, 'en') === 0) {
                $locale = 'en';
            } else {
                continue;
            }

            if (empty($translations[$locale])) {
                throw new RuntimeException('Missing ' . $locale . ' SEO content for main category ' . $categoryId . '.');
            }

            $values = $translations[$locale];
            foreach (array('description', 'meta_title', 'meta_description') as $field) {
                if (!isset($values[$field]) || trim($values[$field]) === '') {
                    throw new RuntimeException('Missing ' . $field . ' for main category ' . $categoryId . ' (' . $locale . ').');
                }
            }

            $titleLength = mb_strlen($values['meta_title'], 'UTF-8');
            $metaDescriptionLength = mb_strlen($values['meta_description'], 'UTF-8');
            if ($titleLength > 65 || $metaDescriptionLength > 165) {
                throw new RuntimeException(
                    'SEO text is too long for main category ' . $categoryId . ' (' . $locale . '): title ' .
                    $titleLength . ', description ' . $metaDescriptionLength . '.'
                );
            }

            $categoryId = (int) $categoryId;
            $languageId = (int) $languageId;
            $select->bind_param('ii', $categoryId, $languageId);
            $select->execute();
            $select->store_result();

            $current = null;
            $currentName = null;
            $currentDescription = null;
            $currentMetaTitle = null;
            $currentMetaDescription = null;
            $select->bind_result(
                $currentName,
                $currentDescription,
                $currentMetaTitle,
                $currentMetaDescription
            );

            if ($select->fetch()) {
                $current = array(
                    'name' => $currentName,
                    'description' => $currentDescription,
                    'meta_title' => $currentMetaTitle,
                    'meta_description' => $currentMetaDescription,
                );
            }

            $select->free_result();

            if (!$current || trim($current['name']) === '') {
                continue;
            }

            if (
                $current['description'] === $values['description'] &&
                $current['meta_title'] === $values['meta_title'] &&
                $current['meta_description'] === $values['meta_description']
            ) {
                continue;
            }

            $update->bind_param(
                'sssii',
                $values['description'],
                $values['meta_title'],
                $values['meta_description'],
                $categoryId,
                $languageId
            );
            $update->execute();
            $updated++;
        }
    }

    return $updated;
}

function seoNaturalList(array $items, $croatian)
{
    $items = array_values(array_filter(array_map('trim', $items)));
    $count = count($items);

    if ($count < 2) {
        return isset($items[0]) ? $items[0] : '';
    }

    $last = array_pop($items);
    return implode(', ', $items) . ($croatian ? ' i ' : ' and ') . $last;
}

function seoApplySubcategoryContent(mysqli $database, array $languages)
{
    $categoryTable = DB_PREFIX . 'category';
    $descriptionTable = DB_PREFIX . 'category_description';
    $storeTable = DB_PREFIX . 'category_to_store';
    $pathTable = DB_PREFIX . 'category_path';
    $update = $database->prepare(
        "UPDATE `{$descriptionTable}` SET `description` = ?, `meta_title` = ?, `meta_description` = ? " .
        "WHERE `category_id` = ? AND `language_id` = ?"
    );
    $updated = 0;
    $descriptionsCreated = 0;

    foreach ($languages as $languageId => $code) {
        if (strpos($code, 'hr') === 0) {
            $croatian = true;
        } elseif (strpos($code, 'en') === 0) {
            $croatian = false;
        } else {
            continue;
        }

        $languageId = (int) $languageId;
        $result = $database->query(
            "SELECT c.`category_id`, cd.`name`, cd.`description`, cd.`meta_title`, cd.`meta_description`, " .
            "parent_cd.`name` AS `parent_name`, COALESCE(root_cd.`name`, parent_cd.`name`) AS `root_name`, " .
            "GROUP_CONCAT(DISTINCT child_cd.`name` ORDER BY child.`sort_order`, child_cd.`name` SEPARATOR '||') AS `children` " .
            "FROM `{$categoryTable}` c " .
            "INNER JOIN `{$storeTable}` c2s ON c2s.`category_id` = c.`category_id` AND c2s.`store_id` = 0 " .
            "INNER JOIN `{$descriptionTable}` cd ON cd.`category_id` = c.`category_id` AND cd.`language_id` = {$languageId} " .
            "INNER JOIN `{$categoryTable}` parent ON parent.`category_id` = c.`parent_id` " .
            "INNER JOIN `{$descriptionTable}` parent_cd ON parent_cd.`category_id` = parent.`category_id` AND parent_cd.`language_id` = {$languageId} " .
            "LEFT JOIN `{$pathTable}` root_path ON root_path.`category_id` = c.`category_id` AND root_path.`level` = 0 " .
            "LEFT JOIN `{$descriptionTable}` root_cd ON root_cd.`category_id` = root_path.`path_id` AND root_cd.`language_id` = {$languageId} " .
            "LEFT JOIN `{$categoryTable}` child ON child.`parent_id` = c.`category_id` AND child.`status` = 1 " .
            "LEFT JOIN `{$storeTable}` child_store ON child_store.`category_id` = child.`category_id` AND child_store.`store_id` = 0 " .
            "LEFT JOIN `{$descriptionTable}` child_cd ON child_cd.`category_id` = child.`category_id` AND child_cd.`language_id` = {$languageId} AND child_store.`category_id` IS NOT NULL " .
            "WHERE c.`parent_id` <> 0 AND c.`status` = 1 AND TRIM(cd.`name`) <> '' " .
            "GROUP BY c.`category_id`, cd.`name`, cd.`description`, cd.`meta_title`, cd.`meta_description`, parent_cd.`name`, root_cd.`name` " .
            "ORDER BY c.`category_id`"
        );

        while ($row = $result->fetch_assoc()) {
            $name = seoPlainText($row['name']);
            $parentName = seoPlainText($row['parent_name']);
            $rootName = seoPlainText($row['root_name']);
            $titleWithParent = $name . ' – ' . $parentName . ' | World of Beauty';
            $title = mb_strlen($titleWithParent, 'UTF-8') <= 65
                ? $titleWithParent
                : seoTruncate($name . ' | World of Beauty', 65);

            if ($croatian) {
                $metaDescription = 'Istražite ' . $name . ' iz ponude ' . $parentName .
                    '. Usporedite profesionalnu opremu, proizvode i pribor za salon te odaberite rješenje za svoj način rada.';
            } else {
                $metaDescription = 'Explore ' . $name . ' in our ' . $parentName .
                    ' range. Compare professional salon equipment, products and accessories to find the right solution for your work.';
            }
            $metaDescription = seoTruncate($metaDescription, 160);

            $description = $row['description'];
            if (seoPlainText($description) === '') {
                $escapedName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                $escapedParent = htmlspecialchars($parentName, ENT_QUOTES, 'UTF-8');
                $escapedRoot = htmlspecialchars($rootName, ENT_QUOTES, 'UTF-8');
                $children = $row['children'] ? array_slice(explode('||', $row['children']), 0, 4) : array();

                if ($croatian) {
                    $firstParagraph = '<p>Pregledajte ponudu <strong>' . $escapedName . '</strong> u sklopu kategorije ' .
                        $escapedParent . '. Na jednom mjestu pronađite profesionalne proizvode, opremu i pribor ' .
                        'namijenjene salonima, studijima i stručnjacima iz područja ' . $escapedRoot . '.</p>';

                    if ($children) {
                        $escapedChildren = array_map(function ($child) {
                            return htmlspecialchars(seoPlainText($child), ENT_QUOTES, 'UTF-8');
                        }, $children);
                        $secondParagraph = '<p>Ponuda obuhvaća ' . seoNaturalList($escapedChildren, true) .
                            ' i povezana rješenja. Usporedite dostupne modele, značajke i namjenu kako biste lakše ' .
                            'odabrali proizvod koji odgovara vašim uslugama, prostoru i načinu rada.</p>';
                    } else {
                        $secondParagraph = '<p>Usporedite dostupne modele, značajke i namjenu proizvoda kako biste lakše ' .
                            'odabrali rješenje koje odgovara vašim uslugama, prostoru i načinu rada.</p>';
                    }
                } else {
                    $firstParagraph = '<p>Browse <strong>' . $escapedName . '</strong> within our ' . $escapedParent .
                        ' category. Find professional products, equipment and accessories for salons, studios and ' .
                        'specialists working in ' . $escapedRoot . '.</p>';

                    if ($children) {
                        $escapedChildren = array_map(function ($child) {
                            return htmlspecialchars(seoPlainText($child), ENT_QUOTES, 'UTF-8');
                        }, $children);
                        $secondParagraph = '<p>The range includes ' . seoNaturalList($escapedChildren, false) .
                            ' and related solutions. Compare available models, features and intended uses to choose a ' .
                            'product suited to your services, space and working style.</p>';
                    } else {
                        $secondParagraph = '<p>Compare available models, features and intended uses to choose a solution ' .
                            'suited to your services, space and working style.</p>';
                    }
                }

                $description = $firstParagraph . $secondParagraph;
                $descriptionsCreated++;
            }

            if (
                $description === $row['description'] &&
                $title === $row['meta_title'] &&
                $metaDescription === $row['meta_description']
            ) {
                continue;
            }

            $categoryId = (int) $row['category_id'];
            $update->bind_param('sssii', $description, $title, $metaDescription, $categoryId, $languageId);
            $update->execute();
            $updated++;
        }
    }

    return array('updated' => $updated, 'descriptions' => $descriptionsCreated);
}

function seoRepairProductMeta(mysqli $database, array $languages)
{
    $table = DB_PREFIX . 'product_description';
    $result = $database->query(
        "SELECT `product_id`, `language_id`, `name`, `description`, `meta_title`, `meta_description` FROM `{$table}`"
    );
    $update = $database->prepare(
        "UPDATE `{$table}` SET `meta_title` = ?, `meta_description` = ? WHERE `product_id` = ? AND `language_id` = ?"
    );
    $updated = 0;

    while ($row = $result->fetch_assoc()) {
        $languageId = (int) $row['language_id'];
        $code = isset($languages[$languageId]) ? $languages[$languageId] : '';
        $croatian = strpos($code, 'hr') === 0;
        $title = $row['meta_title'];
        $description = $row['meta_description'];
        $changed = false;

        if (seoIsBrokenMeta($title)) {
            $title = seoTruncate($row['name'] . ' | World of Beauty', 65);
            $changed = true;
        }

        if (seoIsBrokenMeta($description)) {
            $description = seoPlainText($row['description']);

            if ($description === '') {
                $description = $croatian
                    ? $row['name'] . ' – profesionalna oprema za salone. Sigurna kupnja, brza isporuka i stručna podrška.'
                    : $row['name'] . ' – professional salon equipment with secure shopping, fast delivery and expert support.';
            }

            $description = seoTruncate($description, 160);
            $changed = true;
        }

        if ($changed) {
            $productId = (int) $row['product_id'];
            $update->bind_param('ssii', $title, $description, $productId, $languageId);
            $update->execute();
            $updated++;
        }
    }

    return $updated;
}

function seoRefreshRuntimeCaches(mysqli $database)
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is required for --refresh.');
    }

    if (!is_dir(DIR_MODIFICATION) && !mkdir(DIR_MODIFICATION, 0777, true) && !is_dir(DIR_MODIFICATION)) {
        throw new RuntimeException('Unable to create the OCMOD directory: ' . DIR_MODIFICATION);
    }

    if (!is_writable(DIR_MODIFICATION)) {
        throw new RuntimeException('The OCMOD directory is not writable: ' . DIR_MODIFICATION);
    }

    $userResult = $database->query(
        "SELECT `user_id` FROM `" . DB_PREFIX . "user` WHERE `status` = 1 AND `user_group_id` = 1 ORDER BY `user_id` LIMIT 1"
    );

    if (!$userResult->num_rows) {
        throw new RuntimeException('No active super-admin user is available for OCMOD refresh.');
    }

    $sessionId = bin2hex(random_bytes(16));
    $userToken = bin2hex(random_bytes(16));
    $userId = (int) $userResult->fetch_assoc()['user_id'];
    $sessionData = json_encode(array('user_id' => $userId, 'user_token' => $userToken));
    $sessionTable = DB_PREFIX . 'session';
    $session = $database->prepare(
        "REPLACE INTO `{$sessionTable}` (`session_id`, `data`, `expire`) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))"
    );
    $session->bind_param('ss', $sessionId, $sessionData);
    $session->execute();

    $maintenanceValue = '0';
    $maintenance = $database->prepare(
        "SELECT `value` FROM `" . DB_PREFIX . "setting` " .
        "WHERE `store_id` = 0 AND `code` = 'config' AND `key` = 'config_maintenance' LIMIT 1"
    );
    $maintenance->execute();
    $maintenance->bind_result($storedMaintenanceValue);

    if ($maintenance->fetch()) {
        $maintenanceValue = (string) $storedMaintenanceValue;
    }

    $maintenance->close();

    try {
        $server = defined('HTTPS_SERVER') ? HTTPS_SERVER : HTTP_SERVER;
        $admin = rtrim($server, '/') . '/admin/index.php';
        $cookie = 'OCSESSID=' . $sessionId;
        $urls = array(
            $admin . '?route=marketplace/modification/refresh&user_token=' . rawurlencode($userToken),
            $admin . '?route=common/developer/theme&user_token=' . rawurlencode($userToken)
        );

        foreach ($urls as $index => $url) {
            $curl = curl_init($url);
            curl_setopt_array($curl, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => $index === 0,
                CURLOPT_COOKIE => $cookie,
                CURLOPT_POST => $index === 1,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_SSL_VERIFYPEER => false,
            ));
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($response === false || $error !== '' || $status >= 400) {
                throw new RuntimeException('Runtime refresh failed (HTTP ' . $status . '): ' . $error);
            }

            $responseText = (string) $response;
            $fatalMarkers = array('Fatal error', 'Uncaught Error', 'Uncaught Exception');

            foreach ($fatalMarkers as $marker) {
                if (stripos($responseText, $marker) !== false) {
                    throw new RuntimeException('Runtime refresh returned a PHP fatal error during ' . ($index === 0 ? 'OCMOD' : 'theme') . ' refresh.');
                }
            }

            if (preg_match('/name=["\']username["\'].*name=["\']password["\']/is', $responseText)) {
                throw new RuntimeException('Runtime refresh was redirected to the admin login page.');
            }

            if ($index === 0) {
                $loader = rtrim(DIR_MODIFICATION, '/\\') . '/system/engine/loader.php';

                if (!is_file($loader) || filesize($loader) === 0) {
                    throw new RuntimeException('OCMOD refresh did not rebuild system/engine/loader.php.');
                }

                // Some local OpenCart builds keep the generated header template even
                // after a successful modification refresh. Keep only our stylesheet
                // cache-buster in sync without replacing other OCMOD changes.
                $sourceHeader = DIR_APPLICATION . 'view/theme/basel/template/common/header.twig';
                $modifiedHeader = rtrim(DIR_MODIFICATION, '/\\') . '/catalog/view/theme/basel/template/common/header.twig';

                if (is_file($sourceHeader) && is_file($modifiedHeader)) {
                    $sourceContents = file_get_contents($sourceHeader);
                    $modifiedContents = file_get_contents($modifiedHeader);

                    if (
                        $sourceContents !== false &&
                        $modifiedContents !== false &&
                        preg_match('/wob-optimization\\.css\\?v=([0-9.]+)/', $sourceContents, $versionMatch)
                    ) {
                        $synchronizedContents = preg_replace(
                            '/wob-optimization\\.css\\?v=[0-9.]+/',
                            'wob-optimization.css?v=' . $versionMatch[1],
                            $modifiedContents,
                            1
                        );

                        if ($synchronizedContents !== null && $synchronizedContents !== $modifiedContents) {
                            if (file_put_contents($modifiedHeader, $synchronizedContents) === false) {
                                throw new RuntimeException('Unable to synchronize the optimization stylesheet version.');
                            }
                        }
                    }
                }
            } else {
                $json = json_decode($responseText, true);

                if (!is_array($json) || !empty($json['error']) || empty($json['success'])) {
                    throw new RuntimeException('Theme cache refresh did not return a success response.');
                }
            }
        }
    } finally {
        // The OpenCart refresh controller temporarily enables maintenance mode.
        // Restore its original value even when the HTTP request fails midway.
        seoUpsertSetting($database, 'config', 'config_maintenance', $maintenanceValue, 0);

        $delete = $database->prepare("DELETE FROM `{$sessionTable}` WHERE `session_id` = ?");
        $delete->bind_param('s', $sessionId);
        $delete->execute();
    }
}

try {
    $database->begin_transaction();
    $languages = seoLanguages($database);
    $routeAliases = seoEnsureRouteAliases($database, $languages, array(
        'information/contact' => array(
            'hr' => 'contact',
            'default' => 'contact-us'
        )
    ));
    seoConfigureSearchMeta($database, $languages);
    seoConfigureStructuredData($database);
    $quickEditUpdates = seoOptimizeProductQuickEdit($database);
    $headingFixed = seoFixHomepageHeadingHierarchy($database);
    $imageOptimization = seoOptimizeHomepageImages($database);
    $mainCategoryUpdates = seoApplyMainCategoryContent($database, $languages, $mainCategoryContent);
    $subcategoryUpdates = seoApplySubcategoryContent($database, $languages);
    $categoryUpdates = seoRepairCategoryMeta($database, $languages);
    $productUpdates = seoRepairProductMeta($database, $languages);
    $database->commit();

    echo 'UPDATED search and pagination metadata' . PHP_EOL;
    echo 'ENSURED ' . $routeAliases . ' route aliases' . PHP_EOL;
    echo 'UPDATED Open Graph, Twitter Cards and structured data settings' . PHP_EOL;
    echo 'OPTIMIZED ' . $quickEditUpdates . ' Product Quick Edit performance settings' . PHP_EOL;
    echo ($headingFixed ? 'UPDATED' : 'CHECKED') . ' homepage heading hierarchy' . PHP_EOL;
    echo 'OPTIMIZED ' . $imageOptimization['images'] . ' homepage images in ' . $imageOptimization['modules'] . ' modules' . PHP_EOL;
    echo 'UPDATED ' . $mainCategoryUpdates . ' curated main category content records' . PHP_EOL;
    echo 'UPDATED ' . $subcategoryUpdates['updated'] . ' subcategory SEO records and CREATED ' .
        $subcategoryUpdates['descriptions'] . ' descriptions' . PHP_EOL;
    echo 'REPAIRED ' . $categoryUpdates . ' category metadata records' . PHP_EOL;
    echo 'REPAIRED ' . $productUpdates . ' product metadata records' . PHP_EOL;

    if (in_array('--refresh', $argv, true)) {
        seoRefreshRuntimeCaches($database);
        echo 'REFRESHED OCMOD and theme template cache' . PHP_EOL;
    } else {
        echo 'Next: refresh Extensions > Modifications and the theme cache in OpenCart.' . PHP_EOL;
    }
} catch (Throwable $exception) {
    try {
        $database->rollback();
    } catch (Throwable $ignored) {
    }

    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $database->close();
}
