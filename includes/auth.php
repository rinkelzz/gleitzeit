<?php
require_once __DIR__ . '/../config.php';

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function requireLogin(): void {
    startSecureSession();
    if (empty($_SESSION['authenticated'])) {
        header('Location: /login.php');
        exit;
    }
}

function login(string $password): bool {
    startSecureSession();
    if (password_verify($password, LOGIN_PASSWORD_HASH)) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        return true;
    }
    return false;
}

function logout(): void {
    startSecureSession();
    $_SESSION = [];
    session_destroy();
}

function isLoggedIn(): bool {
    startSecureSession();
    return !empty($_SESSION['authenticated']);
}

function generateCsrfToken(): string {
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool {
    startSecureSession();
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function requireApiKey(): void {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!hash_equals(API_KEY, $key)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

function checkRateLimit(): void {
    startSecureSession();
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'rate_' . md5($ip);

    // Use APCu if available, otherwise fall back to session-based limiting
    if (function_exists('apcu_fetch')) {
        $data = apcu_fetch($key, $success);
        if (!$success) {
            apcu_store($key, ['count' => 1, 'start' => time()], RATE_LIMIT_WINDOW);
            return;
        }
        if (time() - $data['start'] > RATE_LIMIT_WINDOW) {
            apcu_store($key, ['count' => 1, 'start' => time()], RATE_LIMIT_WINDOW);
            return;
        }
        if ($data['count'] >= RATE_LIMIT_MAX) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Too many requests']);
            exit;
        }
        $data['count']++;
        apcu_store($key, $data, RATE_LIMIT_WINDOW);
    } else {
        // Session-based fallback
        if (!isset($_SESSION['rate_limit'])) {
            $_SESSION['rate_limit'] = ['count' => 0, 'start' => time()];
        }
        $rl = &$_SESSION['rate_limit'];
        if (time() - $rl['start'] > RATE_LIMIT_WINDOW) {
            $rl = ['count' => 0, 'start' => time()];
        }
        $rl['count']++;
        if ($rl['count'] > RATE_LIMIT_MAX) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Too many requests']);
            exit;
        }
    }
}
