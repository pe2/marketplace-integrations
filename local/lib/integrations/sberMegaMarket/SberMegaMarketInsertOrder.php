<?php

namespace SberMegaMarketIntegration;

use Bitrix\Currency\CurrencyManager;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Context;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Sale\Basket;
use CUtil;
use DateTime;
use Exception;
use MarketplaceIntegration as MI;
use Local\Init\ServiceHandler as SH;
use SberMegaMarketIntegration\SberMegaMarketRequestHelper as SberMMRH;

/**
 * Class SberMegaMarketInsertOrder
 * Class used for inserting orders from SberMegaMarket into our DB
 *
 * @package SberMegaMarketIntegration
 */
class SberMegaMarketInsertOrder extends SberMegaMarketIntegrationBase
{
    use MI\IntegrationUser;
    use MI\IntegrationOrder {
        MI\IntegrationOrder::__construct as private __IntegrationOrderConstruct;
    }
    use MI\IntegrationProduct {
        MI\IntegrationProduct::__construct as private __IntegrationProductConstruct;
    }
    use MI\IntegrationBasket {
        MI\IntegrationBasket::__construct as private __IntegrationBasketConstruct;
    }
    use MI\IntegrationDelivery {
        MI\IntegrationDelivery::__construct as private __IntegrationDeliveryConstruct;
    }
    use MI\IntegrationPayment {
        MI\IntegrationPayment::__construct as private __IntegrationPaymentConstruct;
    }
    use MI\IntegrationOrder {
        MI\IntegrationOrder::__construct as private __IntegrationOrderConstruct;
    }


    /** @var array Extracted Order data */
    private array $arOrderData;


    /**
     * SberMegaMarketInsertOrder constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->__IntegrationOrderConstruct();
        $this->__IntegrationProductConstruct();
        $this->__IntegrationOrderConstruct();
        $this->__IntegrationBasketConstruct();
    }


    /**
     * Method handles requested order
     *
     * @param array $arOriginalOrderData Original order request data
     *
     * @throws ArgumentException
     * @throws SystemException
     */
    public function handleSberMMOrderInsertRequest(array $arOriginalOrderData): void
    {
        $this->extractOrderData($arOriginalOrderData);

        if (0 >= strlen($this->arOrderData['order_id']) || 0 >= $this->arOrderData['order_price'] ||
            !count($this->arOrderData['basket'])
        ) {
            parent::notify('order-data-extract', 'error', var_export($arOriginalOrderData, true));
            SberMMRH::makeResponse(400, array('description' => 'Order data extract error'));
        }

        if ($this->isOrderExist(parent::SBERMM_ORDER_ID_PROPERTY_CODE, $this->arOrderData['order_id'])) {
            parent::notify('order-exists', 'error', $this->arOrderData['order_id']);
            SberMMRH::makeResponse(406, array('description' => 'Order already exists'));
        }

        $oCheckSberMMOrder = new SberMegaMarketCheckOrder($this->arOrderData['basket']);
        list($arConfirmed, $arRejected, $errorString) = $oCheckSberMMOrder->checkOrder();

        $sendRejectRequest = $sendConfirmRequest = $send200Request = false;
        if (count($arRejected)) {
            parent::notify((!count($arConfirmed) ? 'empty-basket' : 'rejected-products'), 'info',
                $this->arOrderData['order_id'] . '. ' . $errorString);
            $sendRejectRequest = true;
            $this->removeRejectedProductsFromOrder($arRejected);
        }
        if (count($arConfirmed)) {
            $arInsertResult = $this->insertOrder();
            if (0 < $arInsertResult['id']) {
                $send200Request = $sendConfirmRequest = true;
            }
        }

        // Send response/requests in right order
        if ($send200Request) {
            // Everything is ok, send 200 code
            SberMMRH::makeResponse('200', array(), true, false);
        }

        $oSberMMRequest = new SberMegaMarketRequests($this->arOrderData);
        if ($sendRejectRequest) {
            $oSberMMRequest->makeRejectRequest($arRejected);
        }
        if ($sendConfirmRequest) {
            $oSberMMRequest->makeConfirmRequest($arConfirmed, $arInsertResult['id']);
        }
    }


