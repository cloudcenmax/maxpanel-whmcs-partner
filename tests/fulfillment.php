<?php

// Supply a Composer autoloader providing illuminate/database and its dependencies.
$autoload = $argv[1] ?? __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Install the development dependencies with Composer before running tests.\n");
    exit(1);
}
require $autoload;

use Illuminate\Database\Capsule\Manager as Database;

class_alias(Database::class, 'WHMCS\\Database\\Capsule');
define('WHMCS', true);
require __DIR__ . '/../modules/addons/maxpanel_partner/maxpanel_partner.php';

function expect($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new Database();
$db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$db->setAsGlobal();

foreach ([
    'CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, userid INTEGER, status TEXT)',
    'CREATE TABLE tblinvoiceitems (invoiceid INTEGER, type TEXT, relid INTEGER)',
    'CREATE TABLE tblhosting (id INTEGER PRIMARY KEY, userid INTEGER, packageid INTEGER, dedicatedip TEXT, domainstatus TEXT)',
    'CREATE TABLE tblhostingaddons (id INTEGER PRIMARY KEY, hostingid INTEGER, addonid INTEGER, status TEXT)',
    'CREATE TABLE tblhostingconfigoptions (relid INTEGER, configid INTEGER, optionid INTEGER)',
    'CREATE TABLE tblproductconfigoptionssub (id INTEGER PRIMARY KEY, configid INTEGER, optionname TEXT)',
] as $sql) {
    Database::connection()->statement($sql);
}
expect(maxpanel_partner_activate()['status'] === 'success', 'Module schema could not be created.');

$orders = [];
$attempts = [];
$licenses = [];
$transport = function ($method, $path, $payload, $key) use (&$orders, &$attempts, &$licenses) {
    if ($path === 'postpaid/orders' && $method === 'POST') {
        $attempts[$key] = ($attempts[$key] ?? 0) + 1;
        if (!isset($orders[$key])) {
            $id = 'lic_' . str_pad((string) count($orders), 32, '0', STR_PAD_LEFT);
            $licenses[$id] = ['plan' => $payload['plan_id'], 'state' => 'active', 'account_status' => 'active',
                'entitlement_state' => 'active', 'expires_at' => gmdate('c', time() + 864000)];
            $orders[$key] = ['license_id' => $id];
            if ($payload['plan_id'] === 'main') {
                throw new MaxPanelPartnerError('Simulated timeout after MaxPanel created the license.');
            }
        }
        return $orders[$key];
    }
    $parts = explode('/', $path);
    if ($method === 'GET' && $parts[0] === 'postpaid') {
        return $orders[$parts[2]] ?? ['license_id' => ''];
    }
    list(, $id) = $parts;
    if ($method === 'GET') {
        return $licenses[$id];
    }
    switch ($parts[2]) {
        case 'authorize-ip':
            $licenses[$id]['authorized_ip'] = $payload['ip'];
            $licenses[$id]['activation_expires_at'] = gmdate('c', time() + 86400);
            return $licenses[$id];
        case 'suspend-service':
            $licenses[$id]['service_suspended'] = true;
            break;
        case 'resume-service':
            $licenses[$id]['service_suspended'] = false;
            break;
        case 'cancel':
            $licenses[$id]['state'] = 'canceled';
            break;
        default:
            throw new RuntimeException("Unexpected request $method $path");
    }
    return [];
};

$settings = [
    'apiBase' => 'https://maxpanel.net',
    'optionMap' => '{"12":{"option":34,"guidance":"instructions"}}',
    'addonMap' => '{"56":{"plan":"main-plus","guidance":"hidden"}}',
];
$integration = new MaxPanelPartner($settings, $transport);
Database::table('tblinvoices')->insert([
    ['id' => 100, 'userid' => 1, 'status' => 'Unpaid'],
    ['id' => 101, 'userid' => 2, 'status' => 'Paid'],
]);
Database::table('tblhosting')->insert([
    ['id' => 1, 'userid' => 1, 'packageid' => 12, 'dedicatedip' => '', 'domainstatus' => 'Pending'],
    ['id' => 2, 'userid' => 2, 'packageid' => 13, 'dedicatedip' => '198.51.100.20', 'domainstatus' => 'Active'],
]);
Database::table('tblhostingaddons')->insert(['id' => 200, 'hostingid' => 2, 'addonid' => 56, 'status' => 'Active']);
Database::table('tblproductconfigoptionssub')->insert(['id' => 1, 'configid' => 34, 'optionname' => 'main|Renamed display label']);
Database::table('tblhostingconfigoptions')->insert(['relid' => 1, 'configid' => 34, 'optionid' => 1]);
Database::table('tblinvoiceitems')->insert([
    ['invoiceid' => 100, 'type' => 'Hosting', 'relid' => 1],
    ['invoiceid' => 101, 'type' => 'Addon', 'relid' => 200],
]);
$integration->invoicePaid(100);
expect(Database::table(MaxPanelPartner::TABLE)->count() === 0, 'Unpaid order entered the queue.');
Database::table('tblinvoices')->where('id', 100)->update(['status' => 'Paid']);
$integration->invoicePaid(100);
$integration->invoicePaid(101);
$integration->invoicePaid(101);
expect(Database::table(MaxPanelPartner::TABLE)->count() === 2, 'Duplicate callback created duplicate work.');

$run = function (int $cycles = 1) use ($integration) {
    for ($i = 0; $i < $cycles; $i++) {
        Database::table(MaxPanelPartner::TABLE)->update(['next_run' => 0]);
        $integration->syncDue();
    }
};
$run(3);
expect(count($orders) === 1, 'Configurable option activated before the VPS was ready.');
expect(MaxPanelPartner::installationReady(2), 'Add-on IP authorization did not complete.');
expect($integration->customerGuidance(2) === '', 'Hidden guidance rendered for the add-on.');
Database::table('tblhosting')->where('id', 1)->update(['domainstatus' => 'Active', 'dedicatedip' => '192.0.2.10']);
$run(4);
expect(count($orders) === 2 && max($attempts) === 2, 'Timed-out configurable order did not recover with the same key.');
expect(MaxPanelPartner::installationReady(1), 'Configurable option IP authorization did not complete.');
expect(strpos($integration->customerGuidance(1), 'get.maxpanel.net') !== false, 'Install command was not shown.');
$settings['optionMap'] = '{"12":{"option":34,"guidance":"link"}}';
$linkOnly = new MaxPanelPartner($settings, $transport);
expect(strpos($linkOnly->customerGuidance(1), 'get.maxpanel.net') === false, 'Link mode rendered a command.');
expect(strpos($linkOnly->customerGuidance(1), '/docs/installing') !== false, 'Link mode omitted the docs link.');

Database::table('tblhostingaddons')->where('id', 200)->update(['status' => 'Suspended']);
$run();
$addonId = Database::table(MaxPanelPartner::TABLE)->where('sale_id', 200)->value('license_id');
expect($licenses[$addonId]['service_suspended'], 'Add-on suspension did not reach MaxPanel.');
expect(!MaxPanelPartner::installationReady(2), 'Suspended add-on reported ready.');
Database::table('tblhostingaddons')->where('id', 200)->update(['status' => 'Active']);
$run(2);
expect(!$licenses[$addonId]['service_suspended'], 'Add-on reactivation did not reach MaxPanel.');
Database::table('tblhostingaddons')->where('id', 200)->update(['status' => 'Cancelled']);
$run();
expect($licenses[$addonId]['state'] === 'canceled', 'Canceled add-on left a billable license.');
expect(MaxPanelPartner::installationReady(1), 'Add-on cancellation affected an unrelated VPS.');
Database::table('tblhosting')->where('id', 1)->update(['dedicatedip' => '192.0.2.11']);
expect(!MaxPanelPartner::installationReady(1), 'Changed server IP reported ready before authorization.');
expect(strpos($integration->customerGuidance(1), 'get.maxpanel.net') === false, 'Stale authorization rendered an installation command.');
$run();
expect(MaxPanelPartner::installationReady(1), 'Changed IP was not authorized.');
Database::table('tblhosting')->where('id', 1)->update(['domainstatus' => 'Suspended']);
expect(!MaxPanelPartner::installationReady(1), 'Suspended VPS reported ready.');
expect(strpos($integration->customerGuidance(1), 'get.maxpanel.net') === false, 'Suspended VPS rendered an installation command.');
$run();
$optionId = Database::table(MaxPanelPartner::TABLE)->where('sale_type', 'option')->value('license_id');
expect($licenses[$optionId]['service_suspended'], 'VPS suspension did not reach MaxPanel.');
Database::table('tblhosting')->where('id', 1)->update(['domainstatus' => 'Active']);
$run(2);
expect(!$licenses[$optionId]['service_suspended'], 'VPS reactivation did not reach MaxPanel.');
Database::table('tblhosting')->where('id', 1)->update(['domainstatus' => 'Terminated']);
$run();
expect($licenses[$optionId]['state'] === 'canceled', 'Terminated VPS left a billable license.');
expect(Database::table(MaxPanelPartner::TABLE)->whereNotNull('occupied_service')->count() === 0, 'Canceled service retained a queue slot.');
expect(MaxPanelPartner::publicIP('10.0.0.1') === null, 'Private IP accepted.');
expect(MaxPanelPartner::publicIP('100.64.0.1') === null, 'Shared address accepted.');
expect(MaxPanelPartner::publicIP('::1') === null, 'Loopback IPv6 accepted.');
expect(MaxPanelPartner::escape('<script>') === '&lt;script&gt;', 'HTML escaping failed.');
echo "Configurable option and product add-on fulfillment passed.\n";
