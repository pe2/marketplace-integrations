<?php

namespace SberMegaMarketIntegration;

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/lib/classes/dompdf/autoload.inc.php';

use Bitrix\Main\Mail\Event;
use Bitrix\Sale\Order;
use CFile;
use Dompdf\Dompdf;
use Exception;
use OrderPropsHandler;
use SberMegaMarketIntegration\SberMegaMarketRequestHelper as SberMMRH;

class SberMegaMarketPackingOrder extends SberMegaMarketIntegrationBase
{
    /** @var array Order data (request body)
     * {
     *  "data": {
     *      "order": "123456",                                      // site order id
     *      "orders": [ "Х123", "Х456" ],                           // 1C order numbers,
     *      "cargo-places": [ { "126291": 1 }, { "123528": 2 } ],   // Product ids and its cargo places
     *      "disable-packing-request": true,                        // Do not send packing request (optional)
     *      "disable-warehouse-email": true                         // Do not send email (optional)
     *  }
     */
    private $arOrderData;

    /** @var string SberMegaMarket shipment id */
    private $smmShipmentId;

    /** @var array Array with Box codes */
    private $arBoxCodes;

    /** @var array Array with items for sticker print request */
    private $printRequestBody;


    /**
     * SberMegaMarketPackingOrder constructor
     *
     * @param array $arOrderData
     */
    public function __construct(array $arOrderData)
    {
        parent::__construct();

        $this->arOrderData = $arOrderData;
    }


    /**
     * Method prepares and perform packing request
     */
    public function handleSberMMOrderPackingRequest(): void
    {
        if (!$this->checkArOrderDataStructure()) {
            SberMMRH::makeResponse('400', array('description' => 'Error in request body structure'));
            return;
        }

        $this->getSmmShipmentId();
        if (0 >= strlen($this->smmShipmentId)) {
            SberMMRH::makeResponse('400', array('description' => 'Error while getting SMM shipment id'));
            return;
        }

        $arPackingItems = $this->getSmmPackingRequestBody();
        if (!count($arPackingItems)) {
            SberMMRH::makeResponse('400', array('description' => 'Error while getting packing request body'));
            return;
        }

        if (!isset($this->arOrderData['data']['disable-packing-request'])) {
            $oPackingRequest = new SberMegaMarketRequests($this->arOrderData);
            $oPackingRequest->makePackingRequest($this->smmShipmentId, $arPackingItems);
        }

        SberMMRH::makeResponse('200', array('description' => 'Packing request send successfully'), true, false);

        if (!isset($this->arOrderData['data']['disable-warehouse-email'])) {
            $this->sendLetterToWarehouse();
        }

        $this->saveArBoxCodesForOrder();
    }


    /**
     * Method checks mandatory fields in request body array
     *
     * @return bool
     */
    private function checkArOrderDataStructure(): bool
    {
        $arToCheck = $this->arOrderData['data'];

        return isset($arToCheck['htm-order']) && 0 < strlen($arToCheck['htm-order']) &&
            isset($arToCheck['orders']) && count($arToCheck['orders']) &&
            isset($arToCheck['cargo-places']) && count($arToCheck['cargo-places']);
    }


    /**
     * Method returns SMM shipment id for desired order
     */
    private function getSmmShipmentId(): void
    {
        $smmShipmentId = '';
        try {
            // remove 'H' symbol
            $this->arOrderData['data']['htm-order'] = substr($this->arOrderData['data']['htm-order'], 1);
            $oOrderProps = new OrderPropsHandler(Order::load($this->arOrderData['data']['htm-order']));
            $arSmmShipmentId = $oOrderProps->getPropDataByCode(parent::SBERMM_ORDER_ID_PROPERTY_CODE);
            $smmShipmentId = $arSmmShipmentId['VALUE'] ?? '';
            if (0 >= strlen($smmShipmentId)) {
                throw new Exception('Failed to get SberMegaMarket external order id.');
            }
        } catch (Exception $e) {
            parent::notify('order-number-extract', 'error', $e->getMessage() .
                ' Our order id: ' . $this->arOrderData['data']['htm-order']);
        }

        $this->smmShipmentId = $smmShipmentId;
    }


