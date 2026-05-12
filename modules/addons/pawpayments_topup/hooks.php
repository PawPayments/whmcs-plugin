<?php

use WHMCS\View\Menu\Item as MenuItem;

add_hook('ClientAreaPrimarySidebar', 1, function (MenuItem $primarySidebar) {
    $client = Menu::context('client');
    if (!$client) return;

    try {
        $navItem = $primarySidebar->getChild('My Account');
        if ($navItem) {
            $navItem->addChild('Crypto Deposit', [
                'uri' => 'index.php?m=pawpayments_topup',
                'order' => 90,
                'icon' => 'fas fa-wallet',
            ]);
        }
    } catch (\Exception $e) {
    }
});
