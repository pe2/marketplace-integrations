<?php

namespace MarketplaceIntegration;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Sale\Basket;
use Bitrix\Sale\BasketBase;
use Exception;
use Local\Sale\BasketProductPropertiesHandler;

/**
 * Trait IntegrationBasket
 * This trait used for creating basket and working with basket properties
 *
 * @package MarketplaceIntegration
 */
trait IntegrationBasket
{
    /**
     * IntegrationBasket constructor.
     */
    public function __construct()
    {
        try {
            Loader::includeModule('sale');
        } catch (LoaderException $e) {
            //
        }
    }


    /**
     * Method creates basket and appends products in it
     *
     * @param array $arBasket Array with products data
     * @param string $siteId Site id
     * @param string $currencyCode Currency code
     * @param object $order \Bitrix\Sale\Order object
     *
     * @return object|string \Bitrix\Sale\Basket or error string
     *
     * @throws ArgumentException
     * @throws ArgumentNullException
     * @throws NotImplementedException
     * @throws ObjectNotFoundException
     * @throws Exception
     */
    private function appendBasketToOrder(array $arBasket, string $siteId, string $currencyCode, object &$order)
    {
        $basket = $this->createBasket($siteId);
        if (false === $basket) {
            return 'basket-create';
        }

        $this->addProductsToBasket($arBasket, $siteId, $currencyCode, $basket);

        $order->setBasket($basket);
        $basket->save();

        $this->addSpecialPropsToBasketProducts($arBasket, $basket);
        $basket->save();

        return $basket;
    }


    /**
     * Method creates basket object
     *
     * @param string $siteId
     *
     * @return BasketBase|bool
     */
    private function createBasket(string $siteId)
    {
        try {
            return Basket::create($siteId);
        } catch (Exception $e) {
            return false;
        }
    }


    /**
     * Method appends products to basket
     *
     * @param array $arBasket
     * @param string $siteId
     * @param string $currencyCode
     * @param object $basket \Bitrix\Sale\Basket object
     */
    private function addProductsToBasket(array $arBasket, string $siteId, string $currencyCode, object &$basket): void
    {
        foreach ($arBasket as $productId => $arProduct) {
            $productId = (isset($arProduct['SBERMM']) && 'Y' === $arProduct['SBERMM']) ? $arProduct['ID'] : $productId;
            $item = $basket->createItem('catalog', $productId);
            $item->setFields(array(
                'QUANTITY' => $arProduct['QUANTITY'],
                'CURRENCY' => $currencyCode,
                'LID' => $siteId,
                'PRICE' => $arProduct['PRICE_PER_UNIT'],
                'CUSTOM_PRICE' => 'Y',
                'NAME' => $arProduct['NAME'],
                'WEIGHT' => $arProduct['WEIGHT'],
                'PRODUCT_PROVIDER_CLASS' => '\Local\Base\BaseProductProvider',
                'XML_ID' => $arProduct['XML_ID']
            ));
        }
    }


    /**
     * Method appends special props (as json array) to products in basket
     *
     * @param array $arBasket
     * @param object $basket
     *
     * @throws Exception
     */
    private function addSpecialPropsToBasketProducts(array $arBasket, object &$basket): void
    {
        // Index array for SberMM basket
        $arIdsIndexes = array();
        foreach ($arBasket as $index => $basketItem) {
            $arIdsIndexes[$basketItem['ID']] = $index;
        }

        foreach ($basket as $basketItem) {
            $fieldValue = $arBasket[$basketItem->getProductId()]['SERVICE_FIELD_BASKET'] ??
                $arBasket[$arIdsIndexes[$basketItem->getProductId()]]['SERVICE_FIELD_BASKET'];

            $oBasketProductPropertiesHandler = new BasketProductPropertiesHandler();
            $oBasketProductPropertiesHandler->addBasketProperty(
                $basketItem->getId(),
                $basketItem->getField('XML_ID'),
                $fieldValue
            );
        }
    }
}