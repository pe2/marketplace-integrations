<?php

// This script receives xml request with order's data

$_SERVER["DOCUMENT_ROOT"] = '/home/bitrix/ext_www/domain.tld';

require_once $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";

header('Content-type: text/xml');

$xmlData = file_get_contents('php://input');

// Base Vtb Collection integration object
$oVtbCollection = new \VtbCollectionIntegration\VtbCollectionIntegrationBase();

// Process request
try {
    echo $oVtbCollection->handleRequest($xmlData);
} catch (
\Bitrix\Main\ArgumentNullException | \Bitrix\Main\ArgumentOutOfRangeException |
\Bitrix\Main\ObjectPropertyException | \Bitrix\Main\DB\Exception | \Bitrix\Main\LoaderException |
\Bitrix\Main\NotImplementedException | \Bitrix\Main\ObjectNotFoundException |
\Bitrix\Main\ArgumentException | \Bitrix\Main\SystemException $e
) {
    $oVtbCollection->notify('run-handle-request', 'error', $e->getMessage());
}