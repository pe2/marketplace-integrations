<?php

namespace SberMegaMarketIntegration;

use DateTime;
use MarketplaceIntegration as MI;
use SberMegaMarketIntegration\SberMegaMarketRequestHelper as SberMMRH;
use stdClass;

/**
 * Class SberMegaMarketRequests
 * Class used for making requests to SberMegaMarket
 */
class SberMegaMarketRequests extends SberMegaMarketIntegrationBase
{
    use MI\IntegrationOrder {
        MI\IntegrationOrder::__construct as private __IntegrationOrderConstruct;
    }

    /** @var int Retry attempts on reject errors */
    private const REJECT_ATTEMPT_COUNT = 5;
    /** @var int Retry attempts on confirm errors */
    private const CONFIRM_ATTEMPT_COUNT = 5;
    /** @var int Retry attempts on stocks update errors */
    private const STOCKS_UPDATE_ATTEMPT_COUNT = 30;
    /** @var int Retry attempts on prices update errors */
    private const PRICES_UPDATE_ATTEMPT_COUNT = 20;
    /** @var int Retry timeout in seconds on errors */
    private const RETRY_TIMEOUT_INTERVAL = array('min' => 1, 'max' => 4);

    /** @var array Array with order's data */
    private array $arOrderData;


    /**
     * SberMegaMarketRequests constructor
     *
     * @param array $arOrderData
     */
    public function __construct(array $arOrderData)
    {
        parent::__construct();

        $this->__IntegrationOrderConstruct();

        $this->arOrderData = $arOrderData;
    }


    /**
     * Method prepares data and performs reject request and returns $arOrderData['basket'] without rejected products
     *
     * @param array $arRejected Array with rejected product ids
     */
    public function makeRejectRequest(array $arRejected): void
    {
        $lastReason = SberMegaMarketCheckOrder::AR_REJECT_REASONS['stock_error'];
        $arRejectedItems = array();
		/*
        foreach ($arRejected as $index => $rejectedInfo) {
            $arRejectedItems[] = array(
                'itemIndex' => ($index + 1),
                'offerId' => $this->arOrderData['id_indexes'][$index + 1]
            );
            $lastReason = $rejectedInfo['reason'];
		} */

		foreach ($this->arOrderData['id_indexes'] as $index => $offerId) {
            if (in_array($offerId, array_keys($arRejected))) {
                $arRejectedItems[] = array(
                    'itemIndex' => $index,
                    'offerId' => $offerId
                );
            }
			$lastReason = $arRejected[$offerId];
        }

        for ($attempt = 1, $arResponse = []; $attempt <= self::REJECT_ATTEMPT_COUNT; $attempt++, $arResponse = []) {
            SberMMRH::makeRequest(
                'reject',
                array(
                    'data' => array(
                        'shipments' => array(
                            array(
                                'shipmentId' => strval($this->arOrderData['order_id']),
                                'items' => $arRejectedItems
                            )
                        ),
                        'reason' => array(
                            'type' => $lastReason
                        ),
                        'token' => SberMMRH::AUTH_TOKEN
                    ),
                    'meta' => new stdClass()
                ),
                $arResponse,
                $attempt !== self::REJECT_ATTEMPT_COUNT
            );

            if (isset($arResponse['success']) && (1 === $arResponse['success'])) {
                break;
            }

            sleep(rand(self::RETRY_TIMEOUT_INTERVAL['min'], self::RETRY_TIMEOUT_INTERVAL['max']));
        }
    }


    /**
     * Method prepares data and performs confirm request
     *
     * @param array $arConfirmed Array with confirmed product ids
     * @param int $orderNumber Freshly saved order number
     */
    public function makeConfirmRequest(array $arConfirmed, int $orderNumber): void
    {
        $arConfirmedItems = array();

		/*foreach ($arConfirmed as $index) {
            $arConfirmedItems[] = array(
                'itemIndex' => ($index + 1),
                'offerId' => $this->arOrderData['id_indexes'][$index + 1]
            );
		}*/
		foreach ($this->arOrderData['id_indexes'] as $index => $offerId) {
            if (in_array($offerId, $arConfirmed)) {
                $arConfirmedItems[] = array(
                    'itemIndex' => $index,
                    'offerId' => $offerId
                );
            }
        }

        for ($attempt = 1, $arResponse = []; $attempt <= self::CONFIRM_ATTEMPT_COUNT; $attempt++, $arResponse = []) {
            SberMMRH::makeRequest(
                'confirm',
                array(
                    'data' => array(
                        'shipments' => array(
                            array(
                                'shipmentId' => strval($this->arOrderData['order_id']),
                                'orderCode' => $orderNumber,
                                'items' => $arConfirmedItems
                            )
                        ),
                        'token' => SberMMRH::AUTH_TOKEN
                    ),
                    'meta' => new stdClass()
                ),
                $arResponse,
                $attempt !== self::CONFIRM_ATTEMPT_COUNT
            );

            if (isset($arResponse['success']) && (1 === $arResponse['success'])) {
                break;
            }

            sleep(rand(self::RETRY_TIMEOUT_INTERVAL['min'], self::RETRY_TIMEOUT_INTERVAL['max']));
        }
    }


    /**
     * Method performs packing request
     *
     * @param string $smmShipmentId SberMegaMarket shipment id
     * @param array $arPackingItems Array of items
     */
    public function makePackingRequest(string $smmShipmentId, array $arPackingItems): void
    {
        SberMMRH::makeRequest(
            'packing',
            array(
                'data' => array(
                    'shipments' => array(
                        array(
                            'shipmentId' => $smmShipmentId,
                            'orderCode' => $this->arOrderData['data']['htm-order'],
                            'items' => $arPackingItems
                        )
                    ),
                    'token' => SberMMRH::AUTH_TOKEN
                ),
                'meta' => array()
            )
        );
    }


    /**
     * Method returns array of stickers data as strings
     *
     * @param string $smmShipmentId SberMegaMarket shipment id
     * @param array $arBoxCodes Array of box codes
     * @param array $arItems Array of items
     *
     * @return array Response array with stickers data as strings
     */
    public function makeStickerPrintRequest(string $smmShipmentId, array $arBoxCodes, array $arItems): array
    {
        $arResponse = array();

        SberMMRH::makeRequest(
            'print',
            array(
                'data' => array(
                    'shipments' => array(
                        array(
                            'shipmentId' => $smmShipmentId,
                            'boxCodes' => $arBoxCodes,
                            'items' => $arItems
                        )
                    ),
                    'token' => SberMMRH::AUTH_TOKEN
                ),
                'meta' => array()
            ),
            $arResponse
        );

        return $arResponse;
    }


    /**
     * Method performs shipping request
     *
     * @param string $smmShipmentId SberMegaMarket shipment id
     * @param array $arBoxCodes Order box codes
     */
    public function makeShippingRequest(string $smmShipmentId, array $arBoxCodes): void
    {
        $arBoxes = array();
        $counter = 1;
        foreach ($arBoxCodes as $boxCode) {
            $arBoxes[] = array(
                "boxIndex" => $counter++,
                "boxCode" => $boxCode
            );
        }

        $oShippingDate = new DateTime();

        SberMMRH::makeRequest(
            'shipping',
            array(
                'data' => array(
                    'shipments' => array(
                        array(
                            'shipmentId' => $smmShipmentId,
                            'boxes' => $arBoxes,
                            'shipping' => array(
                                'shippingDate' => $oShippingDate->format('Y-m-d\TH:i:s')
                            )
                        )
                    ),
                    'token' => SberMMRH::AUTH_TOKEN
                ),
                'meta' => array()
            )
        );
    }
}