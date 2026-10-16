
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Mengamankan teks sebelum ditampilkan di HTML.
 */
function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * Memastikan pengguna sudah login.
 */
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Memastikan pengguna memiliki peran tertentu.
 */
function require_role(string $role): void
{
    require_login();

    if (($_SESSION['peran'] ?? '') !== $role) {
        header('Location: pilih_peran.php');
        exit;
    }
}

/**
 * Membuat token CSRF untuk keamanan formulir.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Memvalidasi token CSRF.
 */
function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !is_string($sessionToken) ||
        $sessionToken === '' ||
        !hash_equals($sessionToken, $token)
    ) {
        http_response_code(403);
        exit('Permintaan tidak valid. Muat ulang halaman lalu coba lagi.');
    }
}

/**
 * Mengambil data restoran milik pengguna yang login.
 */
function current_restaurant(PDO $pdo): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT *
         FROM restaurants
         WHERE owner_id = ?
         LIMIT 1'
    );

    $stmt->execute([(int) $_SESSION['user_id']]);
    $restaurant = $stmt->fetch(PDO::FETCH_ASSOC);

    return $restaurant ?: null;
}
