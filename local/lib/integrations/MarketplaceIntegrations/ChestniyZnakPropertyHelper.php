<?php

namespace MarketplaceIntegration;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Request;
use Bitrix\Main\SystemException;
use Bitrix\Sale\Order;
use Exception;
use Local\API\Methods\Orders\SetOrderProperties;

/**
 * Trait ChestniyZnakPropertyHelper
 * This trait used for working with "Chestniy Znak" marks property
 *
 * @package MarketplaceIntegration
 */
trait ChestniyZnakPropertyHelper
{
    use IntegrationProduct;

    /** @var string API class with method for changing order props (pseudo-constant) */
    private string $API_METHOD_CLASS = 'Local\API\Methods\Orders\SetOrderProperties';

    /** @var string "Chestniy znak" marks for the order (pseudo-constant) */
    public string $CHESTNIY_ZNAK_PROPERTY_NAME = 'CHESTNIY_ZNAK_CODES';

    /** @var Request Request object */
    private Request $request;
    /** @var string Request body */
    private string $requestBody;
    /** @var string Bitrix order ID */
    private string $orderId;
    /** @var Order Loaded order */
    private Order $order;
    /** @var array Chestniy znak codes */
    private array $arCodes;

    /**
     * ChestniyZnakPropertyHelper trait constructor
     *
     * @param Request $request
     * @param string $requestBody
     */
    public function __construct(Request $request, string $requestBody)
    {
        $this->request = $request;
        $this->requestBody = $requestBody;
    }


    /**
     * Method validates request and request data
     *
     * @return bool
     */
    public function checkRequestForChestniyZnak(): bool
    {
        /** @noinspection PhpUndefinedFieldInspection */
        if ($this->API_METHOD_CLASS !== $this->request->custom->APIClassName) {
            return false;
        }

        $arRequestData = json_decode($this->requestBody, true);

        $oSetOrderProps = new SetOrderProperties($this->request, $this->requestBody);
        $orderId = $oSetOrderProps->normalizeOrderNumber(array_key_first($arRequestData));
        $arOrderProps = $oSetOrderProps->normalizeOrderProperties(reset($arRequestData));
        if (!isset($arOrderProps[$this->CHESTNIY_ZNAK_PROPERTY_NAME])) {
            return false;
        }

        $arCodes = json_decode($arOrderProps[$this->CHESTNIY_ZNAK_PROPERTY_NAME], true);
        if (!is_array($arCodes) || !count($arCodes)) {
            return false;
        }

        try {
            $this->order = Order::load($orderId);
        } catch (ArgumentNullException $e) {
            return false;
        }

        $this->orderId = $orderId;
        $this->arCodes = $arCodes;

        return true;
    }

    /**
     * Method checks equality of given and required codes
     *
     * @return bool
     */
    public function checkChestniyZnakCodesCount(): bool
    {
        try {
            $oOrderPropsHandler = new \OrderPropsHandler($this->order);
            $arRequiredCodes = json_decode($oOrderPropsHandler->getPropDataByCode(
                \IMarketplaceIntegration::PRODUCTS_REQUIRED_CHESTNIY_ZNAK_MARK_PROPERTY_CODE)['VALUE'], true);
            if (!is_array($arRequiredCodes) || !count($arRequiredCodes)) {
                return false;
            }

            $uniqueProductIds = array();
            foreach ($this->arCodes as $arCode) {
                $uniqueProductIds[] = key($arCode);
            }

            return count($arRequiredCodes) === count(array_unique($uniqueProductIds));
        } catch (ObjectPropertyException | ArgumentException | NotImplementedException | SystemException | Exception $e) {
            return false;
        }
    }

    /**
     * Method returns array of bitrix ids and correspondent "Chestniy znak" codes by given 1C codes
     *
     * @return array
     */
    public function prepareChestniyZnakCodes(): array
    {
        $ar1cIds = array();
        foreach ($this->arCodes as $arCode) {
            $ar1cIds[key($arCode)][] = $arCode[key($arCode)];
        }
        if (!count(array_keys($ar1cIds))) {
            return [];
        }

        // Method from MI\IntegrationProduct
        $arBitrixIds = $this->getBitrixIdsBy1cCodes(array_keys($ar1cIds));

        $arBitrixChestniyZnak = array();
        foreach ($ar1cIds as $id1c => $arChestniyZnakCodes) {
            $arBitrixChestniyZnak[$arBitrixIds[$id1c]] = $arChestniyZnakCodes;
        }

        return $arBitrixChestniyZnak;
    }
}