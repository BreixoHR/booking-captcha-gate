<?php
/**
 * Plugin Name:       Booking Captcha Gate
 * Description:       Protege widgets de reserva de terceros (Regiondo, FareHarbor, Bókun…) frente a bots: el widget solo se entrega tras verificar reCAPTCHA en el servidor.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           MIT
 * Text Domain:       booking-captcha-gate
 */

namespace BookingCaptchaGate;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/src/Verifier.php';
require_once __DIR__ . '/src/GateContent.php';
require_once __DIR__ . '/src/RateLimiter.php';

const OPTION = 'booking_captcha_gate';
const VERSION = '2.0.0';

function settings()
{
    $defaults = array('site_key' => '', 'secret_key' => '', 'mode' => 'v2', 'min_score' => 0.5);
    $saved = get_option(OPTION, array());
    $settings = wp_parse_args(is_array($saved) ? $saved : array(), $defaults);
    // Permite definir la clave secreta en wp-config.php y no guardarla en la base de datos
    if (defined('BOOKING_CAPTCHA_GATE_SECRET')) {
        $settings['secret_key'] = BOOKING_CAPTCHA_GATE_SECRET;
    }
    return $settings;
}

/**
 * [captcha_gate]<div id="regiondo-widget">…</div>[/captcha_gate]
 *
 * En la página solo queda un marcador. El contenido real se pide a /unlock tras resolver el captcha.
 */
add_shortcode(GateContent::TAG, function ($atts, $content = null) {
    $post = get_post();
    if (!$post) {
        return '';
    }
    $atts = shortcode_atts(array('message' => __('Confirma que no eres un robot para ver el calendario y reservar.', 'booking-captcha-gate')), $atts);
    $s = settings();

    // Sin claves configuradas el widget se muestra tal cual: no se rompe la venta por un error de configuración
    if ($s['site_key'] === '' || $s['secret_key'] === '') {
        return do_shortcode((string) $content);
    }

    wp_enqueue_script('booking-captcha-gate');
    $html = sprintf(
        '<div class="bcg-gate" data-post="%d" data-gate="%s"><p class="bcg-message">%s</p><div class="bcg-captcha"></div></div>',
        (int) $post->ID,
        esc_attr(GateContent::idFor($content)),
        esc_html($atts['message'])
    );
    return $html;
});

add_action('wp_enqueue_scripts', function () {
    $s = settings();
    $api = $s['mode'] === 'v3'
        ? 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode($s['site_key'])
        : 'https://www.google.com/recaptcha/api.js?render=explicit';
    wp_register_script('google-recaptcha', $api, array(), null, true);
    wp_register_script('booking-captcha-gate', plugins_url('assets/gate.js', __FILE__), array('google-recaptcha'), VERSION, true);
    wp_localize_script('booking-captcha-gate', 'BookingCaptchaGate', array(
        'siteKey' => $s['site_key'],
        'mode' => $s['mode'],
        'endpoint' => esc_url_raw(rest_url('booking-captcha-gate/v1/unlock')),
        'errorText' => __('No se pudo verificar. Inténtalo de nuevo.', 'booking-captcha-gate'),
    ));
});

add_action('rest_api_init', function () {
    register_rest_route('booking-captcha-gate/v1', '/unlock', array(
        'methods' => 'POST',
        'permission_callback' => '__return_true', // público: la autorización ES el captcha
        'args' => array(
            'post' => array('type' => 'integer', 'required' => true, 'minimum' => 1),
            'gate' => array('type' => 'string', 'required' => true, 'pattern' => '^[a-f0-9]{16}$'),
            'token' => array('type' => 'string', 'required' => true),
        ),
        'callback' => __NAMESPACE__ . '\\unlock',
    ));
});

