<?php

/** Password encoding observed in Huawei /unisso/react/loginAPI.js on 2026-11-08.
 * Pure local transformation. Does not log, store or transmit credentials.
 * SHA-384 OAEP is encoded explicitly for older Symcon PHP/OpenSSL versions.
 */
final class FusionSolarWebLoginCrypto
{
    public static function Encrypt(string $password, array $keyResponse): string
    {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('Für die Website-Anmeldung wird PHP-OpenSSL benötigt.');
        }
        if (($keyResponse['enableEncrypt'] ?? null) !== true
            || !is_string($keyResponse['pubKey'] ?? null)
            || !is_string($keyResponse['version'] ?? null)
            || !preg_match('/^[0-9A-Za-z]{1,32}$/D', $keyResponse['version'])) {
            throw new RuntimeException('Huawei-Schlüsselantwort hat ein unbekanntes Format.');
        }
        if ($password === '' || strlen($password) > 4096 || preg_match('//u', $password) !== 1) {
            throw new RuntimeException('Passwort fehlt, ist zu lang oder enthält ungültiges UTF-8.');
        }
        $key = @openssl_pkey_get_public($keyResponse['pubKey']);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (!is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA
            || $details['bits'] < 2048 || $details['bits'] > 8192) {
            throw new RuntimeException('Huawei liefert keinen unterstützten RSA-Schlüssel.');
        }
        $size = intdiv($details['bits'] + 7, 8);
        // JavaScript encodeURIComponent leaves these characters unescaped.
        $encoded = strtr(rawurlencode($password), [
            '%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*'
        ]);
        $chunks = [];
        foreach (str_split($encoded, 270) as $chunk) {
            // Match the website's fixed chunk size; never silently change its protocol.
            if (strlen($chunk) > $size - 98) {
                throw new RuntimeException('RSA-Schlüssel passt nicht zur Huawei-Passwortsegmentierung.');
            }
            $seed = random_bytes(48);
            $db = hash('sha384', '', true)
                . str_repeat("\0", $size - strlen($chunk) - 98) . "\1" . $chunk;
            $maskedDB = $db ^ self::Mask($seed, $size - 49);
            $maskedSeed = $seed ^ self::Mask($maskedDB, 48);
            $block = "\0" . $maskedSeed . $maskedDB;
            $cipher = '';
            if (!@openssl_public_encrypt($block, $cipher, $key, OPENSSL_NO_PADDING)) {
                throw new RuntimeException('Passwortverschlüsselung fehlgeschlagen.');
            }
            // Huawei serializes the RSA integer to even-length hex, then Base64.
            $chunks[] = base64_encode(ltrim($cipher, "\0"));
        }
        return implode('00000001', $chunks) . $keyResponse['version'];
    }

    private static function Mask(string $seed, int $length): string
    {
        $result = '';
        for ($counter = 0; strlen($result) < $length; $counter++) {
            $result .= hash('sha384', $seed . pack('N', $counter), true);
        }
        return substr($result, 0, $length);
    }

    public static function Nonce(): string
    {
        // Huawei renders each random byte in hexadecimal without zero-padding.
        $result = '';
        foreach (unpack('C*', random_bytes(16)) as $byte) {
            $result .= dechex($byte);
        }
        return $result;
    }
}
