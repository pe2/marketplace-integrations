<?php

namespace VtbCollectionIntegration;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/classes/general/xml.php');

use Bitrix\Main;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sale\Order;
use MarketplaceIntegration as MI;
use Local\Init\ServiceHandler as SH;

/**
 * Class VtbCollectionEventHandlers
 * Class for working with VTB-Collection events
 *
 * @package VtbCollectionIntegration
 */
class VtbCollectionEventHandlers extends VtbCollectionIntegrationBase
{
    /** @var array Order statuses */
    public static $allowStatuses = array(
        'CA' => '20',   // 'Отменен' -> отменен
        'AS' => '30',   // 'Собран' -> требует доставки
        'SH' => '40',   // 'Отгружен' -> доставка
        'F' => '50',    // 'Доставлен' -> доставлен
        'ND' => '60'    // 'Не доставлен' -> не доставлен
    );

    /** @var int Timeout for request */
    private const CURL_TIMEOUT = 10;

    /** @var string Path to script */
    private const PATH_TO_JS_ADMIN_SCRIPT = '/local/lib/integrations/vtbCollection/returnScripts/sendReturnClaimVtbCollection.js';


    /**
     * Method handles order cancellation
     *
     * @param Main\Event $event
     *
     * @throws Main\LoaderException
     */
    public static function OnSaleOrderCanceledHandler(Main\Event $event): void
    {
        $parameters = $event->getParameters();
        $order = $parameters['ENTITY'];

        if (!(MI\IntegrationOrder::checkOrderAffiliation(
            $order,
            parent::VTB_COLLECTION_PAYMENT_ID,
            parent::VTB_COLLECTION_DELIVERY_ID))
        ) {
            return;
        }

        $arUserGroups = \CUser::GetUserGroup($order->getUserId());

        $data = [
            'status' => $order->isCanceled() ? 'CA' : 0,
            'orderID' => $order->getID(),
            'reason' => $order->getField("REASON_CANCELED")
        ];

        if (in_array(parent::VTB_COLLECTION_DEFAULT_USER_GROUP, $arUserGroups)) {
            // Send available status
            if (isset(self::$allowStatuses[$data['status']])) {
                self::sendStatus($data, false);
            }
        }
    }


    /**
     * Method handles order status change
     *
     * @param Main\Event $event
     *
     * @throws Main\LoaderException
     */
    public static function OnSaleStatusOrderChangeHandler(Main\Event $event): void
    {
        $parameters = $event->getParameters();
        $order = $parameters['ENTITY'];

        if (!(MI\IntegrationOrder::checkOrderAffiliation(
            $order,
            parent::VTB_COLLECTION_PAYMENT_ID,
            parent::VTB_COLLECTION_DELIVERY_ID))
        ) {
            return;
        }

        $addContentLength = false;

        $arUserGroups = \CUser::GetUserGroup($order->getUserId());

        $data = array(
            'status' => $parameters['VALUE'],
            'orderID' => $order->getID(),
            'reason' => '',
        );

        if (in_array(parent::VTB_COLLECTION_DEFAULT_USER_GROUP, $arUserGroups)) {
            $canceled = $order->isCanceled();
            if ($canceled) {
                $data['reason'] = $order->getField("REASON_CANCELED");
                $data['status'] = 'CA';
                $addContentLength = true;
            }
            // Send available status
            if (isset(self::$allowStatuses[$data['status']])) {
                self::sendStatus($data, $addContentLength);
            }
        }
    }