    /**
     * Method parses order data and prepares $arOrderData array
     *
     * @param array $arOriginalOrderData
     */
    private function extractOrderData(array $arOriginalOrderData): void
    {
        $arBasket = $arIdsIndex = array();
        foreach ($arOriginalOrderData['data']['shipments'][0]['items'] as $arItem) {
            if (!array_key_exists($arItem['offerId'], $arBasket)) {
                $arBasket[$arItem['offerId']] = array(
                    'ID' => intval($arItem['offerId']),
                    'PRICE_PER_UNIT' => floatval($arItem['price']),
                    'PRICE' => floatval($arItem['price']),
                    'QUANTITY' => 1,
					'SBERMM' => 'Y'
                );
            } else {
                $arBasket[$arItem['offerId']]['QUANTITY'] += 1;
            }
			$arIdsIndex[$arItem['itemIndex']] = intval($arItem['offerId']);
        }

        $orderPrice = 0;
        foreach ($arBasket as $basketItem) {
            $orderPrice += $basketItem['PRICE'] * $basketItem['QUANTITY'];
        }

        $arLocation = array(
            'region' => $arOriginalOrderData['data']['shipments'][0]['label']['region'],
            'city' => $arOriginalOrderData['data']['shipments'][0]['label']['city'],
            'address' => $arOriginalOrderData['data']['shipments'][0]['label']['address']
        );

        $arCustomer = array(
            'name' => $arOriginalOrderData['data']['shipments'][0]['label']['fullName'],
            'name_transliterated' => CUtil::translit(
                $arOriginalOrderData['data']['shipments'][0]['label']['fullName'], 'ru',
                array(
                    'change_case' => 'L',
                    'replace_space' => '-',
                    'replace_other' => '-'
                )
            )
        );

        $this->arOrderData = array(
            'order_id' => $arOriginalOrderData['data']['shipments'][0]['shipmentId'],
            'shipping_date' => $this->prepareDeliveryDate($arOriginalOrderData['data']['shipments'][0]['shipping']),
            'order_price' => $orderPrice,
            'basket' => $arBasket,
            'location' => $arLocation,
            'customer' => $arCustomer,
            'id_indexes' => $arIdsIndex,
            'id_articles' => $this->getArticlesIndexArray($arIdsIndex)
        );
    }

    /**
     * Method returns formatted shipping date
     *
     * @param array $arShippingInfo
     *
     * @return string
     */
    private function prepareDeliveryDate(array $arShippingInfo): string
    {
        $shippingDate = '';

        if (isset($arShippingInfo['shippingDate']) && 0 < strlen($arShippingInfo['shippingDate'])) {
            try {
                $oShippingDate = new DateTime($arShippingInfo['shippingDate']);
                $shippingDate = $oShippingDate->format('d.m.Y');
                if (0 < strlen($shippingDate)) {
                    return $shippingDate;
                } else {
                    SH::writeToLog('Error in shipping date: ' . var_export($arShippingInfo['shippingDate'], true),
                        self::LOG_FILE_PATH);
                }
            } catch (Exception $e) {
                SH::writeToLog('Exception in shipping date: ' . $e->getMessage(), self::LOG_FILE_PATH);
            }
        }

        return $shippingDate;
    }

    /**
     * Method substitute offer ids with articles in given array
     *
     * @param array $arIdsIndex Array ['offer_id' => index, ...]
     *
     * @return array Array ['offer_article' => index, ...]
     */
    private function getArticlesIndexArray(array $arIdsIndex): array
    {
        $arArticles = $this->getProductDesiredProperties(array_values($arIdsIndex), array("PROPERTY_CML2_ARTICLE"));
        $arArticlesIndex = array();

        foreach ($arIdsIndex as $index => $productId) {
            $arArticlesIndex[$index] = $arArticles[$productId]['PROPERTY_CML2_ARTICLE_VALUE'];
        }

        return $arArticlesIndex;
    }

    /**
     * Method removes from orders basket rejected ids
     *
     * @param array $arRejected Array with rejected product ids
     */
    private function removeRejectedProductsFromOrder(array $arRejected): void
    {
        foreach ($arRejected as $rejectedId) {
            unset($this->arOrderData['basket'][$rejectedId]);
        }
    }

