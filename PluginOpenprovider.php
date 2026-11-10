<?php

require_once 'modules/admin/models/RegistrarPlugin.php';

class PluginOpenprovider extends RegistrarPlugin
{
    public $features = [
        'nameSuggest' => true,
        'importDomains' => true,
        'importPrices' => true,
    ];

    private $liveUrl = 'https://api.openprovider.eu/v1beta';
    private $sandBox = 'https://api.sandbox.openprovider.nl/v1beta';

    // Seconds the API token is cached in APCu. A 401 from the API forces a fresh login regardless.
    const TOKEN_TTL = 3000;

    // Per-process caches. A single page load calls several methods for the same domain.
    private static $tokenCache = [];
    private static $domainCache = [];

    public function getVariables()
    {
        $variables = [
            lang('Plugin Name') => [
                'type'          => 'hidden',
                'description'   => lang('How CE sees this plugin (not to be confused with the Signup Name)'),
                'value'         => lang('Openprovider'),
            ],
            lang('Use testing server') => [
                'type'          => 'yesno',
                'description'   => lang('Select Yes if you wish to use Openprovider\'s testing environment, so that transactions are not actually made. For this to work, you must first register you server\'s ip in Openprovider\'s testing environment (https://cp.sandbox.openprovider.nl), Below in username and password file you need to enter test environment credentials, whereas for live you need to untick this and provide actual user details below.'),
                'value'         => '',
            ],
            lang('Username') => [
                'type'          => 'text',
                'description'   => lang('Enter your email address for your Openprovider account.'),
                'value'         => ''
            ],
            lang('Password') => [
                'type'          => 'password',
                'description'   => lang('Enter your password for your Openprovider account.'),
                'value'         => ''
            ],
            lang('TLDs to import') => [
                'type'          => 'text',
                'description'   => lang('Optional. Comma separated list of TLDs to import prices for, for example: com, net, org, co.uk. Leave blank to import every TLD (several thousand, slow).'),
                'value'         => ''
            ],
            lang('Import promotional prices') => [
                'type'          => 'yesno',
                'description'   => lang('Select Yes to import the current promotional registration price when a TLD has an active promotion. Select No to import the regular price.'),
                'value'         => '0'
            ],
            lang('Supported Features') => [
                'type'          => 'label',
                'description'   => '* ' . lang('TLD Lookup') . '<br>* ' . lang('Domain Registration') . ' <br>* ' . lang('Get / Set DNS Records') . ' <br>* ' . lang('Get / Set Nameserver Records') . ' <br>* ' . lang('Get / Set Contact Information') . ' <br>* ' . lang('Get / Set Registrar Lock') . ' <br>* ' . lang('Initiate Domain Transfer') . ' <br>* ' . lang('Import TLDs and Prices'),
                'value'         => ''
            ],
            lang('Actions') => [
                'type'          => 'hidden',
                'description'   => lang('Current actions that are active for this plugin (when a domain isn\'t registered)'),
                'value'         => 'Register'
            ],
            lang('Registered Actions') => [
                'type'          => 'hidden',
                'description'   => lang('Current actions that are active for this plugin (when a domain is registered)'),
                'value'         => 'Renew (Renew Domain),DomainTransferWithPopup (Initiate Transfer),Cancel',
            ],
            lang('Registered Actions For Customer') => [
                'type'          => 'hidden',
                'description'   => lang('Current actions that are active for this plugin (when a domain is registered)'),
                'value'         => '',
            ]
        ];

        return $variables;
    }

    // ------------------------------------------------------------------
    // Domain lookup helpers (cached)
    // ------------------------------------------------------------------

    /**
     * Find a domain in the Openprovider account by its full name.
     * Returns the list result row (array) or '' when not found.
     */
    public function findHandleByDomName($domainName)
    {
        $key = $this->cacheKey($domainName);
        if (isset(self::$domainCache[$key]['search'])) {
            return self::$domainCache[$key]['search'];
        }

        $found = '';
        $res = $this->getDataRequest('domains?full_name=' . rawurlencode($domainName));
        if (!empty($res['results'])) {
            // full_name can match more than one domain, so only accept an exact match
            foreach ($res['results'] as $row) {
                $full = strtolower(($row['domain']['name'] ?? '') . '.' . ($row['domain']['extension'] ?? ''));
                if ($full === strtolower($domainName)) {
                    $found = $row;
                    break;
                }
            }
        }

        self::$domainCache[$key]['search'] = $found;
        return $found;
    }

