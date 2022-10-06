<?php

namespace OzonIntegration;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Request;
use Bitrix\Main\SystemException;
use Exception;
use MarketplaceIntegration as MI;
use MarketplaceIntegration\IntegrationOrder;
use OrderPropsHandler;

/**
 * Class OzonShippingOrderAfterApi
 * Class for working with shipping request after Api for orders with "Chestniy znak" marks
 */
class OzonShippingOrderAfterApi extends OzonIntegrationBase
{
    use MI\IntegrationProduct;
    use MI\ChestniyZnakPropertyHelper {
        MI\ChestniyZnakPropertyHelper::__construct as private __ChestniyZnakPropertyHelperConstruct;
    }

    /** @var string Write this constant after successful sending */
    private const SUCCESSFUL_SENDING_RESULT = 'STATUS-SENT';

    /** @var Request Request object */
    private Request $request;
    /** @var string Request body */
    private string $requestBody;

    /**
     * @param Request $request
     * @param string $requestBody
     */
    public function __construct(Request $request, string $requestBody)
    {
        parent::__construct();

        $this->__ChestniyZnakPropertyHelperConstruct($request, $requestBody);

        $this->request = $request;
        $this->requestBody = $requestBody;
    }

    /**
     * Base method to send request
     */
    public function checkAndSendRequest(): void
    {
        if (!$this->checkRequestForChestniyZnak()) {
            return;
        }

        if (!IntegrationOrder::checkOrderAffiliation(
            $this->order, parent::OZON_PAYMENT_ID, parent::OZON_DELIVERY_ID
        )) {
            return;
        }

        if (!$this->checkChestniyZnakCodesCount()) {
            return;
        }

        try {
            $oOrderPropsHandler = new OrderPropsHandler($this->order);
            $ozonOrderId = $oOrderPropsHandler->getPropDataByCode(parent::OZON_ORDER_ID_PROPERTY_CODE)['VALUE'];

            if (OzonSendShippingOrder::sendShippingRequest($this->order, $ozonOrderId, $this->prepareChestniyZnakCodes())) {
                $oOrderPropsHandler = new OrderPropsHandler($this->order);
                $oOrderPropsHandler->updatePropObjectOrder(parent::PRODUCTS_REQUIRED_CHESTNIY_ZNAK_MARK_PROPERTY_CODE,
                    json_encode(self::SUCCESSFUL_SENDING_RESULT)
                );
                $this->order->save();
            }
        } catch (ObjectPropertyException | ArgumentException | NotImplementedException | SystemException | Exception $e) {
            return;
        }
    }
}