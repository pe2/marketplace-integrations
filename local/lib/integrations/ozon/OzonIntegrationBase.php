<?php

namespace OzonIntegration;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Exception;
use MarketplaceIntegration\Notifier;
use IMarketplaceIntegration;

/**
 * Class OzonIntegrationBase
 * Base class for Ozon integration
 *
 * @package OzonIntegration
 */
class OzonIntegrationBase implements IMarketplaceIntegration
{
    /** @var string Path to log-file */
    public const LOG_FILE_PATH = '/local/logs/ozon.log';

    /** @var string Ozon order id to search before add */
    public const OZON_ORDER_ID_PROPERTY_CODE = 'OZON_ORDER_ID';

    /** @var string Product property to add products to feed */
    public const OZON_PRODUCT_PROPERTY_CODE = 'ADD_PRODUCT_TO_OZON_FEED';

    /** @var int[] Interval between request on errors */
    public const RETRY_TIMEOUT_INTERVAL = array('min' => 1, 'max' => 3);

    /** @var array Array with credentials for prod and sandbox env. */
    protected const OZON_CREDENTIALS = array(
        'client_id' => '11111',
        'api_key' => '11111111-0000-cccc-dddd-222222222222',
        'url' => 'https://api-seller.ozon.ru'
    );

    /** @var string Order status to get orders */
    protected const ORDER_STATUS_TO_GET = 'awaiting_packaging';

    /** @var int Orders count per request */
    protected const ORDERS_PER_REQUEST = 10;

    /** @var string Mail list to send notifications */
    protected const MAIL_LIST_NOTIFY = 'operator1@domain.tld, operator2@domain.tld';

    /** @var string Mail list to send errors */
    protected const MAIL_LIST_ERRORS = 'operator3@domain.tld';

    /** @var int Group to add Ozon users */
    protected const OZON_DEFAULT_USER_GROUP = 24;

    /** @var int Default user id if user creation fails */
    protected const OZON_DEFAULT_USER_ID = 232002;

    /** @var int Ozon delivery id */
    protected const OZON_DELIVERY_ID = 95;

    /** @var int Ozon payment id */
    protected const OZON_PAYMENT_ID = 33;


    /**
     * AliExpressIntegration constructor.
     */
    public function __construct()
    {
        Loc::loadMessages(__FILE__);
    }

    /**
     * Method obtains Ozon's product sku by our product ids
     *
     * @param array $arProductIds Array of our product ids
     *
     * @return array ['ourProductId' => 'ozonProductSku', ...]
     */
    public static function obtainOzonProductIds(array $arProductIds): array
    {
        $arOurProductOzonProductIds = array();

        $oOzonProductsRequest = new OzonRequestsHelper(
            '/v2/product/info/list',
            array('offer_id' => $arProductIds, 'product_id' => array(), 'sku' => array())
        );

        $arOzonProductsRequestResult = $oOzonProductsRequest->makePostRequest();

        foreach ($arOzonProductsRequestResult['result']['items'] as $productItem) {
            foreach ($productItem['sources'] as $sourceInfo) {
                if ('fbs' === $sourceInfo['source']) {
                    $arOurProductOzonProductIds[$productItem['offer_id']] = $sourceInfo['sku'];
                }
            }
        }

        return $arOurProductOzonProductIds;
    }

    /**
     * Method obtains all Ozon's sections
     *
     * @return array
     */
    public static function obtainOzonSectionsList(): array
    {
        $arOzonSections = array();
        $oOzonSectionsRequest = new OzonRequestsHelper('/v2/category/tree');
        $arOzonSectionsRequestResult = $oOzonSectionsRequest->makePostRequest();
        foreach ($arOzonSectionsRequestResult as $arSection) {
            self::handleOzonSection($arSection, $arOzonSections);
        }

        return $arOzonSections;
    }

    /**
     * Recursive method to obtains all sections as plain list
     *
     * @param array $arSection Current section
     * @param array $arOzonSections All sections as list (by. ref)
     */
    private static function handleOzonSection(array $arSection, array &$arOzonSections): void
    {
        foreach ($arSection as $arSectionData) {
            $arOzonSections[] = array(
                'id' => $arSectionData['category_id'],
                'title' => $arSectionData['title'],
                'childrenCount' => count($arSectionData['children'])
            );
            if (count($arSectionData['children'])) {
                self::handleOzonSection($arSectionData['children'], $arOzonSections);
            }
        }
    }

