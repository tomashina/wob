<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/upload/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$database = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, (int) DB_PORT);
$database->set_charset('utf8mb4');

const WOB_GATEWAY_MODULE_NAME = 'Home salon gateways';

function encodeModuleHtml($html)
{
    return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
}

function buildGatewayHtml($languageId)
{
    $translations = array(
        3 => array(
            'label' => 'Profesionalna oprema za salone',
            'hair_title' => 'Oprema za<br><span class="wob-salon-card__title-line">frizerske salone</span>',
            'hair_description' => 'Profesionalna oprema za frizerske salone – frizerske stolice, praonici i namještaj za pouzdan svakodnevni rad.',
            'beauty_title' => 'Oprema za<br><span class="wob-salon-card__title-line">kozmetičke salone</span>',
            'beauty_description' => 'Profesionalna oprema za kozmetičke salone – kozmetičke stolice, ležajevi i namještaj za udobnost i vrhunsku njegu.',
            'cta' => 'Pogledaj ponudu',
        ),
        1 => array(
            'label' => 'Professional salon equipment',
            'hair_title' => 'Hair salon<br><span class="wob-salon-card__title-line">equipment</span>',
            'hair_description' => 'Professional hair salon equipment – styling chairs, wash units and furniture for reliable everyday work.',
            'beauty_title' => 'Beauty salon<br><span class="wob-salon-card__title-line">equipment</span>',
            'beauty_description' => 'Professional beauty salon equipment – beauty chairs, treatment beds and furniture for comfort and excellent care.',
            'cta' => 'Explore products',
        ),
    );

    $copy = $translations[$languageId];

    return '<section class="wob-salon-gateway" aria-label="' . $copy['label'] . '">
  <a class="wob-salon-card wob-salon-card--hair" href="index.php?route=product/category&amp;path=655" aria-labelledby="wob-salon-hair-title">
    <div class="wob-salon-card__content">
      <h2 class="wob-salon-card__title" id="wob-salon-hair-title">' . $copy['hair_title'] . '</h2>
      <span class="wob-salon-card__rule" aria-hidden="true"></span>
      <span class="wob-salon-card__description">' . $copy['hair_description'] . '</span>
      <span class="wob-salon-card__cta">' . $copy['cta'] . '</span>
    </div>
    <div class="wob-salon-card__visual" aria-hidden="true">
      <img class="wob-salon-card__image" src="catalog/view/theme/basel/image/home-salon/barber-chair-cutout.webp" alt="" width="1090" height="1254" fetchpriority="high" decoding="async">
    </div>
  </a>
  <a class="wob-salon-card wob-salon-card--beauty" href="index.php?route=product/category&amp;path=946" aria-labelledby="wob-salon-beauty-title">
    <div class="wob-salon-card__content">
      <h2 class="wob-salon-card__title" id="wob-salon-beauty-title">' . $copy['beauty_title'] . '</h2>
      <span class="wob-salon-card__rule" aria-hidden="true"></span>
      <span class="wob-salon-card__description">' . $copy['beauty_description'] . '</span>
      <span class="wob-salon-card__cta">' . $copy['cta'] . '</span>
    </div>
    <div class="wob-salon-card__visual" aria-hidden="true">
      <img class="wob-salon-card__image" src="catalog/view/theme/basel/image/home-salon/cosmetic-chair-hyd-a-234d-cutout.webp" alt="" width="1254" height="1254" loading="lazy" decoding="async">
    </div>
  </a>
</section>';
}

function buildModuleSetting()
{
    return array(
        'save' => 'stay',
        'name' => WOB_GATEWAY_MODULE_NAME,
        'status' => '1',
        'b_setting' => array(
            'title' => '0',
            'title_pl' => array('3' => '', '1' => ''),
            'title_m' => array('3' => '', '1' => ''),
            'title_b' => array('3' => '', '1' => ''),
            'custom_m' => '1',
            'mt' => '30',
            'mr' => '0',
            'mb' => '48',
            'ml' => '0',
            'fw' => '0',
            'block_bg' => '0',
            'bg_color' => '',
            'block_bgi' => '0',
            'bg_par' => '0',
            'bg_pos' => 'center center',
            'bg_repeat' => 'no-repeat',
            'block_bgv' => '0',
            'bg_video' => '',
            'block_css' => '0',
            'css' => '',
        ),
        'bg_image' => '',
        'c_setting' => array(
            'fw' => '0',
            'block_css' => '0',
            'css' => '',
            'nm' => '1',
            'eh' => '0',
        ),
        'columns' => array(
            '1' => array(
                'w' => 'col-sm-12',
                'w_sm' => 'col-xs-12',
                'w_md' => 'col-sm-12',
                'w_lg' => 'col-md-12',
                'type' => 'html',
                'data7' => 'vertical-top text-left',
                'data1' => array(
                    '3' => encodeModuleHtml(buildGatewayHtml(3)),
                    '1' => encodeModuleHtml(buildGatewayHtml(1)),
                ),
            ),
        ),
    );
}

