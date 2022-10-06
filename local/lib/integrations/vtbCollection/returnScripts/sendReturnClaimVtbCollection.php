<?php
/**
 * This script sends request to VTB-Collection for return bonuses.
 * Used in administration panel (button "VTB-Collection bonuses return").
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
$arRequest = $request->getPostList();

if (0 < intval($arRequest['orderId'])) {
    $oVtbCollection = new VtbCollectionIntegration\VtbCollectionEventHandlers();
    echo $oVtbCollection->sendOrderReturnClaimHandler(intval($arRequest['orderId']));
} else {
    echo 'Order id is less then zero!';
}