    /**
     * Method prepares items key for packing and sticker/print requests
     */
    private function getSmmPackingRequestBody(): array
    {
        $arPackingItems = array();

        $arSmmProductIndexes = $this->getSmmOrderProductIndexes();
        if (!count($arSmmProductIndexes)) {
            return $arPackingItems;
        }

        // This code 'glue' product indexes in order and cargo places ('1' => 1, '2' => 1, '3' => 2, ...)
        $arLotIndexCargoIndex = $arBoxCodes = array();
        foreach ($arSmmProductIndexes as $index => $productId) {
            foreach ($this->arOrderData['data']['cargo-places'] as $cargoPlaceIndex => $cargoPlace) {
                if (strval($productId) === strval(key($cargoPlace))) {
                    $arLotIndexCargoIndex[$index] = $cargoPlace[key($cargoPlace)];
                    unset($this->arOrderData['data']['cargo-places'][$cargoPlaceIndex]);
                    continue 2;
                }
            }
        }

        foreach ($arLotIndexCargoIndex as $lotIndex => $cargoIndex) {
            $boxCode = parent::SBERMM_MERCHANT_CODE . '*' . $this->arOrderData['data']['htm-order'] . '*' . $cargoIndex;
            $arBoxCodes[] = $boxCode;
            $arPackingItems[] = array(
                'itemIndex' => $lotIndex,
                'boxes' => array(
                    array(
                        'boxIndex' => $cargoIndex,
                        'boxCode' => $boxCode
                    )
                ),
                'digitalMark' => ''
            );

            $this->printRequestBody[] = array(
                'itemIndex' => $lotIndex,
                'quantity' => 1,    // always 1
                'boxes' => array(
                    array(
                        'boxIndex' => $cargoIndex,
                        'boxCode' => $boxCode
                    )
                ),
            );
        }

        $this->arBoxCodes = array_values(array_unique($arBoxCodes));

        return $arPackingItems;
    }


    /**
     * Method returns SMM order products ids and indexes
     *
     * @return array
     */
    private function getSmmOrderProductIndexes(): array
    {
        $arSmmProductIndexes = array();
        try {
            $oOrderProps = new OrderPropsHandler(Order::load($this->arOrderData['data']['htm-order']));
            $arSmmProductIndexesResult = $oOrderProps->getPropDataByCode(parent::SBERMM_ORDER_PRODUCT_INDEXES_PROPERTY_CODE);
            $strSmmProductIndexes = $arSmmProductIndexesResult['VALUE'] ?? '';
            if (0 >= strlen($strSmmProductIndexes)) {
                throw new Exception('Failed to get SberMegaMarket products indexes.');
            }
            $arSmmProductIndexes = json_decode($strSmmProductIndexes, true);
        } catch (Exception $e) {
            parent::notify('order-indexes-extract', 'error', $e->getMessage() .
                ' Our order id: ' . $this->arOrderData['data']['htm-order']);
        }

        return $arSmmProductIndexes;
    }


    /**
     * This method obtains stickers and send letter to warehouse
     */
    private function sendLetterToWarehouse(): void
    {
        $arFilesId = $this->getOrderStickers();
        if (!count($arFilesId)) {
            parent::notify('all-sticker-print-error', 'error',
                'All sticker print error. Request: ' . var_export($this->arOrderData, true));
            return;
        }

        Event::send(array(
            "EVENT_NAME" => parent::SBERMM_WAREHOUSE_EMAIL_EVENT_NAME,
            "LID" => "s1",
            "C_FIELDS" => array(
                "1C_ORDERS" => implode(', ', $this->arOrderData['data']['orders']),
            ),
            "FILE" => $arFilesId
        ));
    }


