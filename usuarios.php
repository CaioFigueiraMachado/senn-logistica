<?php
/** RF01 - Cadastro de usuários e perfis (somente administrador) */
require_once __DIR__ . '/includes/auth.php';
$u = exigir_perfil(); // sem perfis listados = apenas admin passa
if ($u['perfil'] !== 'admin') { http_response_code(403); exit('Acesso restrito ao administrador.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['excluir'])) {
        if ((int)$_POST['excluir'] === $u['id']) flash('Você não pode excluir a própria conta.', 'erro');
        else { q('DELETE FROM usuarios WHERE id = ?', [(int)$_POST['excluir']]); registrar_log('usuario_excluido', $_POST['excluir']); flash('Usuário excluído.'); }
    } else {
        $nome = trim($_POST['nome'] ?? ''); $email = trim($_POST['email'] ?? ''); $perfil = $_POST['perfil'] ?? ''; $senha = $_POST['senha'] ?? '';
        $erros = [];
        if (mb_strlen($nome) < 3) $erros[] = 'Informe o nome.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erros[] = 'E-mail inválido.';
        if (!isset(PERFIS[$perfil])) $erros[] = 'Perfil inválido.';
        // Política de senha forte
        if (strlen($senha) < 8 || !preg_match('/[A-Z]/', $senha) || !preg_match('/[0-9]/', $senha) || !preg_match('/[^A-Za-z0-9]/', $senha))
            $erros[] = 'A senha precisa de 8+ caracteres com maiúscula, número e símbolo.';
        if (!isset($_POST['consentimento'])) $erros[] = 'É necessário o consentimento LGPD.';
        if ($erros) flash(implode(' ', $erros), 'erro');
        else {
            try {
                q('INSERT INTO usuarios (nome,email,senha_hash,perfil,consentimento_lgpd) VALUES (?,?,?,?,1)', [$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
                registrar_log('usuario_criado', $email);
                flash('Usuário cadastrado.');
            } catch (PDOException) { flash('E-mail já cadastrado.', 'erro'); }
        }
    }
    header('Location: usuarios.php');
    exit;
}

$lista = q('SELECT id, nome, email, perfil, criado_em FROM usuarios ORDER BY nome')->fetchAll();
$titulo = 'Usuários';
require __DIR__ . '/includes/header.php';
?>
<h1>Usuários e permissões</h1>
<section class="cartao">
  <h2>Novo usuário</h2>
  <form method="post"><?= csrf_campo() ?>
    <div class="form-grade">
      <label>Nome <input name="nome" required></label>
      <label>E-mail <input type="email" name="email" required></label>
      <label>Senha <input type="password" name="senha" required minlength="8"></label>
      <label>Perfil <select name="perfil"><?php foreach (PERFIS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></label>
    </div>
    <label><input type="checkbox" name="consentimento"> O usuário consente com o tratamento dos dados conforme a LGPD</label>
    <button class="btn">Cadastrar</button>
  </form>
</section>
<section class="cartao tabela">
  <table><thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Desde</th><th></th></tr></thead><tbody>
  <?php foreach ($lista as $x): ?>
    <tr><td><?= e($x['nome']) ?></td><td><?= e($x['email']) ?></td><td><?= PERFIS[$x['perfil']] ?></td><td><?= date('d/m/Y', strtotime($x['criado_em'])) ?></td>
      <td><?php if ($x['id'] !== $u['id']): ?><form method="post" onsubmit="return confirm('Excluir este usuário?')"><?= csrf_campo() ?><button class="btn-perigo btn-peq" name="excluir" value="<?= $x['id'] ?>">Excluir</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
