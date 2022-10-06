<?php

namespace OzonIntegration;

use Bitrix\Currency\CurrencyManager;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Context;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Location\LocationTable;
use CUtil;
use DateTime;
use Exception;
use MarketplaceIntegration as MI;
use Local\Init\ServiceHandler;

/**
 * Class OzonInsertOrders
 * Class used for inserting orders from AliExpress into our DB
 *
 * @package OzonIntegration
 */
class OzonInsertOrders extends OzonIntegrationBase
{
    use MI\IntegrationUser;

    use MI\IntegrationProduct {
        MI\IntegrationProduct::__construct as private __IntegrationProductsConstruct;
    }
    use MI\IntegrationBasket {
        MI\IntegrationBasket::__construct as private __IntegrationBasketConstruct;
    }
    use MI\IntegrationOrder {
        MI\IntegrationOrder::__construct as private __IntegrationOrderConstruct;
    }
    use MI\IntegrationDelivery {
        MI\IntegrationDelivery::__construct as private __IntegrationDeliveryConstruct;
    }
    use MI\IntegrationPayment {
        MI\IntegrationPayment::__construct as private __IntegrationPaymentConstruct;
    }


    /**
     * OzonInsertOrders constructor.
     */
    public function __construct()
    {
        parent::__construct();

        // Custom constructors
        $this->__IntegrationProductsConstruct();
        $this->__IntegrationBasketConstruct();
        $this->__IntegrationOrderConstruct();
        $this->__IntegrationDeliveryConstruct();
        $this->__IntegrationPaymentConstruct();

        Loc::loadMessages(__FILE__);
    }


