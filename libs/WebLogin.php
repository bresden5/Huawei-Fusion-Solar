<?php

require_once __DIR__ . '/WebLoginCrypto.php';

trait FusionSolarWebLogin
{
    private function LoginWeb($ch, string $origin, string $station): array
    {
        if (!$this->ReadPropertyBoolean('WebAutoLogin')) {
            throw new RuntimeException('Website-Sitzung fehlt. Bitte importieren oder automatische Anmeldung aktivieren.', 201);
        }
        if (time() - $this->ReadAttributeInteger('WebLoginAttempt') < 600) {
            throw new RuntimeException('Anmeldung pausiert: mindestens zehn Minuten seit dem letzten Versuch warten.', 203);
        }
        $username = trim($this->ReadPropertyString('WebUsername'));
        $password = $this->ReadPropertyString('WebPassword');
        if ($username === '' || $password === '') {
            throw new RuntimeException('Website-Benutzername und Website-Passwort eintragen und übernehmen.', 201);
        }
        // Only the observed EU5 flow is supported. Never infer credential destinations.
        if ($origin !== 'https://uni004eu5.fusionsolar.huawei.com') {
            throw new RuntimeException('Automatische Anmeldung unterstützt derzeit nur uni004eu5.fusionsolar.huawei.com.', 201);
        }
        $this->WriteAttributeInteger('WebLoginAttempt', time());
        $this->WriteAttributeBoolean('WebLoginBlocked', true);
        $login = 'https://eu5.fusionsolar.huawei.com';
        curl_setopt($ch, CURLOPT_COOKIELIST, 'ALL');
        $this->LoginRequest($ch, $login . '/unisso/login.action');
        [, $body] = $this->LoginRequest($ch, $login . '/unisso/pubkey');
        $key = json_decode($body, true);
        if (!is_array($key) || !isset($key['timeStamp']) || !preg_match('/^\d+$/D', (string) $key['timeStamp'])) {
            throw new RuntimeException('Anmeldung: Huawei-Schlüsselantwort ungültig.', 201);
        }
        $encode = static function (string $value): string {
            return strtr(rawurlencode($value), ['%21'=>'!', '%27'=>"'", '%28'=>'(', '%29'=>')', '%2A'=>'*']);
        };
        $payload = json_encode(['organizationName'=>'', 'username'=>$encode($username),
            'password'=>FusionSolarWebLoginCrypto::Encrypt($password, $key),
            'verifycode'=>'', 'multiRegionName'=>''], JSON_THROW_ON_ERROR);
        [, $body] = $this->LoginRequest($ch, $login . '/unisso/v3/validateUser.action?' . http_build_query([
            'timeStamp'=>$key['timeStamp'], 'nonce'=>FusionSolarWebLoginCrypto::Nonce()
        ]), $payload);
        $response = json_decode($body, true);
        if (!is_array($response)) {
            throw new RuntimeException('Anmeldung: Huawei liefert keine JSON-Antwort.', 201);
        }
        $regions = $response['respMultiRegionName'] ?? [];
        if (!empty($response['verifyCodeCreate']) || !empty($response['twoFactorStatus']) || ($regions[0] ?? '') === '-7') {
            throw new RuntimeException('Anmeldung erfordert CAPTCHA oder Zwei-Faktor-Bestätigung. Bitte im Browser anmelden und Sitzung importieren.', 201);
        }
        if ((string) ($response['errorCode'] ?? '') !== '470' || !is_array($regions) || ($regions[0] ?? '') !== '-5') {
            throw new RuntimeException('Anmeldung nicht bestätigt. Zugangsdaten prüfen oder im Browser anmelden; automatische Versuche gestoppt.', 201);
        }
        $path = $regions[1] ?? '';
        if (!is_string($path) || preg_match('/[\x00-\x20\x7f#]/', $path)
            || strpos($path, '/rest/dp/web/v1/auth/on-sso-credential-ready?') !== 0) {
            throw new RuntimeException('Anmeldung: unbekannte Ticketadresse.', 201);
        }
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        if (!is_string($query['ticket'] ?? null) || $query['ticket'] === '' || ($query['regionName'] ?? '') !== 'region004') {
            throw new RuntimeException('Anmeldung: Ticket oder Region fehlt.', 201);
        }
        $parts = is_string($regions[2] ?? null) ? explode('&&', $regions[2], 2) : [];
        if (($parts[0] ?? '') !== 'uni004eu5.fusionsolar.huawei.com' || empty($parts[1])
            || preg_match('/[\x00-\x20\x7f;]/', $parts[1])) {
            throw new RuntimeException('Anmeldung: unbekannte regionale Sitzung.', 201);
        }
        curl_setopt($ch, CURLOPT_COOKIELIST, ".fusionsolar.huawei.com\tTRUE\t/\tTRUE\t0\tSSO_TGC_\t" . $parts[1]);
        [$status, , $location] = $this->LoginRequest($ch, $login . $path, null, true);
        if ($status !== 302 || !$this->IsWebPortalLocation($location, $origin)) {
            throw new RuntimeException('Anmeldung: erwartete Weiterleitung zur Anlagenübersicht fehlt.', 201);
        }
        $hasSession = false;
        foreach (curl_getinfo($ch, CURLINFO_COOKIELIST) ?: [] as $line) {
            $cookie = explode("\t", preg_replace('/^#HttpOnly_/', '', $line));
            if (count($cookie) === 7 && ltrim($cookie[0], '.') === 'fusionsolar.huawei.com'
                && $cookie[1] === 'TRUE' && $cookie[5] === 'dp-session' && $cookie[6] !== '') {
                $hasSession = true;
            }
        }
        if (!$hasSession) {
            throw new RuntimeException('Anmeldung: dp-session wurde nicht gesetzt.', 201);
        }
        // Exactly one validated navigation; no automatic redirect following.
        $this->LoginRequest($ch, $location);
        curl_setopt($ch, CURLOPT_REFERER, $origin . '/uniportal/pvmswebsite/assets/build/cloud.html');
        return ['origin'=>$origin, 'station'=>$station, 'cookies'=>curl_getinfo($ch, CURLINFO_COOKIELIST), 'token'=>'', 'paused'=>false];
    }

    private function IsWebPortalLocation(string $url, string $origin): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && !preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === parse_url($origin, PHP_URL_HOST)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
            && ($parts['path'] ?? '') === '/uniportal/pvmswebsite/assets/build/cloud.html';
    }

    private function LoginRequest($ch, string $url, ?string $payload = null, bool $redirect = false): array
    {
        $location = '';
        curl_setopt_array($ch, [CURLOPT_URL=>$url, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_REFERER=>'https://eu5.fusionsolar.huawei.com/unisso/login.action',
            CURLOPT_HTTPHEADER=>['Accept: application/json, text/html', 'Content-Type: application/json', 'Accept-Language: de,en;q=0.8'],
            CURLOPT_HEADERFUNCTION=>static function ($handle, string $line) use (&$location): int {
                if (stripos($line, 'Location:') === 0) $location = trim(substr($line, 9));
                return strlen($line);
            }]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // Release callback and credentials before another request.
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($handle, string $line): int {return strlen($line);});
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        if ($body === false || $status === 408 || $status === 429 || $status >= 500) {
            throw new RuntimeException('Website-Anmeldung vorübergehend nicht erreichbar (HTTP ' . $status . ').', 203);
        }
        if ($status !== 200 && !($redirect && $status === 302)) {
            throw new RuntimeException('Website-Anmeldung fehlgeschlagen (HTTP ' . $status . '). Automatische Versuche gestoppt.', 201);
        }
        return [$status, $body, $location];
    }
}
