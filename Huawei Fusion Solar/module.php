<?php

require_once __DIR__ . "/../libs/WebSession.php";

class HuaweiFusionSolar extends IPSModule
{
    use FusionSolarWebSession;
    public function Create()
    {
        parent::Create();

        // === Profile ===
        $this->RegisterProfiles();

        // === Properties ===
        $this->RegisterPropertyString('AccessMode', 'api');
        $this->RegisterPropertyString('WebOrigin', 'https://uni004eu5.fusionsolar.huawei.com');
        $this->RegisterPropertyString('WebStation', '');
        $this->RegisterPropertyBoolean('WebInverterDetails', false);
        $this->RegisterAttributeString('WebInverterIDs', '[]');
        $this->RegisterVariableBoolean('WebInvertersValid', 'Wechselrichterdetails gültig');
        $this->RegisterAttributeString('WebSession', '');
        $this->RegisterVariableBoolean('WebDataValid', 'Website-Werte gültig');
        $this->RegisterVariableInteger('LastWebSuccess', 'Letzter Website-Abruf', '~UnixTimestamp');
        $this->RegisterVariableString('ConnectionStatus', 'Verbindungsstatus');
        $this->RegisterPropertyString("BaseUrl", "https://eu5.fusionsolar.huawei.com");
        $this->RegisterPropertyString("Username", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyString("StationCode", "");
        $this->RegisterPropertyInteger("UpdateInterval", 300);

        // === Attributes ===
        $this->RegisterAttributeString("Token", "");
        $this->RegisterAttributeString("StationID", "");

        // === Timer (KORREKT & SYMCON-SAFE) ===
        $this->RegisterTimer(
            "UpdateTimer",
            0,
            "IPS_RequestAction(" . $this->InstanceID . ", 'Update', 0);"
        );

        // === Variablen ===
        $this->RegisterVariableFloat("TotalPV", "PV Gesamtleistung", "~Watt");
        $this->RegisterVariableFloat("HouseConsumption", "Hausverbrauch", "~Watt");

        $this->RegisterVariableFloat("GridImport", "Netzbezug", "~Watt");
        $this->RegisterVariableFloat("GridExport", "Netzeinspeisung", "~Watt");

        $this->RegisterVariableFloat("BatterySOC", "Batterie Ladestand", "HFS.Percent");
        $this->RegisterVariableFloat("BatteryPower", "Batterie Leistung", "~Watt");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->InvalidateWebInverters();

        if ($this->ReadPropertyString('AccessMode') === 'web') {
            $this->ConfigureWeb();
            return;
        }
        $this->SetValue('WebDataValid', false);
        $interval = $this->ReadPropertyInteger("UpdateInterval") * 1000;
        if ($this->ReadPropertyString("Username") === "" || $this->ReadPropertyString("Password") === "") {
            $this->SetTimerInterval("UpdateTimer", 0);
            return;
        }

        $this->SetTimerInterval("UpdateTimer", $interval);
    }

    // ================= ACTION ROUTING =================

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'ImportWebSession') {
            $this->ImportWebSession($Value);
            return;
        }
        if ($Ident === 'ForgetWebSession') {
            $this->ForgetWebSession();
            return;
        }
        if ($Ident === 'Update') {
            $this->Update();
            return;
        }

