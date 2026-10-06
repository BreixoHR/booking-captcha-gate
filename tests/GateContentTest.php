<?php

use BookingCaptchaGate\GateContent;
use BookingCaptchaGate\RateLimiter;
use PHPUnit\Framework\TestCase;

final class GateContentTest extends TestCase
{
    private const POST = <<<'HTML'
<h2>Free tour del casco antiguo</h2>
[captcha_gate message="Verifica para reservar"]
<div id="booking-widget-1" data-product="123"></div>
<script src="https://widgets.example-booking.com/widget.js"></script>
[/captcha_gate]
<p>Texto intermedio</p>
[captcha_gate]<iframe src="https://booking.example/calendar/456"></iframe>[/captcha_gate]
HTML;

    public function testExtractsEachGateById(): void
    {
        $this->assertSame(2, GateContent::count(self::POST));
        $iframe = '<iframe src="https://booking.example/calendar/456"></iframe>';
        $widget = "<div id=\"booking-widget-1\" data-product=\"123\"></div>\n<script src=\"https://widgets.example-booking.com/widget.js\"></script>";
        $first = GateContent::extract(self::POST, GateContent::idFor($widget));
        $this->assertStringContainsString('booking-widget-1', $first);
        $this->assertStringContainsString('widget.js', $first);
        $this->assertStringNotContainsString('Texto intermedio', $first, 'no se come el contenido entre gates');
        $this->assertSame($iframe, GateContent::extract(self::POST, GateContent::idFor($iframe)));
    }

    public function testIdIsStableAndIgnoresSurroundingWhitespace(): void
    {
        $this->assertSame(GateContent::idFor('<div>x</div>'), GateContent::idFor("\n  <div>x</div>\n"));
        $this->assertNotSame(GateContent::idFor('<div>x</div>'), GateContent::idFor('<div>y</div>'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', GateContent::idFor('x'));
    }

    public function testUnknownOrInvalidId(): void
    {
        $this->assertNull(GateContent::extract(self::POST, GateContent::idFor('otro contenido')));
        $this->assertNull(GateContent::extract(self::POST, '0'));
        $this->assertNull(GateContent::extract(self::POST, 'ZZZZZZZZZZZZZZZZ'));
        $this->assertNull(GateContent::extract(self::POST, array()));
        $this->assertNull(GateContent::extract('<p>sin gates</p>', GateContent::idFor('x')));
        $this->assertNull(GateContent::extract(null, GateContent::idFor('x')));
        $this->assertSame(0, GateContent::count('<p>sin gates</p>'));
    }

    public function testDoesNotMatchSimilarShortcodes(): void
    {
        $this->assertSame(0, GateContent::count('[captcha_gateway]x[/captcha_gateway]'));
    }

    public function testRateLimiterBlocksAfterMaxAttemptsPerKey(): void
    {
        $store = array();
        $limiter = new RateLimiter(
            function ($k) use (&$store) { return $store[$k] ?? false; },
            function ($k, $v, $ttl) use (&$store) { $store[$k] = $v; },
            3,
            600
        );
        $this->assertTrue($limiter->hit('203.0.113.7'));
        $this->assertTrue($limiter->hit('203.0.113.7'));
        $this->assertTrue($limiter->hit('203.0.113.7'));
        $this->assertFalse($limiter->hit('203.0.113.7'));
        $this->assertTrue($limiter->hit('198.51.100.1'), 'otra IP tiene su propio contador');
    }
}
