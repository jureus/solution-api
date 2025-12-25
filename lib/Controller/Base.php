<?php

namespace Solution\Api\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Loader;
use Bitrix\Iblock\IblockTable;

class Base extends Controller
{
    const IBLOCK_CODE = 'clothes';
    protected static $iblockId = null;

    protected function init()
    {
        parent::init();
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');
        Loader::includeModule('sale');
    }

    /**
     * Получает ID инфоблока по коду (кэширует в статическую переменную)
     */
    protected function getIblockId()
    {
        if (self::$iblockId === null) {
            $result = IblockTable::getList([
                'filter' => ['=CODE' => self::IBLOCK_CODE],
                'select' => ['ID'],
                'limit' => 1
            ])->fetch();

            if ($result) {
                self::$iblockId = (int)$result['ID'];
            } else {
                // Если не нашли по коду, падаем (или можно временно вернуть 2)
                throw new \Exception('Инфоблок с кодом ' . self::IBLOCK_CODE . ' не найден');
            }
        }
        return self::$iblockId;
    }

    /**
     * Конфигурация: отключаем CSRF и авторизацию для теста
     */
    public function configureActions()
    {
        return [
            'list' => ['prefilters' => []],
            'products' => ['prefilters' => []],
            'detail' => ['prefilters' => []],
        ];
    }

    /**
     * Помощник для картинок (возвращает полный путь)
     */
    protected function getPictureUrl($fileId)
    {
        if (!$fileId) return null;
        $path = \CFile::GetPath($fileId);
        // В рамках API лучше отдавать полный путь с доменом, но для теста хватит относительного
        return $path ?: null;
    }
}