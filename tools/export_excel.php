<?php
/**
 * Скрипт экспорта товаров в Excel и отправки на E-mail.
 * Запуск: php -f local/modules/solution.api/tools/export_excel.php [email@example.com]
 *
 * ИНСТРУКЦИЯ ПО НАСТРОЙКЕ ПОЧТЫ:
 * 1. В админке (Настройки -> Настройки продукта -> Почтовые события -> Типы почтовых событий)
 *    создать тип события:
 *    - Тип события: EXPORT_PRODUCTS_FINISH
 *
 * 2. Там же создать Почтовый шаблон:
 *    - Тип события: EXPORT_PRODUCTS_FINISH
 *    - От кого: #DEFAULT_EMAIL_FROM#
 *    - Кому: #EMAIL_TO#
 *    - Тема: Экспорт товаров завершен
 *    - Сообщение: Файл с выгрузкой товаров находится во вложении.
 *
 * Без создания типа события и шаблона письмо не уйдет!
 */

// Определяем DOCUMENT_ROOT для консольного запуска
if (empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = dirname(__DIR__, 4); // Поднимаемся на 4 уровня вверх
}

define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Event;
use Bitrix\Iblock\IblockTable;

// Проверяем аргументы запуска (можно передать email первым параметром)
$emailTo = isset($argv[1]) ? $argv[1] : 'admin@example.com';
$iblockCode = 'clothes'; // Символьный код каталога

if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
    fwrite(STDERR, "Error: Modules not installed\n");
    exit(1);
}

echo "Start export for iblock '$iblockCode' to '$emailTo'...\n";

// 1. Получаем ID инфоблока
$iblock = IblockTable::getList([
    'filter' => ['=CODE' => $iblockCode],
    'select' => ['ID']
])->fetch();

if (!$iblock) {
    fwrite(STDERR, "Error: Iblock '$iblockCode' not found\n");
    exit(1);
}
$iblockId = $iblock['ID'];

// 2. Выбираем товары
// Сортировка: "Наименование категории" и "Наименование товара"
$rsElements = \CIBlockElement::GetList(
    ['IBLOCK_SECTION.NAME' => 'ASC', 'NAME' => 'ASC'],
    ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
    false,
    false,
    ['ID', 'NAME', 'IBLOCK_SECTION_ID', 'DETAIL_PAGE_URL']
);

$rows = [];
$serverName = \Bitrix\Main\Config\Option::get('main', 'server_name', $_SERVER['SERVER_NAME']);
$protocol = \CMain::IsHTTPS() ? 'https://' : 'http://';
$fullDomain = $protocol . $serverName;

echo "Fetching items...\n";

while ($ob = $rsElements->GetNextElement()) {
    $fields = $ob->GetFields();

    // Получаем путь категории (Обувь / Тапочки)
    $categoryPath = 'Без категории';
    if ($fields['IBLOCK_SECTION_ID']) {
        $nav = \CIBlockSection::GetNavChain($iblockId, $fields['IBLOCK_SECTION_ID'], ['NAME'], true);
        $names = array_column($nav, 'NAME');
        $categoryPath = implode(' / ', $names);
    }

    // Данные по SKU и ценам
    $skuCount = 0;
    $minPrice = 0;

    // Проверяем наличие SKU
    $skuInfo = \CCatalogSKU::GetInfoByProductIBlock($iblockId);

    if ($skuInfo) {
        $rsOffers = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $skuInfo['IBLOCK_ID'], 'PROPERTY_'.$skuInfo['SKU_PROPERTY_ID'] => $fields['ID'], 'ACTIVE'=>'Y'],
            false, false, ['ID']
        );
        while ($offer = $rsOffers->GetNext()) {
            $skuCount++;
            // Получаем цену (оптимальную)
            $priceData = \CPrice::GetBasePrice($offer['ID']);
            if ($priceData) {
                $price = (float)$priceData['PRICE'];
                if ($minPrice === 0 || $price < $minPrice) {
                    $minPrice = $price;
                }
            }
        }
    } else {
        // Простой товар
        $priceData = \CPrice::GetBasePrice($fields['ID']);
        if ($priceData) $minPrice = (float)$priceData['PRICE'];
    }

    $rows[] = [
        'ID' => $fields['ID'],
        'NAME' => $fields['NAME'],
        'CATEGORY' => $categoryPath,
        'URL' => $fullDomain . $fields['DETAIL_PAGE_URL'],
        'SKU_COUNT' => $skuCount,
        'PRICE' => $minPrice
    ];
}