        throw new Exception("Unbekannte Aktion: " . $Ident);
    }

    // ================= UPDATE =================

    public function Update()
    {
        if ($this->ReadPropertyString('AccessMode') === 'web') {
            $this->UpdateWeb();
            return;
        }
        try {
            if ($this->ReadAttributeString("Token") === "") {
                $this->Login();
            }

            $stationId = $this->GetStationId();
            $devices   = $this->GetDevices($stationId);

            $pvTotal   = 0.0;
            $gridPower = 0.0;
            $batPower  = 0.0;
            $batSOC    = 0.0;

            $devicesByType = [];
            $relevantDeviceTypes = [1, 17, 38, 39, 47];
            foreach ($devices as $dev) {
                if (!isset($dev['id'], $dev['devTypeId'])) {
                    continue;
                }

                $devTypeId = (int)$dev['devTypeId'];
                if (!in_array($devTypeId, $relevantDeviceTypes, true)) {
                    continue;
                }

                $devicesByType[$devTypeId][(string)$dev['id']] = $dev;
            }

            foreach ($devicesByType as $devTypeId => $typeDevices) {
                $real = $this->GetDeviceRealTime(array_keys($typeDevices), $devTypeId);

                foreach (($real['data'] ?? []) as $item) {
                    $devId = (string)($item['devId'] ?? '');
                    if ($devId === '' || !isset($typeDevices[$devId])) {
                        continue;
                    }

                    $dev = $typeDevices[$devId];
                    $map = $item['dataItemMap'] ?? [];

                    switch ($devTypeId) {

                    // Wechselrichter
                    case 1:
                    case 38:
                        $power = $this->KilowattToWatt($map['active_power'] ?? 0);
                        $pvTotal += $power;

                        $ident = "INV_" . $dev['id'];
                        if (@$this->GetIDForIdent($ident) === false) {
                            $this->RegisterVariableFloat(
                                $ident,
                                "WR " . $dev['devName'],
                                "~Watt"
                            );
                        }
                        $this->SetValue($ident, $power);
                        break;

                    // Batterie
                    case 39:
                        $batSOC   = (float)($map['soc'] ?? 0);
                        $batPower = $this->KilowattToWatt($map['charge_discharge_power'] ?? 0);
                        break;

                    // Netz
                    case 17:
                    case 47:
                        $gridPower = (float)($map['active_power'] ?? 0);
                        break;
                    }
                }
            }

            $this->SetValue("TotalPV", $pvTotal);
            $this->SetValue("BatterySOC", $batSOC);
            $this->SetValue("BatteryPower", $batPower);

            $this->SetValue("GridImport", max($gridPower, 0));
            $this->SetValue("GridExport", max(-$gridPower, 0));

            $house =
                $pvTotal
                + max($gridPower, 0)
                - max(-$gridPower, 0)
                - max($batPower, 0)
                + max(-$batPower, 0);

            $this->SetValue("HouseConsumption", $house);

        } catch (Throwable $e) {
            IPS_LogMessage("FusionSolar", $e->getMessage());
        }
    }

    // ================= PROFILES =================

    private function RegisterProfiles()
    {
        if (!IPS_VariableProfileExists("HFS.Percent")) {
            IPS_CreateVariableProfile("HFS.Percent", VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileValues("HFS.Percent", 0, 100, 1);
            IPS_SetVariableProfileText("HFS.Percent", "", " %");
        }
    }

    // ================= API =================

    private function Login()
    {
        $result = $this->ApiRequest("/thirdData/login", [
            "userName"   => $this->ReadPropertyString("Username"),
            "systemCode" => $this->ReadPropertyString("Password")
        ], false);

        $response = $result['body'];
        if (($response['failCode'] ?? null) !== 0) {
            throw new Exception("FusionSolar Login fehlgeschlagen: " . ($response['failCode'] ?? 'unbekannter Fehler'));
        }

        $token = $result['headers']['xsrf-token'] ?? $result['headers']['xsrf_token'] ?? '';
        if ($token === '') {
            throw new Exception("FusionSolar Login fehlgeschlagen: kein XSRF-TOKEN im Antwort-Header");
        }

        $this->WriteAttributeString("Token", $token);
    }

    private function GetStationId(): string
    {
        $configuredStationCode = trim($this->ReadPropertyString("StationCode"));
        if ($configuredStationCode !== "") {
            return $configuredStationCode;
        }

        if ($this->ReadAttributeString("StationID") !== "") {
            return $this->ReadAttributeString("StationID");
        }

        $response = $this->ApiRequest("/thirdData/stations", [
            "pageNo" => 1
        ])['body'];

        if (!isset($response['data']['list'][0]['plantCode'])) {
            throw new Exception("Keine Station gefunden");
        }

        $stationId = $response['data']['list'][0]['plantCode'];
        $this->WriteAttributeString("StationID", $stationId);

        return $stationId;
    }

    private function GetDevices(string $stationId): array
    {
        $response = $this->ApiRequest("/thirdData/getDevList", [
            "stationCodes" => $stationId
        ])['body'];

        return $response['data'] ?? [];
    }

    private function GetDeviceRealTime(array $devIds, int $devTypeId): array
    {
        return $this->ApiRequest("/thirdData/getDevRealKpi", [
            "devIds"    => implode(",", $devIds),
            "devTypeId" => $devTypeId
        ])['body'];
    }

    private function ApiRequest(string $endpoint, array $payload = [], bool $authenticated = true): array
    {
        $url     = rtrim($this->ReadPropertyString("BaseUrl"), "/") . $endpoint;
        $token   = $this->ReadAttributeString("Token");
        $headers = [
            "Content-Type: application/json"
        ];

        if ($authenticated) {
            if ($token === "") {
                $this->Login();
                $token = $this->ReadAttributeString("Token");
            }

            $headers[] = "XSRF-TOKEN: " . $token;
        }

        $ch = curl_init($url);
        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$responseHeaders) {
                $length = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            }
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("FusionSolar API-Aufruf fehlgeschlagen: " . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new Exception("FusionSolar API lieferte keine gueltige JSON-Antwort");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("FusionSolar API HTTP-Fehler: " . $httpCode);
        }

        if ($authenticated && ($data['failCode'] ?? 0) === 305) {
            $this->WriteAttributeString("Token", "");
            $this->Login();
            return $this->ApiRequest($endpoint, $payload);
        }

        if (($data['failCode'] ?? 0) !== 0) {
            throw new Exception("FusionSolar API-Fehler: " . $data['failCode']);
        }

        return [
            "body"    => $data,
            "headers" => $responseHeaders
        ];
    }

    private function KilowattToWatt($value): float
    {
        return (float)$value * 1000;
    }
}
