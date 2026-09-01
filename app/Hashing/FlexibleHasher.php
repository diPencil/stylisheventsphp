<?php
namespace App\Hashing;

use Illuminate\Contracts\Hashing\Hasher as HasherContract;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Hashing\Argon2IdHasher;

class FlexibleHasher implements HasherContract
{
    protected $scrypt;
    protected $bcrypt;
    protected $argon2id;

    public function __construct()
    {
        $this->scrypt = new ScryptHasher();
        $this->bcrypt = new BcryptHasher();
        $this->argon2id = new Argon2IdHasher();
    }

    public function make(#[\SensitiveParameter] $value, array $options = [])
    {
        return $this->bcrypt->make($value, $options);
    }

    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = [])
    {
        if (! $hashedValue || strlen($hashedValue) === 0) return false;

        if (strpos($hashedValue, 'scrypt:') === 0) {
            return $this->scrypt->check($value, $hashedValue, $options);
        }

        $info = password_get_info($hashedValue);
        $algo = strtolower($info['algoName'] ?? '');

        if (in_array($algo, ['bcrypt', '2y', '2a', '2b'], true) || str_starts_with($hashedValue, '$2')) {
            return $this->bcrypt->check($value, $hashedValue, $options);
        }

        if (str_contains($algo, 'argon')) {
            return $this->argon2id->check($value, $hashedValue, $options);
        }

        return false;
    }

    public function needsRehash($hashedValue, array $options = [])
    {
        if (! $hashedValue) return true;
        if (strpos($hashedValue, 'scrypt:') === 0) {
            return $this->scrypt->needsRehash($hashedValue, $options);
        }
        $info = password_get_info($hashedValue);
        $algo = strtolower($info['algoName'] ?? '');
        if (in_array($algo, ['bcrypt','2y','2a','2b'], true) || str_starts_with($hashedValue, '$2')) {
            return $this->bcrypt->needsRehash($hashedValue, $options);
        }
        if (str_contains($algo, 'argon')) {
            return $this->argon2id->needsRehash($hashedValue, $options);
        }
        return true;
    }

    public function info($hashedValue)
    {
        if (! $hashedValue) return ['algo' => null, 'algoName' => null, 'options' => []];
        if (strpos($hashedValue, 'scrypt:') === 0) {
            return ['algo' => 'scrypt', 'algoName' => 'scrypt', 'options' => []];
        }
        return password_get_info($hashedValue);
    }
}
