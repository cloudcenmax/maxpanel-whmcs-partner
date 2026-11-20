<?php

if (!defined('WHMCS')) {
    exit;
}

require_once __DIR__ . '/integration.php';

add_hook('InvoicePaid', 1, function ($vars) {
    MaxPanelPartner::hook(function ($integration) use ($vars) {
        $integration->invoicePaid((int) $vars['invoiceid']);
    });
});

add_hook('AfterCronJob', 1, function () {
    MaxPanelPartner::hook(function ($integration) {
        $integration->syncDue();
    });
});

add_hook('ClientAreaProductDetailsOutput', 1, function ($vars) {
    return MaxPanelPartner::hook(function ($integration) use ($vars) {
        return isset($vars['service']) ? $integration->customerGuidance((int) $vars['service']->id) : '';
    });
});

// Local interface for a provider recipe running within WHMCS.
function maxpanel_partner_install_ready($serviceId)
{
    return (bool) MaxPanelPartner::hook(function () use ($serviceId) {
        return MaxPanelPartner::installationReady((int) $serviceId);
    });
}
