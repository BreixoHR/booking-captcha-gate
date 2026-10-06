<?php

use BookingCaptchaGate\Verifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VerifierTest extends TestCase
{
    /** Cliente HTTP falso que registra la petición y devuelve la respuesta indicada. */
    private function http(array $googleResponse, int $status = 200, ?array &$captured = null): callable
    {
        return function ($url, array $form) use ($googleResponse, $status, &$captured) {
            $captured = array('url' => $url, 'form' => $form);
            return array('status' => $status, 'body' => json_encode($googleResponse));
        };
    }

    public function testValidV2TokenPasses(): void
    {
        $captured = null;
        $v = new Verifier('secret', $this->http(array('success' => true, 'hostname' => 'www.example.com'), 200, $captured));
        $r = $v->verify('tok', '203.0.113.7', 'www.example.com');

        $this->assertTrue($r['ok']);
        $this->assertSame(Verifier::ENDPOINT, $captured['url']);
        $this->assertSame(array('secret' => 'secret', 'response' => 'tok', 'remoteip' => '203.0.113.7'), $captured['form']);
    }

    public function testRejectedTokenReportsGoogleErrorCodes(): void
    {
        $v = new Verifier('secret', $this->http(array('success' => false, 'error-codes' => array('timeout-or-duplicate'))));
        $this->assertSame(array('ok' => false, 'reason' => 'rejected:timeout-or-duplicate'), $v->verify('tok', '', 'example.com'));
    }

    public function testTokenFromAnotherSiteIsRejected(): void
    {
        // Misma clave usada en varias webs: un token resuelto en otra no debe abrir esta
        $v = new Verifier('secret', $this->http(array('success' => true, 'hostname' => 'otra-web.example')));
        $this->assertSame('hostname-mismatch', $v->verify('tok', '', 'www.example.com')['reason']);
    }

    public function testHostnameComparisonIsCaseInsensitive(): void
    {
        $v = new Verifier('secret', $this->http(array('success' => true, 'hostname' => 'WWW.Example.com')));
        $this->assertTrue($v->verify('tok', '', 'www.example.com')['ok']);
    }

    #[DataProvider('v3Cases')]
    public function testV3ScoreAndAction(array $google, bool $ok, string $reason): void
    {
        $v = new Verifier('secret', $this->http($google + array('success' => true)), 0.5, 'booking_gate');
        $this->assertSame(array('ok' => $ok, 'reason' => $reason), $v->verify('tok', '', ''));
    }

    public static function v3Cases(): array
    {
        return array(
            'humano' => array(array('score' => 0.9, 'action' => 'booking_gate'), true, 'ok'),
            'en el umbral' => array(array('score' => 0.5, 'action' => 'booking_gate'), true, 'ok'),
            'bot' => array(array('score' => 0.1, 'action' => 'booking_gate'), false, 'low-score'),
            'token de otro formulario' => array(array('score' => 0.9, 'action' => 'login'), false, 'action-mismatch'),
        );
    }

    #[DataProvider('failureCases')]
    public function testFailsClosed(string $secret, $token, callable $http, string $reason): void
    {
        $v = new Verifier($secret, $http);
        $this->assertSame(array('ok' => false, 'reason' => $reason), $v->verify($token, '', 'example.com'));
    }

    public static function failureCases(): array
    {
        $ok = function () {
            return array('status' => 200, 'body' => '{"success":true}');
        };
        return array(
            'sin clave secreta' => array('', 'tok', $ok, 'not-configured'),
            'sin token' => array('s', '', $ok, 'missing-token'),
            'token no string' => array('s', array('x'), $ok, 'missing-token'),
            'token gigante' => array('s', str_repeat('a', 5000), $ok, 'missing-token'),
            'error de red' => array('s', 'tok', function () { throw new RuntimeException('timeout'); }, 'network-error'),
            'HTTP 500' => array('s', 'tok', function () { return array('status' => 500, 'body' => ''); }, 'http-error'),
            'respuesta no JSON' => array('s', 'tok', function () { return array('status' => 200, 'body' => '<html>'); }, 'invalid-response'),
        );
    }
}
