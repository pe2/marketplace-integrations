<?php

namespace MarketplaceIntegration;

use Bitrix\Catalog\Model\Product;
use Bitrix\Catalog\PriceTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use CIBlockElement;

/**
 * Trait IntegrationProduct
 * This trait used for working with products properties
 *
 * @package MarketplaceIntegration
 */
trait IntegrationProduct
{
    /**
     * IntegrationProduct constructor.
     */
    public function __construct()
    {
        try {
            Loader::includeModule('catalog');
        } catch (LoaderException $e) {
            //
        }
    }


    /**
     * Method return bitrix ids by given 1c codes
     *
     * @param array $ar1cIds Array of 1C ids
     *
     * @return array
     */
    public function getBitrixIdsBy1cCodes(array $ar1cIds): array
    {
        $arBitrixIds = array();

        try {
            Loader::includeModule('iblock');
        } catch (LoaderException $e) {
            return [];
        }

        if (!count($ar1cIds)) {
            return [];
        }

        $dbBitrixIdsResult = CIBlockElement::GetList(
            array(),
            array('=PROPERTY_PRODUCT_CODE_IN_1C' => $ar1cIds),
            false,
            false,
            array('ID', 'IBLOCK_ID', 'PROPERTY_PRODUCT_CODE_IN_1C')
        );
        while ($arElement = $dbBitrixIdsResult->GetNext()) {
            $arBitrixIds[trim($arElement['PROPERTY_PRODUCT_CODE_IN_1C_VALUE'])] = trim($arElement['ID']);
        }

        return $arBitrixIds;
    }


    /**
     * Method checks product existence in DB
     *
     * @param int $productId Product id
     *
     * @return bool
     */
    private function checkProductExistence(int $productId): bool
    {
        return count(Product::getCacheItem($productId, true));
    }


    /**
     * Method checks product activeness
     *
     * @param int $productId Product id
     *
     * @return bool
     */
    private function checkProductActive(int $productId): bool
    {
        $active = false;
        if ($arItem = CIBlockElement::getByID($productId)->fetch()) {
            $active = ('Y' === $arItem['ACTIVE']);
        }

        return $active;
    }


    /**
     * Method checks product price
     *
     * @param int $productId Product id
     * @param float $ourPrice Our current product price (returns by ref.)
     * @param int $priceGroupId Price group id (BASE by default)
     *
     * @return bool
     */
    private function checkProductPrice(int $productId, float &$ourPrice = 0.0, int $priceGroupId = 1): bool
    {
        $ourPrice = 0.0;
        try {
            $arPrice = PriceTable::getList(array(
                'filter' => array('=PRODUCT_ID' => $productId, '=CATALOG_GROUP_ID' => $priceGroupId)
            ))->fetchAll();
            $ourPrice = intval(round($arPrice[0]['PRICE'], 0));
        } catch (ObjectPropertyException | ArgumentException | SystemException $e) {
            return false;
        }

        return (0 < $ourPrice);
    }


    /**
     * Method checks product quantity (more than 0 and equal or more to process the order)
     *
     * @param int $productId Product id
     * @param int $desiredQuantity Desired quantity
     * @param int|null $realQuantity Real quantity in DB (returns by ref.)s
     *
     * @return bool
     */
    private function checkProductQuantity(int $productId, int $desiredQuantity, &$realQuantity): bool
    {
        $arProductInfo = Product::getCacheItem($productId, true);
        $realQuantity = intval($arProductInfo['QUANTITY']);

        return (
            ('Y' === strval($arProductInfo['AVAILABLE'])) &&
            (0 < intval($arProductInfo['QUANTITY'])) &&
            ($desiredQuantity <= $realQuantity)
        );
    }


    /**
     * Method checks difference in percent between price from order and current price
     *
     * @param float $givenPrice
     * @param float $ourPrice
     * @param float $percentThreshold
     *
     * @return bool True if difference between $givenPrice and $ourPrice less than $percentThreshold
     */
    private function checkProductPricePercent(float $givenPrice, float $ourPrice, float $percentThreshold = 30.0): bool
    {
        $diff = ($givenPrice - $ourPrice) / $ourPrice;
        $percent = abs(round($diff * 100, 2));

        return ($percent < $percentThreshold);
    }


    /**
     * Method checks particular property value for given product id
     *
     * @param int $productId Product id
     * @param string $propertyName Property name
     * @param string $propertyValue Desired property value
     *
     * @return bool
     */
    private function checkProductStringPropertyExport(int $productId, string $propertyName, string $propertyValue): bool
    {
        $inExport = false;

        $dbPropertyResult = CIBlockElement::GetList(
            array(),
            array('ID' => $productId),
            false,
            false,
            array('ID', 'IBLOCK_ID', 'PROPERTY_' . $propertyName)
        );
        if ($arPropertiesResult = $dbPropertyResult->Fetch()) {
            if ($propertyValue === strval($arPropertiesResult['PROPERTY_' . $propertyName . '_VALUE'])) {
                $inExport = true;
            }
        }

        return $inExport;
    }


    /**
     * Method returns array with products properties for given array of ids
     *
     * @param array $arProductsIds Array of product ids
     *
     * @return array
     */
    private function getProductAdditionalProperties(array $arProductsIds): array
    {
        if (!is_array($arProductsIds) || !count($arProductsIds)) {
            return array();
        }

        $dbProductsResult = CIBlockElement::GetList(
            array(),
            array('=ID' => $arProductsIds),
            false,
            false,
            array('ID', 'IBLOCK_ID', 'XML_ID', 'NAME', 'WEIGHT', 'PROPERTY_MEDICINE')
        );
        $arProductsPropInfo = array();
        while ($arElement = $dbProductsResult->GetNext()) {
            $arProductsPropInfo[strval($arElement['ID'])] = array(
                'XML_ID' => $arElement['XML_ID'],
                'NAME' => $arElement['NAME'],
                'WEIGHT' => floatval($arElement['WEIGHT']),
                'SERVICE_FIELD_BASKET' => array(
                    'MEDICINE' => ('Y' === strval($arElement['PROPERTY_MEDICINE_VALUE'])) ? 'Y' : 'N',
                    'PRICE_TYPE' => 'BASE',
                    'BUY_SOCIAL_PRICE' => 'N'
                )
            );
        }

        return $arProductsPropInfo;
    }


    /**
     * Method returns properties values for array of products
     *
     * @param array $arProductIds Array with product ids
     * @param array $arProductProperties Array with properties names
     * @param bool $indexArray Add elements in index array or assoc. with id as a key
     *
     * @return array ['product_id' => array_of_properties_values, ...]
     */
    private function getProductDesiredProperties(array $arProductIds, array $arProductProperties, bool $indexArray = false): array
    {
        if (!is_array($arProductIds) || !count($arProductIds) ||
            !is_array($arProductProperties) || !count($arProductProperties)
        ) {
            return array();
        }

        $dbProductsResult = CIBlockElement::GetList(
            array(),
            array('IBLOCK_ID' => array(IBLOCK_TOVAR, IBLOCK_TOVAR_OFFERS), '=ID' => $arProductIds),
            false,
            false,
            array_merge(array('ID', 'IBLOCK_ID'), $arProductProperties)
        );
        $arProductsPropInfo = array();
        while ($arElement = $dbProductsResult->GetNext()) {
            if ($indexArray) {
                $arProductsPropInfo[] = $arElement;
            } else {
                $arProductsPropInfo[strval($arElement['ID'])] = $arElement;
            }
        }

        return $arProductsPropInfo;
    }
}