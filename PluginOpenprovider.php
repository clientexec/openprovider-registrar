<?php

require_once 'modules/admin/models/RegistrarPlugin.php';

class PluginOpenprovider extends RegistrarPlugin
{
    public $features = [
        'nameSuggest' => true,
        'importDomains' => true,
        'importPrices' => false,
    ];

    private $liveUrl = 'https://api.openprovider.eu/v1beta';
    private $sandBox = 'http://api.sandbox.openprovider.nl:8480/v1beta';
  //  private $recordTypes = array('A', 'AAAA', 'MX', 'CNAME', 'NS', 'TXT', 'SRV', 'SOA');

    public function getVariables()
    {
        $variables = array(
            lang('Plugin Name') => array (
              'type'          => 'hidden',
              'description'   => lang('How CE sees this plugin (not to be confused with the Signup Name)'),
              'value'         => lang('Openprovider'),
            ),
            lang('Use testing server')  => array(
              'type'          => 'yesno',
              'description'   => lang('Select Yes if you wish to use Openprovider\'s testing environment, so that transactions are not actually made. For this to work, you must first register you server\'s ip in Openprovider\'s testing environment (https://cp.sandbox.openprovider.nl), Below in username and password file you need to enter test environment credentials, whereas for live you need to untick this and provide actual user details below.'),
              'value'         => '',
            ),
            lang('Username') => array(
              'type'          => 'text',
              'description'   => lang('Enter your email address for your Openprovider account.'),
              'value'         => ''
            ),
            lang('Password') => array(
              'type'          => 'text',
              'description'   => lang('Enter your password for your Openprovider account.'),
              'value'         => ''
            ),
            lang('Supported Features')  => array(
              'type'          => 'label',
              'description'   => '* ' . lang('TLD Lookup') . '<br>* ' . lang('Domain Registration') . ' <br>* ' . lang('Get / Set DNS Records') . ' <br>* ' . lang('Get / Set Nameserver Records') . ' <br>* ' . lang('Get / Set Contact Information') . ' <br>* ' . lang('Get / Set Registrar Lock') . ' <br>* ' . lang('Initiate Domain Transfer'),
              'value'         => ''
            ),
            lang('Actions') => array (
              'type'          => 'hidden',
              'description'   => lang('Current actions that are active for this plugin (when a domain isn\'t registered)'),
              'value'         => 'Register'
            ),
            lang('Registered Actions') => array (
              'type'          => 'hidden',
              'description'   => lang('Current actions that are active for this plugin (when a domain is registered)'),
              'value'         => 'Renew (Renew Domain),DomainTransferWithPopup (Initiate Transfer),Cancel',
            ),
            lang('Registered Actions For Customer') => array (
              'type'          => 'hidden',
              'description'   => lang('Current actions that are active for this plugin (when a domain is registered)'),
              'value'         => '',
            )
        );

        return $variables;
    }

//working
    public function findHandleByDomName($domainName)
    {
        $getDomain = $this->getDataRequest('domains?full_name=' . $domainName);
        if ($getDomain['results']) {
            return $getDomain['results'][0];
        } else {
            return '';
        }
    }

//Working
    public function doRegister($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $orderid = $this->registerDomain($this->buildRegisterParams($userPackage, $params));
        if ($orderid['result'] == 'success') {
            $userPackage->setCustomField("Registration Status", "Registered");
            $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar") . '_-_' . $orderid['domainId'] . '_-_' . $orderid['handleId']); // store user pattern
            return $userPackage->getCustomField('Domain Name') . ' has been registered.';
        } else {
            return $userPackage->getCustomField('Domain Name') . ' not registered.';
        }
    }

//Working
    public function registerDomain($params)
    {
        $domDetails = $this->findHandleByDomName($params["sld"] . '.' . $params["tld"]);
        $handleId = $domDetails['owner_handle'];

        $tld = $params["tld"];
        $sld = $params["sld"];

        $nameservers = [];
        if (isset($params['NS1'])) {
            for ($i = 1; $i <= 12; $i++) {
                if (isset($params["NS$i"])) {
                    $nameservers[] = $params["NS$i"]['hostname'];
                } else {
                    break;
                }
            }
        } else {
            $nameservers[] = 'ns1.op.eu';
            $nameservers[] = 'ns2.op.nl';
        }


        if ($handleId) {
            $crequestData = $this->getDataRequest('customers/' . $handleId);
            $handleID = $crequestData['handle'];
            $domainorder['admin_handle'] = $handleID;
            $domainorder['billing_handle'] = $handleID;
            $domainorder['owner_handle'] = $handleID;
            $domainorder['tech_handle'] = $handleID;
            $domainorder['domain']['name'] = $sld;
            $domainorder['domain']['extension'] = $tld;
            $domainorder['period'] = "1";
            $domainorder['autorenew'] = "off";
            foreach ($nameservers as $id => $nameserver) {
                $domainorder['name_servers'][$id]['name'] = $nameserver;
            }

            $orderRes = $this->postDataRequest('domains', $domainorder);
            if ($orderRes['code'] == "0") {
                $values["result"] = "success";
                $values["domainId"] = $orderRes['id'];
                $values["handleId"] = $handleID;
            }
        } else {
            $eMail = $params["RegistrantEmailAddress"];
            $companyname = $params["RegistrantOrganizationName"];
            $firstname = $params["RegistrantFirstName"];
            $lastname = $params["RegistrantLastName"];
            $address1 = $params["RegistrantAddress1"];
            $address2 = $params["RegistrantAddress2"];
            $countryname = $params["RegistrantCountry"];
            $state = $params["RegistrantStateProvince"];
            $city = $params["RegistrantCity"];
            $postcode = $params["RegistrantPostalCode"];
            $phonecc = $this->countryCodePhone($params['RegistrantCountry']);
            $phonenumber = $params['RegistrantPhone'];

            $AddClient['address']['street'] = $address1 . ' ' . $address2;
            $AddClient['address']['number'] = "";
            $AddClient['address']['city'] = $city;
            $AddClient['address']['zipcode'] = $postcode;
            $AddClient['address']['state'] = $state;
            $AddClient['address']['country'] = $countryname;

            $AddClient['phone']['subscriber_number'] = $phonenumber;
            $AddClient['phone']['area_code'] = "0";
            $AddClient['phone']['country_code'] = "+" . $phonecc;

            $AddClient['email'] = $eMail;
            $AddClient['username'] = $eMail;
            $AddClient['password'] = $sld;
            $AddClient['role'] = "tech";

            $AddClient['name']['first_name'] = $firstname;
            $AddClient['name']['full_name'] = $firstname . ' ' . $lastname;
            $AddClient['name']['last_name'] = $lastname;
            //  $AddClient['name']['prefix'] = "";
            //  $AddClient['name']['initials'] = "";

            $addClientRes = $this->postDataRequest('customers', $AddClient);
            if ($addClientRes['handle']) {
                $handleID = $addClientRes['handle'];
                $domainorder['admin_handle'] = $handleID;
                $domainorder['billing_handle'] = $handleID;
                $domainorder['owner_handle'] = $handleID;
                $domainorder['tech_handle'] = $handleID;
                $domainorder['domain']['name'] = $sld;
                $domainorder['domain']['extension'] = $tld;
                $domainorder['period'] = "1";
                $domainorder['autorenew'] = "off"; //default
                foreach ($nameservers as $id => $nameserver) {
                    $domainorder['name_servers'][$id]['name'] = $nameserver;
                }

                $orderRes = $this->postDataRequest('domains', $domainorder);
                if ($orderRes['code'] == "0") {
                    $values["result"] = "success";
                    $values["domainId"] = $orderRes['id'];
                    $values["handleId"] = $handleID;
                }
            }
        }

        return $values;
    }

//working
    private function countryCodePhone($country)
    {
        $query = "SELECT phone_code FROM country WHERE iso=? AND phone_code != ''";
        $result = $this->db->query($query, $country);
        $row = $result->fetch();
        if ($row['phone_code']) {
            $phoneCode = $row['phone_code'];
        } else {
            $query = "SELECT phone_code FROM country WHERE name=? AND phone_code != ''";
            $result = $this->db->query($query, $country);
            $row = $result->fetch();
            if ($row['phone_code']) {
                $phoneCode = $row['phone_code'];
            } else {
                $phoneCode = "";
            }
        }
        return $phoneCode;
    }

//working
    public function doRenew($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $orderid = $this->registerDomain($this->buildRegisterParams($userPackage, $params));
        if ($orderid['result'] == 'success') {
            return $userPackage->getCustomField('Domain Name') . ' has been renewed.';
        } else {
            return $userPackage->getCustomField('Domain Name') . ' not renewed.';
        }
    }

//working but in sandbox its not available
    public function renewDomain($params)
    {
        if (@$this->settings->get('plugin_Openprovider_Use testing server')) {
            $values['result'] = '';
        } else {
            $domDetails = $this->findHandleByDomName($params["sld"] . '.' . $params["tld"]);
            $domainId = $domDetails['id'];

            $renewPeriod['period'] = "1";

            $orderRes = $this->postDataRequest('domains/' . $domainId . '/renew', $renewPeriod);
            if ($orderRes['code'] == "0") {
                $values["result"] = "success";
            }
        }
        return $values;
    }

//DOne -working
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

//DOne -working
    public function initiateTransfer($params)
    {
        $domDetails = $this->findHandleByDomName($params["sld"] . '.' . $params["tld"]);
        $handleId = $domDetails['owner_handle'];

        $authCode = $params["eppCode"];
        $tld = $params["tld"];
        $sld = $params["sld"];
        if (isset($params['NS1'])) {
            for ($i = 1; $i <= 12; $i++) {
                if (isset($params["NS$i"])) {
                    $nameservers[] = $params["NS$i"]['hostname'];
                } else {
                    break;
                }
            }
        } else {
            $nameservers[] = 'ns1.op.eu';
            $nameservers[] = 'ns2.op.nl';
        }

        if (!$handleId) {
            $eMail = $params["RegistrantEmailAddress"];
            $companyname = $params["RegistrantOrganizationName"];
            $firstname = $params["RegistrantFirstName"];
            $lastname = $params["RegistrantLastName"];
            $address1 = $params["RegistrantAddress1"];
            $address2 = $params["RegistrantAddress2"];
            $countryname = $params["RegistrantCountry"];
            $state = $params["RegistrantStateProvince"];
            $city = $params["RegistrantCity"];
            $postcode = $params["RegistrantPostalCode"];
            $phonecc = $this->countryCodePhone($params['RegistrantCountry']);
            $phonenumber = $params['RegistrantPhone'];

            $AddClient['address']['street'] = $address1 . ' ' . $address2;
            $AddClient['address']['number'] = "";
            $AddClient['address']['city'] = $city;
            $AddClient['address']['zipcode'] = $postcode;
            $AddClient['address']['state'] = $state;
            $AddClient['address']['country'] = $countryname;

            $AddClient['phone']['subscriber_number'] = $phonenumber;
            $AddClient['phone']['area_code'] = "0";
            $AddClient['phone']['country_code'] = "+" . $phonecc;

            $AddClient['email'] = $eMail;
            $AddClient['username'] = $eMail;
            $AddClient['password'] = $sld;
            $AddClient['role'] = "tech";

            $AddClient['name']['first_name'] = $firstname;
            $AddClient['name']['full_name'] = $firstname . ' ' . $lastname;
            $AddClient['name']['last_name'] = $lastname;
            //    $AddClient['name']['prefix'] = "Mr.";
            //    $AddClient['name']['initials'] = "";

            $addClientRes = $this->postDataRequest('customers', $AddClient);
            if ($addClientRes['handle']) {
                $handleID = $addClientRes['handle'];
                $domainorder['admin_handle'] = $handleID;
                $domainorder['auth_code'] = $authCode;
                $domainorder['billing_handle'] = $handleID;
                $domainorder['owner_handle'] = $handleID;
                $domainorder['tech_handle'] = $handleID;
                $domainorder['domain']['name'] = $sld;
                $domainorder['domain']['extension'] = $tld;
                $domainorder['autorenew'] = "off"; //default
                foreach ($nameservers as $id => $nameserver) {
                    $domainorder['name_servers'][$id]['name'] = $nameserver;
                }

                $orderRes = $this->postDataRequest('domains/transfer', $domainorder);

                if ($orderRes['code'] == "0") {
                    $values["result"] = "success";
                    $values["domainId"] = $orderRes['id'];
                    $values["handleId"] = $handleID;
                }
            }
        } else {
            $crequestData = $this->getDataRequest('customers/' . $handleId);
            $handleID = $crequestData['handle'];
            $domainorder['admin_handle'] = $handleID;
            $domainorder['auth_code'] = $authCode;
            $domainorder['billing_handle'] = $handleID;
            $domainorder['owner_handle'] = $handleID;
            $domainorder['tech_handle'] = $handleID;
            $domainorder['domain']['name'] = $sld;
            $domainorder['domain']['extension'] = $tld;
            $domainorder['autorenew'] = "off"; //default
            foreach ($nameservers as $id => $nameserver) {
                $domainorder['name_servers'][$id]['name'] = $nameserver;
            }

            $orderRes = $this->postDataRequest('domains/transfer', $domainorder);

            if ($orderRes['code'] == "0") {
                  $values["result"] = "success";
                  $values["domainId"] = $orderRes['id'];
                  $values["handleId"] = $handleID;
            }
        }

            return $values;
    }

//Working
    public function getDomainInfoDetails($params)
    {
        $domDetails = $this->findHandleByDomName($params["sld"] . '.' . $params["tld"]);
        $handleId = $domDetails['owner_handle'];
        $domainId = $domDetails['id'];
        return $this->getDataRequest('domains/' . $domainId);
    }

//Working
    public function getRegistrarLock($params)
    {
        return (($this->getDomainInfoDetails($params)["is_locked"]) ? true : false);
    }

//Working
    public function doSetRegistrarLock($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->setRegistrarLock($this->buildLockParams($userPackage, $params));
        return "Updated Registrar Lock.";
    }

//Working
    public function setRegistrarLock($params)
    {
        $domDetails = $this->getDomainInfoDetails($params);
        $requestData['is_locked'] = ($domDetails["is_locked"] == "1" ? false : true);
        $manageRes = $this->putDataRequest('domains/' . $domDetails['id'], $requestData);
        if ($manageRes["code"] != '0') {
            $values["error"] = $manageRes["desc"];
        }
        return $values;
    }

//Working
    public function doSendTransferKey($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->sendTransferKey($this->buildRegisterParams($userPackage, $params));
        return 'Successfully sent auth info for ' . $userPackage->getCustomField('Domain Name');
    }

//Working
    public function sendTransferKey($params)
    {
    }

//Working
    public function setAutorenew($params)
    {
        //throw new Exception('This function is not supported');
    }

