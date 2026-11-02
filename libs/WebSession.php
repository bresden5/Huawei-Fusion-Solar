<?php

trait FusionSolarWebSession
{
    private function WebConfiguration(): array
    {
        $origin = rtrim(trim($this->ReadPropertyString('WebOrigin')), '/');
        if (!preg_match('~^https://[a-z0-9-]+\.fusionsolar\.huawei\.com$~D', $origin)) {
            throw new RuntimeException('Website-Domain muss eine HTTPS-FusionSolar-Domain ohne Pfad sein.');
        }
        $station = trim($this->ReadPropertyString('WebStation'));
        if (!preg_match('/^NE=\d+$/D', $station)) {
            throw new RuntimeException('Website-Anlagenkennung im Format NE=123456 eintragen.');
        }
        return [$origin, $station];
    }

    private function WebStatus(string $message, int $status, bool $valid = false): void
    {
        $this->SetValue('WebDataValid', $valid);
        $this->SetValue('ConnectionStatus', $message);
        $this->SetStatus($status);
    }

    private function ConfigureWeb(): void
    {
        $this->SetTimerInterval('UpdateTimer', 0);
        try {
            [$origin, $station] = $this->WebConfiguration();
            $session = json_decode($this->ReadAttributeString('WebSession'), true);
            if (!is_array($session) || ($session['origin'] ?? '') !== $origin || ($session['station'] ?? '') !== $station) {
                $this->WriteAttributeString('WebSession', '');
                $this->WebStatus('Website-Sitzung erforderlich: Cookie und roarand importieren.', 201);
                return;
            }
            if ($session['paused'] ?? false) {
                $this->WebStatus('Abruf gestoppt. Sitzung erneuern oder Jetzt aktualisieren verwenden.', 202);
                return;
            }
            $this->WebStatus('Website-Sitzung bereit; Messwerte werden beim nächsten Abruf geprüft.', 102);
            $this->SetTimerInterval('UpdateTimer', max(300, $this->ReadPropertyInteger('UpdateInterval')) * 1000);
        } catch (Throwable $e) {
            $this->WebStatus('Website-Domain oder Anlagenkennung ungültig.', 202);
        }
    }