    /**
     * Method adds button to panel in administrative view
     *
     * @param $items
     *
     * @return bool
     *
     * @throws Main\ArgumentNullException
     */
    public static function OrderDetailAdminContextMenuShow(&$items)
    {
        if ('GET' === strval($_SERVER['REQUEST_METHOD']) &&
            '/bitrix/admin/sale_order_view.php' === strval($GLOBALS['APPLICATION']->GetCurPage()) &&
            0 < intval($_REQUEST['ID'])
        ) {
            if (!MI\IntegrationOrder::checkOrderAffiliation(
                Order::load(intval($_REQUEST['ID'])),
                parent::VTB_COLLECTION_PAYMENT_ID,
                parent::VTB_COLLECTION_DELIVERY_ID
            )) {
                return false;
            }

            $items[] = array(
                "TEXT" => Loc::getMessage('VCEH_BONUSES_RETURN'),
                "LINK" => "javascript:sendReturnClaim(" . $_REQUEST['ID'] . ");",
                "TITLE" => Loc::getMessage('VCEH_BONUSES_RETURN'),
                "ICON" => "btn"
            );

            // Add js handlers
            \CJSCore::RegisterExt('sendReturnClaim', array(
                'js' => self::PATH_TO_JS_ADMIN_SCRIPT
            ));
            \CJSCore::Init(array('jquery', 'sendReturnClaim'));
        }

        return true;
    }


    /**
     * Method loads order and prepares data fot return claim request
     *
     * @param int $orderId
     *
     * @return string Strong to show to user
     *
     * @throws Main\ArgumentNullException
     * @throws Main\LoaderException
     */
    public function sendOrderReturnClaimHandler($orderId)
    {
        Loader::includeModule('sale');

        $order = Order::load($orderId);

        if (!(MI\IntegrationOrder::checkOrderAffiliation(
            $order,
            parent::VTB_COLLECTION_PAYMENT_ID,
            parent::VTB_COLLECTION_DELIVERY_ID))
        ) {
            return Loc::getMessage('VCEH_ORDER_IS_NOT_VTB_COLLECTION');
        }

        // We don't need to check paid status thus such orders are always paid
        // if ($order->isCanceled() && $order->isPaid()) {
        if ($order->isCanceled()) {
            return Loc::getMessage('VCEH_ERROR_ON_SEND_RETURN_CLAIM_REQUEST_ORDER_IS_NOT_CANCELED');
        } else {
            $basket = $order->getBasket();
            $data['orderID'] = $order->getId();
            $data['ItemsCost'] = $basket->getPrice();
            $data['DeliveryCost'] = $order->getDeliveryPrice();
            return self::sendReturnClaim($data);
        }
    }


    /**
     * Send request for order cancellation
     *
     * @param $data
     *
     * @return string
     *
     * @throws Main\LoaderException
     */
    private static function sendReturnClaim($data)
    {
        $arResponse = [];

        $xmlOutput = new \SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\" ?><ReturnClaimMessage xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" />");
        $xmlOutput->addChild('ReturnClaimId', $data['orderID']);
        $xmlOutput->addChild('OrderId', $data['orderID']);
        $xmlOutput->addChild('FullReturn', 'true');
        $xmlOutput->addChild('RefundCost', round($data['ItemsCost'] + $data['DeliveryCost'], 0));

        $strXmlData = $xmlOutput->asXML();
        SH::writeToLog($strXmlData, parent::LOG_FILE_PATH, 'Order bonuses return request');

        $response = self::sendRequest($strXmlData, parent::VTB_COLLECTION_RETURN_URL);
        SH::writeToLog($response, parent::LOG_FILE_PATH, 'Order cancellation response');

        if (!empty($response)) {
            $xml = new \CDataXML();
            $xml->LoadString($response);
            if ($node = $xml->SelectNodes('/ReturnClaimResult')) {
                foreach ($node->children() as $children) {
                    $arResponse[$children->name()] = $children->textContent();
                }
            }

            if ('true' !== strval($arResponse['Success'])) {
                $strError = 'ReasonCode: ' . $arResponse['ResultCode'] . '; Reason: ' . $arResponse['ResultDescription'];
                $oVTBCollectionBase = new VtbCollectionIntegrationBase();
                $oVTBCollectionBase->notify('order-cancellation', 'error', $strError);
                return Loc::getMessage('VCEH_ERROR_ON_SEND_RETURN_CLAIM_REQUEST') . $strError;
            } else {
                return Loc::getMessage('VCEH_SUCCESS_ON_SEND_RETURN_CLAIM_REQUEST');
            }
        } else {
            return Loc::getMessage('VCEH_ERROR_ON_SEND_RETURN_CLAIM_REQUEST_EMPTY_RESPONSE');
        }
    }


