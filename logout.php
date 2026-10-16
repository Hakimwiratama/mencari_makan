
<?php
require_once __DIR__ . '/config.php';

// Hapus semua data session login.
$_SESSION = [];

// Hapus cookie session jika digunakan.
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Hapus session di server.
session_destroy();

// Kembali ke halaman login.
header('Location: login.php');
exit;
