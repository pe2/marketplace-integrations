<?php

namespace OzonIntegration;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Request;
use Bitrix\Main\SystemException;
use Bitrix\Sale\Order;
use MarketplaceIntegration\IntegrationOrder;
use Local\Init\ServiceHandler as SH;
use OrderPropsHandler;

/**
 * Class OzonSendShippingOrder
 * Class for working with order logistic info (statuses, etc.)
 *
 * @package OzonIntegration
 */
class OzonSendShippingOrder extends OzonIntegrationBase
{
    /** @var int Retry count on sending shipping request */
    private const SHIPPING_REQUEST_RETRY_COUNT = 3;

    /** @var string Ozon order id to search before add */
    private const OZON_ORDER_STATUS_TO_CHANGE_DELIVERY = 'SO';

    /**
     * Method send request to change order status
     *
     * @param Order $order \Bitrix\Sale\Order object
     *
     * @throws ArgumentException
     * @throws NotImplementedException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function checkAndSendAfterStatusChange(Order $order): void
    {
        if (self::OZON_ORDER_STATUS_TO_CHANGE_DELIVERY !== strval($order->getField('STATUS_ID'))) {
            return;
        }

        if (!IntegrationOrder::checkOrderAffiliation(
            $order, parent::OZON_PAYMENT_ID, parent::OZON_DELIVERY_ID
        )) {
            return;
        }

        $orderPropsObject = new OrderPropsHandler($order);

        $arChestniyZnakRequiredProducts = json_decode(
            $orderPropsObject->getPropDataByCode(self::PRODUCTS_REQUIRED_CHESTNIY_ZNAK_MARK_PROPERTY_CODE)['VALUE'], true);
        if (is_array($arChestniyZnakRequiredProducts) && count($arChestniyZnakRequiredProducts)) {
            // Orders with chestniy znak marks send after API request
            return;
        }

        $ozonOrderNumber = $orderPropsObject->getPropDataByCode(parent::OZON_ORDER_ID_PROPERTY_CODE)['VALUE'];
        if (0 >= mb_strlen($ozonOrderNumber)) {
            return;
        }

        self::sendShippingRequest($order, $ozonOrderNumber);
    }

    /**
     * Method sends shipping order request
     *
     * @param Order $order \Bitrix\Sale\Order object
     * @param string $ozonOrderNumber Ozon order number
     * @param array $arChestniyZnakCodes Array of codes
     *
     * @return bool Is sending successful
     */
    public static function sendShippingRequest(Order $order, string $ozonOrderNumber, array $arChestniyZnakCodes = array()): bool
    {
        $isSendingSuccessful = false;

        $oAwaitDeliveryRequest = new OzonRequestsHelper(
            '/v3/posting/fbs/ship',
            self::prepareShippingRequestBody($order, $ozonOrderNumber, $arChestniyZnakCodes),
        );

        $arAwaitDeliveryRequestResult = array();
        for ($attempt = 1; $attempt <= self::SHIPPING_REQUEST_RETRY_COUNT; $attempt++) {
            $arAwaitDeliveryRequestResult = $oAwaitDeliveryRequest->makePostRequest();

            if (is_array($arAwaitDeliveryRequestResult) && count($arAwaitDeliveryRequestResult) &&
                isset($arAwaitDeliveryRequestResult['result']) && count($arAwaitDeliveryRequestResult['result'])
            ) {
                $isSendingSuccessful = true;
                break;
            }

            sleep(rand(parent::RETRY_TIMEOUT_INTERVAL['min'], parent::RETRY_TIMEOUT_INTERVAL['max']));
        }

        $description = 'Ozon posting ' . $ozonOrderNumber . ' status change request result. Retry #' . $attempt;
        if ($isSendingSuccessful) {
            SH::writeToLog('Success', parent::LOG_FILE_PATH, $description);
        } else {
            SH::writeToLog(var_export($arAwaitDeliveryRequestResult, true) . "\nRequest object:\n" .
                var_export($oAwaitDeliveryRequest, true), parent::LOG_FILE_PATH, $description);
        }

        return $isSendingSuccessful;
    }

    /**
     * Method prepares request body for status change
     *
     * @param Order $order \Bitrix\Sale\Order object
     * @param string $postingNumber Ozon posting number
     *
     * @return array Response array
     */
    private static function prepareShippingRequestBody(Order $order, string $postingNumber, array $arChestniyZnakCodes = array()): array
    {
        $arRequestBody = array(
            'packages' => array(
                array(
                    'products' => array()
                )
            ),
            'posting_number' => $postingNumber
        );

        $arBasket = $order->getBasket()->getBasketItems();
        $arProductIds = array();
        foreach ($arBasket as $basketItem) {
            $arProductIds[] = strval($basketItem->getProductId());
        }
        $arOurProductOzonProductIds = parent::obtainOzonProductIds($arProductIds);

        foreach ($arBasket as $basketItem) {
            $arExemplarInfo = array();
            if (count($arChestniyZnakCodes) && isset($arChestniyZnakCodes[$basketItem->getProductId()])) {
                foreach ($arChestniyZnakCodes[$basketItem->getProductId()] as $code) {
                    $arExemplarInfo[] = array('mandatory_mark' => $code, 'gtd' => '', 'is_gtd_absent' => true);
                }
            } else {
                $arExemplarInfo = array(array('mandatory_mark' => '', 'gtd' => '', 'is_gtd_absent' => true));
            }

            $arRequestBody['packages'][0]['products'][] = array(
                'exemplar_info' => $arExemplarInfo,
                'product_id' => intval($arOurProductOzonProductIds[$basketItem->getProductId()]),
                'quantity' => intval($basketItem->getQuantity())
            );
        }

        return $arRequestBody;
    }

    /**
     * Method checks and sends order shipping info for orders with "Chestniy znak" marks
     * For those orders we don't info by status change but wait until all the marks will be received
     *
     * @param Request $request Request object
     * @param string $requestBody Request body
     */
    public static function checkAndSendAfterApiRequest(Request $request, string $requestBody): void
    {
        $oOzonShippingOrderAfterApi = new OzonShippingOrderAfterApi($request, $requestBody);
        $oOzonShippingOrderAfterApi->checkAndSendRequest();
    }
}