    private function ImportWebSession($value): void
    {
        if ($this->ReadPropertyString('AccessMode') !== 'web') {
            throw new RuntimeException('Zuerst Website-Modus auswählen und Änderungen übernehmen.');
        }
        $lock = 'HFSWeb' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) {
            throw new RuntimeException('Ein Abruf läuft bereits. Bitte kurz warten.');
        }
        try {
            [$origin, $station] = $this->WebConfiguration();
            $input = is_string($value) ? json_decode($value, true) : null;
            $cookie = is_array($input) && is_string($input['cookie'] ?? null) ? trim($input['cookie']) : '';
            $token = is_array($input) && is_string($input['token'] ?? null) ? trim($input['token']) : '';
            if ($cookie === '' || $token === '' || preg_match('/[\x00-\x1f\x7f]/', $cookie) || preg_match('/[\x00-\x20\x7f]/', $token)) {
                throw new RuntimeException('Cookie und roarand jeweils einzeilig und ohne Headernamen eintragen.');
            }
            $cookies = [];
            foreach (explode(';', $cookie) as $pair) {
                $parts = explode('=', trim($pair), 2);
                if (count($parts) !== 2 || !preg_match('/^[A-Za-z0-9_.-]+$/D', $parts[0])) {
                    throw new RuntimeException('Cookie-Format ungültig. Nur den Wert des Cookie-Headers verwenden.');
                }
                $cookies[] = parse_url($origin, PHP_URL_HOST) . "\tFALSE\t/\tTRUE\t0\t" . $parts[0] . "\t" . $parts[1];
            }
            $this->WriteAttributeString('WebSession', json_encode([
                'origin' => $origin, 'station' => $station, 'cookies' => $cookies,
                'token' => $token, 'paused' => false
            ], JSON_THROW_ON_ERROR));
            $this->UpdateFormField('CookieInput', 'value', '');
            $this->UpdateFormField('TokenInput', 'value', '');
            $this->ConfigureWeb();
        } finally {
            IPS_SemaphoreLeave($lock);
        }
        $this->UpdateWeb();
    }

    private function ForgetWebSession(): void
    {
        $lock = 'HFSWeb' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) {
            throw new RuntimeException('Ein Abruf läuft bereits. Bitte kurz warten.');
        }
        try {
            $this->WriteAttributeString('WebSession', '');
            if ($this->ReadPropertyString('AccessMode') === 'web') {
                $this->SetTimerInterval('UpdateTimer', 0);
                $this->WebStatus('Website-Sitzung lokal entfernt.', 201);
            }
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    // Kein Redirect-Following: Sitzungsdaten niemals an ein anderes Ziel senden.
    private function WebGet($ch, string $url, string $token, bool $energy): array
    {
        $headers = ['Accept: application/json', 'Accept-Language: de,en;q=0.8', 'roarand: ' . $token,
            'Sec-Fetch-Dest: empty', 'Sec-Fetch-Mode: cors', 'Sec-Fetch-Site: same-origin'];
        if ($energy) {
            $headers[] = 'X-Requested-With: XMLHttpRequest';
            $headers[] = 'x-non-renewal-session: true';
            $headers[] = 'x-timezone-offset: ' . ((new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->getOffset() / 60);
        }
        curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_HTTPHEADER => $headers]);
        $body = curl_exec($ch);
        if ($body === false) {
            throw new RuntimeException('Netzwerkfehler beim Website-Abruf.');
        }
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (($http >= 300 && $http < 400) || $http === 401 || $http === 403) {
            throw new RuntimeException('Website-Sitzung nicht mehr nutzbar. Im Browser anmelden und Sitzung neu importieren.', 201);
        }
        if ($http !== 200) {
            throw new RuntimeException('Website HTTP-Status ' . $http . '; Abruf gestoppt.');
        }
        $result = json_decode($body, true);
        if (!is_array($result)) {
            throw new RuntimeException('Website liefert keine JSON-Daten. Sitzung prüfen.');
        }
        return $result;
    }

    private function UpdateWeb(): void
    {
        $lock = 'HFSWeb' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1000)) {
            return;
        }
        $ch = null;
        $session = null;
        $this->SetTimerInterval('UpdateTimer', 0);
        try {
            [$origin, $station] = $this->WebConfiguration();
            $session = json_decode($this->ReadAttributeString('WebSession'), true);
            if (!is_array($session) || ($session['origin'] ?? '') !== $origin || ($session['station'] ?? '') !== $station
                || !is_array($session['cookies'] ?? null) || !is_string($session['token'] ?? null) || $session['token'] === '') {
                throw new RuntimeException('Website-Sitzung fehlt. Bitte neu importieren.', 201);
            }
            if (!function_exists('curl_init')) {
                throw new RuntimeException('PHP-cURL ist nicht verfügbar.');
            }
            $ch = curl_init();
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_ENCODING => '',
                CURLOPT_COOKIEFILE => '',
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:158.0) Gecko/20100101 Firefox/158.0',
                CURLOPT_REFERER => $origin . '/uniportal/pvmswebsite/assets/build/cloud.html']);
            foreach ($session['cookies'] as $line) {
                curl_setopt($ch, CURLOPT_COOKIELIST, $line);
            }
            $keep = $this->WebGet($ch, $origin . '/rest/dpcloud/auth/v1/keep-alive', $session['token'], false);
            if (($keep['code'] ?? null) !== 0 || !is_string($keep['payload'] ?? null) || $keep['payload'] === ''
                || preg_match('/[\x00-\x20\x7f]/', $keep['payload'])) {
                throw new RuntimeException('Keep-alive nicht bestätigt. Sitzung neu importieren.', 201);
            }
            $session['token'] = $keep['payload'];
            $query = http_build_query(['stationDn' => $station, 'featureId' => 'aifc', '_' => sprintf('%.0f', microtime(true) * 1000)], '', '&', PHP_QUERY_RFC3986);
            $data = $this->WebGet($ch, $origin . '/rest/pvms/web/station/v3/overview/energy-flow?' . $query, $session['token'], true);
            if (($data['success'] ?? null) !== true || ($data['failCode'] ?? null) !== 0) {
                throw new RuntimeException('Energiefluss-Abfrage nicht bestätigt. Sitzung und Berechtigungen prüfen.');
            }
            $values = $this->ParseWebFlow($data['data']['flow'] ?? []);
            foreach ($values as $ident => $value) {
                $this->SetValue($ident, $value);
            }
            $session['cookies'] = curl_getinfo($ch, CURLINFO_COOKIELIST);
            $session['paused'] = false;
            $this->WriteAttributeString('WebSession', json_encode($session, JSON_THROW_ON_ERROR));
            $this->SetValue('LastWebSuccess', time());
            $this->WebStatus('Website verbunden. Abrufzeit ist kein Messzeitpunkt. Batterie und Einzelwechselrichter nicht verfügbar.', 102, true);
            $this->SetTimerInterval('UpdateTimer', max(300, $this->ReadPropertyInteger('UpdateInterval')) * 1000);
        } catch (Throwable $e) {
            if ($e->getCode() === 201) {
                $this->WriteAttributeString('WebSession', '');
            } elseif (is_array($session)) {
                if ($ch !== null) {
                    $session['cookies'] = curl_getinfo($ch, CURLINFO_COOKIELIST);
                }
                $session['paused'] = true;
                $this->WriteAttributeString('WebSession', json_encode($session));
            }
            // Nur kontrollierte eigene Fehlermeldungen, keine Antworten/Token protokollieren.
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'Interner Fehler beim Website-Abruf.';
            $this->WebStatus($message, $e->getCode() === 201 ? 201 : 202);
        } finally {
            if ($ch !== null) {
                curl_close($ch);
            }
            IPS_SemaphoreLeave($lock);
        }
    }

    private function ParseWebFlow(array $flow): array
    {
        if (!is_array($flow['nodes'] ?? null) || !is_array($flow['links'] ?? null)) {
            throw new RuntimeException('Energiefluss-Daten fehlen. Letzte Werte bleiben erhalten.');
        }
        $power = static function ($text): float {
            if (!is_string($text) || !preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*(kW|W)\s*$/D', $text, $m)) {
                throw new RuntimeException('Messwert fehlt oder Einheit unbekannt. Letzte Werte bleiben erhalten.');
            }
            return (float) str_replace(',', '.', $m[1]) * ($m[2] === 'kW' ? 1000 : 1);
        };
        $values = [];
        $grid = null;
        foreach ($flow['nodes'] as $node) {
            $name = $node['name'] ?? '';
            $ident = ['neteco.pvms.devTypeLangKey.string' => 'TotalPV',
                'neteco.pvms.KPI.kpiView.electricalLoad' => 'HouseConsumption'][$name] ?? null;
            if ($ident !== null) {
                if (isset($values[$ident]) || ($node['display'] ?? '') !== 'CONNECTED') {
                    throw new RuntimeException('Energiefluss-Knoten nicht eindeutig oder getrennt.');
                }
                $values[$ident] = $power($node['description']['value'] ?? null);
            }
            if ($name === 'neteco.pvms.partials.main.dm.detailInfo.curInfo.grid') {
                $grid = (string) $node['id'];
            }
        }
        foreach ($flow['links'] as $link) {
            $from = (string) ($link['fromNode'] ?? '');
            $to = (string) ($link['toNode'] ?? '');
            if ($grid === null || ($from !== $grid && $to !== $grid) || ($link['visible'] ?? false) !== true) {
                continue;
            }
            if (isset($values['GridImport'])) {
                throw new RuntimeException('Netzfluss nicht eindeutig.');
            }
            $watt = $power($link['description']['value'] ?? null);
            $direction = $link['flowing'] ?? '';
            if (!in_array($direction, ['FORWARD', 'BACKWARD', 'NONE'], true) || ($direction === 'NONE' && $watt != 0)) {
                throw new RuntimeException('Netzfluss-Richtung unbekannt.');
            }
            $import = ($from === $grid && $direction === 'FORWARD') || ($to === $grid && $direction === 'BACKWARD');
            $values['GridImport'] = $import ? $watt : 0.0;
            $values['GridExport'] = $import ? 0.0 : $watt;
        }
        if (count($values) !== 4) {
            throw new RuntimeException('PV, Hausverbrauch oder Netzfluss fehlt. Letzte Werte bleiben erhalten.');
        }
        return $values;
    }
}
