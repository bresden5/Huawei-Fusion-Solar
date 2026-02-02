<?php

class HuaweiFusionSolar extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // === Profile ===
        $this->RegisterProfiles();

        // === Properties ===
        $this->RegisterPropertyString("Username", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyInteger("UpdateInterval", 300);

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

        $interval = $this->ReadPropertyInteger("UpdateInterval") * 1000;
        $this->SetTimerInterval("UpdateTimer", $interval);
    }

    // ================= ACTION ROUTING =================

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Update') {
            $this->Update();
        }
    }

    // ================= UPDATE =================

    public function Update()
    {
        try {
            if ($this->GetBuffer("Token") === "") {
                $this->Login();
            }

            $stationId = $this->GetStationId();
            $devices   = $this->GetDevices($stationId);

            $pvTotal   = 0.0;
            $gridPower = 0.0;
            $batPower  = 0.0;
            $batSOC    = 0.0;

            foreach ($devices as $dev) {

                $real = $this->GetDeviceRealTime($dev['id']);
                $map  = $real['data'][0]['dataItemMap'] ?? [];

                switch ($dev['devTypeId']) {

                    // Wechselrichter
                    case 1:
                        $power = (float)($map['active_power'] ?? 0);
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
                        $batPower = (float)($map['charge_discharge_power'] ?? 0);
                        break;

                    // Netz
                    case 47:
                        $gridPower = (float)($map['active_power'] ?? 0);
                        break;
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
        $response = $this->ApiRequest("/thirdData/login", [
            "userName"   => $this->ReadPropertyString("Username"),
            "systemCode" => $this->ReadPropertyString("Password")
        ]);

        if (!isset($response['data'])) {
            throw new Exception("FusionSolar Login fehlgeschlagen");
        }

        $this->SetBuffer("Token", $response['data']);
    }

    private function GetStationId(): string
    {
        if ($this->GetBuffer("StationID") !== "") {
            return $this->GetBuffer("StationID");
        }

        $response = $this->ApiRequest("/thirdData/getStationList");

        if (!isset($response['data'][0]['stationCode'])) {
            throw new Exception("Keine Station gefunden");
        }

        $stationId = $response['data'][0]['stationCode'];
        $this->SetBuffer("StationID", $stationId);

        return $stationId;
    }

    private function GetDevices(string $stationId): array
    {
        $response = $this->ApiRequest("/thirdData/getDevList", [
            "stationCodes" => [$stationId]
        ]);

        return $response['data'] ?? [];
    }

    private function GetDeviceRealTime(string $devId): array
    {
        return $this->ApiRequest("/thirdData/getDevRealKpi", [
            "devIds" => [$devId]
        ]);
    }

    private function ApiRequest(string $endpoint, array $payload = []): array
    {
        $url   = "https://eu5.fusionsolar.huawei.com" . $endpoint;
        $token = $this->GetBuffer("Token");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                "Content-Type: application/json",
                "XSRF-TOKEN: " . $token
            ]
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if (($data['failCode'] ?? 0) === 305) {
            $this->SetBuffer("Token", "");
            $this->Login();
            return $this->ApiRequest($endpoint, $payload);
        }

        return $data ?? [];
    }
}
