<?php

namespace SberMegaMarketIntegration;

use Bitrix\Main\HttpRequest;
use Local\Init\ServiceHandler as SH;
use StdClass;

/**
 * Class SberMegaMarketRequestHelper
 * Request and auth helper class
 */
class SberMegaMarketRequestHelper extends SberMegaMarketIntegrationBase
{
    /** @var string Authorization token for requests to SberMegaMarket */
    public const AUTH_TOKEN = '11111111-0000-1111-2222-000000000000';

    /** @var string Domain for API requests */
    private const DOMAIN = 'https://partner.sbermegamarket.ru/';
    /** @var string Part of url for stocks and prices update */
    private const STOCKS_PRICES_UPDATE_API_URL = 'api/merchantIntegration/v1/offerService/';
    /** @var string[] Stocks and prices updates methods */
    private const AR_STOCKS_PRICES_METHODS = array('stock/update', 'manualPrice/save');
    /** @var string sha1 of username */
    private const SHA1_USERNAME = 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
    /** @var string sha1 of password */
    private const SHA1_PASSWORD = 'yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy';
    /** @var int Timeout for curl request */
    private const CURL_TIMEOUT = 15;


    /**
     * Method prepares data array and echoes it
     *
     * @param int $httpCode HTTP response code to set
     * @param array $data Data to send
     * @param bool $isSuccess Success or error (false by def.)
     * @param bool $die Stop working (true by def.)
     */
    public static function makeResponse(int $httpCode, array $data, bool $isSuccess = false, bool $die = true): void
    {
        header('Content-Type: application/json');

        if (0 >= strlen($httpCode) || !is_array($data)) {
            $httpCode = 500;
            $echoData = array(
                'success' => 0,
                'error' => 'Variables $httpCode or $data don\'t set'
            );
        } else {
            // Empty $data array is ok
            if (!count($data)) {
                $data = new StdClass();
            }

            $echoData = $isSuccess ? array('success' => 1, 'data' => $data) : array('success' => 0, 'error' => $data);
        }
        $echoData['meta'] = new stdClass(); // 'meta' key is always an empty object

        http_response_code($httpCode);
        echo json_encode($echoData);

        SH::writeToLog($echoData, parent::LOG_FILE_PATH, 'Outgoing request');

        if ($die) die();
    }


    /**
     * Method checks auth by matching sha1's of username and password
     *
     * @param object $request \Bitrix\Main\HttpRequest
     *
     * @return bool
     */
    public static function checkAuth(object $request): bool
    {
        if (!($request instanceof HttpRequest)) {
            return false;
        }

        $oServerParams = $request->getServer();
        $username = $oServerParams->get('PHP_AUTH_USER');
        $password = $oServerParams->get('PHP_AUTH_PW');

        if (empty($username) || empty($password)) {
            return false;
        }

        if (self::SHA1_USERNAME !== sha1($username) || self::SHA1_PASSWORD !== sha1($password)) {
            return false;
        }

        return true;
    }


    /**
     * Method returns url for request
     *
     * @param string $method
     *
     * @return string
     */
    private static function makeApiURL(string $method): string
    {
        // Stocks update, prices update
        if (in_array($method, self::AR_STOCKS_PRICES_METHODS)) {
            return self::DOMAIN . self::STOCKS_PRICES_UPDATE_API_URL . $method;
        }

        // Other methods
        $type = ('print' === $method) ? '/sticker/' : '/order/';
        return self::DOMAIN . _SBER_MEGA_MARKET_API_PREFIX . $type . $method;
    }


    /**
     * Method performs request to SberMegaMarket
     *
     * @param string $method Method for perform request
     * @param array $data Request body
     * @param array $arResponse Array with response (by ref.)
     * @param bool $retryMode Is method called in cycle? True - cycle, false - not cycle or last call
     */
    public static function makeRequest(string $method, array $data, array &$arResponse = array(), bool $retryMode = false): void
    {
        $jsonData = json_encode($data);

        if (0 >= strlen($jsonData)) {
            exit();
        }

        $ch = curl_init();

        $arHeaders = array(
            'authorization: Token ' . self::AUTH_TOKEN,
            'content-Type: application/json'
        );

        curl_setopt($ch, CURLOPT_URL, self::makeApiURL($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CURL_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::CURL_TIMEOUT);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $arHeaders);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $arResponse = json_decode($response, true);
        $jsonError = json_last_error();
        $requestInfo = curl_getinfo($ch);

        curl_close($ch);

        $result = (1 === intval($arResponse['success'])) ? 'ok' : 'error';
        if (in_array($method, self::AR_STOCKS_PRICES_METHODS)) {
            SH::writeToLog($jsonData . '; result: ' . $result . '; retryMode: ' . ($retryMode ? 'Y' : 'N'),
                parent::FILE_LOG_PATH_SBERMM_DATA_UPDATE, 'short_version');
        } else {
            SH::writeToLog($jsonData . '; result: ' . $result . '; retryMode: ' . ($retryMode ? 'Y' : 'N'),
                parent::LOG_FILE_PATH, 'Outgoing request and result for method: ' . $method);
        }

        if (!$retryMode) {
            $oNotifyObject = new SberMegaMarketIntegrationBase();
            if (200 !== intval($requestInfo['http_code'])) {
                $oNotifyObject->notify('http-code-not-200', 'error', 'Method: ' . $method . "\n" .
                    'Response: ' . var_export($arResponse, true) . "\n" . var_export($requestInfo, true));
            } elseif (0 !== $jsonError) {
                $oNotifyObject->notify('response-decode-error', 'error', 'JSON error: #' .
                    $jsonError . "\n" . 'Method: ' . $method . "\n" . 'Response: ' . var_export($arResponse, true));
            } elseif (1 !== $arResponse['success']) {
                $oNotifyObject->notify('request-error', 'error', 'Method: ' . $method . "\n" .
                    'Response: ' . var_export($arResponse, true));
            }
        }
    }
}