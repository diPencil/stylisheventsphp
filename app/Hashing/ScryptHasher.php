<?php

namespace App\Hashing;

use Illuminate\Contracts\Hashing\Hasher;

class ScryptHasher implements Hasher
{
    /**
     * Get information about the given hashed value.
     */
    public function info($hashedValue): array
    {
        return [
            'algo' => 'scrypt',
            'algoName' => 'scrypt',
            'options' => [],
        ];
    }

    /**
     * Hash the given value.
     */
    public function make($value, array $options = []): string
    {
        $salt = bin2hex(random_bytes(16));
        $derived = $this->deriveKey($value, $salt);
        return "scrypt:{$salt}:{$derived}";
    }

    /**
     * Check the given plain value against a hash.
     */
    public function check($value, $hashedValue, array $options = []): bool
    {
        if (! $hashedValue || strpos($hashedValue, 'scrypt:') !== 0) {
            return false;
        }

        $parts = explode(':', $hashedValue);
        if (count($parts) !== 3) {
            return false;
        }

        [$scheme, $salt, $stored] = $parts;

        if ($scheme !== 'scrypt' || empty($salt) || empty($stored)) {
            return false;
        }

        try {
            $derived = $this->deriveKey($value, $salt);
            return hash_equals($stored, $derived);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if the given hash has been hashed using the given options.
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return false;
    }

    /**
     * Derive the scrypt key matching Node's crypto.scrypt default parameters.
     */
    protected function deriveKey(string $password, string $saltHex): string
    {
        // Node does: crypto.randomBytes(16).toString('hex') => which is a 32 character hex string.
        // Node passes this 32 character hex string *as a string* to crypto.scrypt.

        // Ensure the salt is exactly 32 bytes as required by sodium_crypto_pwhash_scryptsalsa208sha256
        $saltBytes = str_pad($saltHex, \SODIUM_CRYPTO_PWHASH_SCRYPTSALSA208SHA256_SALTBYTES, "\0");

        $binaryHash = sodium_crypto_pwhash_scryptsalsa208sha256(
            64,
            $password,
            $saltBytes,
            \SODIUM_CRYPTO_PWHASH_SCRYPTSALSA208SHA256_OPSLIMIT_INTERACTIVE,
            \SODIUM_CRYPTO_PWHASH_SCRYPTSALSA208SHA256_MEMLIMIT_INTERACTIVE
        );

        return bin2hex($binaryHash);
    }
}
