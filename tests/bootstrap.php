<?php
/**
 * Standalone bootstrap: the WHMCS module outside a WHMCS install.
 *
 * Only the pure decision functions are under test (pawpayments_common.php and
 * the addon's credential resolution). The one WHMCS global they reach is
 * stubbed here and driven by $GLOBALS['whmcs_gateway'].
 */

define('WHMCS', true);

$GLOBALS['whmcs_gateway'] = [];

function getGatewayVariables($module)
{
    return $GLOBALS['whmcs_gateway'];
}

$modules = dirname(__DIR__) . '/modules';
require_once $modules . '/gateways/pawpayments/pawpayments_common.php';
require_once $modules . '/addons/pawpayments_topup/pawpayments_topup.php';
