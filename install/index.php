<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\UrlRewriter;

Loc::loadMessages(__FILE__);

class solution_api extends CModule
{
    public $MODULE_ID = 'solution.api';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;

    public function __construct()
    {
        $this->MODULE_VERSION = '1.0.0';
        $this->MODULE_VERSION_DATE = '2025-01-01 00:00:00';
        $this->MODULE_NAME = 'REST API Интернет-магазина';
        $this->MODULE_DESCRIPTION = 'Тестовое задание: API для каталога одежды';
        $this->PARTNER_NAME = 'Candidate';
        $this->PARTNER_URI = '';
    }

    public function DoInstall()
    {
        ModuleManager::registerModule($this->MODULE_ID);
        $this->InstallFiles();
        $this->InstallUrlRewrite();
        return true;
    }

    public function DoUninstall()
    {
        $this->UnInstallUrlRewrite();
        $this->UnInstallFiles();
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }

    public function InstallFiles()
    {
        // Копируем api_loader.php из папки install модуля в корень local/
        $source = __DIR__ . '/api_loader.php';
        $target = $_SERVER['DOCUMENT_ROOT'] . '/local/api_loader.php';

        if (file_exists($source)) {
            // Создаем папку local если её нет (бывает на чистых установках)
            if (!file_exists($_SERVER['DOCUMENT_ROOT'] . '/local/')) {
                mkdir($_SERVER['DOCUMENT_ROOT'] . '/local/', 0755, true);
            }
            copy($source, $target);
        }
        return true;
    }

    public function UnInstallFiles()
    {
        // Удаляем файл при деинсталляции
        $target = $_SERVER['DOCUMENT_ROOT'] . '/local/api_loader.php';
        if (file_exists($target)) {
            unlink($target);
        }
        return true;
    }

    public function InstallUrlRewrite()
    {
        // Добавляем правило роутинга
        $arRule = [
            'CONDITION' => '#^/api/#',
            'RULE' => '',
            'ID' => '',
            'PATH' => '/local/api_loader.php',
            'SORT' => 10,
        ];

        UrlRewriter::add('s1', $arRule);
        return true;
    }

    public function UnInstallUrlRewrite()
    {
        // Удаляем правило по PATH
        UrlRewriter::delete('s1', ['PATH' => '/local/api_loader.php']);
        return true;
    }
}