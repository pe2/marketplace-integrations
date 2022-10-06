<?php

namespace SberMegaMarketIntegration;

use Bitrix\Main\Localization\Loc;
use MarketplaceIntegration as MI;

/**
 * Class SberMegaMarketCheckOrder
 * Class used for checking products in order
 *
 * @package SberMegaMarketIntegration
 */
class SberMegaMarketCheckOrder extends SberMegaMarketIntegrationBase
{
    use MI\IntegrationProduct {
        MI\IntegrationProduct::__construct as private __IntegrationProductsConstruct;
    }

    /** @var string[] Array with product reject reasons */
    public const AR_REJECT_REASONS = array(
        'stock_error' => 'OUT_OF_STOCK',
        'price_error' => 'INCORRECT_PRICE',
        'offer_error' => 'INCORRECT_PRODUCT',
        'chars_error' => 'INCORRECT_SPEC',
        'double_error' => 'TWICE_ORDER',
        'time_error' => 'NOT_TIME_FOR_SHIPPING',
        'fraud_error' => 'FRAUD_ORDER'
    );

    /** @var array Array with basket items */
    private array $arBasket;


    /**
     * SberMegaMarketCheckOrder constructor
     *
     * @param array $arBasket
     */
    public function __construct(array $arBasket)
    {
        parent::__construct();

        $this->arBasket = $arBasket;

        $this->__IntegrationProductsConstruct();
    }

    /**
     * Method checks order and returns array with confirmed product ids, rejected product ids and error string
     *
     * @return array ['confirmed' => array(), 'rejected' => array('id' => '', 'reason' => ''), 'errorString' => '']
     */
    public function checkOrder(): array
    {
        $arConfirmed = $arRejected = array();
        $errorString = '';

        foreach ($this->arBasket as $arProductInfo) {
            $error = $reason = '';
            $productId = $arProductInfo['ID'];
            do {
                if (!$this->checkProductExistence($productId)) {
                    $error = Loc::getMessage('AEIO_PRODUCT_NOT_IN_DB_ERROR', array('#PRODUCT_ID#' => $productId));
                    $reason = self::AR_REJECT_REASONS['stock_error'];
                    break;
                }

                if (!$this->checkProductActive($productId)) {
                    $error = Loc::getMessage('AEIO_PRODUCT_NOT_ACTIVE_ERROR', array('#PRODUCT_ID#' => $productId));
                    $reason = self::AR_REJECT_REASONS['stock_error'];
                    break;
                }

                if (!$this->checkProductPrice($productId)) {
                    $error = Loc::getMessage('AEIO_PRODUCT_PRICE_ERROR', array('#PRODUCT_ID#' => $productId));
                    $reason = self::AR_REJECT_REASONS['price_error'];
                    break;
                }

                if (!$this->checkProductQuantity($productId, $arProductInfo['QUANTITY'], $realQuantity)) {
                    $error = Loc::getMessage('AEIO_PRODUCT_QUANTITY_ERROR', array(
                            '#PRODUCT_ID#' => $productId, '#REAL_QUANTITY#' => $realQuantity, '#DESIRED_QUANTITY#' => $arProductInfo['QUANTITY'])
                    );
                    $reason = self::AR_REJECT_REASONS['stock_error'];
                    break;
                }
            } while (false);

            if (mb_strlen($error)) {
				//$arRejected[$index] = array('reason' => $reason);
				$arRejected[$productId] = array('reason' => $reason);
            } else {
				// $arConfirmed[] = $index;
				$arConfirmed[] = $productId;
            }

            // Append error description to errors string
            $errorString .= !mb_strlen($errorString) ? $error : ' ' . $error;
        }

        return array($arConfirmed, $arRejected, $errorString);
    }
}