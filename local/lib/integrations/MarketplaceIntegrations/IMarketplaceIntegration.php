<?php

/**
 * Interface IMarketplaceIntegration
 *
 * Every integration should implement this interface
 */
interface IMarketplaceIntegration
{
    /** @var string Array of bitrix ids that required "Chestniy znak" marks (pseudo-constant) */
    public const PRODUCTS_REQUIRED_CHESTNIY_ZNAK_MARK_PROPERTY_CODE = 'CHESTNIY_ZNAK_PRODUCTS';

    /** @var string "Chestniy znak" marks for the order */
    public const CHESTNIY_ZNAK_PROPERTY_NAME = 'CHESTNIY_ZNAK_CODES';

    /**
     * Method to obtain orders from partner
     *
     * @param string $backInTime Interval for strtotime() function
     *
     * @return array
     */
    public function obtainOrders(string $backInTime): array;


    /**
     * Method to insert orders in our DB
     *
     * @param array $arOrders
     */
    public function insertOrders(array $arOrders): void;


    /**
     * Method handles error notifications
     *
     * @param string $code
     * @param string $type ['error', 'info']
     * @param string $additionalInfo
     */
    public function notify(string $code, string $type, string $additionalInfo): void;
}