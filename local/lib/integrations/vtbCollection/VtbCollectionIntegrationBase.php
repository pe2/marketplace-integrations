<?php

namespace VtbCollectionIntegration;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\DB\Exception;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use CDataXML;
use MarketplaceIntegration\Notifier;
use IMarketplaceIntegration;
use Local\Init\ServiceHandler as SH;

/**
 * Class OzonIntegrationBase
 * Base class for Ozon integration
 *
 * @package OzonIntegration
 */
class VtbCollectionIntegrationBase implements IMarketplaceIntegration
{
    /** @var string Path to log-file */
    public const LOG_FILE_PATH = '/local/logs/vtb_collection.log';

    /** @var string Property name to check affiliation */
    public const VTB_COLLECTION_PRODUCT_PROPERTY_NAME = 'EXPORT_TO_VTB';

    /** @var string Vtb-Collection order id to search */
    public const VTB_COLLECTION_ORDER_ID_PROPERTY_CODE = 'VTB_COLLECTION_ORDER_ID';

    /** @var string URL to send order status notifications */
    protected const VTB_COLLECTION_NOTIFY_URL = 'https://partner-api.multibonus.ru:544/NotifyOrderStatus.ashx';

    /** @var string URL to send returns */
    protected const VTB_COLLECTION_RETURN_URL = 'https://partner-api.multibonus.ru:544/ReturnClaim.ashx';

    /** @var string Path to ssl certificate */
    protected const VTB_COLLECTION_SSL_CERT_PATH = '/path/to/keys/client.crt';

    /** @var string Path to ssl key */
    protected const VTB_COLLECTION_SSL_KEY_PATH = '/path/to/keys/client.key';

    /** @var string Mail list to send notifications */
    protected const MAIL_LIST_NOTIFY = 'operator1@domain.tld';

    /** @var string Mail list to send errors */
    protected const MAIL_LIST_ERRORS = 'operator2@domain.tld, operator3@domain.tld';

    /** @var int Group to add Vtb Collection users */
    protected const VTB_COLLECTION_DEFAULT_USER_GROUP = 20;

    /** @var int Default user id if user creation fails */
    protected const VTB_COLLECTION_DEFAULT_USER_ID = 224360;

    /** @var int Ozon delivery id */
    protected const VTB_COLLECTION_DELIVERY_ID = 91;

    /** @var int Ozon payment id */
    protected const VTB_COLLECTION_PAYMENT_ID = 31;

    /** @var int IBlock id with deliveries info */
    protected const VTB_COLLECTION_DELIVERIES_IBLOCK_ID = 21;

    /** @var float Price threshold between given price and current price */
    protected const VTB_COLLECTION_PRICE_THRESHOLD = 30.0;

    /**
     * VtbCollectionIntegrationBase constructor.
     *
     * @throws LoaderException
     */
    public function __construct()
    {
        Loc::loadMessages(__FILE__);

        Loader::includeModule('iblock');
    }


    /**
     * Stub method for interface. We don't obtain orders, VTB Collection sends order to us.
     *
     * @param string $backInTime Interval for strtotime() function
     *
     * @return array Empty array
     */
    public function obtainOrders(string $backInTime): array
    {
        return array();
    }


    /**
     * Stub method for interface. Order creation via request
     *
     * @param array $arOrders
     */
    public function insertOrders(array $arOrders): void
    {
    }


