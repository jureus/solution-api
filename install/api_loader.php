<?php
// 1. Подключаем ядро (без визуальной части)
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Context;
use Bitrix\Main\Application;

// 2. Грузим модуль
if (!Loader::includeModule('solution.api')) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Module solution.api not installed']);
    die();
}

// 3. Разбор URL
$request = Context::getCurrent()->getRequest();
$uri = $request->getRequestUri();
$path = parse_url($uri, PHP_URL_PATH);

// Переменные для маршрутизации
$controller = null;
$result = null;

try {
    // --- РУЧНАЯ МАРШРУТИЗАЦИЯ (Manual Dispatch) ---

    // 1. Категории: /api/v1/categories
    if (preg_match('#^/api/v1/categories$#', $path)) {
        $controller = new \Solution\Api\Controller\Category();
        // Вызываем метод напрямую, без посредников
        $result = $controller->listAction();
    }

    // 2. Товары в категории: /api/v1/categories/123/products
    elseif (preg_match('#^/api/v1/categories/(\d+)/products$#', $path, $matches)) {
        $categoryId = (int)$matches[1];
        $controller = new \Solution\Api\Controller\Category();
        // Передаем ID напрямую в аргумент функции
        $result = $controller->productsAction($categoryId);
    }

    // 3. Детальная товара: /api/v1/products/123
    elseif (preg_match('#^/api/v1/products/(\d+)$#', $path, $matches)) {
        $productId = (int)$matches[1];
        $controller = new \Solution\Api\Controller\Product();
        // Передаем ID напрямую
        $result = $controller->detailAction($productId);
    }

    // 4. Обработка результата
    if ($controller) {
        // Если метод вернул массив данных
        if (is_array($result)) {
            header('Content-Type: application/json');
            echo Json::encode($result);
        } else {
            // Если вернулся null, проверяем ошибки контроллера
            $errors = $controller->getErrors();
            if (!empty($errors)) {
                $errMessages = [];
                foreach ($errors as $error) $errMessages[] = $error->getMessage();

                header('Content-Type: application/json');
                // Если ошибка 404 (товар не найден), отдаем правильный код
                if (isset($errMessages[0]) && $errMessages[0] == 'Товар не найден') {
                    http_response_code(404);
                }
                echo json_encode(['status' => 'error', 'errors' => $errMessages]);
            } else {
                // Пустой успешный ответ
                header('Content-Type: application/json');
                echo Json::encode(['status' => 'success', 'data' => []]);
            }
        }
    } else {
        // Маршрут не совпал
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'API Route not found', 'path' => $path]);
    }

} catch (\Throwable $e) {
    // Ловим любые исключения
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_after.php");