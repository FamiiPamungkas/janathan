<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

class CryptoService
{
    private const CIPHER = 'aes-256-gcm';

    private string $key;

    public function __construct(string $appKey)
    {
        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY is not set. Generate one and add it to .env');
        }

        $this->key = hash('sha256', $appKey, true);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new \RuntimeException('Failed to encrypt router credentials.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        $data = base64_decode($payload, true);

        if ($data === false || strlen($data) < 29) {
            throw new \RuntimeException('Invalid encrypted payload.');
        }

        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $ciphertext = substr($data, 28);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt router credentials.');
        }

        return $plaintext;
    }
}
