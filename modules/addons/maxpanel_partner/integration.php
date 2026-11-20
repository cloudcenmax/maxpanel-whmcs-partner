<?php

use WHMCS\Database\Capsule;

final class MaxPanelPartnerError extends RuntimeException
{
}

final class MaxPanelPartner
{
    const TABLE = 'mod_maxpanel_partner_licenses';
    const INSTALL = 'curl -fsSL https://get.maxpanel.net | sh';

    private $options;
    private $addons;
    private $endpoint;
    private $docs;
    private $apiKey;
    private $transport;

    public function __construct(array $settings, $transport = null)
    {
        $this->options = $this->mapping($settings['optionMap'] ?? '{}', 'option');
        $this->addons = $this->mapping($settings['addonMap'] ?? '{}', 'plan');
        $this->endpoint = rtrim(($settings['apiBase'] ?? '') ?: 'https://maxpanel.net', '/');
        if (!preg_match('~^https://[A-Za-z0-9.-]+(?::[0-9]+)?$~D', $this->endpoint)) {
            throw new MaxPanelPartnerError('The MaxPanel URL must be an HTTPS origin.');
        }
        $docs = $settings['docsURL'] ?? '';
        $this->docs = filter_var($docs, FILTER_VALIDATE_URL) && parse_url($docs, PHP_URL_SCHEME) === 'https'
            ? $docs : 'https://maxpanel.net/docs/installing#partner-ip-activation';
        $this->apiKey = trim((string) getenv('MAXPANEL_PARTNER_API_KEY'));
        $this->transport = $transport;
    }

    public static function configured()
    {
        $active = Capsule::table('tblconfiguration')->where('setting', 'ActiveAddonModules')->value('value');
        if (!in_array('maxpanel_partner', array_map('trim', explode(',', (string) $active)), true)) {
            return null;
        }
        $settings = Capsule::table('tbladdonmodules')->where('module', 'maxpanel_partner')->pluck('value', 'setting')->all();
        return new self($settings);
    }

    public static function hook(callable $callback)
    {
        try {
            $integration = self::configured();
            return $integration ? $callback($integration) : '';
        } catch (Throwable $e) {
            logActivity('MaxPanel Partner: ' . ($e instanceof MaxPanelPartnerError ? $e->getMessage() : 'WHMCS integration failed.'));
            return '';
        }
    }

