<?php
/**
 * Verificación de tokens de reCAPTCHA (v2 checkbox o v3) contra la API siteverify de Google.
 *
 * Sin dependencias de WordPress: el cliente HTTP se inyecta, lo que permite testearla aislada.
 */

namespace BookingCaptchaGate;

final class Verifier
{
    const ENDPOINT = 'https://www.google.com/recaptcha/api/siteverify';

    /** @var string */
    private $secret;
    /** @var callable(string $url, array $form): array{status:int, body:string} */
    private $http;
    /** @var float */
    private $minScore;
    /** @var string|null */
    private $expectedAction;

    /**
     * @param callable $http      fn(string $url, array $form): ['status' => int, 'body' => string]
     * @param float    $minScore  Solo v3: puntuación mínima (0.0 bot … 1.0 humano)
     */
    public function __construct($secret, callable $http, $minScore = 0.5, $expectedAction = null)
    {
        $this->secret = (string) $secret;
        $this->http = $http;
        $this->minScore = (float) $minScore;
        $this->expectedAction = $expectedAction;
    }

    /**
     * @return array{ok: bool, reason: string}
     */
    public function verify($token, $remoteIp, $expectedHost)
    {
        if ($this->secret === '') {
            return self::fail('not-configured');
        }
        if (!is_string($token) || $token === '' || strlen($token) > 4096) {
            return self::fail('missing-token');
        }

        try {
            $response = call_user_func($this->http, self::ENDPOINT, array(
                'secret' => $this->secret,
                'response' => $token,
                'remoteip' => (string) $remoteIp,
            ));
        } catch (\Throwable $e) {
            return self::fail('network-error');
        }

        if (!is_array($response) || (int) ($response['status'] ?? 0) !== 200) {
            return self::fail('http-error');
        }
        $data = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($data)) {
            return self::fail('invalid-response');
        }
        if (empty($data['success'])) {
            $codes = isset($data['error-codes']) && is_array($data['error-codes']) ? implode(',', $data['error-codes']) : 'unknown';
            return self::fail('rejected:' . $codes);
        }
        // Un token válido emitido para OTRA web (misma clave reutilizada) no sirve aquí
        if (isset($data['hostname']) && $expectedHost !== '' && strcasecmp($data['hostname'], $expectedHost) !== 0) {
            return self::fail('hostname-mismatch');
        }
        // reCAPTCHA v3: acción y puntuación
        if (isset($data['score'])) {
            if ($this->expectedAction !== null && ($data['action'] ?? null) !== $this->expectedAction) {
                return self::fail('action-mismatch');
            }
            if ((float) $data['score'] < $this->minScore) {
                return self::fail('low-score');
            }
        }
        return array('ok' => true, 'reason' => 'ok');
    }

    private static function fail($reason)
    {
        return array('ok' => false, 'reason' => $reason);
    }
}