  //Working
    public function getEPPCode($params)
    {
        $res = $this->getDomainInfoDetails($params)['auth_code'];
        if ($res) {
            return $res;
        }
    }

//working
    public function checkDomain($params)
    {
        $arguments['with_price'] = true;
        $arguments['domains'][0] = [
          'extension' => $params['tld'],
          'name' => $params["sld"]
        ];
        $res = $this->postDataRequest('domains/check', $arguments);
        if ($res["results"][0]['status'] == "free") { //Domain Available for registration
            $domains[] = array('tld' => $params['tld'], 'domain' => $params['sld'], 'status' => 0);
        } else {
            $domains[] = array('tld' => $params['tld'], 'domain' => $params['sld'], 'status' => 1);
        }
        return array("result" => $domains);
    }

//working
    private function makeRequest($reqURLs, $method = 'GET', $data = false)
    {
        if (@$this->settings->get('plugin_Openprovider_Use testing server')) {
            $reqURLs = $this->sandBox . '/' . $reqURLs;
        } else {
            $reqURLs = $this->liveUrl . '/' . $reqURLs;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $reqURLs);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $caPathOrFile = \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
        if (is_dir($caPathOrFile)) {
            curl_setopt($ch, CURLOPT_CAPATH, $caPathOrFile);
        } else {
            curl_setopt($ch, CURLOPT_CAINFO, $caPathOrFile);
        }

        $headers = array();
        $headers[] = 'Authorization: Bearer ' . $this->logInRequest();
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $data = json_encode($data);
        CE_Lib::log(4, "CURL request data for $reqURLs: $data");

        switch ($method) {
            case 'GET':
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        $response = curl_exec($ch);

        CE_Lib::log(4, $response);

        if (curl_errno($ch)) {
            throw new CE_Exception('Openprovider connection error: ' . curl_error($ch));
        }

        return json_decode($response, true);
    }

////working
    public function getDataRequest($endPoint)
    {
        $requestData = $this->makeRequest($endPoint);
        if ($requestData['code'] == '0') {
            return $requestData['data'];
        } else {
            throw new CE_Exception($requestData['desc']);
        }
    }

//working
    public function putDataRequest($endPoint, $PutData)
    {
        $requestData = $this->makeRequest($endPoint, "PUT", $PutData);
        if ($requestData['code'] == '0') {
            return $requestData['data'];
        } else {
            throw new CE_Exception($requestData['desc']);
        }
    }

//working
    public function postDataRequest($endPoint, $PostData)
    {
        $requestData = $this->makeRequest($endPoint, "POST", $PostData);
        if ($requestData['code'] == '0') {
            return $requestData['data'];
        } else {
            throw new CE_Exception($requestData['desc']);
        }
    }

//working
    public function logInRequest()
    {
        if (@$this->settings->get('plugin_Openprovider_Use testing server')) {
            $url = $this->sandBox . '/auth/login';
        } else {
            $url = $this->liveUrl . '/auth/login';
        }

        $loginData['username'] = @$this->settings->get('plugin_Openprovider_Username');
        $loginData['password'] = @$this->settings->get('plugin_Openprovider_Password');
        $loginData['ip'] = CE_Lib::getServerAddr();

        $headers = array();
        $headers[] = 'Content-Type: application/json';

        $requestData = NE_Network::curlRequest($this->settings, $url, json_encode($loginData), $headers);

        if ($requestData instanceof CE_Error) {
            throw new CE_Exception($requestData);
        }
        $requestData = json_decode($requestData, true);
        CE_Lib::log(4, $requestData);

        if ($requestData['data']['token']) {
            return $requestData['data']['token'];
        } else {
            throw new CE_Exception($requestData['desc']);
        }
    }

//working
    public function getContactInformation($params)
    {
        $domDetails = $this->findHandleByDomName($params["sld"] . '.' . $params["tld"]);
        $handleId = $domDetails['owner_handle'];
        $contact = $this->getDataRequest('customers/' . $handleId);

        $info = [];
        foreach (array('Registrant', 'AuxBilling', 'Admin', 'Tech') as $type) {
            $result = $this->db->query("SELECT name FROM country WHERE iso=?", $contact['address']['country']);
            $row = $result->fetch();
            if ($row['name']) {
                $country = $row['name'];
            } else {
                $country = $contact['address']['country'];
            }

              $info[$type]['OrganizationName']  = array($this->user->lang('Organization'), (string)$contact['company_name']);
              $info[$type]['FirstName'] = array($this->user->lang('First Name'), (string)$contact['name']['first_name']);
              $info[$type]['LastName']  = array($this->user->lang('Last Name'), (string)$contact['name']['last_name']);
              $info[$type]['Address1']  = array($this->user->lang('Address') . ' 1', (string)$contact['address']['street']);
              $info[$type]['City']      = array($this->user->lang('City'), (string)$contact['address']['city']);
              $info[$type]['StateProvince']  = array($this->user->lang('Province') . '/' . $this->user->lang('State'), (string)$contact['address']['state']);
              $info[$type]['Country']   = array($this->user->lang('Country'), (string)$country);
              $info[$type]['PostalCode']  = array($this->user->lang('Postal Code'), (string)$contact['address']['zipcode']);
              $info[$type]['EmailAddress']     = array($this->user->lang('Email'), (string)$contact['email']);
              $info[$type]['Phone']  = array($this->user->lang('Phone'), $contact['phone']['country_code'] . $contact['phone']['subscriber_number']);
        }
        return $info;
    }

//working
    public function setContactInformation($params)
    {
        $result = $this->db->query("SELECT iso FROM country WHERE name=?", $params['Registrant_Country']);
        $row = $result->fetch();
        if ($row['iso']) {
            $country = $row['iso'];
        } else {
            $country = $params['Registrant_Country'];
        }

        $domDetails = $this->findHandleByDomName($params["sld"] . '.' . $params["tld"]);
        $handleId = $domDetails['owner_handle'];
        $countryCode = $this->countryCodePhone($params['Registrant_Country']);
        $Phone = explode($countryCode, $params['Registrant_Phone']);
        $PHone_NO = (($Phone[1]) ? $Phone[1] : $params['Registrant_Phone']);

        $contactData['address']['city'] = $params['Registrant_City'];
        $contactData['address']['country'] = $country;
        $contactData['address']['street'] = $params['Registrant_Address1'];
        $contactData['address']['state'] = $params['Registrant_StateProvince'];
        $contactData['address']['zipcode'] = $params['Registrant_PostalCode'];
        $contactData['email'] = $params['Registrant_EmailAddress'];
        $contactData['phone']['country_code'] = "+" . $countryCode;
        $contactData['phone']['subscriber_number'] = trim($PHone_NO);
        $contactData['phone']['area_code'] = "0";
        return $this->putDataRequest('customers/' . $handleId, $contactData);
    }

//working
    public function getGeneralInfo($params)
    {
        $res = $this->getDomainInfoDetails($params);
        if ($res['id']) {
            $data = [];
            $data['id'] = (int)$res["id"];
            $data['domain'] = (string)$res["domain"]['name'] . '.' . $res["domain"]['extension'];
            $data['expiration'] = ($res['expiration_date'] ? $res["expiration_date"] : 'N/A');
            $data['registrationstatus'] = $res["status"];
            $data['purchasestatus'] = $res["status"];
            $data['autorenew'] = false;
            $data['eppCode'] = $res["auth_code"];
            return $data;
        }
    }

//working
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

//Done
    public function getTLDsAndPrices($params)
    {
        return [];
    }

    public function getDNS($params)
    {
        return ;
    }

    public function setDNS($params)
    {
        return ;
    }

//working
    public function getNameServers($params)
    {
              $name_servers = $this->getDomainInfoDetails($params)['name_servers'];
        if ($name_servers) {
            $info = [];
            foreach ($name_servers as $key => $ns) {
                          $info[] = $ns["name"];
            }
        } else {
            $info = [];
        }
              return $info;
    }
//working
    public function setNameServers($params)
    {
        $domainNameId = $this->getDomainInfoDetails($params)['id'];
        $Nameservers = implode(',', $params['ns']);
        $Nameservers_explide = explode(',', $Nameservers);

        if ($Nameservers_explide[0]) {
            $domainorder['name_servers'][0]['name'] = $Nameservers_explide[0];
        }
        if ($Nameservers_explide[1]) {
            $domainorder['name_servers'][1]['name'] = $Nameservers_explide[1];
        }

        $manageRes = $this->putDataRequest('domains/' . $domainNameId, $domainorder);
        if ($manageRes["code"] != '0') {
              $values = $manageRes["desc"];
        }
            return $valueserror;
    }

    public function fetchDomains($params)
    {
        $domainList = [];
        $response = $this->getDataRequest('domains');
        $total = $response['data']['total'];
        foreach ($response['data']['results'] as $domain) {
            CE_Lib::log(4, "DomId: " . $domain['id']);
            $data = [];
            $data['id'] = (int)$domain['id'];
            $data['sld'] = $domain['domain']['name'];
            $data['tld'] = $domain['domain']['extension'];
            $data['exp'] = $domain['expiration_date'];
            $domainsList[] = $data;
        }
        $metaData = array();
        $metaData['total'] = $total;
        $metaData['next'] = 0;
        $metaData['start'] = 0;
        $metaData['end'] = $total;
        $metaData['numPerPage'] = $total;
        return array($domainsList, $metaData);
    }
}