    private function mapping($json, $required)
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new MaxPanelPartnerError('Sales mappings must be valid JSON objects.');
        }
        foreach ($data as $id => $rule) {
            if ((int) $id < 1 || !is_array($rule) || !isset($rule[$required])
                || ($required === 'option' && (!is_int($rule['option']) || $rule['option'] < 1))
                || ($required === 'plan' && !self::validPlan($rule['plan']))
                || !in_array($rule['guidance'] ?? 'instructions', ['instructions', 'link', 'hidden'], true)
                || !in_array($rule['activation'] ?? 'ip', ['ip', 'key'], true)) {
                throw new MaxPanelPartnerError('Check the product IDs, plan IDs and guidance modes in the sales mappings.');
            }
        }
        return $data;
    }

    private static function validPlan($plan)
    {
        return is_string($plan) && preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $plan);
    }

    private function choice($serviceId, $optionId)
    {
        $selected = Capsule::table('tblhostingconfigoptions as choice')
            ->join('tblproductconfigoptionssub as option_value', 'option_value.id', '=', 'choice.optionid')
            ->where('choice.relid', $serviceId)->where('choice.configid', $optionId)
            ->where('option_value.configid', $optionId)->value('option_value.optionname');
        return explode('|', (string) $selected, 2)[0];
    }

    public function invoicePaid($invoiceId)
    {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->where('status', 'Paid')->first();
        if (!$invoice) {
            return;
        }
        $items = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->whereIn('type', ['Hosting', 'Addon'])->get();
        foreach ($items as $item) {
            $isAddon = $item->type === 'Addon';
            $addon = $isAddon ? Capsule::table('tblhostingaddons')->where('id', (int) $item->relid)->first() : null;
            if ($isAddon && !$addon) {
                continue;
            }
            $serviceId = (int) ($isAddon ? $addon->hostingid : $item->relid);
            $service = Capsule::table('tblhosting')->where('id', $serviceId)->where('userid', $invoice->userid)->first();
            if (!$service) {
                continue;
            }
            $rule = $isAddon ? ($this->addons[$addon->addonid] ?? null) : ($this->options[$service->packageid] ?? null);
            if (!$rule) {
                continue;
            }
            $plan = $isAddon ? $rule['plan'] : $this->choice($serviceId, $rule['option']);
            if (!self::validPlan($plan) || $plan === 'none') {
                continue;
            }
            $this->enqueue($isAddon ? 'addon' : 'option', (int) $item->relid, $serviceId, $invoiceId, $plan);
        }
    }

    private function enqueue($type, $saleId, $serviceId, $invoiceId, $plan)
    {
        try {
            Capsule::table(self::TABLE)->insert([
                'sale_type' => $type, 'sale_id' => $saleId, 'service_id' => $serviceId,
                'occupied_service' => $serviceId, 'invoice_id' => $invoiceId, 'plan' => $plan,
                'request_key' => 'whmcs-' . bin2hex(random_bytes(24)),
            ]);
        } catch (Throwable $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            if (!Capsule::table(self::TABLE)->where('sale_type', $type)->where('sale_id', $saleId)->exists()) {
                logActivity('MaxPanel Partner: multiple license offers purchased for service ' . $serviceId . '. Review the order.');
            }
        }
    }

    public function syncDue()
    {
        $ids = Capsule::table(self::TABLE)->whereNotNull('occupied_service')->where('next_run', '<=', time())
            ->orderBy('next_run')->orderBy('id')->limit(25)->pluck('id');
        $deadline = microtime(true) + 45;
        foreach ($ids as $id) {
            if (microtime(true) >= $deadline) {
                break;
            }
            try {
                Capsule::connection()->transaction(function () use ($id) {
                    $row = Capsule::table(self::TABLE)->where('id', $id)->lockForUpdate()->first();
                    if (!$row || $row->occupied_service === null || $row->next_run > time()) {
                        return;
                    }
                    $update = $this->sync($row);
                    Capsule::table(self::TABLE)->where('id', $id)->update($update);
                });
            } catch (Throwable $e) {
                Capsule::table(self::TABLE)->where('id', $id)->where('next_run', '<=', time())->update([
                    'last_error' => $e instanceof MaxPanelPartnerError ? $e->getMessage() : 'License synchronization failed.',
                    'next_run' => time() + 300,
                ]);
            }
        }
    }

    private function sync($row)
    {
        $service = Capsule::table('tblhosting')->where('id', $row->service_id)->first();
        $addon = $row->sale_type === 'addon'
            ? Capsule::table('tblhostingaddons')->where('id', $row->sale_id)->where('hostingid', $row->service_id)->first() : null;
        $terminal = ['Cancelled', 'Terminated', 'Fraud', 'Deleted', 'Completed'];
        $states = [$service ? $service->domainstatus : 'Deleted'];
        if ($row->sale_type === 'addon') {
            $states[] = $addon ? $addon->status : 'Deleted';
        }
        $path = 'licenses/' . $row->license_id;
        $update = ['next_run' => time() + 300, 'last_error' => ''];
        $paid = Capsule::table('tblinvoices')->where('id', $row->invoice_id)->value('status');
        if (array_intersect($terminal, $states)) {
            // The order may have succeeded before WHMCS committed the returned ID.
            if ($row->license_id) {
                $this->request('POST', $path . '/cancel', ['at_period_end' => false]);
            } elseif ($row->state === 'provisioning') {
                $this->recoverCanceledOrder($row);
            }
            return $update + ['state' => 'canceled', 'occupied_service' => null, 'authorized_until' => 0];
        }
        if (count(array_diff($states, ['Active'])) > 0 || $paid !== 'Paid') {
            if ($row->license_id && $row->state !== 'suspended') {
                $this->request('POST', $path . '/suspend-service', []);
            }
            return $update + ['state' => $row->license_id ? 'suspended' : ($row->state === 'provisioning' ? 'provisioning' : 'waiting_for_service')];
        }
        $rule = $addon ? ($this->addons[$addon->addonid] ?? null) : ($this->options[$service->packageid] ?? null);
        if (!$rule && !$row->license_id) {
            return $update + ['state' => $row->state === 'provisioning' ? 'provisioning' : 'waiting_for_mapping'];
        }
        $ip = self::publicIP($service->dedicatedip);
        $keyMode = ($rule['activation'] ?? 'ip') === 'key';
        if ($ip && !$keyMode && Capsule::table('tblhosting')->where('dedicatedip', $service->dedicatedip)
            ->where('id', '<>', $service->id)->whereIn('domainstatus', ['Active', 'Suspended'])->exists()) {
            $keyMode = true;
        }
        if (!$row->license_id) {
            if ($row->state !== 'provisioning') {
                return array_replace($update, ['state' => $ip || $keyMode ? 'provisioning' : 'waiting_for_ip', 'next_run' => $ip || $keyMode ? time() : $update['next_run']]);
            }
            if (!$ip && !$keyMode) {
                return $update;
            }
            $license = $this->request('POST', 'postpaid/orders', ['plan_id' => $row->plan], $row->request_key);
            $id = $license['license_id'] ?? '';
            if (!preg_match('/^lic_[a-f0-9]{32}$/D', $id)) {
                throw new MaxPanelPartnerError('MaxPanel returned an invalid license ID.');
            }
            return array_replace($update, ['license_id' => $id, 'state' => 'issued', 'next_run' => time()]);
        }

        $remote = $this->request('GET', $path);
        if (($remote['state'] ?? '') === 'canceled') {
            return $update + ['state' => 'canceled', 'occupied_service' => null, 'authorized_until' => 0];
        }
        if (!empty($remote['service_suspended'])) {
            $this->request('POST', $path . '/resume-service', []);
            return array_replace($update, ['state' => 'issued', 'next_run' => time()]);
        }
        if (($remote['state'] ?? '') !== 'active' || ($remote['account_status'] ?? '') !== 'active' || !empty($remote['account_hold']) || ($remote['entitlement_state'] ?? '') !== 'active' || strtotime($remote['expires_at'] ?? '') <= time()) {
            return $update + ['state' => 'waiting_for_partner', 'authorized_until' => 0];
        }
        if (!empty($remote['server_fingerprint'])) {
            return array_replace($update, ['state' => 'active', 'authorized_until' => 0, 'next_run' => time() + 900]);
        }
        if (($keyMode || !$ip) && !empty($remote['authorized_ip'])) {
            $this->request('POST', $path . '/clear-ip', []);
        }
        if ($keyMode) {
            return $update + ['state' => 'key_required', 'authorized_until' => 0];
        }
        if (!$ip) {
            return $update + ['state' => 'waiting_for_ip', 'authorized_until' => 0];
        }
        $authorized = explode('/', (string) ($remote['authorized_ip'] ?? ''), 2)[0];
        $until = strtotime($remote['activation_expires_at'] ?? '');
        if ($ip !== self::publicIP($authorized) || $until <= time()) {
            $authorization = $this->request('POST', $path . '/authorize-ip', ['ip' => $ip]);
            $until = strtotime($authorization['activation_expires_at'] ?? '');
            if ($until <= time()) {
                throw new MaxPanelPartnerError('MaxPanel returned an invalid activation expiry.');
            }
        }
        return $update + ['state' => 'ready', 'ip' => $ip, 'authorized_until' => $until];
    }

    private function recoverCanceledOrder($row)
    {
        $result = $this->request('GET', 'postpaid/orders/' . $row->request_key);
        if (!empty($result['license_id'])) {
            $this->request('POST', 'licenses/' . $result['license_id'] . '/cancel', ['at_period_end' => false]);
        }
    }

    public static function publicIP($value)
    {
        $value = trim((string) $value);
        if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
        $packed = inet_pton($value);
        if (strlen($packed) === 4) {
            $bytes = unpack('Cfirst/Csecond', $packed);
            if (($bytes['first'] === 100 && $bytes['second'] >= 64 && $bytes['second'] <= 127) || $bytes['first'] >= 224) {
                return null;
            }
        } elseif ((ord($packed[0]) & 0xe0) !== 0x20) {
            return null;
        }
        return inet_ntop($packed);
    }

    public static function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function installationReady($serviceId)
    {
        $row = Capsule::table(self::TABLE)->where('occupied_service', $serviceId)->first();
        if (!$row || $row->state !== 'ready' || $row->authorized_until <= time()) {
            return false;
        }
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$service || $service->domainstatus !== 'Active' || self::publicIP($service->dedicatedip) !== $row->ip) {
            return false;
        }
        return $row->sale_type !== 'addon' || Capsule::table('tblhostingaddons')->where('id', $row->sale_id)
            ->where('hostingid', $serviceId)->where('status', 'Active')->exists();
    }

    public function customerGuidance($serviceId)
    {
        $row = Capsule::table(self::TABLE)->where('occupied_service', $serviceId)->first();
        if (!$row) {
            return '';
        }
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$service) {
            return '';
        }
        $rule = $this->options[$service->packageid] ?? null;
        if ($row->sale_type === 'addon') {
            $addon = Capsule::table('tblhostingaddons')->where('id', $row->sale_id)->value('addonid');
            $rule = $this->addons[$addon] ?? null;
        }
        $guidance = $rule['guidance'] ?? 'instructions';
        if (!$rule || $guidance === 'hidden') {
            return '';
        }
        $body = '<p>Your MaxPanel license is being prepared.</p>';
        if ($row->state === 'active') {
            $body = '<p>Your MaxPanel license is activated on this server.</p>';
        } elseif ($row->state === 'key_required') {
            $body = '<p>Contact your provider for a MaxPanel installation key.</p>';
        } elseif ($row->state === 'suspended' || $row->state === 'waiting_for_partner') {
            $body = '<p>Your license is unavailable. Contact your provider if you need help.</p>';
        } elseif (self::installationReady($serviceId)) {
            $body = '<p>Install MaxPanel on this server by following the '
                . '<a href="' . self::escape($this->docs) . '">installation guide</a>.</p>';
            if ($guidance === 'instructions') {
                $body .= '<p>Sign in as root on your Ubuntu 24.04 server and run:</p><pre>' . self::escape(self::INSTALL) . '</pre>';
            }
        }
        return '<section class="panel panel-default card"><div class="panel-body card-body"><h3>MaxPanel</h3>' . $body . '</div></section>';
    }

    private function request($method, $path, array $data = [], $idempotency = '')
    {
        if ($this->transport) {
            return call_user_func($this->transport, $method, $path, $data, $idempotency);
        }
        if (!preg_match('/^mp_partner_[A-Za-z0-9]+$/D', $this->apiKey)) {
            throw new MaxPanelPartnerError('The MaxPanel partner API key is missing or invalid.');
        }
        $curl = curl_init($this->endpoint . '/partner/api/v1/' . $path);
        $headers = ['Authorization: Bearer ' . $this->apiKey, 'Accept: application/json'];
        if ($method !== 'GET') {
            $headers[] = 'Content-Type: application/json';
        }
        if ($idempotency !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotency;
        }
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($method !== 'GET') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode((object) $data));
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($body === false || $status < 200 || $status >= 300) {
            throw new MaxPanelPartnerError('MaxPanel API ' . ($status ? 'returned HTTP ' . $status : 'could not be reached') . '.');
        }
        $result = json_decode($body, true);
        if (!is_array($result)) {
            throw new MaxPanelPartnerError('MaxPanel API returned an invalid response.');
        }
        return $result;
    }
}
