<?php

declare(strict_types=1);

namespace Fame1302\Janathan\Services;

use PDO;

class CryptoService
{
    private const CIPHER = 'aes-256-gcm';

    private string $key;

    public function __construct(string $appKey)
    {
        if ($appKey === '') {
            throw new \RuntimeException('Encryption key is not available.');
        }

        $this->key = hash('sha256', $appKey, true);
    }

    public static function fromPdo(PDO $pdo): self
    {
        $stmt = $pdo->prepare("SELECT value FROM app_settings WHERE key = 'encryption_key'");
        $stmt->execute();
        $key = $stmt->fetchColumn();

        if ($key === false || $key === '') {
            $key = bin2hex(random_bytes(32));
            $pdo->prepare('INSERT OR IGNORE INTO app_settings (key, value) VALUES (\'encryption_key\', :value)')
                ->execute(['value' => $key]);
        }

        return new self((string) $key);
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
