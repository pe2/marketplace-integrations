<?php

namespace SberMegaMarketIntegration;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use IMarketplaceIntegration;
use MarketplaceIntegration\Notifier;
use JsonException;
use Local\Init\ServiceHandler as SH;
use SberMegaMarketIntegration\SberMegaMarketRequestHelper as SberMMRH;

/**
 * Class SberMegaMarketIntegrationBase
 * Base class for SberMegaMarket integration
 *
 * @package OzonIntegration
 */
class SberMegaMarketIntegrationBase implements IMarketplaceIntegration
{
    /** @var string Path to log-file */
    public const LOG_FILE_PATH = '/local/logs/sber_mega_market.log';

    /** @var string Product property to add products to feed */
    public const SBERMM_PRODUCT_PROPERTY = 'ADD_PRODUCT_TO_SBERMM_FEED';

    /** @var string If product stock less then this value we don't add this product to platform */
    public const SBERMM_QUANTITY_THRESHOLD_PROPERTY = 'SBERMM_Q_THRESHOLD';

    /** @var string[] Allowed method to handle */
    public const AR_ALLOWED_METHODS = array('order-new', 'order-cancel', 'pack-orders');

    /** @var string Merchant code */
    public const SBERMM_MERCHANT_CODE = '11111';

    /** @var string Mail list for info messages */
    public const MAIL_LIST_NOTIFY = 'operator1@domain.tld';

    /** @var string Mail list for error messages */
    public const MAIL_LIST_ERRORS = 'operator2@domain.tld';

    /** @var string SberMegaMarket order id property code */
    public const SBERMM_ORDER_ID_PROPERTY_CODE = 'SBERMEAGEMARKET_ORDER_ID';

    /** @var string SberMegaMarket order product indexes */
    public const SBERMM_ORDER_PRODUCT_INDEXES_PROPERTY_CODE = 'SBERMEAGEMARKET_PRODUCT_INDEXES';

    /** @var string SberMegaMarket box codes array */
    public const SBERMM_ORDER_BOX_CODES_PROPERTY_CODE = 'SBERMEAGEMARKET_BOX_CODES';

    /** @var string SberMegaMarket warehouse letter event */
    public const SBERMM_WAREHOUSE_EMAIL_EVENT_NAME = 'SBERMM_WAREHOUSE_LETTER';

    /** @var int Group id to add SberMegaMarket users */
    public const SBERMM_DEFAULT_USER_GROUP_ID = 27;

    /** @var int Default user id if user creation fails */
    public const SBERMM_DEFAULT_USER_ID = 256989;

    /** @var int SberMegaMarket delivery id */
    public const SBERMM_DELIVERY_ID = 101;

    /** @var int SberMegaMarket payment id */
    public const SBERMM_PAYMENT_ID = 35;


    /**
     * SberMegaMarketIntegrationBase constructor.
     */
    public function __construct()
    {
        Loc::loadMessages(__FILE__);
    }


