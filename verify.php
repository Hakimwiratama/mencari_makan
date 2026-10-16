<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
if (empty($_SESSION['pending_user']) || !isset($_SESSION['otp'], $_SESSION['otp_expiry'])) {
    header('Location: login.php'); exit;
}
$error = '';
$expired = time() > (int)$_SESSION['otp_expiry'];
if ($expired) {
    unset($_SESSION['otp'], $_SESSION['otp_expiry']);
    $error = 'Kode OTP kedaluwarsa. Silakan login kembali.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = trim($_POST['otp'] ?? '');
    if (preg_match('/^[0-9]{6}$/', $input) && hash_equals((string)$_SESSION['otp'], $input)) {
        $user = $_SESSION['pending_user'];
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['nama'] = $user['nama'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['peran'] = $_SESSION['pending_user']['peran'];
        unset($_SESSION['pending_user'], $_SESSION['otp'], $_SESSION['otp_expiry']);
        header('Location: pilih_peran.php'); exit;
    }
    $error = 'Kode OTP salah. Pastikan memasukkan 6 digit yang benar.';
}
$demoOtp = $_SESSION['otp'] ?? '';
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Verifikasi OTP</title><link rel="stylesheet" href="style.css"></head><body>
<header><h1>🔐 Verifikasi OTP</h1><p>Selangkah lagi menuju Mencari Makan.</p></header><main><section class="panel" style="max-width:520px;margin:30px auto"><h2>Masukkan kode OTP</h2>
<?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<?php if (!$expired): ?><p>Kode OTP demo kamu:</p><h2><?= e($demoOtp) ?></h2><p class="muted">Kode berlaku selama 5 menit.</p><form method="post"><label for="otp">OTP 6 digit</label><input id="otp" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required><button>Verifikasi</button></form><?php endif; ?>
<p><a href="login.php">Kembali ke login</a></p></section></main></body></html>
