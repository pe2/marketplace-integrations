<?php

namespace VtbCollectionIntegration;

use Bitrix\Main\Localization\Loc;
use MarketplaceIntegration as MI;

/**
 * Class VtbCollectionCheckOrder
 * Class used for checking orders
 *
 * @package VtbCollectionIntegration
 */
class VtbCollectionCheckOrder extends VtbCollectionIntegrationBase
{
    use MI\IntegrationProduct {
        MI\IntegrationProduct::__construct as private __IntegrationProductsConstruct;
    }

    /** @var \CDataXMLNode XML node */
    private $node;


    /**
     * VtbCollectionCheckOrder constructor.
     *
     * @param \CDataXMLNode $node
     *
     * @throws \Bitrix\Main\LoaderException
     */
    public function __construct($node)
    {
        parent::__construct();

        $this->__IntegrationProductsConstruct();

        $this->node = $node;
    }


    /**
     * Method checks order and return result as xml-string
     *
     * @param string $errorStr (by ref)
     *
     * @return mixed
     */
    public function checkOrder(&$errorStr = '')
    {
        $errorStr = '';
        $arOrderFields = $this->extractOrderData();

        // Check delivery
        VtbCollectionCheckDelivery::checkDeliveryRegion($arOrderFields['DELIVERY'], $errorStr);

        // Check items
        if (empty($errorStr) && !empty($arOrderFields['ITEMS'])) {
            $arBasket = $this->prepareBasketToAppendToOrder($arOrderFields['ITEMS']);
            $this->checkAndRemoveFailedProducts($arBasket, $errorStr);
        }

        $xmlOutput = new \SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\" ?><CheckOrderResult " .
            "xmlns=\"http://tempuri.org/XMLSchema.xsd\" />");

        $xmlOutput->addChild('Checked', (empty($errorStr) ? 1 : 0));
        if (!empty($errorStr)) {
            $xmlOutput->addChild('Reason', $errorStr);
        }

        if (!empty($errorStr)) {
            $this->notify('order-check', 'error', $errorStr);
        }

        return $xmlOutput->asXML();
    }


    /**
     * Method returns order fields as an array
     *
     * @return array
     */
    public function getOrderFields()
    {
        return $this->extractOrderData();
    }


    /**
     * Method returns cleared basket
     *
     * @return array
     */
    public function getOrderPreparedBasket()
    {
        $arOrderFields = $this->getOrderFields();

        $arBasket = $this->prepareBasketToAppendToOrder($arOrderFields['ITEMS']);
        $this->checkAndRemoveFailedProducts($arBasket, $errorStr);

        return $arBasket;
    }


    /**
     * Method prepare basket for checking and attaching to order
     *
     * @param $arProducts
     *
     * @return array
     */
    private function prepareBasketToAppendToOrder($arProducts)
    {
        $arBasket = array();

        foreach ($arProducts as $arProduct) {
            $arBasket[strval($arProduct['OFFERID'])] = array(
                'ID' => strval($arProduct['OFFERID']),
                'PRICE_PER_UNIT' => floatval($arProduct['PRICE']),
                'PRICE' => floatval($arProduct['PRICE']),
                'QUANTITY' => intval($arProduct['AMOUNT'])
            );
        }

        return $arBasket;
    }


    /**
     * Method obtain order fields from request
     *
     * @return array
     */
    private function extractOrderData()
    {
        $arOrderFields = array();

        foreach ($this->node->children() as $children) {
            switch ($children->name()) {
                case 'Items':
                    foreach ($children->children() as $item) {
                        $arItem = array();
                        foreach ($item->children() as $field) {
                            $arItem[strtoupper($field->name())] = $field->textContent();
                        }
                        $arOrderFields['ITEMS'][$arItem['OFFERID']] = $arItem;
                    }
                    break;

                case 'DeliveryInfo':
                    foreach ($children->children() as $item) {

                        switch ($item->name()) {
                            case 'Contacts':
                                foreach ($item->children() as $contact) {
                                    $arContact = array();
                                    foreach ($contact->children() as $field) {
                                        $arContact[strtoupper($field->name())] = $field->textContent();
                                    }
                                    $arOrderFields['DELIVERY']['CONTACTS'][] = $arContact;
                                }
                                break;

                            default:
                                $arOrderFields['DELIVERY'][strtoupper($item->name())] = $item->textContent();
                                break;
                        }
                    }
                    break;

                default:
                    $arOrderFields[strtoupper($children->name())] = $children->textContent();
                    break;
            }
        }

        return $arOrderFields;
    }


    /**
     * Method performs products checks and remove failed products
     *
     * @param array $arBasket (by ref)
     * @param string $errorString (by ref)
     *
     * @return bool true
     *
     * @uses \MarketplaceIntegration\IntegrationProduct
     */
    private function checkAndRemoveFailedProducts(&$arBasket, &$errorString)
    {
        $error = '';

        foreach ($arBasket as $productId => $arProductInfo) {
            do {
                if (!$this->checkProductExistence($productId)) {
                    $error = Loc::getMessage('VCCO_PRODUCT_NOT_IN_DB_ERROR', array('#PRODUCT_ID#' => $productId));
                    break;
                }

                if (!$this->checkProductActive($productId)) {
                    $error = Loc::getMessage('VCCO_PRODUCT_NOT_ACTIVE_ERROR', array('#PRODUCT_ID#' => $productId));
                    break;
                }

                if (!$this->checkProductStringPropertyExport(
                    $productId,
                    parent::VTB_COLLECTION_PRODUCT_PROPERTY_NAME,
                    'Y')
                ) {
                    $error = Loc::getMessage('VCCO_PRODUCT_DOES_NOT_HAVE_PROPERTY_ERROR', array('#PRODUCT_ID#' => $productId));
                    break;
                }

                $ourPrice = 0.0;
                if (!$this->checkProductPrice($productId, $ourPrice)) {
                    $error = Loc::getMessage('VCCO_PRODUCT_PRICE_ERROR', array('#PRODUCT_ID#' => $productId));
                    break;
                }

                if (!$this->checkProductPricePercent(
                    $arProductInfo['PRICE'],
                    $ourPrice,
                    parent::VTB_COLLECTION_PRICE_THRESHOLD)
                ) {
                    $error = Loc::getMessage('VCCO_PRODUCT_PRICE_THRESHOLD_ERROR', array(
                        '#PRODUCT_ID#' => $productId, '#PERCENT#' => parent::VTB_COLLECTION_PRICE_THRESHOLD)
                    );
                    break;
                }

                if (!$this->checkProductQuantity($productId, $arProductInfo['QUANTITY'], $realQuantity)) {
                    $error = Loc::getMessage('VCCO_PRODUCT_QUANTITY_ERROR', array(
                        '#PRODUCT_ID#' => $productId, '#REAL_QUANTITY#' => $realQuantity, '#DESIRED_QUANTITY#' => $arProductInfo['QUANTITY'])
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

        return true;
    }
}