    /**
     * Stub method for interface. We don't obtain orders, SberMegaMarket sends order to us.
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
     * Method handles requested method
     *
     * @param string $method Requested method
     * @param object $request \Bitrix\Main\HttpRequest object
     * @param string $requestBody JSON encoded request body
     *
     * @throws ArgumentException
     * @throws SystemException
     */
    public function handleRequest(string $method, object $request, string $requestBody): void
    {
        if (!SberMMRH::checkAuth($request)) {
            SberMMRH::makeResponse(401, array('description' => 'Unauthorized access'));
        }

        if (!in_array($method, self::AR_ALLOWED_METHODS)) {
            SberMMRH::makeResponse(405, array('description' => 'Unknown method'));
        }

        $arRequestBody = array();
        try {
            // Just in case remove BOM
            $requestBody = SH::removeBOM($requestBody);
            $arRequestBody = json_decode($requestBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->notify('json-parse-error', 'error', $e->getMessage() . "\n\n" .
                var_export($requestBody, true));
            SberMMRH::makeResponse(400, array('description' => 'JSON parse error'));
        }

        if (!count($arRequestBody) || !count($arRequestBody['data'])) {
            $this->notify('request-array-is-empty', 'error', var_export($arRequestBody, true));
            SberMMRH::makeResponse(400, array('description' => 'Request array is empty'));
        }

        SH::writeToLog($arRequestBody, self::LOG_FILE_PATH, 'Incoming request body. Method: ' . $method);

        if ('order-new' === $method) {
            $oSberMMOrder = new SberMegaMarketInsertOrder();
            $oSberMMOrder->handleSberMMOrderInsertRequest($arRequestBody);
        }

        if ('order-cancel' === $method) {
            $oSberMMOrder = new SberMegaMarketCancelOrder();
            $oSberMMOrder->handleSberMMOrderCancelRequest($arRequestBody);
        }

        if ('pack-orders' === $method) {
            $oSberMMPackingOrder = new SberMegaMarketPackingOrder($arRequestBody);
            $oSberMMPackingOrder->handleSberMMOrderPackingRequest();
        }
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
        $arMessagesByCode = array(
            // Errors
            'json-parse-error' => Loc::getMessage('SMMIB_JSON_PARSE_ERROR') . $additionalInfo,
            'order-exists' => Loc::getMessage('SMMIB_ORDER_EXISTS_ERROR') . $additionalInfo,
            'request-array-is-empty' => Loc::getMessage('SMMIB_ARRAY_IN_EMPTY_ERROR') . $additionalInfo,
            'http-code-not-200' => Loc::getMessage('AEIB_HTTP_CODE_NOT_200') . $additionalInfo,
            'response-decode-error' => Loc::getMessage('AEIB_RESPONSE_DECODE_ERROR') . $additionalInfo,
            'request-error' => Loc::getMessage('SMMIB_REQUEST_NOT_SUCCESS_ERROR') . $additionalInfo,
            'order-data-extract' => Loc::getMessage('SMMIB_ORDER_DATA_EXTRACT_ERROR') . $additionalInfo,
            'user-create' => Loc::getMessage('AEIB_USER_CREATE_ERROR') . $additionalInfo,
            'order-create' => Loc::getMessage('AEIB_ORDER_CREATE_ERROR') . $additionalInfo,
            'basket-create' => Loc::getMessage('AEIB_BASKET_CREATE_ERROR') . $additionalInfo,
            'order-number-extract' => $additionalInfo,
            'packing-basket-error' => $additionalInfo,
            'order-indexes-extract' => $additionalInfo,
            'sticker-print-error' => $additionalInfo,
            'all-sticker-print-error' => $additionalInfo,
            'one-sticker-print-error' => $additionalInfo,
            'box-codes-error' => $additionalInfo,
            'data-shipping-error' => $additionalInfo,
            'order-does-not-exists' => Loc::getMessage('SMMIB_ORDER_DOES_NOT_EXISTS_ERROR') . $additionalInfo,
            'order-exists-error' => Loc::getMessage('SMMIB_ORDER_CANCEL_ERROR') . $additionalInfo,

            // Info
            'empty-basket' => Loc::getMessage('SMMIB_EMPTY_BASKET_ERROR') . $additionalInfo,
            'rejected-products' => Loc::getMessage('SMMIB_REJECTED_PRODUCTS_ERROR') . $additionalInfo,
            'order-cancel-success' => Loc::getMessage('SMMIB_ORDER_CANCEL_SUCCESS') . $additionalInfo,
        );

        $message = $arMessagesByCode[$code] ?? Loc::getMessage('AEIB_UNDEFINED_ERROR') . $code;

        $oNotifier = new Notifier($code, $type, self::LOG_FILE_PATH, $message,
            Loc::getMessage('SMMIB_MAIL_THEME'), ('info' === $type) ? self::MAIL_LIST_NOTIFY : self::MAIL_LIST_ERRORS
        );

        $oNotifier->addToLog();

        if ('error' === $type) {
            $oNotifier->sendEmailMessage();
        }
    }
}