    /**
     * Method iterates through orders list and insert orders to DB
     *
     * @param array $arOrders
     *
     * @return array [Orders processed, orders inserted, added order ids, error string]
     *
     * @throws ArgumentException
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
     * @throws NotImplementedException
     * @throws ObjectNotFoundException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public function insertOzonOrders(array $arOrders): array
    {
        $ordersProcessed = 0;
        $ordersInserted = 0;
        $ordersDataString = '';
        $errorString = '';

        foreach ($arOrders as $arOrder) {
            $ordersProcessed++;

            $arResult = $this->insertOrder($arOrder);
            if (isset($arResult['id']) && 0 < intval($arResult['id'])) {
                $ordersDataString .= "'" . $arOrder['posting_number'] . "'->" . $arResult['id'] . '; ';
            }

            if (0 !== intval($arResult['id'])) {
                $ordersInserted++;
            }

            $errorString .= $arResult['error'];
        }

        return array(
            'processed' => $ordersProcessed,
            'inserted' => $ordersInserted,
            'ids' => $ordersDataString,
            'errors' => $errorString
        );
    }


    /**
     * Method insert order to DB
     *
     * @param array $arOrder Order's info
     *
     * @return array|bool
     *
     * @throws ArgumentException
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
     * @throws NotImplementedException
     * @throws ObjectNotFoundException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function insertOrder(array $arOrder)
    {
        if ($this->isOrderExist(parent::OZON_ORDER_ID_PROPERTY_CODE, $arOrder['posting_number'])) {
            return false;
        }

        $arOrder['addressee']['posting_number'] = $arOrder['posting_number'];
        // Get user ID (create or make a new one)s
        $userId = $this->prepareUserToAppendToOrder($arOrder['addressee'], $userEmail, $userPhone);
        if ($userId === parent::OZON_DEFAULT_USER_ID) {
            parent::notify('user-create', 'error', var_export($arOrder['addressee'], true));
        }

        // Prepare basket
        $arBasket = $this->prepareBasketToAppendToOrder($arOrder, $errorString);
        if (!count($arBasket)) {
            parent::notify('empty-basket', 'info', $arOrder['posting_number'] . '. ' . $errorString);
            return false;
        }

        $siteId = (0 >= strlen(Context::getCurrent()->getSite())) ? Context::getCurrent()->getSite() : 's1';
        $currencyCode = (0 >= strlen(CurrencyManager::getBaseCurrency())) ? CurrencyManager::getBaseCurrency() : 'RUB';

        // Create order
        $order = $this->initiateOrder($userId, $siteId, $currencyCode);
        if (false === $order) {
            parent::notify('order-create', 'error', $arOrder['posting_number']);
            return false;
        }

        // Set fields and props for order
        $this->setOrderComment($this->composeComment($arOrder) . $errorString);
        $this->setOrderProperties(
            array(
                'FIO' => trim($arOrder['addressee']['name']),
                // 'EMAIL' => $userEmail,
                // 'PHONE' => $userPhone,
                'CITY' => $arOrder['analytics_data']['city'],
                'REGION_NAME' => $arOrder['analytics_data']['region'],
                parent::OZON_ORDER_ID_PROPERTY_CODE => $arOrder['posting_number'],
                self::PRODUCTS_REQUIRED_CHESTNIY_ZNAK_MARK_PROPERTY_CODE => $this->prepareChestniyZnakMarkIds($arOrder)
            )
        );
        // Location
        if (isset($arOrder['analytics_data']['city']) && 0 < mb_strlen($arOrder['analytics_data']['city'])) {
            $dbLocationInfo = LocationTable::getList(
                array(
                    'filter' => array(
                        '=NAME.LANGUAGE_ID' => LANGUAGE_ID,
                        '=NAME_RU' => $arOrder['analytics_data']['city']
                    ),
                    'select' => array('*', 'NAME_RU' => 'NAME.NAME', 'TYPE_CODE' => 'TYPE.CODE')
                )
            );
            $isLocationSet = false;
            while ($arLocation = $dbLocationInfo->fetch()) {
                $isLocationSet = true;
                $this->setOrderProperties(array('LOCATION' => $arLocation['CODE']));
            }
            if (!$isLocationSet) {
                parent::notify(
                    'empty-location',
                    'error',
                    $arOrder['posting_number'] . ' - ' . $arOrder['analytics_data']['city']
                );
            }
        } else {
            parent::notify('empty-city', 'info', $arOrder['posting_number']);
        }
        // Shipment date
        try {
            $oShipmentDate = new DateTime($arOrder['shipment_date']);
            $shipmentDate = $oShipmentDate->format('d.m.Y');
            if (0 < strlen($shipmentDate)) {
                $this->setOrderProperties(array('OZON_DELIVERY_DATE' => $shipmentDate));
                ServiceHandler::writeToLog(Loc::getMessage('OIO_DELIVERY_DATE_SET', array(
                    '#POSTING_ID#' => $arOrder['posting_number'], '#DATE#' => $shipmentDate,
                    '#ORIGIN_DATE#' => $arOrder['shipment_date'])),
                    self::LOG_FILE_PATH, Loc::getMessage('OIO_DELIVERY_DATE')
                );
            } else {
                ServiceHandler::writeToLog(Loc::getMessage('OIO_DELIVERY_DATE_ERROR', array(
                    '#POSTING_ID#' => $arOrder['posting_number'], '#DATE#' => $shipmentDate,
                    '#ORIGIN_DATE#' => $arOrder['shipment_date'])),
                    self::LOG_FILE_PATH, Loc::getMessage('OIO_DELIVERY_DATE')
                );
            }
        } catch (Exception $e) {
            ServiceHandler::writeToLog(Loc::getMessage('OIO_DELIVERY_DATE_EXCEPTION', array(
                '#POSTING_ID#' => $arOrder['posting_number'], '#ORIGIN_DATE#' => $arOrder['shipment_date'],
                '#MESSAGE#' => $e->getMessage())),
                self::LOG_FILE_PATH, Loc::getMessage('OIO_DELIVERY_DATE')
            );
        }

        // Create and append basket to order
        $basket = $this->appendBasketToOrder($arBasket, $siteId, $currencyCode, $order);
        if (!($basket instanceof Basket) && is_string($basket)) {
            parent::notify($basket, 'error', $arOrder['posting_number']);
            return false;
        }

        // Calculate order prices
        list($deliveryPrice, $payedAmount) = $this->calculateOrderPrices($arOrder);

        // Create and append delivery to order
        $arDeliveryInfo = array('id' => parent::OZON_DELIVERY_ID, 'cost' => $deliveryPrice);
        $this->appendDeliveryToOrder($arDeliveryInfo, $order, $basket);

        // Create and append payment to order
        $arPaymentInfo = array('id' => parent::OZON_PAYMENT_ID, 'cost' => $payedAmount);
        $this->appendPaymentToOrder($arPaymentInfo, $userId, $currencyCode, $order);

        // Save order and get result
        return $this->finalizeOrder();
    }

    /**
     * Method returns customer's id
     *
     * @param array $userInfo User's info
     * @param string $userEmail User's email (by ref)
     * @param string $userPhone User's phone (by ref)
     *
     * @return int User id
     *
     * @uses \MarketplaceIntegration\IntegrationUser
     */
    private function prepareUserToAppendToOrder(array $userInfo, &$userEmail, &$userPhone): int
    {
        $userPhone = '8' . substr($userInfo['phone'], 1);

        // Take transliterated name if there is no phone
        $localPart = ('8' === $userPhone) ?
            CUtil::translit(
                trim($userInfo['name']),
                'ru',
                array(
                    'change_case' => 'L',
                    'replace_space' => '-',
                    'replace_other' => '-'
                )
            ) :
            $userPhone;

        if (0 >= strlen($localPart)) {
            $localPart = $userInfo['posting_number'];
        }

        $userEmail = $localPart . '@ozon-email.com';

        return $this->getUserId(
            trim($userInfo['name']),
            $userEmail,
            $userPhone,
            parent::OZON_DEFAULT_USER_GROUP,
            parent::OZON_DEFAULT_USER_ID
        );
    }