    /**
     * Full domain record. Uses the domain id stored in Registrar Order Id when available,
     * which saves the search round trip, and falls back to a search by name.
     */
    public function getDomainInfoDetails($params)
    {
        $domainName = $params['sld'] . '.' . $params['tld'];
        $key = $this->cacheKey($domainName);
        if (isset(self::$domainCache[$key]['detail'])) {
            return self::$domainCache[$key]['detail'];
        }

        $detail = null;
        $knownId = $this->domainIdFromOrderId($params);
        if ($knownId) {
            try {
                $candidate = $this->getDataRequest('domains/' . $knownId);
                $candidateName = strtolower(($candidate['domain']['name'] ?? '') . '.' . ($candidate['domain']['extension'] ?? ''));
                if ($candidateName === strtolower($domainName)) {
                    $detail = $candidate;
                }
            } catch (Exception $e) {
                $detail = null;
            }
        }

        if ($detail === null) {
            $domDetails = $this->findHandleByDomName($domainName);
            if (!is_array($domDetails) || empty($domDetails['id'])) {
                throw new CE_Exception($this->user->lang('%s was not found in your Openprovider account', $domainName));
            }
            $detail = $this->getDataRequest('domains/' . $domDetails['id']);
        }

        self::$domainCache[$key]['detail'] = $detail;
        return $detail;
    }