    /**
     * Method returns info about product loading by given task id
     *
     * @param string $taskId Task id
     *
     * @return array
     */
    public static function obtainOzonTaskIdInfo(string $taskId): array
    {
        $oOzonTaskIdRequest = new OzonRequestsHelper('/v1/product/import/info', array('task_id' => $taskId));
        $oOzonTaskIdRequestResult = $oOzonTaskIdRequest->makePostRequest();

        return $oOzonTaskIdRequestResult['result'];
    }

    /**
     * Method to obtain orders from Ozon
     *
     * @param string $backInTime Interval for strtotime() function
     *
     * @return array
     *
     * @throws Exception
     */
    public function obtainOrders(string $backInTime): array
    {
        $oOzonOrders = new OzonObtainOrders($backInTime);

        return $oOzonOrders->getOrders();
    }

    /**
     * Method to insert orders in our DB
     *
     * @param array $arOrders
     *
     * @throws ArgumentException
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
     * @throws NotImplementedException
     * @throws ObjectNotFoundException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function insertOrders(array $arOrders): void
    {
        $oOzonOrders = new OzonInsertOrders();

        $arOrdersInsertResult = $oOzonOrders->insertOzonOrders($arOrders);

        if (0 < $arOrdersInsertResult['processed'] && 0 < $arOrdersInsertResult['inserted']) {
            if (0 >= mb_strlen($arOrdersInsertResult['errors'])) {
                $this->notify('order-insert', 'info', Loc::getMessage('OIB_ORDERS_PROCESSING_RESULT',
                    array(
                        '#ORDERS_PROCESSED#' => $arOrdersInsertResult['processed'],
                        '#ORDERS_INSERTED#' => $arOrdersInsertResult['inserted'],
                        '#ORDERS_IDS#' => trim($arOrdersInsertResult['ids'])
                    ))
                );
            } else {
                $this->notify('order-insert', 'error', Loc::getMessage('OIB_ORDERS_PROCESSING_RESULT_WITH_ERRORS',
                    array(
                        '#ORDERS_PROCESSED#' => $arOrdersInsertResult['processed'],
                        '#ORDERS_INSERTED#' => $arOrdersInsertResult['inserted'],
                        '#ORDERS_IDS#' => trim($arOrdersInsertResult['ids']),
                        '#ERRORS#' => trim($arOrdersInsertResult['errors'])
                    ))
                );
            }
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
            'http-code-not-200' => Loc::getMessage('OIB_HTTP_CODE_NOT_200') . $additionalInfo,
            'response-decode-error' => Loc::getMessage('OIB_RESPONSE_DECODE_ERROR') . $additionalInfo,
            'order-create' => Loc::getMessage('OIB_ORDER_CREATE_ERROR') . $additionalInfo,
            'basket-create' => Loc::getMessage('OIB_BASKET_CREATE_ERROR') . $additionalInfo,
            'user-create' => Loc::getMessage('OIB_USER_CREATE_ERROR') . $additionalInfo,
            'empty-location' => Loc::getMessage('OIB_EMPTY_LOCATION_ERROR') . $additionalInfo,
            'empty-city' => Loc::getMessage('OIB_EMPTY_CITY_ERROR') . $additionalInfo,
            /* 'ozon-id-error' => Loc::getMessage('OIB_OZON_ID_ERROR') . $additionalInfo, */
            // Info messages
            'order-insert' => $additionalInfo,
            'empty-basket' => Loc::getMessage('OIB_EMPTY_BASKET_ERROR') . $additionalInfo
        );

        $message = $arMessagesByCode[$code] ?? Loc::getMessage('OIB_UNDEFINED_ERROR') . $code . "\n" . $additionalInfo;

        $oNotifier = new Notifier($code, $type, self::LOG_FILE_PATH, $message,
            Loc::getMessage('OIB_MAIL_THEME'), ('info' === $type) ? self::MAIL_LIST_NOTIFY : self::MAIL_LIST_ERRORS
        );
        $oNotifier->addToLog();
        $oNotifier->sendEmailMessage();
    }
}
