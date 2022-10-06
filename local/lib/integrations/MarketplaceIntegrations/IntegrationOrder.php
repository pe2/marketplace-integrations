<?php

namespace MarketplaceIntegration;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Loader;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Sale\Order;
use Exception;
use Local\Init\ServiceHandler;
use Local\Sale\OrderInformationHandler as OIH;
use OzonIntegration\OzonIntegrationBase;
use AliExpressIntegration\AliExpressIntegrationBase;
use SberMegaMarketIntegration\SberMegaMarketIntegrationBase;
use VtbCollectionIntegration\VtbCollectionIntegrationBase;
use Yandex\Market;

/**
 * Trait IntegrationOrder
 * This trait used for creating order
 *
 * @package MarketplaceIntegration
 */
trait IntegrationOrder
{
    /** @var array Result of insertion */
    private $arOrderInsertResult;
    /** @var object $order Instance on \Bitrix\Sale\Order */
    private $order;
    /** @var object \OrderPropsHandler object */
    private $orderPropsObject;


    /**
     * IntegrationOrder constructor.
     */
    public function __construct()
    {
        $this->arOrderInsertResult = array(
            'id' => 0,
            'error' => ''
        );

        try {
            Loader::includeModule('sale');
        } catch (\Bitrix\Main\LoaderException $e) {
            //
        }
    }


    /**
     * Method checks order affiliation to partner by given payment id and delivery id
     *
     * @param object $order \Bitrix\Sale\Order object
     * @param string $paySystemId Pay system id to check in order
     * @param string $deliverySystemId Delivery id to check in order
     *
     * @return bool
     */
    public static function checkOrderAffiliation(object $order, string $paySystemId, string $deliverySystemId): bool
    {
        $paySystemCheck = $deliverySystemCheck = false;

        foreach ($order->getPaySystemIdList() as $orderPaySystemId) {
            if ($paySystemId === strval($orderPaySystemId)) {
                $paySystemCheck = true;
            }
        }

        foreach ($order->getDeliveryIdList() as $orderDeliverySystemId) {
            if ($deliverySystemId === strval($orderDeliverySystemId)) {
                $deliverySystemCheck = true;
            }
        }

        return ($paySystemCheck && $deliverySystemCheck);
    }


    /**
     * Method checks order's affiliation to one of the marketplace integrations
     *
     * @param object $order \Bitrix\Sale\Order object
     *
     * @return bool
     */
    public static function isMarketplaceOrder(object $order): bool
    {
        /** @var array Array or integration props code which stores marketplace order id */
        $arIntegrationPropCodes = array(
            OzonIntegrationBase::OZON_ORDER_ID_PROPERTY_CODE,
            SberMegaMarketIntegrationBase::SBERMM_ORDER_ID_PROPERTY_CODE,
            VtbCollectionIntegrationBase::VTB_COLLECTION_ORDER_ID_PROPERTY_CODE
        );

        $arProperties = $order->getPropertyCollection()->getArray();
        foreach ($arProperties['properties'] as $arProperty) {
            if (in_array($arProperty['CODE'], $arIntegrationPropCodes)) {
                if (count($arProperty['VALUE']) && 0 < mb_strlen($arProperty['VALUE'][0])) {
                    return true;
                }
            }
        }

        /** @var array|null $yandexMarketOrder Yandex marketplace order info */
        $yandexMarketOrder = Market\Trading\Entity\Sale\Platform::parseOrderXmlId($order->getField('XML_ID'));
        if (is_array($yandexMarketOrder) && 0 < mb_strlen($yandexMarketOrder['ORDER_ID'])) {
            return true;
        }

        return false;
    }


