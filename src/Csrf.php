<?php
declare(strict_types=1);

namespace FNBBOS;

/**
 * Per-session CSRF token. All state-changing forms must include
 * <input type="hidden" name="_csrf" value="<?= Csrf::token() ?>"> and call
 * Csrf::requireValid() on the receiving handler.
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function isValid(?string $token): bool
    {
        $expected = $_SESSION['_csrf'] ?? '';
        return is_string($token) && $expected !== '' && hash_equals($expected, $token);
    }

    public static function requireValid(): void
    {
        $name = (string)\config('security.csrf_token_name', '_csrf');
        $token = $_POST[$name] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (!self::isValid($token)) {
            http_response_code(419);
            echo 'CSRF token invalid or expired. Please refresh the page and try again.';
            exit;
        }
    }

    public static function field(): string
    {
        $name = (string)\config('security.csrf_token_name', '_csrf');
        return '<input type="hidden" name="' . \e($name) . '" value="' . \e(self::token()) . '">';
    }
}
