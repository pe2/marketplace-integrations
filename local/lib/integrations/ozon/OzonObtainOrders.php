<?php

namespace OzonIntegration;

use DateTime;
use DateTimeZone;
use Exception;

/**
 * Class OzonObtainOrders
 * Class used for obtaining orders from Ozon and prepare them for inserting into our DB
 *
 * @package OzonIntegration
 */
class OzonObtainOrders extends OzonIntegrationBase
{
    /** @var string Variable to set lower frontier of interval */
    private string $backInTime = '1 hour ago';
    /** @var string Start interval in UTC timezone */
    private string $startTimeUTC = '';
    /** @var string End interval in UTC timezone */
    private string $endTimeUTC = '';


    /**
     * AliExpressObtainOrders constructor.
     *
     * @param string $backInTime Interval for strtotime() function
     *
     * @throws Exception
     */
    public function __construct(string $backInTime)
    {
        parent::__construct();

        if (0 < strlen($backInTime)) {
            $this->backInTime = $backInTime;
        }

        $oTime = new DateTime();
        $oTime->setTimeZone(new DateTimeZone('Etc/UTC'));

        $oTime->setTimestamp(strtotime($this->backInTime));
        $this->startTimeUTC = $oTime->format('Y-m-d\TH:i:s\Z');
        $oTime->setTimestamp(strtotime('now'));
        $this->endTimeUTC = $oTime->format('Y-m-d\TH:i:s\Z');
    }


    /**
     * Method returns array of orders for given interval
     *
     * @return array
     */
    public function getOrders(): array
    {
        $arOrdersResult = $this->getOrdersRequest(0);

        $arOrders = $arOrdersResult['orders'];
        $hasNext = $arOrdersResult['has_next'];
        $offset = intval($arOrdersResult['offset']);

        while ($hasNext) {
            $arOrdersLocalResult = $this->getOrdersRequest($offset);
            $arOrders = array_merge_recursive($arOrders, $arOrdersLocalResult['orders']);
            $hasNext = $arOrdersLocalResult['has_next'];
            $offset = $arOrdersLocalResult['offset'];
        }

        // Add recipient info
        /* @note There is no data about addressee at all
         * foreach ($arOrders as $index => $arOrder) {
         * $arOrders[$index]['addressee'] = $this->getDetailOrderInfo($arOrder['posting_number'])['addressee'];
         * } */

        return $arOrders;
    }


    /**
     * Method perform request to get orders for given interval
     *
     * @param int $offset
     *
     * @return array
     */
    private function getOrdersRequest(int $offset): array
    {
        $requestBody = array(
            'dir' => 'asc',
            'filter' => array(
                'since' => $this->startTimeUTC,
                'to' => $this->endTimeUTC,
                'status' => parent::ORDER_STATUS_TO_GET,
            ),
            'limit' => parent::ORDERS_PER_REQUEST,
            'offset' => $offset,
            'sort_by' => 'order_created_at',
            'with' => array(
                'analytics_data' => true,
                'barcodes' => true,
                'financial_data' => true
            )
        );

        $oOzonOrdersRequest = new OzonRequestsHelper('/v3/posting/fbs/list', $requestBody);
        $arOzonOrdersRequestResult = $oOzonOrdersRequest->makePostRequest();

        if (false !== $arOzonOrdersRequestResult) {
            return array(
                'has_next' => $arOzonOrdersRequestResult['result']['has_next'],
                'orders' => $arOzonOrdersRequestResult['result']['postings'],
                'offset' => $offset + parent::ORDERS_PER_REQUEST
            );
        } else {
            return array(
                'has_next' => false,
                'orders' => array(),
                'offset' => 0
            );
        }
    }


    /**
     * Method returns detailed order info by given order id
     * @note Unused now
     *
     * @param string $orderId
     *
     * @return mixed
     */
    private function getDetailOrderInfo($orderId)
    {
        $requestBody = array(
            'posting_number' => $orderId,
            'with' => array(
                'analytics_data' => false,
                'barcodes' => false,
                'financial_data' => false
            )
        );

        $oOzonOrderRequest = new OzonRequestsHelper('/v3/posting/fbs/get', $requestBody);
        $arOzonOrderRequestResult = $oOzonOrderRequest->makePostRequest();

        return (false !== $arOzonOrderRequestResult) ? $arOzonOrderRequestResult['result'] : array();
    }
}