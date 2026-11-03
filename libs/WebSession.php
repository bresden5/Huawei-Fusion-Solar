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
        if (!$valid) {
            $this->InvalidateWebInverters();
        }
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
            $detailMessage = 'Wechselrichterdetails deaktiviert.';
            $this->InvalidateWebInverters();
            if ($this->ReadPropertyBoolean('WebInverterDetails')) {
                $detailMessage = $this->UpdateWebInverters($ch, $origin, $session['token'], $data['data']['flow']);
            }
            foreach ($values as $ident => $value) {
                $this->SetValue($ident, $value);
            }
            $session['cookies'] = curl_getinfo($ch, CURLINFO_COOKIELIST);
            $session['paused'] = false;
            $this->WriteAttributeString('WebSession', json_encode($session, JSON_THROW_ON_ERROR));
            $this->SetValue('LastWebSuccess', time());
            $this->WebStatus('Website verbunden. Batterie nicht verfügbar. ' . $detailMessage, 102, true);
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
    private function InverterSignals(): array
    {
        // IDs and units observed in device-realtime-data, not Northbound devTypeIds.
        return [
            10025 => ['Status', 'Status', '', 'text'],
            10032 => ['DailyYield', 'Tagesertrag', 'kWh', 'number'],
            10029 => ['TotalYield', 'Gesamtertrag', 'kWh', 'number'],
            10018 => ['ActivePower', 'Wirkleistung AC', 'kW', 'number'],
            10019 => ['ReactivePower', 'Blindleistung', 'kvar', 'number'],
            10006 => ['RatedPower', 'Nennleistung', 'kW', 'number'],
            10020 => ['PowerFactor', 'Leistungsfaktor', '', 'number'],
            10021 => ['Frequency', 'Frequenz', 'Hz', 'number'],
            21029 => ['OutputMode', 'Ausgabemodus', '', 'text'],
            10014 => ['CurrentL1', 'Strom L1', 'A', 'number'],
            10015 => ['CurrentL2', 'Strom L2', 'A', 'number'],
            10016 => ['CurrentL3', 'Strom L3', 'A', 'number'],
            10011 => ['VoltageL1', 'Spannung L1', 'V', 'number'],
            10012 => ['VoltageL2', 'Spannung L2', 'V', 'number'],
            10013 => ['VoltageL3', 'Spannung L3', 'V', 'number'],
            10027 => ['Startup', 'Anschaltzeit (Website)', '', 'text'],
            10028 => ['Shutdown', 'Abschaltzeit (Website)', '', 'text'],
            10023 => ['Temperature', 'Innentemperatur', '℃', 'number'],
            10024 => ['Insulation', 'Isolationswiderstand', 'MΩ', 'number'],
            230223000 => ['ActiveAdjustment', 'Wirkleistungsanpassung', '', 'text'],
            230223001 => ['ReactiveAdjustment', 'Blindleistungsanpassung', '', 'text'],
            230223007 => ['SwitchStatus', 'Schaltstatus', '', 'text']
        ];
    }

    private function InvalidateWebInverters(): void
    {
        $this->SetValue('WebInvertersValid', false);
        foreach (json_decode($this->ReadAttributeString('WebInverterIDs'), true) ?? [] as $device) {
            $this->RegisterVariableBoolean('WEBINV_' . $device . '_Valid', 'WR ' . $device . ' – Details vollständig');
            $this->SetValue('WEBINV_' . $device . '_Valid', false);
        }
    }

    private function ParseInverterSignals(array $response): array
    {
        if (($response['success'] ?? null) !== true || ($response['failCode'] ?? null) !== 0 || !is_array($response['data'] ?? null)) {
            throw new RuntimeException('Wechselrichter-Abfrage nicht bestätigt.');
        }
        $map = $this->InverterSignals();
        $parsed = [];
        $seen = [];
        foreach ($response['data'] as $group) {
            if (!is_array($group) || !isset($group['signals'])) {
                continue;
            }
            if (!is_array($group['signals'])) {
                throw new RuntimeException('Wechselrichter-Signalformat unbekannt.');
            }
            foreach ($group['signals'] as $signal) {
                $id = $signal['id'] ?? null;
                if (!is_int($id) && !(is_string($id) && preg_match('/^\d+$/D', $id))) {
                    continue;
                }
                $id = (int) $id;
                if (!isset($map[$id])) {
                    continue;
                }
                if (isset($seen[$id])) {
                    throw new RuntimeException('Wechselrichter-Signal doppelt vorhanden.');
                }
                $seen[$id] = true;
                $unit = $signal['unit'] ?? null;
                $expectedUnit = $map[$id][2];
                if ($expectedUnit === '℃' && $unit === '°C') {
                    $unit = '℃';
                }
                $stamp = $signal['latestTime'] ?? null;
                if ($unit !== $expectedUnit || !is_numeric($stamp) || (float) $stamp <= 0 || (float) $stamp != (int) $stamp) {
                    continue;
                }
                if ($map[$id][3] === 'number') {
                    $raw = $signal['realValue'] ?? null;
                    if ((!is_string($raw) && !is_int($raw) && !is_float($raw)) || !is_numeric($raw) || !is_finite((float) $raw)) {
                        continue;
                    }
                    $value = (float) $raw;
                    if ($unit === 'kW') {
                        $value *= 1000;
                    }
                } else {
                    $value = $signal['value'] ?? null;
                    if (!is_string($value) || trim($value) === '' || strlen($value) > 512) {
                        continue;
                    }
                }
                $parsed[$id] = ['value' => $value, 'time' => (int) $stamp];
            }
        }
        if ($parsed === []) {
            throw new RuntimeException('Keine unterstützten Wechselrichter-Messwerte geliefert.');
        }
        return $parsed;
    }

    private function UpdateWebInverters($ch, string $origin, string $token, array $flow): string
    {
        $devices = [];
        foreach ($flow['nodes'] as $node) {
            if (($node['name'] ?? '') !== 'neteco.pvms.devTypeLangKey.inverter') {
                continue;
            }
            foreach (($node['devIds'] ?? []) as $dn) {
                if (!is_string($dn) || !preg_match('/^NE=(\d+)$/D', $dn, $match)) {
                    throw new RuntimeException('Ungültige Wechselrichterkennung im Energiefluss.');
                }
                $devices[$match[1]] = $dn;
            }
        }
        if ($devices === []) {
            return 'Keine Wechselrichterkennungen im Energiefluss gefunden.';
        }
        // Bounded requests for this residential integration; never silently omit devices.
        if (count($devices) > 8) {
            throw new RuntimeException('Mehr als acht Wechselrichter: Detailabruf bitte deaktivieren.');
        }
        $results = [];
        foreach ($devices as $device => $dn) {
            $query = http_build_query(['deviceDn' => $dn, 'displayAccessModel' => 'true',
                '_' => sprintf('%.0f', microtime(true) * 1000)], '', '&', PHP_QUERY_RFC3986);
            $response = $this->WebGet($ch, $origin . '/rest/pvms/web/device/v1/device-realtime-data?' . $query, $token, true);
            $results[$device] = $this->ParseInverterSignals($response);
        }
        $known = json_decode($this->ReadAttributeString('WebInverterIDs'), true) ?? [];
        $known = array_values(array_unique(array_merge($known, array_map('strval', array_keys($devices)))));
        $this->WriteAttributeString('WebInverterIDs', json_encode($known));
        $complete = true;
        foreach ($results as $device => $signals) {
            $prefix = 'WEBINV_' . $device . '_';
            $name = 'WR ' . $device . ' – ';
            $missing = [];
            foreach ($this->InverterSignals() as $id => [$suffix, $caption, $unit, $type]) {
                $ident = $prefix . $suffix;
                if ($type === 'text') {
                    $this->RegisterVariableString($ident, $name . $caption);
                } else {
                    $profile = '';
                    if ($unit !== '') {
                        $displayUnit = $unit === 'kW' ? 'W' : ($unit === '℃' ? '°C' : $unit);
                        $profile = 'HFS.Web.' . $suffix;
                        if (!IPS_VariableProfileExists($profile)) {
                            IPS_CreateVariableProfile($profile, VARIABLETYPE_FLOAT);
                            IPS_SetVariableProfileDigits($profile, $displayUnit === 'W' ? 0 : 3);
                            IPS_SetVariableProfileText($profile, '', ' ' . $displayUnit);
                        }
                    }
                    $this->RegisterVariableFloat($ident, $name . $caption, $profile);
                }
                $this->RegisterVariableInteger($ident . '_Time', $name . $caption . ' Messzeit', '~UnixTimestamp');
                if (isset($signals[$id])) {
                    $this->SetValue($ident, $signals[$id]['value']);
                    $this->SetValue($ident . '_Time', $signals[$id]['time']);
                } else {
                    $missing[] = $caption;
                    $this->SetValue($ident . '_Time', 0);
                }
            }
            $this->RegisterVariableBoolean($prefix . 'Valid', $name . 'Details vollständig');
            $this->RegisterVariableString($prefix . 'Missing', $name . 'Fehlende Details');
            $this->RegisterVariableInteger($prefix . 'LastSuccess', $name . 'Letzter Detailabruf', '~UnixTimestamp');
            $this->SetValue($prefix . 'Valid', $missing === []);
            $this->SetValue($prefix . 'Missing', implode(', ', $missing));
            $this->SetValue($prefix . 'LastSuccess', time());
            $complete = $complete && $missing === [];
        }
        $this->SetValue('WebInvertersValid', $complete);
        return count($results) . ' Wechselrichter abgerufen.' . ($complete ? '' : ' Einige Details fehlen; Messzeiten und Fehlende Details prüfen.');
    }
}
