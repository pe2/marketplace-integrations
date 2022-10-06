<?php

namespace MarketplaceIntegration;

use Bitrix\Main\Loader;
use Bitrix\Sale\PaySystem\Manager;

/**
 * Trait IntegrationDelivery
 * This trait used for working with shipment and delivery
 *
 * @package MarketplaceIntegration
 */
trait IntegrationPayment
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
     * Method appends payment for order
     *
     * @param array $arPaySystemInfo Array with payment info ['id', 'cost']
     * @param int $userId Payer
     * @param string $currencyCode Currency code
     * @param object $order \Bitrix\Sale\Order object
     *
     * @return bool
     */
    private function appendPaymentToOrder(array $arPaySystemInfo, int $userId, string $currencyCode, object &$order): bool
    {
        $paymentCollection = $order->getPaymentCollection();
        $payment = $paymentCollection->createItem();
        $paySystemService = Manager::getObjectById($arPaySystemInfo['id']);
        $payment->setFields(array(
            'PAY_SYSTEM_ID' => $paySystemService->getField("PAY_SYSTEM_ID"),
            'PAY_SYSTEM_NAME' => $paySystemService->getField("NAME"),
            'SUM' => $arPaySystemInfo['cost'],
            'PAID' => 'Y',
            'EMP_PAID_ID' => intval($userId),
            'CURRENCY' => $currencyCode
        ));

        return true;
    }
}