    /**
     * Send request on order status change
     *
     * @param array $data
     * @param bool $addContentLength
     *
     * @return bool
     *
     * @throws Main\LoaderException
     */
    private static function sendStatus($data, $addContentLength)
    {
        $arResponse = array();

        $xmlOutput = new \SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\" ?><NotifyOrderStatusMessage xmlns=\"http://tempuri.org/XMLSchema.xsd\" />");
        $xmlOrders = $xmlOutput->addChild("Orders");
        $xmlOrder = $xmlOrders->addChild('Order');
        $xmlOrder->addChild('InternalOrderId', $data['orderID']);
        $xmlOrder->addChild('InternalStatusCode', $data['status']);
        $xmlOrder->addChild('StatusCode', self::$allowStatuses[$data['status']]);
        $xmlOrder->addChild('StatusDateTime', date('Y-m-d\TH:i:s'));
        if (self::$allowStatuses[$data['status']] == '20' && !empty($data['reason'])) {
            $xmlOrder->addChild('StatusReason', $data['reason']);
        } else {
            $xmlOrder->addChild('StatusReason', '1C order change');
        }

        $strXmlData = $xmlOutput->asXML();
        SH::writeToLog($strXmlData, parent::LOG_FILE_PATH, 'Order status change request');

        $response = self::sendRequest($strXmlData, parent::VTB_COLLECTION_NOTIFY_URL, $addContentLength);
        SH::writeToLog($response, parent::LOG_FILE_PATH, 'Order status change response');

        if (!empty($response)) {
            $xml = new \CDataXML();
            $xml->LoadString($response);
            if ($node = $xml->SelectNodes('/NotifyOrdersResult/Orders')) {
                foreach ($node->children() as $order) {
                    foreach ($order->children() as $children) {
                        $arResponse[$children->name()] = $children->textContent();
                    }
                }
            }

            if (0 < intval($arResponse['ResultCode'])) {
                $oVTBCollectionBase = new VtbCollectionIntegrationBase();
                $oVTBCollectionBase->notify('order-send-status', 'error', 'Order ID: ' .
                    $arResponse['InternalOrderId'] . '; ResultCode: ' . $arResponse['ResultCode'] . '; Reason: ' .
                    $arResponse['Reason'] . "; \$arResponse:\n" . var_export($arResponse, true));
            }
        }

        return true;
    }


    /**
     * Method makes request to partner
     *
     * @param $strXmlData
     * @param string $url
     * @param bool $addContentLength
     *
     * @return bool|string
     *
     * @throws Main\LoaderException
     */
    private static function sendRequest($strXmlData, $url = parent::VTB_COLLECTION_NOTIFY_URL, $addContentLength = false)
    {
        if (empty($strXmlData)) {
            return false;
        }

        $ch = curl_init();

        $arHeaders = array(
            'Content-type: application/xml'
        );
        if ($addContentLength) {
            array_push($arHeaders, 'Content-length: ' . mb_strlen($strXmlData));
        }

        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)',
            CURLOPT_VERBOSE => true,
            CURLOPT_URL => $url,
            CURLOPT_POST => 1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $strXmlData,
            CURLOPT_HTTPHEADER => $arHeaders,
            CURLOPT_HEADER => true,
            CURLOPT_SSLCERT => parent::VTB_COLLECTION_SSL_CERT_PATH,
            CURLOPT_SSLKEY => parent::VTB_COLLECTION_SSL_KEY_PATH,
            CURLOPT_TIMEOUT => self::CURL_TIMEOUT
        );

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);

        // Check and write errors
        if (curl_errno($ch)) {
            $oVTBCollectionBase = new VtbCollectionIntegrationBase();
            $oVTBCollectionBase->notify('order-send-status-curl', 'error', 'Error #' .
                curl_errno($ch) . ', description: ' . curl_error($ch) . ".\ncURL getinfo:\n" .
                var_export(curl_getinfo($ch), true) . "\n\nRequest data:\n" . $strXmlData
            );
        }

        curl_close($ch);

        return $response;
    }
}