    /**
     * Method inserts order in our DB
     *
     * @return array ['id' => id, 'error' => error]
     *
     * @throws ArgumentException
     * @throws SystemException
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
     * @throws NotImplementedException
     * @throws ObjectNotFoundException
     * @throws ObjectPropertyException
     */
    private function insertOrder(): array
    {
        // Get user ID (create or make a new one)
        $userId = $this->prepareUserToAppendToOrder($userEmail);
        if ($userId === parent::SBERMM_DEFAULT_USER_ID) {
            parent::notify('user-create', 'error', var_export($this->arOrderData['ae_user_id']['customer'], true));
        }

        // Get additional props
        $arBasketItemsProps = $this->getProductAdditionalProperties(array_column($this->arOrderData['basket'], 'ID'));
        // Merge arrays by product id
        foreach ($this->arOrderData['basket'] as $id => $arBasketItem) {
            $this->arOrderData['basket'][$id] = array_merge($arBasketItem, $arBasketItemsProps[$arBasketItem['ID']]);
        }

        $siteId = (0 >= strlen(Context::getCurrent()->getSite())) ? Context::getCurrent()->getSite() : 's1';
        $currencyCode = (0 >= strlen(CurrencyManager::getBaseCurrency())) ? CurrencyManager::getBaseCurrency() : 'RUB';

        // Create order
        $order = $this->initiateOrder($userId, $siteId, $currencyCode);
        if (false === $order) {
            parent::notify('order-create', 'error', $this->arOrderData['order_id']);
            return array('id' => 0, 'error' => 'Order create error.');
        }

        // Set fields and props for order
        $this->setOrderComment($this->composeComment());
        $this->setOrderProperties(
            array(
                'FIO' => $this->arOrderData['customer']['name'],
                'EMAIL' => $userEmail,
                // 'ZIP' => $arOrder['recipientInfo']['zip'],
                'CITY' => $this->arOrderData['location']['city'],
                'REGION_NAME' => $this->arOrderData['location']['region'],
                'OZON_DELIVERY_DATE' => $this->arOrderData['shipping_date'],
                parent::SBERMM_ORDER_ID_PROPERTY_CODE => $this->arOrderData['order_id'],
                parent::SBERMM_ORDER_PRODUCT_INDEXES_PROPERTY_CODE => json_encode($this->arOrderData['id_articles'])
            )
        );

        // Create and append basket to order
        $basket = $this->appendBasketToOrder($this->arOrderData['basket'], $siteId, $currencyCode, $order);
        if (!($basket instanceof Basket) && is_string($basket)) {
            parent::notify($basket, 'error', $this->arOrderData['order_id']);
            return array('id' => 0, 'error' => 'Basket append error.');
        }

        // Create and append delivery to order
        $arDeliveryInfo = array('id' => parent::SBERMM_DELIVERY_ID, 'cost' => 0);
        $this->appendDeliveryToOrder($arDeliveryInfo, $order, $basket);

        // Create and append payment to order
        $arPaymentInfo = array('id' => parent::SBERMM_PAYMENT_ID, 'cost' => $this->arOrderData['order_price']);
        $this->appendPaymentToOrder($arPaymentInfo, $userId, $currencyCode, $order);

        // Save order and get result
        return $this->finalizeOrder();
    }


    /**
     * Method returns customer's id
     *
     * @param string|null $userEmail User's email (by ref.)
     *
     * @return int
     */
    private function prepareUserToAppendToOrder(&$userEmail): int
    {
        //$userEmail = $this->arOrderData['customer']['name_transliterated'] . '@sbermegamarket-email.com';
        // Unique user for every order
        $userEmail = $this->arOrderData['order_id'] . '@sbermegamarket-email.com';

        return $this->getUserId(
            $this->arOrderData['customer']['name'],
            $userEmail,
            '',
            parent::SBERMM_DEFAULT_USER_GROUP_ID,
            parent::SBERMM_DEFAULT_USER_ID
        );
    }


    /**
     * Method compose comment from order fields
     *
     * @return string String for comment field
     */
    private function composeComment(): string
    {
        return trim($this->arOrderData['order_id'] . '; ' . $this->arOrderData['location']['address'] . '; ' .
            $this->arOrderData['customer']['name']);
    }
}