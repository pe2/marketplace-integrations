<?php

namespace MarketplaceIntegration;

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Sale\Delivery\Services\Manager;

/**
 * Trait IntegrationDelivery
 * This trait used for working with shipment and delivery
 *
 * @package MarketplaceIntegration
 */
trait IntegrationDelivery
{
    /**
     * IntegrationDelivery constructor.
     */
    public function __construct()
    {
        try {
            Loader::includeModule('sale');
        } catch (\Bitrix\Main\LoaderException $e) {
            //
        }
    }


    /**
     * Method appends shipment and delivery for order
     *
     * @param array $arDeliveryInfo Array with delivery info ['id', 'cost']
     * @param object $order \Bitrix\Sale\Order object
     * @param object $basket \Bitrix\Sale\Basket object
     *
     * @return bool
     *
     * @throws SystemException
     */
    private function appendDeliveryToOrder(array $arDeliveryInfo, object &$order, object &$basket): bool
    {
        $shipmentCollection = $order->getShipmentCollection();
        $shipment = $shipmentCollection->createItem();

        $service = Manager::getById($arDeliveryInfo['id']);
        $deliveryData = array(
            'DELIVERY_ID' => $service['ID'],
            'DELIVERY_NAME' => $service['NAME'],
            'ALLOW_DELIVERY' => 'Y',
            'BASE_PRICE_DELIVERY' => $arDeliveryInfo['cost'],
            'PRICE_DELIVERY' => $arDeliveryInfo['cost'],
            'DISCOUNT_PRICE' => $arDeliveryInfo['cost'],
            'CUSTOM_PRICE_DELIVERY' => 'Y'
        );
        $shipment->setFields($deliveryData);

        $shipmentItemCollection = $shipment->getShipmentItemCollection();
        foreach ($basket as $item) {
            $shipmentItem = $shipmentItemCollection->createItem($item);
            $shipmentItem->setQuantity($item->getQuantity());
        }

        return true;
    }
}