function findOrCreateModule(mysqli $database, $settingJson)
{
    $moduleTable = DB_PREFIX . 'module';
    $select = $database->prepare(
        "SELECT `module_id` FROM `{$moduleTable}` WHERE `code` = 'basel_content' AND `name` = ? LIMIT 1"
    );
    $moduleName = WOB_GATEWAY_MODULE_NAME;
    $select->bind_param('s', $moduleName);
    $select->execute();
    $select->bind_result($moduleId);
    $found = $select->fetch();
    $select->close();

    if ($found) {
        $moduleId = (int) $moduleId;
        $update = $database->prepare(
            "UPDATE `{$moduleTable}` SET `setting` = ? WHERE `module_id` = ?"
        );
        $update->bind_param('si', $settingJson, $moduleId);
        $update->execute();
        return array($moduleId, 'updated');
    }

    $insert = $database->prepare(
        "INSERT INTO `{$moduleTable}` (`name`, `code`, `setting`) VALUES (?, 'basel_content', ?)"
    );
    $insert->bind_param('ss', $moduleName, $settingJson);
    $insert->execute();

    return array((int) $database->insert_id, 'created');
}

function replaceHomeSlider(mysqli $database, $moduleId)
{
    $layoutTable = DB_PREFIX . 'layout';
    $layoutModuleTable = DB_PREFIX . 'layout_module';
    $layout = $database->query(
        "SELECT `layout_id` FROM `{$layoutTable}` WHERE `name` = 'Home' ORDER BY `layout_id` LIMIT 1"
    );

    if (!$layout->num_rows) {
        throw new RuntimeException('The Home layout was not found.');
    }

    $layoutId = (int) $layout->fetch_assoc()['layout_id'];
    $deleteSlider = $database->prepare(
        "DELETE FROM `{$layoutModuleTable}` WHERE `layout_id` = ? AND `code` LIKE 'basel_layerslider.%'"
    );
    $deleteSlider->bind_param('i', $layoutId);
    $deleteSlider->execute();
    $removedSliders = $deleteSlider->affected_rows;

    $moduleCode = 'basel_content.' . $moduleId;
    $select = $database->prepare(
        "SELECT `layout_module_id` FROM `{$layoutModuleTable}` WHERE `layout_id` = ? AND `code` = ? LIMIT 1"
    );
    $select->bind_param('is', $layoutId, $moduleCode);
    $select->execute();
    $select->bind_result($layoutModuleId);
    $found = $select->fetch();
    $select->close();

    if ($found) {
        $layoutModuleId = (int) $layoutModuleId;
        $update = $database->prepare(
            "UPDATE `{$layoutModuleTable}` SET `position` = 'top', `sort_order` = 10 WHERE `layout_module_id` = ?"
        );
        $update->bind_param('i', $layoutModuleId);
        $update->execute();
        return array('updated', $removedSliders);
    }

    $insert = $database->prepare(
        "INSERT INTO `{$layoutModuleTable}` (`layout_id`, `code`, `position`, `sort_order`) VALUES (?, ?, 'top', 10)"
    );
    $insert->bind_param('is', $layoutId, $moduleCode);
    $insert->execute();

    return array('created', $removedSliders);
}

try {
    $database->begin_transaction();
    $settingJson = json_encode(buildModuleSetting(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($settingJson === false) {
        throw new RuntimeException('Unable to encode the Basel Content module setting.');
    }

    list($moduleId, $moduleAction) = findOrCreateModule($database, $settingJson);
    list($layoutAction, $removedSliders) = replaceHomeSlider($database, $moduleId);
    $database->commit();

    echo strtoupper($moduleAction) . ' Basel Content module #' . $moduleId . PHP_EOL;
    echo strtoupper($layoutAction) . ' Home layout placement at sort order 10' . PHP_EOL;
    echo 'REMOVED ' . $removedSliders . ' LayerSlider placement(s) from Home' . PHP_EOL;
} catch (Throwable $exception) {
    $database->rollback();
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $database->close();
}
