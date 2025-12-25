<?php

namespace Solution\Api\Controller;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Highloadblock\HighloadBlockTable;

class Product extends Base
{
    /**
     * Задание 1.3: Детальная информация о товаре
     */
    public function detailAction($productId)
    {
        try {
            // Подключаем модуль HL-блоков, так как бренды и цвета там
            Loader::includeModule('highloadblock');

            $iblockId = $this->getIblockId();

            // Основные поля товара
            $rsElement = \CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => $iblockId, 'ID' => $productId, 'ACTIVE' => 'Y'],
                false,
                false,
                ['ID', 'NAME', 'DETAIL_PAGE_URL', 'DETAIL_PICTURE', 'PROPERTY_MORE_PHOTO', 'PROPERTY_BRAND_REF', 'PROPERTY_MANUFACTURER', 'PROPERTY_MATERIAL']
            );

            if (!($ob = $rsElement->GetNextElement())) {
                $this->addError(new Error('Товар не найден', 404));
                return null;
            }

            $fields = $ob->GetFields();
            $props = $ob->GetProperties();

            // Галерея
            $gallery = [];
            if ($fields['DETAIL_PICTURE']) {
                $gallery[] = $this->getPictureUrl($fields['DETAIL_PICTURE']);
            }
            if (isset($props['MORE_PHOTO']) && !empty($props['MORE_PHOTO']['VALUE'])) {
                $photos = is_array($props['MORE_PHOTO']['VALUE']) ? $props['MORE_PHOTO']['VALUE'] : [$props['MORE_PHOTO']['VALUE']];
                foreach ($photos as $fid) {
                    $gallery[] = $this->getPictureUrl($fid);
                }
            }

            // Артикул родителя
            $parentArticle = $this->getSmartPropValue($props, ['ART', 'Артикул']);

            $productData = [
                'id' => (int)$fields['ID'],
                'name' => $fields['NAME'],
                'url' => $fields['DETAIL_PAGE_URL'],
                'gallery' => $gallery,
                'properties' => [
                    'brand' => $this->getSmartPropValue($props, ['BRAND', 'Бренд']),
                    'manufacturer' => $this->getSmartPropValue($props, ['MANUFACTURER', 'Производитель']),
                    'material' => $this->getSmartPropValue($props, ['MATERIAL', 'Материал']),
                ],
                'offers' => $this->getOffers($productId, $iblockId, $parentArticle)
            ];

            return ['data' => $productData, 'status' => 'success'];

        } catch (\Exception $e) {
            $this->addError(new Error($e->getMessage()));
            return null;
        }
    }

    private function getOffers($productId, $iblockId, $parentArticle = null)
    {
        $offers = [];
        $skuInfo = \CCatalogSKU::GetInfoByProductIBlock($iblockId);

        if ($skuInfo) {
            $skuIblockId = $skuInfo['IBLOCK_ID'];

            $rsOffers = \CIBlockElement::GetList(
                ['SORT' => 'ASC', 'ID' => 'ASC'],
                [
                    'IBLOCK_ID' => $skuIblockId,
                    'PROPERTY_' . $skuInfo['SKU_PROPERTY_ID'] => $productId,
                    'ACTIVE' => 'Y'
                ]
            );

            while ($ob = $rsOffers->GetNextElement()) {
                $fields = $ob->GetFields();
                $props = $ob->GetProperties();

                $size = $this->getSmartPropValue($props, ['SIZE', 'Размер']);
                $color = $this->getSmartPropValue($props, ['COLOR', 'Цвет']);
                $article = $this->getSmartPropValue($props, ['ART', 'Артикул']);

                if (empty($article) && !empty($parentArticle)) {
                    $article = $parentArticle;
                }

                $offers[] = [
                    'id' => (int)$fields['ID'],
                    'name' => $fields['NAME'],
                    'article' => (string)$article,
                    'color' => (string)$color,
                    'size' => (string)$size,
                ];
            }
        }
        return $offers;
    }

    /**
     * Умный поиск значений
     */
    private function getSmartPropValue($props, $keywords)
    {
        foreach ($props as $code => $prop) {
            if (empty($prop['VALUE'])) continue;

            foreach ($keywords as $word) {
                if (stripos($code, $word) !== false || stripos($prop['NAME'], $word) !== false) {
                    return $this->extractValue($prop);
                }
            }
        }
        return null;
    }

    private function extractValue($prop)
    {
        // 1. Списки (L)
        if ($prop['PROPERTY_TYPE'] == 'L') {
            return $prop['VALUE_ENUM'];
        }

        // 2. Справочники (S:directory) - HighloadBlock
        // Здесь самое важное изменение!
        if ($prop['USER_TYPE'] == 'directory' && !empty($prop['USER_TYPE_SETTINGS']['TABLE_NAME'])) {
            $tableName = $prop['USER_TYPE_SETTINGS']['TABLE_NAME'];
            $xmlIds = is_array($prop['VALUE']) ? $prop['VALUE'] : [$prop['VALUE']];

            $realValues = $this->getHighloadBlockValue($tableName, $xmlIds);

            if (!empty($realValues)) {
                return is_array($prop['VALUE']) ? $realValues : $realValues[0];
            }
            // Если не нашли в базе, возвращаем хотя бы XML_ID (red, company2)
            return is_array($prop['VALUE']) ? implode(', ', $prop['VALUE']) : $prop['VALUE'];
        }

        // 3. Остальное
        $val = $prop['VALUE'];
        return is_array($val) ? implode(', ', $val) : $val;
    }

    /**
     * Получает реальное название (UF_NAME) из Highload-блока по XML_ID
     */
    private function getHighloadBlockValue($tableName, $xmlIds)
    {
        $result = [];

        // Статический кэш, чтобы не дергать базу в цикле офферов
        static $cache = [];
        $cacheKey = $tableName . '|' . implode(',', $xmlIds);

        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        try {
            // Находим HL-блок по имени таблицы
            $hlblock = HighloadBlockTable::getList([
                'filter' => ['=TABLE_NAME' => $tableName]
            ])->fetch();

            if ($hlblock) {
                $entity = HighloadBlockTable::compileEntity($hlblock);
                $entityClass = $entity->getDataClass();

                $rsData = $entityClass::getList([
                    'select' => ['UF_NAME', 'UF_XML_ID'],
                    'filter' => ['=UF_XML_ID' => $xmlIds]
                ]);

                $map = [];
                while ($item = $rsData->fetch()) {
                    $map[$item['UF_XML_ID']] = $item['UF_NAME'];
                }

                // Собираем результат в том порядке, в котором пришли ID
                foreach ($xmlIds as $xmlId) {
                    if (isset($map[$xmlId])) {
                        $result[] = $map[$xmlId];
                    } else {
                        $result[] = $xmlId;
                    }
                }
            }
        } catch (\Exception $e) {
            // Если ошибка, вернем пустой массив, метод выше вернет XML_ID
        }

        $cache[$cacheKey] = $result;
        return $result;
    }
}