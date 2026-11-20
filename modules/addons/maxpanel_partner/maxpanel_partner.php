<?php

if (!defined('WHMCS')) {
    exit;
}

require_once __DIR__ . '/integration.php';

use WHMCS\Database\Capsule;

function maxpanel_partner_config()
{
    return [
        'name' => 'MaxPanel Partner Licenses',
        'description' => 'Sell and activate MaxPanel licenses on VPS and dedicated server services.',
        'version' => '0.1.0',
        'author' => 'MaxPanel',
        'fields' => [
            'apiBase' => ['FriendlyName' => 'MaxPanel URL', 'Type' => 'text', 'Default' => 'https://maxpanel.net'],
            'optionMap' => [
                'FriendlyName' => 'Configurable options (JSON)', 'Type' => 'textarea', 'Rows' => 5,
                'Default' => '{}', 'Description' => '{"12":{"option":34,"guidance":"instructions"}} (keys are VPS product IDs)',
            ],
            'addonMap' => [
                'FriendlyName' => 'Product add-ons (JSON)', 'Type' => 'textarea', 'Rows' => 5,
                'Default' => '{}', 'Description' => '{"56":{"plan":"main","guidance":"hidden"}} (keys are add-on definition IDs)',
            ],
            'docsURL' => [
                'FriendlyName' => 'Installation guide URL', 'Type' => 'text',
                'Default' => 'https://maxpanel.net/docs/installing#partner-ip-activation',
            ],
        ],
    ];
}

function maxpanel_partner_activate()
{
    try {
        $schema = Capsule::schema();
        if (!$schema->hasTable(MaxPanelPartner::TABLE)) {
            $schema->create(MaxPanelPartner::TABLE, function ($table) {
                $table->increments('id');
                $table->string('sale_type', 10);
                $table->unsignedInteger('sale_id');
                $table->unsignedInteger('service_id');
                $table->unsignedInteger('occupied_service')->nullable()->unique();
                $table->unsignedInteger('invoice_id');
                $table->string('plan', 80);
                $table->string('request_key', 80)->unique();
                $table->string('license_id', 80)->nullable();
                $table->string('ip', 45)->nullable();
                $table->unsignedInteger('authorized_until')->default(0);
                $table->string('state', 32)->default('queued');
                $table->string('last_error', 255)->default('');
                $table->unsignedInteger('next_run')->default(0);
                $table->index(['next_run', 'id']);
                $table->unique(['sale_type', 'sale_id']);
            });
        }
        return ['status' => 'success', 'description' => 'Configure the sales mappings and set MAXPANEL_PARTNER_API_KEY.'];
    } catch (Throwable $e) {
        return ['status' => 'error', 'description' => 'Could not initialize the MaxPanel license queue.'];
    }
}

function maxpanel_partner_deactivate()
{
    return ['status' => 'success', 'description' => 'License records were retained. Reactivate the module to resume synchronization.'];
}

function maxpanel_partner_output($vars)
{
    try {
        $integration = MaxPanelPartner::configured();
        if (!$integration) {
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['maxpanel_replay'])) {
            check_token('WHMCS.admin.default');
            $integration->invoicePaid((int) $_POST['maxpanel_replay']);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['maxpanel_retry'])) {
            check_token('WHMCS.admin.default');
            Capsule::table(MaxPanelPartner::TABLE)->where('id', (int) $_POST['maxpanel_retry'])
                ->whereNotNull('occupied_service')->update(['next_run' => 0]);
        }
        echo '<p>MaxPanel provisions licenses for paid services and authorizes the dedicated service IP.</p>';
        $token = MaxPanelPartner::escape(generate_token('plain'));
        echo '<form method="post" class="form-inline"><input type="hidden" name="token" value="' . $token
            . '"><label>Paid invoice ID <input class="form-control" type="number" name="maxpanel_replay" min="1" required></label> '
            . '<button class="btn btn-default">Queue paid invoice</button></form>';
        if (!getenv('MAXPANEL_PARTNER_API_KEY')) {
            echo '<p class="text-danger">Set MAXPANEL_PARTNER_API_KEY in the web and cron environments.</p>';
        }
        $rows = Capsule::table(MaxPanelPartner::TABLE)->orderBy('id', 'desc')->limit(100)->get();
        echo '<table class="table"><thead><tr><th>Service</th><th>Sale</th><th>Plan</th><th>License</th>'
            . '<th>IP</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . (int) $row->service_id . '</td><td>' . MaxPanelPartner::escape($row->sale_type) . ' ' . (int) $row->sale_id
                . '</td><td>' . MaxPanelPartner::escape($row->plan) . '</td><td>' . MaxPanelPartner::escape($row->license_id)
                . '</td><td>' . MaxPanelPartner::escape($row->ip) . '</td><td>' . MaxPanelPartner::escape($row->state)
                . '<br>' . MaxPanelPartner::escape($row->last_error) . '</td><td>';
            if ($row->occupied_service !== null) {
                echo '<form method="post"><input type="hidden" name="token" value="' . $token
                    . '"><button class="btn btn-default" name="maxpanel_retry" value="' . (int) $row->id . '">Retry on cron</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    } catch (Throwable $e) {
        echo '<p class="text-danger">' . MaxPanelPartner::escape($e instanceof MaxPanelPartnerError
            ? $e->getMessage() : 'Could not read the MaxPanel settings or license queue.') . '</p>';
    }
}