echo "Found " . count($rows) . " items. Generating Excel...\n";

// 3. Формируем Excel (HTML Table формат)
// Это самый надежный способ сделать стили (рамки, жирный текст) без библиотек
$content = '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>';
$content .= '<table border="1" style="border-collapse: collapse; width: 100%; border: 1px solid black;">';

// Заголовки (жирные)
$headerStyle = 'background-color: #f0f0f0; font-weight: bold; text-align: center; border: 1px solid black; padding: 5px;';
$cellStyle = 'border: 1px solid black; padding: 5px; vertical-align: top;';

$content .= '<thead><tr>';
$content .= '<th style="' . $headerStyle . '">ID</th>';
$content .= '<th style="' . $headerStyle . '">Наименование</th>';
$content .= '<th style="' . $headerStyle . '">Категория</th>';
$content .= '<th style="' . $headerStyle . '">Ссылка</th>';
$content .= '<th style="' . $headerStyle . '">SKU шт.</th>';
$content .= '<th style="' . $headerStyle . '">Цена от</th>';
$content .= '</tr></thead><tbody>';

foreach ($rows as $row) {
    $content .= '<tr>';
    $content .= '<td style="' . $cellStyle . '">' . $row['ID'] . '</td>';
    $content .= '<td style="' . $cellStyle . '">' . htmlspecialcharsbx($row['NAME']) . '</td>';
    $content .= '<td style="' . $cellStyle . '">' . htmlspecialcharsbx($row['CATEGORY']) . '</td>';
    // Гиперссылка
    $content .= '<td style="' . $cellStyle . '"><a href="' . $row['URL'] . '">Перейти</a></td>';
    $content .= '<td style="' . $cellStyle . '">' . $row['SKU_COUNT'] . '</td>';
    $content .= '<td style="' . $cellStyle . '">' . number_format($row['PRICE'], 2, '.', ' ') . '</td>';
    $content .= '</tr>';
}
$content .= '</tbody></table></body></html>';

// 4. Сохраняем файл
$fileName = 'export_' . date('Ymd_His') . '.xls';
$filePath = $_SERVER['DOCUMENT_ROOT'] . '/upload/' . $fileName;

if (file_put_contents($filePath, $content) === false) {
    fwrite(STDERR, "Error: Cannot write file $filePath\n");
    exit(1);
}

echo "File saved: $filePath\n";

// 5. Отправка почты
$fileArray = \CFile::MakeFileArray($filePath);
$fileId = \CFile::SaveFile($fileArray, 'export_xls');

if ($fileId) {
    // 1. Программно ищем ID активного шаблона для нашего события
    $templateId = "";
    $dbTemplates = \CEventMessage::GetList($by="site_id", $order="desc", [
        "TYPE_ID" => "EXPORT_PRODUCTS_FINISH",
        "SITE_ID" => "s1",
        "ACTIVE"  => "Y"
    ]);

    if ($tpl = $dbTemplates->Fetch()) {
        $templateId = $tpl['ID'];
        echo "Found template ID: " . $templateId . "\n";
    } else {
        echo "Error: Template not found. Please create template for EXPORT_PRODUCTS_FINISH\n";
    }

    // 2. Отправляем письмо, ЯВНО указывая ID шаблона
    if ($templateId) {
        $result = \CEvent::Send(
            "EXPORT_PRODUCTS_FINISH", // Тип события
            "s1",                     // Сайт
            ["EMAIL_TO" => $emailTo], // Поля
            "Y",                      // Дублировать
            $templateId,              // <--- ВОТ ЗДЕСЬ МЫ ЖЕСТКО ЗАДАЕМ ШАБЛОН
            [$fileId],                // Файлы
            "ru"                      // Язык
        );

        if ($result) {
            echo "Event created successfully with ID: " . $result . "\n";
        } else {
            echo "Error creating event.\n";
        }
    }
} else {
    echo "Error saving file for attachment.\n";
}

echo "Done.\n";