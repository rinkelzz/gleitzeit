<?php
require_once __DIR__ . '/includes/auth.php';

startSecureSession();
if (isLoggedIn()) {
    header('Location: /index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if (login($password)) {
        header('Location: /index.php');
        exit;
    }
    $error = 'Falsches Passwort.';
    // Small delay to slow brute-force
    sleep(1);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gleitzeit — Login</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="login-page">
<main class="login-box">
    <h1>⏱ Gleitzeit</h1>
    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/login.php">
        <label for="password">Passwort</label>
        <input type="password" id="password" name="password" autofocus required>
        <button type="submit">Einloggen</button>
    </form>
</main>
</body>
</html>
