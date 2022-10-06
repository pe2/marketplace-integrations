<?php

namespace SberMegaMarketIntegration;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SystemException;
use Bitrix\Sale\Order;
use CSaleOrder;
use Exception;
use MarketplaceIntegration as MI;
use Local\Init\ServiceHandler;
use SberMegaMarketIntegration\SberMegaMarketRequestHelper as SberMMRH;

/**
 * Class SberMegaMarketCancelOrder
 * Class used for  orders cancellation
 *
 * @package SberMegaMarketIntegration
 */
class SberMegaMarketCancelOrder extends SberMegaMarketIntegrationBase
{
    use MI\IntegrationOrder {
        MI\IntegrationOrder::__construct as private __IntegrationOrderConstruct;
    }

    /** @var array Extracted Order data */
    private array $arOrderData;

    /**
     * SberMegaMarketCancelOrder constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->__IntegrationOrderConstruct();
    }

    /**
     * @param array $arOriginalOrderData
     *
     * @throws ArgumentException
     * @throws SystemException
     */
    public function handleSberMMOrderCancelRequest(array $arOriginalOrderData): void
    {
        $this->extractOrderData($arOriginalOrderData);

        if (!isset($this->arOrderData['order_id']) || 0 >= strlen($this->arOrderData['order_id'])) {
            parent::notify('order-data-extract', 'error', var_export($arOriginalOrderData, true));
            SberMMRH::makeResponse(400, array('description' => 'Order data extract error'));
        }

        $ourOrderId = 0;
        if (!$this->isOrderExist(parent::SBERMM_ORDER_ID_PROPERTY_CODE, $this->arOrderData['order_id'], $ourOrderId)) {
            parent::notify('order-does-not-exists', 'error', $this->arOrderData['order_id']);
            SberMMRH::makeResponse(406, array('description' => 'Order is not exists'));
        }

        if ($this->cancelOrder($ourOrderId)) {
            parent::notify('order-cancel-success', 'info', $this->arOrderData['order_id']);
            SberMMRH::makeResponse(200, array('description' => 'Order cancelled'), true);
        } else {
            parent::notify('order-exists-error', 'error', $this->arOrderData['order_id'] .
                '. ' . $this->arOrderData['cancel_error']);
            SberMMRH::makeResponse(500, array('description' => 'Order cancelled error. ' . $this->arOrderData['cancel_error']));
        }
    }

    /**
     * Method extracts order's data
     *
     * @param array $arOriginalOrderData
     */
    private function extractOrderData(array $arOriginalOrderData): void
    {
        $this->arOrderData['order_id'] = $arOriginalOrderData['data']['shipments'][0]['shipmentId'];
    }

    /**
     * Method cancels order
     *
     * @param int $ourOrderId
     *
     * @return bool
     */
    private function cancelOrder(int $ourOrderId): bool
    {
        try {
            CSaleOrder::CancelOrder($ourOrderId, 'Y', Loc::getMessage('SMMCO_CANCEL_REASON',
                array('#SBERMM_ID#' => $this->arOrderData['order_id']))
            );
            return true;
        } catch (Exception $e) {
            $this->arOrderData['cancel_error'] = $e->getMessage();
            return false;
        }
    }
}
