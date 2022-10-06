<?php

namespace SberMegaMarketIntegration;

use Bitrix\Sale\Order;
use Exception;
use MarketplaceIntegration\IntegrationOrder;
use OrderPropsHandler;

class SberMegaMarketShippingOrder extends SberMegaMarketIntegrationBase
{
    /** @var string Order status to send shipping request */
    private const SBERMM_ORDER_STATUS_TO_SEND_SHIPPING_REQUEST = 'SH';

    /**
     * Method sends shipping request
     *
     * @param Order $order
     */
    public static function checkAndSendShippingRequest(Order $order): void
    {
        if (self::SBERMM_ORDER_STATUS_TO_SEND_SHIPPING_REQUEST !== strval($order->getField('STATUS_ID'))) {
            return;
        }

        if (!IntegrationOrder::checkOrderAffiliation($order, parent::SBERMM_PAYMENT_ID,
            parent::SBERMM_DELIVERY_ID)) {
            return;
        }

        try {
            $orderPropsObject = new OrderPropsHandler($order);
            $sberMMOrderNumber = $orderPropsObject->getPropDataByCode(parent::SBERMM_ORDER_ID_PROPERTY_CODE)['VALUE'];
            $sberMMBoxCodes = json_decode(
                $orderPropsObject->getPropDataByCode(parent::SBERMM_ORDER_BOX_CODES_PROPERTY_CODE)['VALUE'], true);

            if (0 < mb_strlen($sberMMOrderNumber) && count($sberMMBoxCodes)) {
                $oSberMMShippingRequest = new SberMegaMarketRequests(array());
                $oSberMMShippingRequest->makeShippingRequest($sberMMOrderNumber, $sberMMBoxCodes);
            } else {
                parent::notify('data-shipping-error', 'info',
                    'Failed to obtain $orderPropsObject or $sberMMBoxCodes data');
                return;
            }
        } catch (Exception $e) {
            parent::notify('data-shipping-error', 'error',
                'Failed to obtain $orderPropsObject or $sberMMBoxCodes data. Description: ' . $e->getMessage());
            return;
        }
    }
}