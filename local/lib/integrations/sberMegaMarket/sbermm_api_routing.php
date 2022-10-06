<?php

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Routing\RoutingConfigurator;
use SberMegaMarketIntegration\SberMegaMarketIntegrationBase;


// This script describes routes for SberMegaMarket ingegraion

/** API for SberMegaMarket services */
const _SBER_MEGA_MARKET_API_PREFIX = 'api/market/v1/orderService';


return function (RoutingConfigurator $routes) {
    $routes->prefix(_SBER_MEGA_MARKET_API_PREFIX)->group(function (RoutingConfigurator $routes) {

        $routes->post('order/new/', function (HttpRequest $request) {
            $apiRequest = new SberMegaMarketIntegrationBase();
            $apiRequest->handleRequest('order-new', $request, file_get_contents('php://input'));
        });

        $routes->post('order/cancel/', function (HttpRequest $request) {
            $apiRequest = new SberMegaMarketIntegrationBase();
            $apiRequest->handleRequest('order-cancel', $request, file_get_contents('php://input'));
        });

        $routes->post('order/pack/', function (HttpRequest $request) {
            $apiRequest = new SberMegaMarketIntegrationBase();
            $apiRequest->handleRequest('pack-orders', $request, file_get_contents('php://input'));
        });
    });
};