    /**
     * Method obtain all necessary data for basket
     *
     * @param array $arOrder
     * @param string $errorString
     *
     * @return array
     *
     * @uses \MarketplaceIntegration\IntegrationProduct
     */
    private function prepareBasketToAppendToOrder($arOrder, &$errorString): array
    {
        $arBasket = array();

        foreach ($arOrder['products'] as $arProduct) {
            $arBasket[strval($arProduct['offer_id'])] = array(
                'ID' => intval($arProduct['offer_id']),
                'PRICE_PER_UNIT' => floatval($arProduct['price']),
                'PRICE' => floatval($arProduct['price']),
                'QUANTITY' => intval($arProduct['quantity'])
            );
        }

        $this->checkAndRemoveFailedProducts($arBasket, $errorString);

        if (count($arBasket)) {
            // Get additional props
            $arBasketItemsProps = $this->getProductAdditionalProperties(array_column($arBasket, 'ID'));
            // Merge arrays by product id
            foreach ($arBasket as $id => $arBasketItem) {
                $arBasket[$id] = array_merge($arBasketItem, $arBasketItemsProps[$id]);
            }
        }

        return $arBasket;
    }


    /**
     * Method performs products checks and remove failed products
     *
     * @param array $arBasket (by ref)
     * @param string $errorString (by ref)
     *
     * @uses \MarketplaceIntegration\IntegrationProduct
     */
    private function checkAndRemoveFailedProducts(&$arBasket, &$errorString): void
    {
        $error = '';
        foreach ($arBasket as $productId => $arProductInfo) {
            do {
                if (!$this->checkProductExistence($productId)) {
                    $error = Loc::getMessage('OIO_PRODUCT_NOT_IN_DB_ERROR', array('#PRODUCT_ID#' => $productId));
                    break;
                }
                if (!$this->checkProductActive($productId)) {
                    $error = Loc::getMessage('OIO_PRODUCT_NOT_ACTIVE_ERROR', array('#PRODUCT_ID#' => $productId));
                    break;
                }

                if (!$this->checkProductPrice($productId)) {
                    $error = Loc::getMessage('OIO_PRODUCT_PRICE_ERROR', array('#PRODUCT_ID#' => $productId));
                    break;
                }

                if (!$this->checkProductQuantity($productId, $arProductInfo['QUANTITY'], $realQuantity)) {
                    $error = Loc::getMessage(
                        'OIO_PRODUCT_QUANTITY_ERROR',
                        array(
                            '#PRODUCT_ID#' => $productId,
                            '#REAL_QUANTITY#' => $realQuantity,
                            '#DESIRED_QUANTITY#' => $arProductInfo['QUANTITY']
                        )
                    );
                    break;
                }
            } while (false);

            // Remove element on error
            if (mb_strlen($error)) {
                unset($arBasket[$productId]);
            }

            // Append error description to errors string
            $errorString .= !mb_strlen($errorString) ? $error : ' ' . $error;
        }
    }


    /**
     * Method compose comment from order fields
     *
     * @param array $arOrder
     *
     * @return string Comment
     */
    private function composeComment(array $arOrder): string
    {
        $comment = $arOrder['posting_number'] . '; ';
        // Address
        $comment .= $arOrder['analytics_data']['region'] . ', ' . $arOrder['analytics_data']['city'] . ', ' .
            $arOrder['analytics_data']['delivery_type'] . '; ';
        // $comment .= trim($arOrder['addressee']['name']) . '; ';
        // $comment .= '+' . trim($arOrder['addressee']['phone']) . '; ';

        return trim($comment);
    }

    /**
     * Method returns json-encoded string with product bitrix ids that required "Chestniy znak" marks
     *
     * @param array $arOrder Order info array
     *
     * @return string
     */
    private function prepareChestniyZnakMarkIds(array $arOrder): string
    {
        $arOzonIds = $arOrder['requirements']['products_requiring_mandatory_mark'] ?? array();
        if (!is_array($arOzonIds) || !count($arOzonIds)) {
            return json_encode([]);
        }

        $arBitrixIds = array();
        foreach ($arOrder['products'] as $arProduct) {
            if (in_array($arProduct['sku'], $arOzonIds)) {
                $arBitrixIds[] = intval($arProduct['offer_id']);
            }
        }

        return json_encode($arBitrixIds);
    }

    /**
     * Method calculates delivery, basket and total order prices
     *
     * @param array $arOrder
     *
     * @return array ['delivery', 'payed amount']
     */
    private function calculateOrderPrices(array $arOrder): array
    {
        $deliveryPrice = $realOrderPayAmount = 0;

        foreach ($arOrder['products'] as $arProduct) {
            $realOrderPayAmount += floatval($arProduct['price']) * intval($arProduct['quantity']);
        }

        return array($deliveryPrice, $realOrderPayAmount);
    }
}
