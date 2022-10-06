<?php

namespace OzonIntegration;

/**
 * Class OzonRequestsHelper
 * Class for performing request to Ozon
 *
 * @package OzonIntegration
 */
class OzonRequestsHelper extends OzonIntegrationBase
{
    /** @var string[] Do not notify on these errors */
    private const AR_IGNORED_ERROR_TYPES = array(
        'HAS_INCORRECT_STATUS', 'POSTING_ALREADY_CANCELLED', 'POSTING_ALREADY_SHIPPED'
    );

    /** @var string Endpoint (method) */
    private string $endPoint;
    /** @var array Request body */
    private array $requestBody;


    /**
     * OzonRequestsHelper constructor.
     *
     * @param string $endPoint
     * @param array $requestBody
     */
    public function __construct(string $endPoint, array $requestBody = array())
    {
        parent::__construct();

        $this->endPoint = $endPoint;
        $this->requestBody = $requestBody;
    }

    /**
     * Method returns request body for error logs
     *
     * @return string
     */
    public function getRequestBody(): string
    {
        return count($this->requestBody) ? json_encode($this->requestBody) : '';
    }

    /**
     * Method perform request to Ozon
     *
     * @param bool $needToLogError Log or not to log error
     *
     * @return array|bool
     */
    public function makePostRequest(bool $needToLogError = true)
    {
        $ch = curl_init();

        $requestBody = count($this->requestBody) ? json_encode($this->requestBody) : '';

        $arHeaders = array(
            'Client-Id:' . parent::OZON_CREDENTIALS['client_id'],
            'Api-Key:' . parent::OZON_CREDENTIALS['api_key'],
            'Content-Type:application/json',
            'Content-Length:' . mb_strlen($requestBody)
        );

        curl_setopt($ch, CURLOPT_URL, parent::OZON_CREDENTIALS['url'] . $this->endPoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $arHeaders);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $arResponse = json_decode($response, true);
        $jsonError = json_last_error();
        $requestInfo = curl_getinfo($ch);

        curl_close($ch);

        if (200 !== intval($requestInfo['http_code']) && $needToLogError) {
            if (!in_array($arResponse['message'], self::AR_IGNORED_ERROR_TYPES)) {
                $this->notify('http-code-not-200', 'error', var_export($requestInfo, true) .
                    "\n" . var_export($arResponse, true));
                return false;
            }
        }

        if (0 !== $jsonError && $needToLogError) {
            $this->notify('response-decode-error', 'error', $jsonError . "\n" .
                var_export($requestInfo, true));
            return false;
        }

        return $arResponse;
    }
}
