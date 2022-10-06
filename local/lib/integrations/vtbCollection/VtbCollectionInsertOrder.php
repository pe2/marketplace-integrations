<?php

namespace VtbCollectionIntegration;

use Bitrix\Currency\CurrencyManager;
use Bitrix\Main\Context;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sale\Location\Admin\LocationHelper;
use Bitrix\Sale\Location\LocationTable;
use MarketplaceIntegration as MI;

/**
 * Class VtbCollectionInsertOrder
 * Class for inserting orders in our DB
 *
 * @package VtbCollectionIntegration
 */
class VtbCollectionInsertOrder extends VtbCollectionIntegrationBase
{
    use MI\IntegrationUser;
    use MI\IntegrationProduct {
        MI\IntegrationProduct::__construct as private __IntegrationProductsConstruct;
    }
    use MI\IntegrationOrder {
        MI\IntegrationOrder::__construct as private __IntegrationOrderConstruct;
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

    /** @var \CDataXMLNode */
    private $node;


    /**
     * VtbCollectionInsertOrder constructor.
     *
     * @param \CDataXMLNode $node
     *
     * @throws \Bitrix\Main\LoaderException
     */
    public function __construct($node)
    {
        parent::__construct();

        $this->__IntegrationProductsConstruct();
        $this->__IntegrationOrderConstruct();
        $this->__IntegrationBasketConstruct();
        $this->__IntegrationDeliveryConstruct();
        $this->__IntegrationPaymentConstruct();

        Loc::loadMessages(__FILE__);

        $this->node = $node;
    }


    /**
     * Method insert order and prepare response
     *
     * @param bool $sendNotify Send notification or not
     * @param int $orderId Id of created order
     * @param int $vtbOrderId Id VTB-Collection order
     * @param string $errorStr Errors
     *
     * @return mixed
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\NotImplementedException
     * @throws \Bitrix\Main\ObjectNotFoundException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public function insertOrder($sendNotify = false, &$orderId = 0, &$vtbOrderId = 0, &$errorStr = '')
    {
        $orderId = 0;

        $oVtbCollectionCheckOrder = new VtbCollectionCheckOrder($this->node);
        $oVtbCollectionCheckOrder->checkOrder($errorStr);

        if (empty($errorStr)) {
            $arBasket = $oVtbCollectionCheckOrder->getOrderPreparedBasket();
            $arOrderFields = $oVtbCollectionCheckOrder->getOrderFields();

            $arCreationResult = $this->createOrder($arOrderFields, $arBasket);
            $orderId = $arCreationResult['id'];
            $errorStr = $arCreationResult['error'];
            $vtbOrderId = $arOrderFields['ORDERID'];

            if (0 < intval($orderId) && 0 >= mb_strlen($errorStr) && $sendNotify) {
                parent::notify('order-insert', 'info',
                    Loc::getMessage('VCIO_ORDERS_PROCESSING_RESULT', array(
                        '#ORDERS_PROCESSED#' => '1',
                        '#ORDERS_INSERTED#' => '1',
                        '#ORDERS_IDS#' => $vtbOrderId . '->' . $orderId
                    )));
            }
        }

        $xmlOutput = new \SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\" ?>" .
            "<CommitOrderResult xmlns=\"http://tempuri.org/XMLSchema.xsd\" />");

        $xmlOutput->addChild('InternalOrderId', $orderId);
        $xmlOutput->addChild('Confirmed', (empty($ErrorStr) ? 1 : 0));
        if (!empty($errorStr)) {
            $xmlOutput->addChild('Reason', $errorStr);
        }

        return $xmlOutput->asXML();
    }


    /**
     * Method inserts several orders
     *
     * @return mixed
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\NotImplementedException
     * @throws \Bitrix\Main\ObjectNotFoundException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public function insertSeveralOrders()
    {
        $ordersProcessed = 0;
        $ordersInserted = 0;
        $ordersDataString = '';

        $xmlOutput = new \SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\" ?>" .
            "<CommitOrdersResult xmlns=\"http://tempuri.org/XMLSchema.xsd\" />");
        $xmlOrders = $xmlOutput->addChild("Orders");

        foreach ($this->node->children() as $nodeOrder) {
            $oOrder = new self($nodeOrder);
            $oOrder->insertOrder(false, $orderId, $vtbOrderId, $errorStr);

            if (isset($orderId) && 0 < intval($orderId)) {
                $ordersDataString .= $orderId . '->' . $vtbOrderId . '; ';
            }

            if (0 !== intval($orderId)) {
                $ordersInserted++;
            }

            $ordersProcessed++;

            $xmlOrder = $xmlOrders->addChild('Order');
            $xmlOrder->addChild('OrderId', $vtbOrderId);
            $xmlOrder->addChild('InternalOrderId', (!empty($orderId) ? $orderId : $vtbOrderId));
            $xmlOrder->addChild('Confirmed', (empty($errorStr) ? 1 : 0));
            if (!empty($errorStr)) {
                $xmlOrder->addChild('Reason', $errorStr);
            }

            unset($orderId, $vtbOrderId, $errorStr);
        }

        parent::notify('order-insert', 'info',
            Loc::getMessage('VCIO_ORDERS_PROCESSING_RESULT', array(
                '#ORDERS_PROCESSED#' => $ordersProcessed,
                '#ORDERS_INSERTED#' => $ordersInserted,
                '#ORDERS_IDS#' => $ordersDataString
            )));

        return $xmlOutput->asXML();
    }


    /**
     * Method creates order
     *
     * @param array $arOrderFields
     * @param array $arBasket
     *
     * @return array|bool
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     * @throws \Bitrix\Main\NotImplementedException
     * @throws \Bitrix\Main\ObjectNotFoundException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private function createOrder($arOrderFields, $arBasket)
    {
        // Get user ID (create or make a new one)s
        $userId = $this->prepareUserToAppendToOrder($arOrderFields, $userName, $userEmail, $userPhone);
        if ($userId === parent::VTB_COLLECTION_DEFAULT_USER_ID) {
            parent::notify('user-create', 'error', var_export($arOrderFields['DELIVERY'], true));
        }

        // Basket already prepared, just add some necessary props
        if (!count($arBasket)) {
            parent::notify('empty-basket', 'info', $arOrderFields['ORDERID']);
            return false;
        } else {
            $arBasketItemsProps = $this->getProductAdditionalProperties(array_column($arBasket, 'ID'));
            // Merge arrays by product id
            foreach ($arBasket as $id => $arBasketItem) {
                $arBasket[$id] = array_merge($arBasketItem, $arBasketItemsProps[$id]);
            }
        }

        $siteId = (0 >= strlen(Context::getCurrent()->getSite())) ? Context::getCurrent()->getSite() : 's1';
        $currencyCode = (0 >= strlen(CurrencyManager::getBaseCurrency())) ? CurrencyManager::getBaseCurrency() : 'RUB';

        // Create order
        $order = $this->initiateOrder($userId, $siteId, $currencyCode);
        if (false === $order) {
            parent::notify('order-create', 'error', $arOrderFields['ORDERID']);
            return false;
        }

        // Set fields and props for order
        $this->setOrderComment($this->composeComment($arOrderFields, $userName, $userEmail, $userPhone));
        $this->setOrderProperties(
            array(
                'FIO' => trim($userName),
                'EMAIL' => $userEmail,
                'PHONE' => $userPhone,
                'ZIP' => $arOrderFields['DELIVERY']['POSTCODE'],
                parent::VTB_COLLECTION_ORDER_ID_PROPERTY_CODE => $arOrderFields['ORDERID']
            )
        );
        // Location field
        if (isset($arOrderFields['DELIVERY']['POSTCODE']) && 0 < strlen($arOrderFields['DELIVERY']['POSTCODE'])) {
            $arLocationHelperInfo =
                LocationHelper::getLocationsByZip($arOrderFields['DELIVERY']['POSTCODE'], array('limit' => 1))->fetch();
            if (isset($arLocationHelperInfo['LOCATION_ID']) && 0 < strlen($arLocationHelperInfo['LOCATION_ID'])) {
                $arLocation = LocationTable::getById($arLocationHelperInfo['LOCATION_ID'])->fetch();
                if (isset($arLocation['CODE']) && 0 < strlen($arLocation['CODE'])) {
                    $this->setOrderProperties(array('LOCATION' => $arLocation['CODE']));
                } else {
                    parent::notify('location-code', 'error',
                        $arOrderFields['ORDERID'] . ' - ' . $arLocationHelperInfo['LOCATION_ID']);
                }
            } else {
                parent::notify('location-id', 'error',
                    $arOrderFields['ORDERID'] . ' - ' . $arOrderFields['DELIVERY']['POSTCODE']);
            }
        }

        // Create and append basket to order
        $basket = $this->appendBasketToOrder($arBasket, $siteId, $currencyCode, $order);
        if (!($basket instanceof \Bitrix\Sale\Basket) && is_string($basket)) {
            parent::notify('basket-create', 'error', $arOrderFields['ORDERID']);
            return false;
        }

        // Create and append delivery to order
        $arDeliveryInfo = array('id' => parent::VTB_COLLECTION_DELIVERY_ID, 'cost' => $arOrderFields['DELIVERYCOST']);
        $this->appendDeliveryToOrder($arDeliveryInfo, $order, $basket);

        // Create and append payment to order
        $arPaymentInfo = array('id' => parent::VTB_COLLECTION_PAYMENT_ID, 'cost' => $arOrderFields['TOTALCOST']);
        $this->appendPaymentToOrder($arPaymentInfo, $userId, $currencyCode, $order);

        // Save order and get result
        return $this->finalizeOrder();
    }


    /**
     * Method returns customer's id
     *
     * @param array $arOrderFields User's info
     * @param string $userName User's name (by ref)
     * @param string $userEmail User's email (by ref)
     * @param string $userPhone User's phone (by ref)
     *
     * @return int User id
     *
     * @uses \MarketplaceIntegration\IntegrationUser
     */
    private function prepareUserToAppendToOrder($arOrderFields, &$userName, &$userEmail, &$userPhone)
    {
        $userName = $arOrderFields['DELIVERY']['CONTACTS'][0]['FIRSTNAME'] .
            (!empty($arOrderFields['DELIVERY']['CONTACTS'][0]['MIDDLENAME']) ?
                ' ' . $arOrderFields['DELIVERY']['CONTACTS'][0]['MIDDLENAME'] : '') .
            (!empty($arOrderFields['DELIVERY']['CONTACTS'][0]['LASTNAME']) ?
                ' ' . $arOrderFields['DELIVERY']['CONTACTS'][0]['LASTNAME'] : '');

        $userEmail = (!empty($arOrderFields['DELIVERY']['CONTACTS'][0]['EMAIL']) ?
            $arOrderFields['DELIVERY']['CONTACTS'][0]['EMAIL'] :
            $arOrderFields['DELIVERY']['CONTACTS'][0]['PHONENUMBER'] . '@vtb-collection-email.com');

        $userPhone = '8' . substr($arOrderFields['DELIVERY']['CONTACTS'][0]['PHONENUMBER'], 1);

        return $this->getUserId(
            trim($userName),
            $userEmail,
            $userPhone,
            parent::VTB_COLLECTION_DEFAULT_USER_GROUP,
            parent::VTB_COLLECTION_DEFAULT_USER_ID
        );
    }


    /**
     * Method compose comment from order fields
     *
     * @param array $arOrderFields
     * @param string $userName
     * @param string $userEmail
     * @param string $userPhone
     *
     * @return string Comment
     */
    private function composeComment($arOrderFields, $userName, $userEmail, $userPhone)
    {
        $comment = $arOrderFields['ORDERID'] . ';';
        // Address
        if (isset($arOrderFields['DELIVERY']['ADDRESS'])) {
            $comment .= $arOrderFields['DELIVERY']['ADDRESS'] . '; ';
        }

        $comment .= trim($userName) . '; ' . trim($userEmail) . '; ' . trim($userPhone) . '; ';

        if (isset($arOrderFields['DELIVERY']['COMMENT'])) {
            $comment .= $arOrderFields['DELIVERY']['COMMENT'] . '; ';
        }

        return trim($comment);
    }
}