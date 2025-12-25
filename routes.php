<?php
use Bitrix\Main\Routing\RoutingConfigurator;

return function (RoutingConfigurator $routes) {
    $routes->prefix('api/v1')
        ->group(function (RoutingConfigurator $routes) {
            $routes->get('categories', ['\Solution\Api\Controller\Category', 'listAction']);

            $routes->get('categories/{categoryId}/products', ['\Solution\Api\Controller\Category', 'productsAction'])
                ->where('categoryId', '\d+');

            $routes->get('products/{productId}', ['\Solution\Api\Controller\Product', 'detailAction'])
                ->where('productId', '\d+');
        });
};