    /**
     * Extract the Openprovider domain id from the package's Registrar Order Id.
     * Supports "<registrar>_-_<domainId>_-_<handle>" (register / transfer) and
     * "<registrar>-<domainId>" (domain import).
     */
    private function domainIdFromOrderId(array $params): int
    {
        if (empty($params['userPackageId'])) {
            return 0;
        }
        try {
            $userPackage = new UserPackage($params['userPackageId']);
            $orderId = (string)$userPackage->getCustomField('Registrar Order Id');
        } catch (Exception $e) {
            return 0;
        }

        $parts = explode('_-_', $orderId);
        if (isset($parts[1]) && ctype_digit($parts[1])) {
            return (int)$parts[1];
        }
        if (preg_match('/-(\d+)$/', $orderId, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }

    private function cacheKey(string $domainName): string
    {
        return md5(($this->isSandbox() ? 'sandbox:' : 'live:') . strtolower($domainName));
    }

    private function clearDomainCache(array $params): void
    {
        unset(self::$domainCache[$this->cacheKey($params['sld'] . '.' . $params['tld'])]);
    }

    // ------------------------------------------------------------------
    // Registration / transfer
    // ------------------------------------------------------------------

    public function doRegister($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $orderid = $this->registerDomain($this->buildRegisterParams($userPackage, $params));
        if ($orderid['result'] == 'success') {
            $userPackage->setCustomField("Registration Status", "Registered");
            $userPackage->setCustomField(
                "Registrar Order Id",
                $userPackage->getCustomField("Registrar") . '_-_' . $orderid['domainId'] . '_-_' . $orderid['handleId']
            );
            return $userPackage->getCustomField('Domain Name') . ' has been registered.';
        } else {
            return $userPackage->getCustomField('Domain Name') . ' not registered.';
        }
    }

    public function registerDomain($params)
    {
        $values = ['result' => 'failed'];

        $domDetails = $this->findHandleByDomName($params["sld"] . '.' . $params["tld"]);
        $handleId = is_array($domDetails) ? $domDetails['owner_handle'] : '';
        if (!$handleId) {
            $handleId = $this->createCustomer($params);
        }
        if (!$handleId) {
            return $values;
        }

        $domainorder = $this->buildDomainOrder($params, $handleId);
        $domainorder['period'] = max(1, (int)($params['NumYears'] ?? 1));

        $orderRes = $this->postDataRequest('domains', $domainorder);
        $this->clearDomainCache($params);
        if ($this->orderSucceeded($orderRes)) {
            $values["result"] = "success";
            $values["domainId"] = $orderRes['id'];
            $values["handleId"] = $handleId;
        }

        return $values;
    }

    public function doDomainTransferWithPopup($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $transferid = $this->initiateTransfer($this->buildTransferParams($userPackage, $params));
        if ($transferid['result'] == 'success') {
            $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar") . '_-_' . $transferid['domainId'] . '_-_' . $transferid['handleId']); // store user pattern
            $userPackage->setCustomField('Transfer Status', 'Pending');
            return $userPackage->getCustomField('Domain Name') . ' Transfer of has been initiated.';
        } else {
            return $userPackage->getCustomField('Domain Name') . ' not transfered.';
        }
    }

    public function initiateTransfer($params)
    {
        $values = ['result' => 'failed'];

        $domDetails = $this->findHandleByDomName($params["sld"] . '.' . $params["tld"]);
        $handleId = is_array($domDetails) ? $domDetails['owner_handle'] : '';
        if (!$handleId) {
            $handleId = $this->createCustomer($params);
        }
        if (!$handleId) {
            return $values;
        }

        $domainorder = $this->buildDomainOrder($params, $handleId);
        $domainorder['auth_code'] = $params["eppCode"];

        $orderRes = $this->postDataRequest('domains/transfer', $domainorder);
        $this->clearDomainCache($params);
        if ($this->orderSucceeded($orderRes)) {
            $values["result"] = "success";
            $values["domainId"] = $orderRes['id'];
            $values["handleId"] = $handleId;
        }

        return $values;
    }

    /**
     * postDataRequest() throws when the API reports an error. The create, transfer and renew
     * payloads carry the new id, so accept either that or a zero code.
     */
    private function orderSucceeded($orderRes): bool
    {
        if (!is_array($orderRes)) {
            return false;
        }
        return isset($orderRes['id']) || (isset($orderRes['code']) && (string)$orderRes['code'] === '0');
    }

    private function buildNameservers(array $params): array
    {
        $nameservers = [];
        if (isset($params['NS1'])) {
            for ($i = 1; $i <= 12; $i++) {
                if (!isset($params["NS$i"])) {
                    break;
                }
                $nameservers[] = $params["NS$i"]['hostname'];
            }
        }

        if (count($nameservers) == 0) {
            $nameservers = ['ns1.openprovider.nl', 'ns2.openprovider.nl', 'ns3.openprovider.nl'];
        }
        return $nameservers;
    }

    private function buildDomainOrder(array $params, string $handleId): array
    {
        $domainorder = [
            'admin_handle'   => $handleId,
            'billing_handle' => $handleId,
            'owner_handle'   => $handleId,
            'tech_handle'    => $handleId,
            'domain'         => ['name' => $params['sld'], 'extension' => $params['tld']],
            'period'         => 1,
            'autorenew'      => 'off',
        ];
        foreach ($this->buildNameservers($params) as $id => $nameserver) {
            $domainorder['name_servers'][$id]['name'] = $nameserver;
        }
        return $domainorder;
    }

    /**
     * Create an Openprovider customer (handle) from the registrant details. Returns the handle or ''.
     */
    private function createCustomer(array $params): string
    {
        $phonecc = $this->countryCodePhone($params['RegistrantCountry']);
        $phonenumber = $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']);

        $addClient = [];
        $addClient['address']['street'] = trim($params["RegistrantAddress1"] . ' ' . $params["RegistrantAddress2"]);
        $addClient['address']['number'] = "";
        $addClient['address']['city'] = $params["RegistrantCity"];
        $addClient['address']['zipcode'] = $params["RegistrantPostalCode"];
        $addClient['address']['state'] = $params["RegistrantStateProvince"];
        $addClient['address']['country'] = $params["RegistrantCountry"];

        $addClient['phone']['subscriber_number'] = $phonenumber;
        $addClient['phone']['area_code'] = "0";
        $addClient['phone']['country_code'] = "+" . $phonecc;

        $addClient['email'] = $params["RegistrantEmailAddress"];
        $addClient['username'] = $params["RegistrantEmailAddress"];
        // Random password; nobody logs in as this handle.
        $addClient['password'] = bin2hex(random_bytes(8)) . 'Aa1!';
        $addClient['role'] = "tech";

        if (!empty($params["RegistrantOrganizationName"])) {
            $addClient['company_name'] = $params["RegistrantOrganizationName"];
        }

        $addClient['name']['first_name'] = $params["RegistrantFirstName"];
        $addClient['name']['full_name'] = $params["RegistrantFirstName"] . ' ' . $params["RegistrantLastName"];
        $addClient['name']['last_name'] = $params["RegistrantLastName"];

        $res = $this->postDataRequest('customers', $addClient);
        return !empty($res['handle']) ? (string)$res['handle'] : '';
    }

    // ------------------------------------------------------------------
    // Renewal
    // ------------------------------------------------------------------

    public function doRenew($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $orderid = $this->renewDomain($this->buildRenewParams($userPackage, $params));
        if ($orderid['result'] == 'success') {
            return $userPackage->getCustomField('Domain Name') . ' has been renewed.';
        } else {
            return $userPackage->getCustomField('Domain Name') . ' not renewed.';
        }
    }

    public function renewDomain($params)
    {
        $values = ['result' => ''];

        // Renewals are skipped entirely in testing mode.
        if ($this->isSandbox()) {
            return $values;
        }

        $domDetails = $this->getDomainInfoDetails($params);
        $renewPeriod = ['period' => max(1, (int)($params['NumYears'] ?? 1))];

        $this->postDataRequest('domains/' . $domDetails['id'] . '/renew', $renewPeriod);
        $this->clearDomainCache($params);
        $values["result"] = "success";

        return $values;
    }

    // ------------------------------------------------------------------
    // Lock / transfer key / EPP
    // ------------------------------------------------------------------

    public function getRegistrarLock($params)
    {
        return !empty($this->getDomainInfoDetails($params)["is_locked"]);
    }

    public function doSetRegistrarLock($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->setRegistrarLock($this->buildLockParams($userPackage, $params));
        return "Updated Registrar Lock.";
    }

    public function setRegistrarLock($params)
    {
        $domDetails = $this->getDomainInfoDetails($params);
        $isLocked = !empty($domDetails["is_locked"]);
        // Use the requested state when provided, otherwise toggle the current one.
        $wantLocked = isset($params['lock']) ? (bool)$params['lock'] : !$isLocked;
        if ($wantLocked === $isLocked) {
            return;
        }
        $this->putDataRequest('domains/' . $domDetails['id'], ['is_locked' => $wantLocked]);
        $this->clearDomainCache($params);
    }

    public function doSendTransferKey($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->sendTransferKey($this->buildRegisterParams($userPackage, $params));
        return 'Successfully sent auth info for ' . $userPackage->getCustomField('Domain Name');
    }

    public function sendTransferKey($params)
    {
    }

    public function setAutorenew($params)
    {
        //throw new Exception('This function is not supported');
    }

    public function getEPPCode($params)
    {
        $res = $this->getDomainInfoDetails($params)['auth_code'];
        if ($res) {
            return $res;
        }
    }

    // ------------------------------------------------------------------
    // Availability
    // ------------------------------------------------------------------

    public function checkDomain($params)
    {
        $arguments['domains'][0] = [
            'extension' => $params['tld'],
            'name' => $params["sld"]
        ];

        // 0 = available, 1 = registered, 5 = could not contact the registry
        try {
            $res = $this->postDataRequest('domains/check', $arguments);
        } catch (Exception $e) {
            CE_Lib::log(1, 'Openprovider domain check failed for ' . $params['sld'] . '.' . $params['tld'] . ': ' . $e->getMessage());
            $domains[] = ['tld' => $params['tld'], 'domain' => $params['sld'], 'status' => 5];
            return ["result" => $domains];
        }
        if (!isset($res["results"][0]['status'])) {
            CE_Lib::log(1, 'Openprovider domain check returned no status for ' . $params['sld'] . '.' . $params['tld']);
            $domains[] = ['tld' => $params['tld'], 'domain' => $params['sld'], 'status' => 5];
            return ["result" => $domains];
        }

        $status = $res["results"][0]['status'] == "free" ? 0 : 1;
        $domains[] = ['tld' => $params['tld'], 'domain' => $params['sld'], 'status' => $status];
        return ["result" => $domains];
    }

    // ------------------------------------------------------------------
    // HTTP layer
    // ------------------------------------------------------------------

    private function isSandbox(): bool
    {
        return (bool)$this->settings->get('plugin_Openprovider_Use testing server');
    }

    private function getBaseUrl(): string
    {
        return $this->isSandbox() ? $this->sandBox : $this->liveUrl;
    }

    private function buildCurl(string $endPoint, string $method = 'GET', $data = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getBaseUrl() . '/' . $endPoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        $caPathOrFile = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        if (is_dir($caPathOrFile)) {
            curl_setopt($ch, CURLOPT_CAPATH, $caPathOrFile);
        } else {
            curl_setopt($ch, CURLOPT_CAINFO, $caPathOrFile);
        }

        $headers = [
            'Authorization: Bearer ' . $this->getToken(),
            'Content-Type: application/json',
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data !== null && $method !== 'DELETE') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        return $ch;
    }

    private function makeRequest(string $endPoint, string $method = 'GET', $data = null, bool $isRetry = false): array
    {
        $ch = $this->buildCurl($endPoint, $method, $data);

        CE_Lib::log(4, "Openprovider request: $method $endPoint " . $this->redact($data));

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $seconds = round((float)curl_getinfo($ch, CURLINFO_TOTAL_TIME), 2);
        $error = curl_errno($ch) ? curl_error($ch) : '';
        if ($error !== '') {
            throw new CE_Exception('Openprovider connection error: ' . $error);
        }

        $decoded = json_decode((string)$response, true);
        $logBody = is_array($decoded) ? $this->redact($decoded) : (string)$response;
        CE_Lib::log(4, "Openprovider response (HTTP $httpCode, {$seconds}s): " . substr($logBody, 0, 2000));

        // Token expired or revoked: log in again once and repeat the call
        if ($httpCode === 401 && !$isRetry) {
            $this->clearToken();
            return $this->makeRequest($endPoint, $method, $data, true);
        }

        if (!is_array($decoded)) {
            throw new CE_Exception("Openprovider returned an invalid response (HTTP $httpCode)");
        }

        return $decoded;
    }

    private function redact($data): string
    {
        if ($data === null) {
            return '';
        }
        if (is_array($data)) {
            array_walk_recursive($data, function (&$value, $key) {
                if (in_array($key, ['password', 'auth_code', 'internal_auth_code', 'token'], true)) {
                    $value = '***';
                }
            });
        }
        return (string)json_encode($data);
    }

    private function unwrap(array $requestData)
    {
        if (isset($requestData['code']) && (string)$requestData['code'] === '0') {
            return $requestData['data'] ?? [];
        }
        throw new CE_Exception($requestData['desc'] ?? 'Unknown Openprovider error');
    }

    public function getDataRequest($endPoint)
    {
        return $this->unwrap($this->makeRequest($endPoint));
    }

    public function putDataRequest($endPoint, $PutData)
    {
        return $this->unwrap($this->makeRequest($endPoint, "PUT", $PutData));
    }

    public function postDataRequest($endPoint, $PostData)
    {
        return $this->unwrap($this->makeRequest($endPoint, "POST", $PostData));
    }

    // ------------------------------------------------------------------
    // Authentication (the token is reused instead of logging in for every call)
    // ------------------------------------------------------------------

    private function apcuEnabled(): bool
    {
        return function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    private function tokenKey(): string
    {
        return 'ce_openprovider_token_' . md5($this->getBaseUrl() . '|' . $this->settings->get('plugin_Openprovider_Username'));
    }

    private function getToken(): string
    {
        $key = $this->tokenKey();
        if (!empty(self::$tokenCache[$key])) {
            return self::$tokenCache[$key];
        }

        // APCu (when available) shares the token between page loads
        if ($this->apcuEnabled()) {
            $token = apcu_fetch($key, $hit);
            if ($hit && $token) {
                return self::$tokenCache[$key] = $token;
            }
        }

        $token = $this->logInRequest();
        self::$tokenCache[$key] = $token;
        if ($this->apcuEnabled()) {
            apcu_store($key, $token, self::TOKEN_TTL);
        }
        return $token;
    }

    private function clearToken(): void
    {
        $key = $this->tokenKey();
        unset(self::$tokenCache[$key]);
        if ($this->apcuEnabled()) {
            apcu_delete($key);
        }
    }

    public function logInRequest()
    {
        $url = $this->getBaseUrl() . '/auth/login';

        $loginData['username'] = $this->settings->get('plugin_Openprovider_Username');
        $loginData['password'] = $this->settings->get('plugin_Openprovider_Password');
        $loginData['ip'] = CE_Lib::getServerAddr();

        // Plain cURL instead of NE_Network::curlRequest(), which logs the request body including the password
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $caPathOrFile = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        if (is_dir($caPathOrFile)) {
            curl_setopt($ch, CURLOPT_CAPATH, $caPathOrFile);
        } else {
            curl_setopt($ch, CURLOPT_CAINFO, $caPathOrFile);
        }

        CE_Lib::log(4, "Openprovider request: POST auth/login " . $this->redact($loginData));

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch) ? curl_error($ch) : '';
        if ($error !== '') {
            throw new CE_Exception('Openprovider connection error: ' . $error);
        }

        $requestData = json_decode((string)$response, true);

        // The token itself is deliberately not logged
        if (!empty($requestData['data']['token'])) {
            CE_Lib::log(4, "Openprovider response (HTTP $httpCode): login successful");
            return $requestData['data']['token'];
        }

        CE_Lib::log(4, "Openprovider response (HTTP $httpCode): " . substr((string)$response, 0, 2000));
        throw new CE_Exception($requestData['desc'] ?? 'Openprovider login failed');
    }

    // ------------------------------------------------------------------
    // Contacts
    // ------------------------------------------------------------------

    private function countryCodePhone($country): string
    {
        $query = "SELECT phone_code FROM country WHERE iso=? AND phone_code != ''";
        $result = $this->db->query($query, $country);
        $row = $result->fetch();
        if (!empty($row['phone_code'])) {
            return (string)$row['phone_code'];
        }

        $query = "SELECT phone_code FROM country WHERE name=? AND phone_code != ''";
        $result = $this->db->query($query, $country);
        $row = $result->fetch();
        return !empty($row['phone_code']) ? (string)$row['phone_code'] : '';
    }

    public function getContactInformation($params)
    {
        $domDetails = $this->getDomainInfoDetails($params);
        if (empty($domDetails['owner_handle'])) {
            throw new CE_Exception($this->user->lang('%s has no owner handle', $params["sld"] . '.' . $params["tld"]));
        }
        $contact = $this->getDataRequest('customers/' . $domDetails['owner_handle']);

        $result = $this->db->query("SELECT name FROM country WHERE iso=?", $contact['address']['country']);
        $row = $result->fetch();
        $country = !empty($row['name']) ? $row['name'] : $contact['address']['country'];

        $entry = [];
        $entry['OrganizationName'] = [$this->user->lang('Organization'), (string)($contact['company_name'] ?? '')];
        $entry['FirstName'] = [$this->user->lang('First Name'), (string)$contact['name']['first_name']];
        $entry['LastName'] = [$this->user->lang('Last Name'), (string)$contact['name']['last_name']];
        $entry['Address1'] = [$this->user->lang('Address') . ' 1', (string)$contact['address']['street']];
        $entry['City'] = [$this->user->lang('City'), (string)$contact['address']['city']];
        $entry['StateProvince'] = [$this->user->lang('Province') . '/' . $this->user->lang('State'), (string)$contact['address']['state']];
        $entry['Country'] = [$this->user->lang('Country'), (string)$country];
        $entry['PostalCode'] = [$this->user->lang('Postal Code'), (string)$contact['address']['zipcode']];
        $entry['EmailAddress'] = [$this->user->lang('Email'), (string)$contact['email']];
        $entry['Phone'] = [$this->user->lang('Phone'), $contact['phone']['country_code'] . $contact['phone']['subscriber_number']];

        $info = [];
        foreach (['Registrant', 'AuxBilling', 'Admin', 'Tech'] as $type) {
            $info[$type] = $entry;
        }
        return $info;
    }

    public function setContactInformation($params)
    {
        $result = $this->db->query("SELECT iso FROM country WHERE name=?", $params['Registrant_Country']);
        $row = $result->fetch();
        $country = !empty($row['iso']) ? $row['iso'] : $params['Registrant_Country'];

        $domDetails = $this->getDomainInfoDetails($params);
        $handleId = $domDetails['owner_handle'];
        $countryCode = $this->countryCodePhone($params['Registrant_Country']);
        $phoneNumber = $this->validatePhone($params['Registrant_Phone'], $country);

        $contactData['address']['city'] = $params['Registrant_City'];
        $contactData['address']['country'] = $country;
        $contactData['address']['street'] = $params['Registrant_Address1'];
        $contactData['address']['state'] = $params['Registrant_StateProvince'];
        $contactData['address']['zipcode'] = $params['Registrant_PostalCode'];
        $contactData['email'] = $params['Registrant_EmailAddress'];
        $contactData['phone']['country_code'] = "+" . $countryCode;
        $contactData['phone']['subscriber_number'] = trim($phoneNumber);
        $contactData['phone']['area_code'] = "0";

        $res = $this->putDataRequest('customers/' . $handleId, $contactData);
        $this->clearDomainCache($params);
        return $res;
    }

    /**
     * Strip non digits and the country dial code from a phone number.
     */
    private function validatePhone($phone, $country): string
    {
        $phone = preg_replace('/[^\d]/', '', (string)$phone);
        if ($phone == '') {
            return $phone;
        }

        $query = "SELECT phone_code FROM country WHERE iso=? AND phone_code != ''";
        $result = $this->db->query($query, $country);
        if (!$row = $result->fetch()) {
            return $phone;
        }

        $code = preg_quote($row['phone_code'], '/');
        return preg_replace("/^($code)(\\d+)/", '\2', $phone);
    }

    // ------------------------------------------------------------------
    // Info / transfer status
    // ------------------------------------------------------------------

    public function getGeneralInfo($params)
    {
        $res = $this->getDomainInfoDetails($params);
        if ($res['id']) {
            $data = [];
            $data['id'] = (int)$res["id"];
            $data['domain'] = (string)$res["domain"]['name'] . '.' . $res["domain"]['extension'];
            // Domain import parses this as m/d/Y
            $data['expiration'] = !empty($res['expiration_date']) ? $this->formatDate($res['expiration_date']) : 'N/A';
            $data['registrationstatus'] = $res["status"];
            $data['purchasestatus'] = $res["status"];
            $data['autorenew'] = false;
            $data['eppCode'] = $res["auth_code"];
            return $data;
        }
    }

    private function formatDate(string $date): string
    {
        $time = strtotime($date);
        return $time ? date('m/d/Y', $time) : $date;
    }

    public function getTransferStatus($params)
    {
        $getDomain = $this->getDomainInfoDetails($params);
        $userPackage = new UserPackage($params['userPackageId']);

        if ($getDomain['status'] == 'ACT') {
            $resultmsg = "Completed";
            $userPackage->setCustomField('Transfer Status', 'Completed');
        } elseif ($getDomain['status'] == 'FAI') {
            $resultmsg = "Failed";
            $userPackage->setCustomField('Transfer Status', 'Failed');
        } elseif ($getDomain['status'] == 'REQ') {
            $resultmsg = "Pending";
            $userPackage->setCustomField('Transfer Status', 'Pending');
        } else {
            $userPackage->setCustomField('Transfer Status', 'Pending');
            $resultmsg = $getDomain["status"];
        }
        return $resultmsg;
    }

    public function getDNS($params)
    {
        return ;
    }

    public function setDNS($params)
    {
        return ;
    }

    // ------------------------------------------------------------------
    // Nameservers
    // ------------------------------------------------------------------

    public function getNameServers($params)
    {
        $info = [];
        $name_servers = $this->getDomainInfoDetails($params)['name_servers'];
        if ($name_servers) {
            foreach ($name_servers as $ns) {
                $info[] = $ns["name"];
            }
        }
        return $info;
    }

    public function setNameServers($params)
    {
        $domainNameId = $this->getDomainInfoDetails($params)['id'];

        $domainorder = ['name_servers' => []];
        foreach ((array)$params['ns'] as $ns) {
            if (is_array($ns)) {
                $ns = $ns['hostname'] ?? '';
            }
            $ns = trim((string)$ns);
            if ($ns !== '') {
                $domainorder['name_servers'][] = ['name' => $ns];
            }
        }
        if (count($domainorder['name_servers']) === 0) {
            return;
        }

        $this->putDataRequest('domains/' . $domainNameId, $domainorder);
        $this->clearDomainCache($params);
    }

    // ------------------------------------------------------------------
    // Import domains
    // ------------------------------------------------------------------

    public function fetchDomains($params)
    {
        $domainsList = [];
        $limit = 500;
        $offset = 0;
        $total = 0;

        do {
            $response = $this->getDataRequest('domains?limit=' . $limit . '&offset=' . $offset);
            $total = (int)($response['total'] ?? 0);
            $results = $response['results'] ?? [];

            foreach ($results as $domain) {
                $exp = $domain['expiration_date'] ?? '';
                $domainsList[] = [
                    'id'  => (int)$domain['id'],
                    'sld' => $domain['domain']['name'],
                    'tld' => $domain['domain']['extension'],
                    'exp' => $exp ? $this->formatDate($exp) : '',
                ];
            }
            $offset += $limit;
        } while (count($results) > 0 && $offset < $total);

        $metaData = [];
        $metaData['total'] = $total;
        $metaData['next'] = 0;
        $metaData['start'] = 0;
        $metaData['end'] = $total;
        $metaData['numPerPage'] = $total;
        return [$domainsList, $metaData];
    }

    // ------------------------------------------------------------------
    // Import TLDs and prices
    // ------------------------------------------------------------------

    /**
     * Returns $tlds[$tld]['pricing'] with register, transfer and renew always set, as importtldsAction()
     * reads all three. Prices are the reseller cost in the Openprovider account currency; the controller
     * saves them as the CE default currency, so the two must match.
     */
    public function getTLDsAndPrices($params)
    {
        // The full list is several thousand TLDs; don't let PHP or a closed browser cut it off
        @set_time_limit(300);
        @ignore_user_abort(true);

        $only = $this->getTldFilter();
        $usePromo = (bool)$this->settings->get('plugin_Openprovider_Import promotional prices');
        $defaultCurrency = strtoupper((string)$this->settings->get('Default Currency'));
        $tlds = [];
        $promoCount = 0;

        foreach ($this->fetchAllTlds() as $row) {
            if (empty($row['name']) || empty($row['prices'])) {
                continue;
            }
            if (isset($row['status']) && !in_array($row['status'], ['ACT', 'GAV'], true)) {
                continue;
            }

            $tld = strtolower(ltrim($row['name'], '.'));

            // IDN TLDs are not supported
            if (substr($tld, 0, 4) === 'xn--') {
                continue;
            }
            if ($only && !isset($only[$tld])) {
                continue;
            }

            $prices = $row['prices'];
            // reseller_price is the cost to register; create_price is Openprovider's retail price
            [$register, $currency] = $this->extractPrice($prices, ['reseller_price', 'create_price'], false, $usePromo);
            if ($register === null || $register <= 0) {
                continue;
            }

            if ($currency !== null && $defaultCurrency !== '' && strtoupper($currency) !== $defaultCurrency) {
                throw new CE_Exception($this->user->lang('Openprovider prices are in %s but your default currency is %s. Prices were not imported.', strtoupper($currency), $defaultCurrency));
            }

            if ($usePromo && isset($prices['reseller_price']['promotion_details'])) {
                $promoCount++;
            }

            [$renew] = $this->extractPrice($prices, ['renew_price']);
            [$transfer] = $this->extractPrice($prices, ['transfer_price'], true);

            $tlds[$tld] = $this->formatPricing($register, $transfer, $renew);
        }

        CE_Lib::log(4, 'Openprovider price import: ' . count($tlds) . ' TLDs, ' . $promoCount . ' using a promotional registration price');
        return $tlds;
    }

    /**
     * Optional "TLDs to import" setting as a lookup array. Empty means no filter.
     */
    private function getTldFilter(): array
    {
        $raw = (string)$this->settings->get('plugin_Openprovider_TLDs to import');
        $filter = [];
        foreach (preg_split('/[\s,;]+/', strtolower($raw)) as $tld) {
            $tld = ltrim(trim($tld), '.');
            if ($tld !== '') {
                $filter[$tld] = true;
            }
        }
        return $filter;
    }

    /**
     * Fetch the whole TLD list. The first page gives the total; the remaining pages are fetched in parallel.
     */
    protected function fetchAllTlds(): array
    {
        $limit = 500;
        $base = 'tlds?with_price=true&limit=' . $limit . '&offset=';

        $first = $this->getDataRequest($base . '0');
        $rows = $first['results'] ?? [];
        $total = (int)($first['total'] ?? 0);

        $endpoints = [];
        for ($offset = $limit; $offset < $total; $offset += $limit) {
            $endpoints[] = $base . $offset;
        }

        foreach ($this->fetchPagesParallel($endpoints) as $page) {
            if (!empty($page['results'])) {
                $rows = array_merge($rows, $page['results']);
            }
        }
        return $rows;
    }

    private function fetchPagesParallel(array $endpoints): array
    {
        $pages = [];
        foreach (array_chunk($endpoints, 5, true) as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $i => $endpoint) {
                $handles[$i] = $this->buildCurl($endpoint);
                curl_multi_add_handle($mh, $handles[$i]);
            }

            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running && $status === CURLM_OK);

            foreach ($handles as $i => $ch) {
                $body = curl_multi_getcontent($ch);
                $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);

                $decoded = json_decode((string)$body, true);
                if ($http === 200 && is_array($decoded) && isset($decoded['code']) && (string)$decoded['code'] === '0') {
                    $pages[$i] = $decoded['data'] ?? [];
                } else {
                    // Retry through the normal path, which handles an expired token and raises a proper error
                    $pages[$i] = $this->getDataRequest($endpoints[$i]);
                }
            }
            curl_multi_close($mh);
        }
        return $pages;
    }

    /**
     * Returns [price, currency] from the reseller price (account currency). The product price is in the
     * registry's currency and is never used. With $usePromo off, a promotional price is replaced by the
     * regular one.
     */
    private function extractPrice(array $prices, array $keys, bool $allowZero = false, bool $usePromo = false): array
    {
        foreach ($keys as $key) {
            if (!isset($prices[$key]) || !is_array($prices[$key])) {
                continue;
            }
            $node = $prices[$key];
            if (!$usePromo && isset($node['promotion_details']['non_promo_price']['reseller']['price'])) {
                $node = $node['promotion_details']['non_promo_price'];
            }
            if (!isset($node['reseller']['price']) || !is_numeric($node['reseller']['price'])) {
                continue;
            }
            $price = (float)$node['reseller']['price'];
            if ($price <= 0 && !$allowZero) {
                continue;
            }
            return [$price, $node['reseller']['currency'] ?? null];
        }
        return [null, null];
    }

    /**
     * importtldsAction() reads register, transfer and renew without checking them, so all three are
     * always set: a missing renew price falls back to register, a missing transfer price to renew.
     */
    private function formatPricing(float $register, ?float $transfer, ?float $renew): array
    {
        $renew = $renew ?? $register;
        $transfer = $transfer ?? $renew;

        return [
            'pricing' => [
                'register' => $register,
                'transfer' => $transfer,
                'renew'    => $renew,
            ],
        ];
    }

    public function canAutoRenewDomain($params): bool
    {
        try {
            $domDetails = $this->getDomainInfoDetails($params);
        } catch (Exception $e) {
            CE_Lib::log(4, 'Openprovider: could not load domain details, blocking autorenew: ' . $e->getMessage());
            return false;
        }
        $status = (string)($domDetails['status'] ?? '');
        if (!empty($domDetails['is_deleted'])) {
            CE_Lib::log(4, 'Openprovider: domain is deleted, blocking autorenew. Status: ' . $status);
            return false;
        }

        // can_renew is Openprovider's own renewability flag
        // restores from redemption use a separate endpoint and stay manual
        if (!empty($domDetails['can_renew'])) {
            return true;
        }

        CE_Lib::log(4, 'Openprovider: domain is not renewable, blocking autorenew. Status: ' . $status);
        return false;
    }
}
