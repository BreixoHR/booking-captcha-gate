<?php
/**
 * Límite de intentos por clave (IP) en una ventana de tiempo.
 * El almacenamiento se inyecta: en WordPress, transients; en tests, un array.
 */

namespace BookingCaptchaGate;

final class RateLimiter
{
    /** @var callable(string): mixed */
    private $get;
    /** @var callable(string, mixed, int): void */
    private $set;
    private $max;
    private $windowSeconds;

    public function __construct(callable $get, callable $set, $max = 10, $windowSeconds = 600)
    {
        $this->get = $get;
        $this->set = $set;
        $this->max = (int) $max;
        $this->windowSeconds = (int) $windowSeconds;
    }

    /** Registra un intento y devuelve false si se ha superado el límite. */
    public function hit($key)
    {
        $storageKey = 'bcg_rl_' . md5((string) $key);
        $count = (int) call_user_func($this->get, $storageKey);
        if ($count >= $this->max) {
            return false;
        }
        call_user_func($this->set, $storageKey, $count + 1, $this->windowSeconds);
        return true;
    }
}
