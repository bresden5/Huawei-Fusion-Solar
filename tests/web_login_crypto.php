<?php
require __DIR__ . '/../libs/WebLoginCrypto.php';
// Offline cross-check using the OpenSSL CLI OAEP implementation, synthetic keys only.
$key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($key === false) { throw new RuntimeException('Test RSA key generation failed'); }
openssl_pkey_export($key, $private);
$response = ['enableEncrypt' => true, 'pubKey' => openssl_pkey_get_details($key)['key'], 'version' => '00000480'];
$dir = sys_get_temp_dir() . '/hfs-crypto-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
try {
    file_put_contents($dir . '/key.pem', $private);
    chmod($dir . '/key.pem', 0600);
    foreach (["testOnly!ä€😀'()*~", str_repeat('ä!x', 100), 'synthetic-password'] as $password) {
        $encrypted = FusionSolarWebLoginCrypto::Encrypt($password, $response);
        if (substr($encrypted, -8) !== $response['version']) { throw new RuntimeException('Version suffix'); }
        $decoded = '';
        foreach (explode('00000001', substr($encrypted, 0, -8)) as $chunk) {
            $cipher = base64_decode($chunk, true);
            if ($cipher === false) { throw new RuntimeException('Base64'); }
            file_put_contents($dir . '/cipher.bin', str_pad($cipher, 384, "\0", STR_PAD_LEFT));
            $cmd = ['openssl','pkeyutl','-decrypt','-inkey',$dir.'/key.pem','-in',$dir.'/cipher.bin',
                '-pkeyopt','rsa_padding_mode:oaep','-pkeyopt','rsa_oaep_md:sha384','-pkeyopt','rsa_mgf1_md:sha384'];
            $process = proc_open($cmd, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
            fclose($pipes[0]);
            $decoded .= stream_get_contents($pipes[1]); fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
            if (proc_close($process) !== 0) { throw new RuntimeException('Independent OAEP decode failed: '.$error); }
        }
        $expected = strtr(rawurlencode($password), ['%21'=>'!','%27'=>"'",'%28'=>'(','%29'=>')','%2A'=>'*']);
        if ($decoded !== $expected) { throw new RuntimeException('encodeURIComponent/chunk mismatch'); }
    }
    echo "PASS independent SHA-384 OAEP decoding, Unicode and multi-chunk passwords\n";
    if (FusionSolarWebLoginCrypto::Encrypt('test', $response) === FusionSolarWebLoginCrypto::Encrypt('test', $response)) {
        throw new RuntimeException('Encryption is deterministic');
    }
    echo "PASS random OAEP padding\n";
    foreach ([array_merge($response, ['enableEncrypt'=>false]), array_merge($response, ['pubKey'=>'invalid'])] as $bad) {
        $rejected = false;
        try { FusionSolarWebLoginCrypto::Encrypt('test', $bad); } catch (RuntimeException $e) { $rejected = true; }
        if (!$rejected) { throw new RuntimeException('Invalid key accepted'); }
    }
    echo "PASS invalid and unencrypted key responses rejected\n";
    if (!preg_match('/^[a-f0-9]{16,32}$/D', FusionSolarWebLoginCrypto::Nonce())) { throw new RuntimeException('Nonce format'); }
    echo "PASS nonce format\n";
} finally {
    foreach (glob($dir . '/*') as $file) { unlink($file); }
    rmdir($dir);
}