    /**
     * Method handles error notifications
     *
     * @param string $code
     * @param string $type ['error', 'info']
     * @param string $additionalInfo
     */
    public function notify(string $code, string $type, string $additionalInfo): void
    {
        $message = Loc::getMessage('VCIB_UNDEFINED_ERROR');

        // Errors
        if ('order-check' === $code) {
            $message = Loc::getMessage('VCIB_ORDER_CHECK_ERROR') . $additionalInfo;
        }

        if ('order-cancellation' === $code) {
            $message = Loc::getMessage('VCIB_ORDER_CANCELLATION_ERROR') . $additionalInfo;
        }

        if ('order-send-status' === $code) {
            $message = Loc::getMessage('VCIB_ORDER_SEND_STATUS_ERROR') . $additionalInfo;
        }

        if ('order-send-status-curl' === $code) {
            $message = Loc::getMessage('VCIB_ORDER_SEND_STATUS_CURL_ERROR') . $additionalInfo;
        }

        if ('user-create' === $code) {
            $message = Loc::getMessage('VCIB_USER_CREATE_ERROR') . $additionalInfo;
        }

        if ('order-create' === $code) {
            $message = Loc::getMessage('VCIB_ORDER_CREATE_ERROR') . $additionalInfo;
        }

        if ('basket-create' === $code) {
            $message = Loc::getMessage('VCIB_BASKET_CREATE_ERROR') . $additionalInfo;
        }

        if ('location-code' === $code) {
            $message = Loc::getMessage('VCIB_LOCATION_CODE_ERROR') . $additionalInfo;
        }

        if ('location-id' === $code) {
            $message = Loc::getMessage('VCIB_LOCATION_ID_ERROR') . $additionalInfo;
        }

        // Info messages
        if ('order-insert' === $code) {
            $message = $additionalInfo;
        }

        if ('empty-basket' === $code) {
            $message = Loc::getMessage('VCIB_EMPTY_BASKET_ERROR') . $additionalInfo;
        }


        $oNotifier = new Notifier($code, $type, self::LOG_FILE_PATH, $message,
            Loc::getMessage('VCIB_MAIL_THEME'), ('info' === $type) ? self::MAIL_LIST_NOTIFY : self::MAIL_LIST_ERRORS
        );
        $oNotifier->addToLog();
        $oNotifier->sendEmailMessage();
    }


    /**
     * Method handles request from Vtb Collection
     *
     * @param string $xmlData
     *
     * @return string
     *
     * @throws ArgumentException
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
     * @throws Exception
     * @throws LoaderException
     * @throws NotImplementedException
     * @throws ObjectNotFoundException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function handleRequest(string $xmlData)
    {
        SH::writeToLog($xmlData, self::LOG_FILE_PATH, 'Incoming request');

        $xml = new CDataXML();
        $xml->LoadString($xmlData);

        $xmlExecutionResult = $this->executeRequestMethod($xml);

        SH::writeToLog($xmlExecutionResult, self::LOG_FILE_PATH, 'Response');

        return $xmlExecutionResult;
    }


    /**
     * Method checks request for method and execute if we have one
     *
     * @param CDataXML $xml XML-request object
     *
     * @return CDataXML
     *
     * @throws ArgumentException
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
     * @throws Exception
     * @throws LoaderException
     * @throws NotImplementedException
     * @throws ObjectNotFoundException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function executeRequestMethod(&$xml)
    {
        $xmlResult = '';

        // Check request type
        if ($node = $xml->SelectNodes('/GetDeliveryVariantsMessage')) {
            $oCheckVtbCollectionDelivery = new VtbCollectionCheckDelivery($node);
            $xmlResult = $oCheckVtbCollectionDelivery->checkDelivery();
        } else if ($node = $xml->SelectNodes('/CheckOrderMessage/Order')) {
            $oCheckVtbCollectionOrder = new VtbCollectionCheckOrder($node);
            $xmlResult = $oCheckVtbCollectionOrder->checkOrder();
        } else if ($node = $xml->SelectNodes('/CommitOrderMessage/Order')) {
            $oCheckVtbCollectionOrder = new VtbCollectionInsertOrder($node);
            $xmlResult = $oCheckVtbCollectionOrder->insertOrder(true);
        } else if ($node = $xml->SelectNodes('/CommitOrdersMessage/Orders')) {
            $oCheckVtbCollectionOrder = new VtbCollectionInsertOrder($node);
            $xmlResult = $oCheckVtbCollectionOrder->insertSeveralOrders();
        }

        return $xmlResult;
    }
}
