<?php

namespace VtbCollectionIntegration;

use Bitrix\Main\Localization\Loc;
use Helptomama\settings\HelptomamaSettingsOptions;

class VtbCollectionCheckDelivery extends VtbCollectionIntegrationBase
{

    private const VTB_COLLECTION_DEFAULT_DELIVERY_ZIP = '190000';
    private const VTB_COLLECTION_DEFAULT_DELIVERY_LOCATION = 'г. Санкт-Петербург';
    private const VTB_COLLECTION_DEFAULT_DELIVERY_COURIER_GROUP_NAME = 'Курьерская доставка';
    private const VTB_COLLECTION_DEFAULT_DELIVERY_COURIER_NAME = 'Курьерская доставка';
    private const VTB_COLLECTION_DEFAULT_DELIVERY_COURIER_DESCRIPTION = 'Срок доставки 1 - 2 рабочих дня';
    private const VTB_COLLECTION_DEFAULT_DELIVERY_DELIVERY_COST = 500;

    /** @var \CDataXMLNode */
    private $node;

    /** @var array Helptomama settings from module */
    private $arHtmSettings;


    /**
     * VtbCollectionCheckDelivery constructor.
     *
     * @param \CDataXMLNode $node
     *
     * @throws \Bitrix\Main\DB\Exception
     * @throws \Bitrix\Main\LoaderException
     */
    public function __construct($node)
    {
        parent::__construct();

        $this->node = $node;
    }


    /**
     * Method checks is given KladrCode or PostCode of a city suitable for delivery
     *
     * @param array $arDeliveryData Array with delivery data
     * @param string $errorStr String with errors
     *
     * @return bool
     */
    public static function checkDeliveryRegion($arDeliveryData, &$errorStr)
    {
        if (!is_array($arDeliveryData) || !count($arDeliveryData)) {
            return false;
        }

        $isError = false;
        if (isset($arDeliveryData['KLADRCODE']) && 0 < strlen($arDeliveryData['KLADRCODE'])) {
            $dbDeliveryResult = \CIBlockElement::GetList(
                array(),
                array(
                    'IBLOCK_ID' => parent::VTB_COLLECTION_DELIVERIES_IBLOCK_ID,
                    '=PROPERTY_deliveryCladrCode' => $arDeliveryData['KLADRCODE']
                ),
                false,
                false,
                array()
            );
            $arRegionResult = $dbDeliveryResult->Fetch();

            $isError = (is_array($arRegionResult) && count($arRegionResult)) ? false : true;
        }

        if (isset($arDeliveryData['POSTCODE']) && 0 < strlen($arDeliveryData['POSTCODE'])) {
            $dbDeliveryResult = \CIBlockElement::GetList(
                array(),
                array(
                    'IBLOCK_ID' => parent::VTB_COLLECTION_DELIVERIES_IBLOCK_ID,
                    '<=PROPERTY_deliveryRangeStart' => $arDeliveryData['POSTCODE'],
                    '>=PROPERTY_deliveryRangeEnd' => $arDeliveryData['POSTCODE']
                ),
                false,
                false,
                array()
            );
            $arRegionResult = $dbDeliveryResult->Fetch();

            $isError = (is_array($arRegionResult) && count($arRegionResult)) ? false : true;
        }

        if ($isError) {
            $errorStr = Loc::getMessage('VCCO_UNSUITABLE_DELIVERY_REGION_ERROR_REASON');
            return false;
        } else {
            $errorStr = null;
            return true;
        }
    }


    /**
     * Method checks delivery and returns delivery price result
     *
     * @return mixed
     */
    public function checkDelivery()
    {
        $arOrderFields = $this->extractOrderData();

        $xmlOutput = new \SimpleXMLElement("<?xml version=\"1.0\" encoding=\"utf-8\" ?><GetDeliveryVariantsResult " .
            "xmlns=\"http://tempuri.org/XMLSchema.xsd\" />");

        $postCode = (!empty($arOrderFields['location']['POSTCODE'])) ?
            $arOrderFields['location']['POSTCODE'] : self::VTB_COLLECTION_DEFAULT_DELIVERY_ZIP;

        self::checkDeliveryRegion($arOrderFields['location'], $errorStr);

        if (!isset($errorStr) && 0 >= mb_strlen($errorStr)) {
            $itemsCost = 0;
            foreach ($arOrderFields['items'] as $item) {
                $itemsCost += round(intval($item['Price']) * intval($item['Amount']), 0);
            }

            $deliveryCost = self::VTB_COLLECTION_DEFAULT_DELIVERY_DELIVERY_COST;

            $totalCost = $itemsCost + $deliveryCost;

            // Result code: 0 - ok, 1 - some errors
            $xmlOutput->addChild('ResultCode', 0);

            // Location
            $location = $xmlOutput->addChild('Location');
            $location->addChild('LocationName', self::VTB_COLLECTION_DEFAULT_DELIVERY_LOCATION);
            $location->addChild('PostCode', $postCode);

            // Deliveries
            $deliveries = $xmlOutput->addChild('DeliveryGroups');
            $delivery = $deliveries->addChild('DeliveryGroup');
            $delivery->addChild('GroupName', self::VTB_COLLECTION_DEFAULT_DELIVERY_COURIER_GROUP_NAME);
            $variants = $delivery->addChild('DeliveryVariants');
            $variant = $variants->addChild('DeliveryVariant');

            $variant->addChild('DeliveryVariantName', self::VTB_COLLECTION_DEFAULT_DELIVERY_COURIER_NAME);
            $variant->addChild('ExternalDeliveryVariantId', parent::VTB_COLLECTION_DELIVERY_ID);
            $variant->addChild('Description', self::VTB_COLLECTION_DEFAULT_DELIVERY_COURIER_DESCRIPTION);
            $variant->addChild('ItemsCost', $itemsCost);
            $variant->addChild('DeliveryCost', $deliveryCost);
            $variant->addChild('TotalCost', $totalCost);
        } else {
            $itemsCost = $deliveryCost = $totalCost = 0;

            $xmlOutput->addChild('ResultCode', 1);
            $xmlOutput->addChild('Reason', Loc::getMessage('VCCO_UNSUITABLE_DELIVERY_REGION_ERROR_REASON'));
        }

        return $xmlOutput->asXML();
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
                case 'Location':
                    foreach ($children->children() as $locItem) {
                        $arOrderFields['location'][strtoupper($locItem->name())] = $locItem->textContent();
                    }
                    break;

                case 'Items':
                    $counter = 0;
                    foreach ($children->children() as $itemsItem) {
                        foreach ($itemsItem->children() as $item) {
                            $arOrderFields['items'][$counter][$item->name()] = $item->textContent();
                        }
                        $counter++;
                    }
                    break;
            }
        }

        return $arOrderFields;
    }
}