    /**
     * Method checks order existence in our DB
     *
     * @param string $propertyName Order property to check external number
     * @param string $orderId External order identification number in partner system
     * @param int $ourOrderId Our order id (returns by ref.)
     *
     * @return bool
     *
     * @throws ArgumentException
     * @throws SystemException
     */
    private function isOrderExist(string $propertyName, string $orderId, int &$ourOrderId = 0): bool
    {
        if (0 >= strlen($orderId)) {
            return true;
        }

        try {
            $objStartDate = new \DateTime('2020-01-01T00:00:00');
            $objEndDate = new \DateTime('now');
        } catch (Exception $e) {
            return true;
        }

        $arAdditionalOrderFilterProperties = array(
            'forFilter' => array(
                'PROPERTY_VAL.CODE' => $propertyName,
                'PROPERTY_VAL.VALUE' => $orderId
            ),
            'runtime' => array(
                new \Bitrix\Main\Entity\ReferenceField(
                    'PROPERTY_VAL',
                    '\Bitrix\sale\Internals\OrderPropsValueTable',
                    array("=this.ID" => "ref.ORDER_ID"),
                    array("join_type" => "left")
                ),
            )
        );

        $oOrderInformation = new OIH(
            $objStartDate->format('d.m.Y 00:00:00'),
            $objEndDate->format('d.m.Y H:i:s'),
            array(),
            'asc',
            $arAdditionalOrderFilterProperties
        );

        $arOrderData = $oOrderInformation->getOrdersBaseData();

        $ourOrderId = array_key_first($arOrderData); // return by ref.

        return (is_array($arOrderData) && count($arOrderData));
    }


    /**
     * Method creates orders and sets some fields
     *
     * @param int $userId
     * @param string $siteId
     * @param string $currencyCode
     * @param int $payerTypeId
     *
     * @return Order|bool
     *
     * @throws ArgumentNullException
     * @throws ArgumentException
     * @throws ArgumentOutOfRangeException
     * @throws NotImplementedException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private function initiateOrder(int $userId, string $siteId, string $currencyCode, int $payerTypeId = 1)
    {
        // Disable coupons
        \Bitrix\Sale\DiscountCouponsManager::freezeCouponStorage();

        try {
            $this->order = Order::create($siteId, $userId);
        } catch (Exception $e) {
            return false;
        }

        $this->order->setPersonTypeId($payerTypeId);
        $this->order->setField('CURRENCY', $currencyCode);

        $this->orderPropsObject = new \OrderPropsHandler($this->order);

        return $this->order;
    }


    /**
     * Method sets comment for order
     *
     * @param string $comment
     *
     * @return bool
     */
    private function setOrderComment(string $comment): bool
    {
        if (0 < mb_strlen($comment)) {
            $arUpdateResult = $this->order->setField('USER_DESCRIPTION', $comment);
            return $arUpdateResult->isSuccess();
        }

        return false;
    }


    /**
     * Method sets order properties from given array
     *
     * @param array $arOrderProperties Assoc. array (key is property name, values is property value)
     */
    private function setOrderProperties(array $arOrderProperties): void
    {
        foreach ($arOrderProperties as $propertyKey => $propertyValue) {
            if (isset($propertyKey) && 0 < mb_strlen($propertyKey) &&
                isset($propertyValue) && 0 < mb_strlen($propertyValue)
            ) {
                try {
                    $this->setOrderProperty($propertyKey, $propertyValue);
                } catch (Exception $e) {
                    //
                }
            }
        }
    }


    /**
     * Method updates order property
     *
     * @param string $propertyName
     * @param string $propertyValue
     *
     * @return bool
     *
     * @uses \OrderPropsHandler
     */
    private function setOrderProperty(string $propertyName, string $propertyValue): bool
    {
        return $this->orderPropsObject->updatePropObjectOrder($propertyName, $propertyValue);
    }


    /**
     * Method saves order and returns result
     *
     * @return array ['id', 'error']
     */
    private function finalizeOrder(): array
    {
        $this->order->doFinalAction(true);
        $orderResult = $this->order->save();

        if ($orderResult->isSuccess()) {
            $this->arOrderInsertResult['id'] = $this->order->getId();
        }

        $arErrors = array_merge($orderResult->getErrorMessages(), $orderResult->getWarningMessages());
        $this->arOrderInsertResult['error'] = implode("\n", array_unique($arErrors));

        // Enable coupons
        \Bitrix\Sale\DiscountCouponsManager::unFreezeCouponStorage();

        return $this->arOrderInsertResult;
    }
}