function unlock(\WP_REST_Request $request)
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

    $limiter = new RateLimiter('get_transient', 'set_transient', 10, 10 * MINUTE_IN_SECONDS);
    if (!$limiter->hit($ip)) {
        return new \WP_REST_Response(array('error' => 'too-many-attempts'), 429);
    }

    $post = get_post((int) $request['post']);
    if (!$post || $post->post_status !== 'publish' || post_password_required($post)) {
        return new \WP_REST_Response(array('error' => 'not-found'), 404);
    }
    $inner = GateContent::extract($post->post_content, (string) $request['gate']);
    if ($inner === null) {
        return new \WP_REST_Response(array('error' => 'not-found'), 404);
    }

    $s = settings();
    $verifier = new Verifier($s['secret_key'], __NAMESPACE__ . '\\http_post', (float) $s['min_score'], $s['mode'] === 'v3' ? 'booking_gate' : null);
    $result = $verifier->verify((string) $request['token'], $ip, (string) wp_parse_url(home_url(), PHP_URL_HOST));
    if (!$result['ok']) {
        return new \WP_REST_Response(array('error' => $result['reason']), 403);
    }

    $response = new \WP_REST_Response(array('html' => do_shortcode($inner)), 200);
    $response->header('Cache-Control', 'no-store'); // nunca cachear el widget desbloqueado
    return $response;
}

function http_post($url, array $form)
{
    $res = wp_remote_post($url, array('timeout' => 10, 'body' => $form));
    if (is_wp_error($res)) {
        throw new \RuntimeException($res->get_error_message());
    }
    return array('status' => (int) wp_remote_retrieve_response_code($res), 'body' => (string) wp_remote_retrieve_body($res));
}

// Ajustes → Booking Captcha Gate
add_action('admin_init', function () {
    register_setting('booking_captcha_gate', OPTION, array(
        'type' => 'array',
        'sanitize_callback' => function ($input) {
            return array(
                'site_key' => sanitize_text_field($input['site_key'] ?? ''),
                'secret_key' => sanitize_text_field($input['secret_key'] ?? ''),
                'mode' => in_array($input['mode'] ?? '', array('v2', 'v3'), true) ? $input['mode'] : 'v2',
                'min_score' => min(1, max(0, (float) ($input['min_score'] ?? 0.5))),
            );
        },
    ));
});

add_action('admin_menu', function () {
    add_options_page('Booking Captcha Gate', 'Booking Captcha Gate', 'manage_options', 'booking-captcha-gate', function () {
        $s = settings();
        $secretLocked = defined('BOOKING_CAPTCHA_GATE_SECRET');
        ?>
        <div class="wrap">
            <h1>Booking Captcha Gate</h1>
            <form method="post" action="options.php">
                <?php settings_fields('booking_captcha_gate'); ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="bcg-site">Site key</label></th>
                        <td><input id="bcg-site" class="regular-text" name="<?php echo esc_attr(OPTION); ?>[site_key]" value="<?php echo esc_attr($s['site_key']); ?>"></td></tr>
                    <tr><th><label for="bcg-secret">Secret key</label></th>
                        <td><?php if ($secretLocked) : ?>
                                <em>Definida en wp-config.php (BOOKING_CAPTCHA_GATE_SECRET)</em>
                            <?php else : ?>
                                <input id="bcg-secret" type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr(OPTION); ?>[secret_key]" value="<?php echo esc_attr($s['secret_key']); ?>">
                            <?php endif; ?></td></tr>
                    <tr><th>Versión</th>
                        <td><select name="<?php echo esc_attr(OPTION); ?>[mode]">
                                <option value="v2" <?php selected($s['mode'], 'v2'); ?>>v2 (casilla)</option>
                                <option value="v3" <?php selected($s['mode'], 'v3'); ?>>v3 (invisible, por puntuación)</option>
                            </select></td></tr>
                    <tr><th><label for="bcg-score">Puntuación mínima (v3)</label></th>
                        <td><input id="bcg-score" type="number" min="0" max="1" step="0.1" name="<?php echo esc_attr(OPTION); ?>[min_score]" value="<?php echo esc_attr($s['min_score']); ?>"></td></tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    });
});
