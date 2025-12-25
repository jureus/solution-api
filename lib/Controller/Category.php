<?php

namespace Solution\Api\Controller;

use Bitrix\Main\Error;

class Category extends Base
{
    /**
     * Задание 1.1: Дерево категорий
     * GET /api/v1/categories
     */
    public function listAction()
    {
        try {
            $iblockId = $this->getIblockId();

            // Получаем все активные разделы
            $rsSections = \CIBlockSection::GetList(
                ['LEFT_MARGIN' => 'ASC', 'SORT' => 'ASC'], // Сортировка по структуре дерева
                [
                    'IBLOCK_ID' => $iblockId,
                    'ACTIVE' => 'Y',
                    'GLOBAL_ACTIVE' => 'Y'
                ],
                false,
                ['ID', 'NAME', 'SECTION_PAGE_URL', 'PICTURE', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL']
            );

            $sections = [];
            while ($arSection = $rsSections->GetNext()) {
                $sections[$arSection['ID']] = [
                    'id' => (int)$arSection['ID'],
                    'name' => $arSection['NAME'],
                    'url' => $arSection['SECTION_PAGE_URL'],
                    'picture' => $this->getPictureUrl($arSection['PICTURE']),
                    'parent_id' => (int)$arSection['IBLOCK_SECTION_ID'],
                    'children' => []
                ];
            }

            // Строим дерево из плоского списка
            $tree = [];
            foreach ($sections as $id => &$section) {
                if ($section['parent_id'] && isset($sections[$section['parent_id']])) {
                    $sections[$section['parent_id']]['children'][] = &$section;
                } else {
                    $tree[] = &$section;
                }
            }

            // Чистим ссылки (чтобы не было рекурсии в json_encode) и лишние поля
            $cleanTree = $this->cleanUpTree($tree);

            return ['data' => $cleanTree, 'status' => 'success'];

        } catch (\Exception $e) {
            $this->addError(new Error($e->getMessage()));
            return null;
        }
    }

    /**
     * Задание 1.2: Список товаров в категории
     * GET /api/v1/categories/{categoryId}/products
     */
    public function productsAction($categoryId)
    {
        try {
            $iblockId = $this->getIblockId();

            // Выбираем товары
            $rsElements = \CIBlockElement::GetList(
                ['SORT' => 'ASC'],
                [
                    'IBLOCK_ID' => $iblockId,
                    'SECTION_ID' => $categoryId,
                    'INCLUDE_SUBSECTIONS' => 'Y', // Включая подкатегории (обычно так принято)
                    'ACTIVE' => 'Y'
                ],
                false,
                false,
                ['ID', 'NAME', 'DETAIL_PAGE_URL', 'PREVIEW_PICTURE', 'DETAIL_PICTURE']
            );

            $products = [];
            $productIds = [];

            while ($ob = $rsElements->GetNextElement()) {
                $fields = $ob->GetFields();
                $products[$fields['ID']] = [
                    'id' => (int)$fields['ID'],
                    'name' => $fields['NAME'],
                    'url' => $fields['DETAIL_PAGE_URL'],
                    'picture' => $this->getPictureUrl($fields['PREVIEW_PICTURE'] ?: $fields['DETAIL_PICTURE']),
                    'price_from' => 0 // Цену вычислим ниже
                ];
                $productIds[] = $fields['ID'];
            }

            // Вычисление минимальной цены (с учетом торговых предложений)
            if (!empty($productIds)) {
                $products = $this->fillMinPrices($products, $productIds);
            }

            return ['data' => array_values($products), 'status' => 'success'];

        } catch (\Exception $e) {
            $this->addError(new Error($e->getMessage()));
            return null;
        }
    }

    // Рекурсивная очистка для дерева
    private function cleanUpTree($tree) {
        $result = [];
        foreach ($tree as $node) {
            $cleanNode = [
                'id' => $node['id'],
                'name' => $node['name'],
                'url' => $node['url'],
                'picture' => $node['picture'],
                'children' => []
            ];
            if (!empty($node['children'])) {
                $cleanNode['children'] = $this->cleanUpTree($node['children']);
            }
            $result[] = $cleanNode;
        }
        return $result;
    }

    // Вычисление цены "от"
    private function fillMinPrices($products, $productIds) {
        // Получаем оптимальные цены для списка товаров
        // В демо-магазине товары с SKU, поэтому цена лежит в торговых предложениях

        // 1. Проверяем наличие SKU
        $skuInfo = \CCatalogSKU::GetInfoByProductIBlock($this->getIblockId());

        if ($skuInfo) {
            // Получаем все торговые предложения для выбранных товаров
            $offersList = \CCatalogSKU::getOffersList(
                $productIds,
                $this->getIblockId(),
                ['ACTIVE' => 'Y'],
                ['ID', 'ParentID'],
                ['CODE' => ['BASE']] // Получаем цену BASE
            );

            foreach ($products as $pid => &$product) {
                $minPrice = null;

                if (isset($offersList[$pid])) {
                    // Перебираем офферы
                    foreach ($offersList[$pid] as $offer) {
                        // Получаем цену оффера
                        $priceData = \CPrice::GetBasePrice($offer['ID']);
                        if ($priceData && isset($priceData['PRICE'])) {
                            if ($minPrice === null || $priceData['PRICE'] < $minPrice) {
                                $minPrice = $priceData['PRICE'];
                            }
                        }
                    }
                } else {
                    // Если это простой товар без SKU
                    $priceData = \CPrice::GetBasePrice($pid);
                    if ($priceData) $minPrice = $priceData['PRICE'];
                }

                $product['price_from'] = $minPrice ? (float)$minPrice : 0;
            }
        }

        return $products;
    }
}