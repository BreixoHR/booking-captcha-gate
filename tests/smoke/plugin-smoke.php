<?php
/**
 * Smoke test del plugin completo con stubs mínimos de WordPress (sin instalar WordPress):
 * shortcode, endpoint de desbloqueo, límite de intentos y verificación.
 *
 *   php tests/smoke/plugin-smoke.php
 */

define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);

// ---- Stubs de WordPress ---------------------------------------------------
$GLOBALS['wp'] = array('shortcodes' => array(), 'actions' => array(), 'routes' => array(), 'options' => array(), 'transients' => array(), 'enqueued' => array(), 'post' => null, 'http' => null);

function add_shortcode($tag, $cb) { $GLOBALS['wp']['shortcodes'][$tag] = $cb; }
function add_action($hook, $cb) { $GLOBALS['wp']['actions'][$hook][] = $cb; }
function do_action($hook) { foreach ($GLOBALS['wp']['actions'][$hook] ?? array() as $cb) { $cb(); } }
function register_rest_route($ns, $route, $args) { $GLOBALS['wp']['routes']["$ns$route"] = $args; }
function get_option($k, $d = false) { return $GLOBALS['wp']['options'][$k] ?? $d; }
function wp_parse_args($a, $d) { return array_merge($d, $a); }
function get_post($id = null) { $p = $GLOBALS['wp']['post']; return ($id === null || ($p && $p->ID === $id)) ? $p : null; }
function shortcode_atts($d, $a) { return array_merge($d, (array) $a); }
function __($s) { return $s; }
function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url_raw($s) { return $s; }
function do_shortcode($s) { return $s; }
function wp_enqueue_script($h) { $GLOBALS['wp']['enqueued'][] = $h; }
function get_transient($k) { return $GLOBALS['wp']['transients'][$k] ?? false; }
function set_transient($k, $v, $t) { $GLOBALS['wp']['transients'][$k] = $v; }
function post_password_required($p) { return false; }
function sanitize_text_field($s) { return trim((string) $s); }
function wp_unslash($s) { return $s; }
function home_url() { return 'https://www.example.com'; }
function wp_parse_url($u, $c) { return parse_url($u, $c); }
function wp_remote_post($url, $args) { return call_user_func($GLOBALS['wp']['http'], $url, $args['body']); }
function is_wp_error($x) { return false; }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }

class WP_REST_Request implements ArrayAccess {
    private $p;
    public function __construct(array $p) { $this->p = $p; }
    public function offsetExists($o): bool { return isset($this->p[$o]); }
    public function offsetGet($o): mixed { return $this->p[$o]; }
    public function offsetSet($o, $v): void { $this->p[$o] = $v; }
    public function offsetUnset($o): void { unset($this->p[$o]); }
}
class WP_REST_Response {
    public $data; public $status; public $headers = array();
    public function __construct($d, $s) { $this->data = $d; $this->status = $s; }
    public function header($k, $v) { $this->headers[$k] = $v; }
}

// ---- Carga del plugin -----------------------------------------------------
require __DIR__ . '/../../booking-captcha-gate.php';
do_action('rest_api_init');

$fails = 0;
function check($name, $ok, $extra = '') { global $fails; if (!$ok) $fails++; echo ($ok ? 'PASS' : 'FAIL') . "  $name $extra\n"; }

$widget = '<div id="booking-widget"></div><script src="https://widgets.example/w.js"></script>';
$GLOBALS['wp']['post'] = (object) array('ID' => 42, 'post_status' => 'publish', 'post_content' => "<p>Intro</p>[captcha_gate]{$widget}[/captcha_gate]");
$shortcode = $GLOBALS['wp']['shortcodes']['captcha_gate'];
$unlock = $GLOBALS['wp']['routes']['booking-captcha-gate/v1/unlock']['callback'];
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

// Sin claves: el widget se muestra (no se rompe la venta por falta de configuración)
check('sin configurar → widget visible', $shortcode(array(), $widget) === $widget);

$GLOBALS['wp']['options'][BookingCaptchaGate\OPTION] = array('site_key' => 'site', 'secret_key' => 'secret', 'mode' => 'v2');
$html = $shortcode(array(), $widget);
check('configurado → el widget NO está en la página', strpos($html, 'booking-widget') === false && strpos($html, 'w.js') === false);
$gate = \BookingCaptchaGate\GateContent::idFor($widget);
check('marcador con post e id estable', strpos($html, 'data-post="42"') !== false && strpos($html, 'data-gate="' . $gate . '"') !== false);
check('renderizar dos veces da el mismo id', $shortcode(array(), $widget) === $html);

// Google responde según el token
$GLOBALS['wp']['http'] = function ($url, $form) {
    $ok = $form['response'] === 'good';
    return array('code' => 200, 'body' => json_encode(array('success' => $ok, 'hostname' => 'www.example.com')));
};

$r = $unlock(new WP_REST_Request(array('post' => 42, 'gate' => $gate, 'token' => 'bad')));
check('token inválido → 403', $r->status === 403 && strpos(json_encode($r->data), 'booking-widget') === false);

$r = $unlock(new WP_REST_Request(array('post' => 42, 'gate' => $gate, 'token' => 'good')));
check('token válido → 200 con el widget', $r->status === 200 && $r->data['html'] === $widget);
check('respuesta no cacheable', ($r->headers['Cache-Control'] ?? '') === 'no-store');

$r = $unlock(new WP_REST_Request(array('post' => 99, 'gate' => $gate, 'token' => 'good')));
check('post inexistente → 404', $r->status === 404);
$r = $unlock(new WP_REST_Request(array('post' => 42, 'gate' => \BookingCaptchaGate\GateContent::idFor('otro'), 'token' => 'good')));
check('gate inexistente → 404', $r->status === 404);

$GLOBALS['wp']['post']->post_status = 'draft';
$r = $unlock(new WP_REST_Request(array('post' => 42, 'gate' => $gate, 'token' => 'good')));
check('borrador → 404 (no filtra contenido no publicado)', $r->status === 404);
$GLOBALS['wp']['post']->post_status = 'publish';

// 10 intentos por IP y ventana: ya van 5 → 5 más y el siguiente se bloquea
for ($i = 0; $i < 5; $i++) { $unlock(new WP_REST_Request(array('post' => 42, 'gate' => $gate, 'token' => 'bad'))); }
$r = $unlock(new WP_REST_Request(array('post' => 42, 'gate' => $gate, 'token' => 'good')));
check('límite por IP → 429 aunque el token sea bueno', $r->status === 429);
$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
$r = $unlock(new WP_REST_Request(array('post' => 42, 'gate' => $gate, 'token' => 'good')));
check('otra IP no está bloqueada', $r->status === 200);

exit($fails > 0 ? 1 : 0);