    /**
     * This method performs request to get stickers, saves them and returns array of file ids
     *
     * @return array Array of pdf file ids with stickers
     */
    private function getOrderStickers(): array
    {
        try {
            $oStickerPrintRequest = new SberMegaMarketRequests($this->arOrderData);
            $arResponse = $oStickerPrintRequest->makeStickerPrintRequest($this->smmShipmentId, $this->arBoxCodes,
                $this->printRequestBody);
            if (!is_array($arResponse) || 1 !== $arResponse['success'] || 0 >= strlen($arResponse['data'])) {
                throw new Exception("Error with arResponse while getting stickers. arResponse:\n" .
                    var_export($arResponse));
            }
        } catch (Exception $e) {
            parent::notify('sticker-print-error', 'error', $e->getMessage());
            return array();
        }

        return $this->getPdfFileIds($arResponse['data']);
    }


    /**
     * This method obtains data from request, makes pdf file and saves them to db
     *
     * @param string $dataString String with sticker html-markup
     *
     * @return array Array of file ids
     */
    private function getPdfFileIds(string $dataString): array
    {
        $arFilesIds = array();

        $counter = 0;
        // Temp file
        $filename = $_SERVER['DOCUMENT_ROOT'] . '/upload/' . $this->arOrderData['data']['htm-order'] . '-' . $counter . '.pdf';
        file_put_contents($filename, self::generatePdfFile($dataString));         // Save tmp file
        $arFile = CFile::MakeFileArray($filename, 'application/pdf');  // Create file info array
        $fileId = CFile::SaveFile($arFile, '/smm-stickers/');         // Save in bitrix DB

        if (0 >= $fileId) {
            parent::notify('one-sticker-print-error', 'error',
                'One sticker print error. $dataString ' . $dataString);
        } else {
            $arFilesIds[] = $fileId;
            unlink($filename);
        }

        return $arFilesIds;
    }


    /**
     * Method returns content of generated pdf file
     *
     * @param string $dataString String with sticker html-markup
     *
     * @return string Generated pdf file
     */
    private static function generatePdfFile(string $dataString): string
    {
        return self::generatePdfFileViaMkHtmlToPdf($dataString);
    }


    /**
     * Method convert html-markup to pdf file via dompdf
     * Method unused due to markup errors in generated pdf file
     *
     * @param string $dataString String with sticker html-markup
     *
     * @return string String with sticker pdf file
     */
    private static function generatePdfFileViaDomPdf(string $dataString): string
    {
        // Barcode scale fix
        $dataString = str_replace('max-width: 100%', 'max-width: 308px', $dataString);
        // Append common font family style (cyrillic font fix)
        $dataString = substr_replace($dataString, '* {font-family: dejavu sans;}',
            strpos($dataString, '</style>') - 1, 0);

        $oDomPdf = new Dompdf();
        $oDomPdf->loadHtml($dataString);
        $oDomPdf->render();
        return $oDomPdf->output();
    }


    /**
     * Method convert html-markup to pdf file via wkhtmltopdf utility
     *
     * @param string $dataString String with sticker html-markup
     *
     * @return string String with sticker pdf file
     */
    private static function generatePdfFileViaMkHtmlToPdf(string $dataString): string
    {
        $wkHtmlToPdfOSPath = '/usr/local/bin/wkhtmltopdf';

        $fileName = $_SERVER['DOCUMENT_ROOT'] . '/upload/' . md5(time()) . '.html';
        file_put_contents($fileName, $dataString);

        $pdfFileString = shell_exec($wkHtmlToPdfOSPath . ' ' . $fileName . ' -');
        unlink($fileName);

        return $pdfFileString;
    }


    /**
     * Method stores arBoxCodes array in order prop for shipping request
     */
    private function saveArBoxCodesForOrder(): void
    {
        try {
            $oOrder = Order::load($this->arOrderData['data']['htm-order']);
            $oOrderProps = new OrderPropsHandler($oOrder);
            $oOrderProps->updatePropObjectOrder(parent::SBERMM_ORDER_BOX_CODES_PROPERTY_CODE,
                json_encode($this->arBoxCodes));
            $oOrder->save();
        } catch (Exception $e) {
            parent::notify('box-codes-error', 'error',
                'Error while setting box codes order prop. Description: ' . $e->getMessage());
        }
    }
}
