<?php
/**
 * Localiza el contenido protegido de cada [captcha_gate]…[/captcha_gate] de un post.
 *
 * El HTML del widget nunca viaja en la página: el endpoint de desbloqueo lo vuelve a extraer
 * del contenido del post solo cuando el captcha se ha verificado en el servidor.
 *
 * Cada gate se identifica por el hash de su contenido, no por su posición: WordPress puede
 * renderizar el mismo contenido varias veces por petición (extractos, plugins SEO…) y un
 * contador de posición se desincronizaría.
 */

namespace BookingCaptchaGate;

final class GateContent
{
    const TAG = 'captcha_gate';

    /** Identificador estable de un gate a partir de su contenido interior. */
    public static function idFor($inner)
    {
        return substr(hash('sha256', trim((string) $inner)), 0, 16);
    }

    public static function isValidId($id)
    {
        return is_string($id) && preg_match('/^[a-f0-9]{16}$/', $id) === 1;
    }

    /** Contenido interior del gate con identificador $id en $postContent, o null. */
    public static function extract($postContent, $id)
    {
        if (!is_string($postContent) || !self::isValidId($id)) {
            return null;
        }
        foreach (self::all($postContent) as $inner) {
            if (hash_equals(self::idFor($inner), $id)) {
                return $inner;
            }
        }
        return null;
    }

    /** Número de gates del contenido. */
    public static function count($postContent)
    {
        return count(self::all($postContent));
    }

    /** @return string[] contenido interior de cada gate, en orden */
    private static function all($postContent)
    {
        if (!is_string($postContent)) {
            return array();
        }
        $pattern = '/\[' . self::TAG . '(?:\s[^\]]*)?\](.*?)\[\/' . self::TAG . '\]/s';
        return preg_match_all($pattern, $postContent, $m) ? array_map('trim', $m[1]) : array();
    }
}
