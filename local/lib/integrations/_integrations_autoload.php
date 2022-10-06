<?php
// Integrations autoload
use Bitrix\Main\LoaderException;

try {
    Bitrix\Main\Loader::registerAutoLoadClasses(null, array(
        // Integrations API
        'IMarketplaceIntegration' => '/local/lib/integrations/MarketplaceIntegration/IMarketplaceIntegration.php',
        '\MarketplaceIntegration\IntegrationUser' => '/local/lib/integrations/MarketplaceIntegration/IntegrationUser.php',
        '\MarketplaceIntegration\IntegrationProduct' => '/local/lib/integrations/MarketplaceIntegration/IntegrationProduct.php',
        '\MarketplaceIntegration\IntegrationBasket' => '/local/lib/integrations/MarketplaceIntegration/IntegrationBasket.php',
        '\MarketplaceIntegration\IntegrationOrder' => '/local/lib/integrations/MarketplaceIntegration/IntegrationOrder.php',
        '\MarketplaceIntegration\IntegrationDelivery' => '/local/lib/integrations/MarketplaceIntegration/IntegrationDelivery.php',
        '\MarketplaceIntegration\IntegrationPayment' => '/local/lib/integrations/MarketplaceIntegration/IntegrationPayment.php',
        '\MarketplaceIntegration\Notifier' => '/local/lib/integrations/MarketplaceIntegration/Notifier.php',
        '\MarketplaceIntegration\ChestniyZnakPropertyHelper' => '/local/lib/integrations/MarketplaceIntegration/ChestniyZnakPropertyHelper.php',

        // Ozon integration
        '\OzonIntegration\OzonIntegrationBase' => '/local/lib/integrations/ozon/OzonIntegrationBase.php',
        '\OzonIntegration\OzonRequestsHelper' => '/local/lib/integrations/ozon/OzonRequestsHelper.php',
        '\OzonIntegration\OzonObtainOrders' => '/local/lib/integrations/ozon/OzonObtainOrders.php',
        '\OzonIntegration\OzonInsertOrders' => '/local/lib/integrations/ozon/OzonInsertOrders.php',
        '\OzonIntegration\OzonSendShippingOrder' => '/local/lib/integrations/ozon/OzonSendShippingOrder.php',
        '\OzonIntegration\OzonShippingOrderAfterApi' => '/local/lib/integrations/ozon/OzonShippingOrderAfterApi.php',

        // VTB Collection integration
        '\VtbCollectionIntegration\VtbCollectionIntegrationBase' => '/local/lib/integrations/vtbCollection/VtbCollectionIntegrationBase.php',
        '\VtbCollectionIntegration\VtbCollectionEventHandlers' => '/local/lib/integrations/vtbCollection/VtbCollectionEventHandlers.php',
        '\VtbCollectionIntegration\VtbCollectionCheckOrder' => '/local/lib/integrations/vtbCollection/VtbCollectionCheckOrder.php',
        '\VtbCollectionIntegration\VtbCollectionCheckDelivery' => '/local/lib/integrations/vtbCollection/VtbCollectionCheckDelivery.php',
        '\VtbCollectionIntegration\VtbCollectionInsertOrder' => '/local/lib/integrations/vtbCollection/VtbCollectionInsertOrder.php',

        // SberMegaMarket
        '\SberMegaMarketIntegration\SberMegaMarketIntegrationBase' => '/local/lib/integrations/sberMegaMarket/SberMegaMarketIntegrationBase.php',
        '\SberMegaMarketIntegration\SberMegaMarketRequestHelper' => '/local/lib/integrations/sberMegaMarket/SberMegaMarketRequestHelper.php',
        '\SberMegaMarketIntegration\SberMegaMarketRequests' => '/local/lib/integrations/sberMegaMarket/SberMegaMarketRequests.php',
        '\SberMegaMarketIntegration\SberMegaMarketInsertOrder' => '/local/lib/integrations/sberMegaMarket/SberMegaMarketInsertOrder.php',
        '\SberMegaMarketIntegration\SberMegaMarketCheckOrder' => '/local/lib/integrations/sberMegaMarket/SberMegaMarketCheckOrder.php',
        '\SberMegaMarketIntegration\SberMegaMarketPackingOrder' => '/local/lib/integrations/sberMegaMarket/SberMegaMarketPackingOrder.php',
        '\SberMegaMarketIntegration\SberMegaMarketShippingOrder' => '/local/lib/integrations/sberMegaMarket/SberMegaMarketShippingOrder.php',
        '\SberMegaMarketIntegration\SberMegaMarketCancelOrder' => '/local/lib/integrations/sberMegaMarket/SberMegaMarketCancelOrder.php',
        '\SberMegaMarketIntegration\SberMegaMarketDataUpdate' => '/local/lib/integrations/sberMegaMarket/dataUpdate/SberMegaMarketDataUpdate.php',
    ));
} catch (LoaderException $e) {
    file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/local/logs/class_loading.err', $e->getMessage());
}