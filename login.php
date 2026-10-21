<?php
require_once __DIR__ . '/includes/auth.php';

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir_csrf();
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $perfil = $_POST['perfil'] ?? '';

    // Limite simples de tentativas por sessão contra força bruta
    $_SESSION['tentativas'] = ($_SESSION['tentativas'] ?? 0) + 1;
    if ($_SESSION['tentativas'] > 5 && time() - ($_SESSION['ultima_tentativa'] ?? 0) < 60) {
        $erro = 'Muitas tentativas. Aguarde 1 minuto.';
    } else {
        $_SESSION['ultima_tentativa'] = time();
        $u = q('SELECT * FROM usuarios WHERE email = ?', [$email])->fetch();
        if ($u && password_verify($senha, $u['senha_hash']) && $u['perfil'] === $perfil) {
            session_regenerate_id(true);
            $_SESSION['usuario'] = ['id' => $u['id'], 'nome' => $u['nome'], 'perfil' => $u['perfil']];
            $_SESSION['tentativas'] = 0;
            registrar_log('login', $u['email']);
            header('Location: ' . painel_do_perfil($u['perfil']));
            exit;
        }
        $erro = 'E-mail, senha ou perfil inválidos.';
    }
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Entrar · <?= APP_NOME ?></title>
<script>try { if (localStorage.getItem('tema') === 'escuro') document.documentElement.dataset.tema = 'escuro'; } catch (e) {}</script>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="tela-login">
<main class="cartao login">
  <h1><span class="logo" aria-hidden="true">S</span> SENN AI-X</h1>
  <p class="sub">Gestão inteligente de entregas de última milha</p>
  <?php if (!$erro && ($f = flash())): ?><div class="aviso aviso-<?= e($f[1]) ?>" role="status"><?= e($f[0]) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="aviso aviso-erro" role="alert"><?= e($erro) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_campo() ?>
    <label>E-mail <input type="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>"></label>
    <label>Senha <input type="password" name="senha" required></label>
    <label>Perfil
      <select name="perfil" required>
        <?php foreach (PERFIS as $k => $v): ?>
          <option value="<?= $k ?>" <?= ($_POST['perfil'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn">Entrar</button>
  </form>
  <p class="demo">Esqueceu a senha? Fale com o administrador da sua transportadora.<br><a href="privacidade.php">Política de privacidade</a></p>
</main>
</body>
</html>
