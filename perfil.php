<?php
/** Meu perfil: dados da conta e troca de senha (todos os perfis). */
require_once __DIR__ . '/includes/auth.php';
$u = exigir_perfil('gestor', 'despachante', 'motorista');
$conta = q('SELECT * FROM usuarios WHERE id = ?', [$u['id']])->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['acao'] ?? '') === 'dados') {
        $nome = trim($_POST['nome'] ?? '');
        if (mb_strlen($nome) < 3) flash('Informe seu nome completo.', 'erro');
        else {
            q('UPDATE usuarios SET nome = ? WHERE id = ?', [$nome, $u['id']]);
            $_SESSION['usuario']['nome'] = $nome;
            registrar_log('perfil_atualizado');
            flash('Seus dados foram atualizados.');
        }
    } elseif (($_POST['acao'] ?? '') === 'senha') {
        $atual = $_POST['atual'] ?? ''; $nova = $_POST['nova'] ?? ''; $conf = $_POST['confirmacao'] ?? '';
        if (!password_verify($atual, $conta['senha_hash'])) flash('A senha atual não confere.', 'erro');
        elseif ($nova !== $conf) flash('A confirmação não é igual à nova senha.', 'erro');
        elseif (strlen($nova) < 8 || !preg_match('/[A-Z]/', $nova) || !preg_match('/[0-9]/', $nova) || !preg_match('/[^A-Za-z0-9]/', $nova))
            flash('A nova senha precisa de 8+ caracteres com maiúscula, número e símbolo.', 'erro');
        else {
            q('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [password_hash($nova, PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            registrar_log('senha_trocada');
            flash('Senha trocada com sucesso.');
        }
    }
    header('Location: perfil.php');
    exit;
}

$motorista = $u['perfil'] === 'motorista' ? q('SELECT m.*, v.placa FROM motoristas m LEFT JOIN veiculos v ON v.id = m.veiculo_id WHERE m.usuario_id = ?', [$u['id']])->fetch() : null;
$ultimo = q("SELECT criado_em FROM logs WHERE usuario_id = ? AND acao = 'login' ORDER BY id DESC LIMIT 1 OFFSET 1", [$u['id']])->fetchColumn();
$iniciaisPerfil = mb_strtoupper(implode('', array_map(fn($p) => mb_substr($p, 0, 1), array_slice(explode(' ', $conta['nome']), 0, 2))));

$titulo = 'Meu perfil';
require __DIR__ . '/includes/header.php';
?>
<h1>Meu perfil</h1>
<section class="cartao perfil-topo">
  <span class="avatar avatar-grande" aria-hidden="true"><?= e($iniciaisPerfil) ?></span>
  <div>
    <h2 style="margin: 0"><?= e($conta['nome']) ?></h2>
    <p class="ia-fonte" style="margin: 4px 0 0"><?= e($conta['email']) ?> · <?= PERFIS[$conta['perfil']] ?></p>
    <p class="ia-fonte" style="margin: 2px 0 0">Conta criada em <?= date('d/m/Y', strtotime($conta['criado_em'])) ?><?= $ultimo ? ' · Acesso anterior em ' . date('d/m/Y \à\s H:i', strtotime($ultimo)) : '' ?></p>
  </div>
</section>

<div class="grade">
  <section class="cartao">
    <h2>Meus dados</h2>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="dados">
      <label>Nome <input name="nome" required value="<?= e($conta['nome']) ?>"></label>
      <label>E-mail <input value="<?= e($conta['email']) ?>" disabled></label>
      <label>Perfil <input value="<?= PERFIS[$conta['perfil']] ?>" disabled></label>
      <p class="ia-fonte">Para mudar e-mail ou perfil, fale com o administrador.</p>
      <button>Salvar</button>
    </form>
  </section>
  <section class="cartao">
    <h2>Trocar senha</h2>
    <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="senha">
      <label>Senha atual <input type="password" name="atual" required autocomplete="current-password"></label>
      <label>Nova senha <input type="password" name="nova" required minlength="8" autocomplete="new-password"></label>
      <label>Confirmar nova senha <input type="password" name="confirmacao" required minlength="8" autocomplete="new-password"></label>
      <p class="ia-fonte">Pelo menos 8 caracteres, com letra maiúscula, número e símbolo.</p>
      <button>Trocar senha</button>
    </form>
  </section>
  <?php if ($motorista): ?>
  <section class="cartao">
    <h2>Dados de motorista</h2>
    <table>
      <tr><th>CNH</th><td>Categoria <?= e($motorista['cnh_categoria']) ?>, válida até <?= date('d/m/Y', strtotime($motorista['cnh_validade'])) ?></td></tr>
      <tr><th>Telefone</th><td><?= e($motorista['telefone']) ?></td></tr>
      <tr><th>Veículo</th><td><?= e($motorista['placa'] ?? 'Nenhum vinculado') ?></td></tr>
    </table>
  </section>
  <?php endif; ?>
  <section class="cartao">
    <h2>Privacidade</h2>
    <p>Seus dados são usados apenas para a gestão das entregas. Veja a <a href="privacidade.php">política de privacidade</a> ou peça a exclusão dos seus dados ao administrador.</p>
    <a class="btn btn-sec" href="logout.php">Sair da conta</a>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
