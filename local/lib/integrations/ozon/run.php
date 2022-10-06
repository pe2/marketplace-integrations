<?php

// This script executes by cron

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use OzonIntegration\OzonIntegrationBase;

$_SERVER["DOCUMENT_ROOT"] = '/home/bitrix/ext_www/domain.tld';

require_once $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";

// Base Ozon integration object
$oOzon = new OzonIntegrationBase();

// Obtain orders from Ozon
try {
    $arOzonOrders = $oOzon->obtainOrders('1 hour ago');
} catch (Exception $e) {
    $oOzon->notify('run-obtain-orders', 'error', $e->getMessage());
}

// Insert orders into our DB
if (isset($arOzonOrders) && is_array($arOzonOrders) && count($arOzonOrders)) {
    try {
        $oOzon->insertOrders($arOzonOrders);
    } catch (
    ArgumentNullException | ArgumentOutOfRangeException | ObjectPropertyException | ArgumentException |
    NotImplementedException | ObjectNotFoundException | SystemException $e
    ) {
        $oOzon->notify('run-insert-orders', 'error', $e->getMessage());
    }
}