<?php
namespace lib;

final class Http
{
    public static function session(string $name): void
    {
        ini_set('display_errors', '0');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || getenv('COOKIE_SECURE') === '1',
            'httponly' => true, 'samesite' => 'Strict',
        ]);
        if (!session_start()) {
            throw new \RuntimeException('Session unavailable');
        }
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
    }

    public static function input(): array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['error' => 'Use POST for this action.'], 405);
        }
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
            self::json(['error' => 'Cross-site request rejected.'], 403);
        }
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            self::json(['error' => 'Refresh the page and try again.'], 403);
        }
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if (strlen($raw) > 16384) {
            self::json(['error' => 'Request is too large.'], 413);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::json(['error' => 'Invalid request.'], 400);
        }
        return $data;
    }

    public static function text(array $data, string $key, int $max): string
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || strlen($data[$key]) > $max) {
            throw new \InvalidArgumentException('Invalid ' . str_replace('_', ' ', $key) . '.');
        }
        return trim($data[$key]);
    }

    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
