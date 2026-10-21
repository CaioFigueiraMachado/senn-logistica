<?php
/** RF01 - Cadastro e edição de usuários e perfis (somente administrador) */
require_once __DIR__ . '/includes/auth.php';
$u = exigir_perfil(); // sem perfis listados = apenas admin passa
if ($u['perfil'] !== 'admin') { http_response_code(403); exit('Acesso restrito ao administrador.'); }

/** Política de senha forte: 8+ caracteres com maiúscula, número e símbolo. */
function senha_forte(string $s): bool {
    return strlen($s) >= 8 && preg_match('/[A-Z]/', $s) && preg_match('/[0-9]/', $s) && preg_match('/[^A-Za-z0-9]/', $s);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $volta = 'usuarios.php';
    if (isset($_POST['excluir'])) {
        if ((int)$_POST['excluir'] === $u['id']) flash('Você não pode excluir a própria conta.', 'erro');
        else { q('DELETE FROM usuarios WHERE id = ?', [(int)$_POST['excluir']]); registrar_log('usuario_excluido', $_POST['excluir']); flash('Usuário excluído.'); }
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? ''); $email = trim($_POST['email'] ?? ''); $perfil = $_POST['perfil'] ?? ''; $senha = $_POST['senha'] ?? '';
        $erros = [];
        if (mb_strlen($nome) < 3) $erros[] = 'Informe o nome.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erros[] = 'E-mail inválido.';
        if (!isset(PERFIS[$perfil])) $erros[] = 'Perfil inválido.';
        // na edição, senha em branco mantém a atual
        if (($id === 0 || $senha !== '') && !senha_forte($senha)) $erros[] = 'A senha precisa de 8+ caracteres com maiúscula, número e símbolo.';
        if ($id === 0 && !isset($_POST['consentimento'])) $erros[] = 'É necessário o consentimento LGPD.';
        // evita que o sistema fique sem nenhum administrador
        if ($id === $u['id'] && $perfil !== 'admin') $erros[] = 'Você não pode tirar o perfil de administrador da própria conta.';

        if ($erros) {
            flash(implode(' ', $erros), 'erro');
            if ($id) $volta = "usuarios.php?editar=$id";
        } else {
            try {
                if ($id) {
                    q('UPDATE usuarios SET nome = ?, email = ?, perfil = ? WHERE id = ?', [$nome, $email, $perfil, $id]);
                    if ($senha !== '') q('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [password_hash($senha, PASSWORD_DEFAULT), $id]);
                    if ($id === $u['id']) $_SESSION['usuario']['nome'] = $nome;
                    registrar_log('usuario_editado', $email);
                    flash('Usuário atualizado.' . ($senha !== '' ? ' A senha foi trocada.' : ''));
                } else {
                    q('INSERT INTO usuarios (nome,email,senha_hash,perfil,consentimento_lgpd) VALUES (?,?,?,?,1)', [$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $perfil]);
                    registrar_log('usuario_criado', $email);
                    flash('Usuário cadastrado.');
                }
            } catch (PDOException) {
                flash('Já existe outro usuário com esse e-mail.', 'erro');
                if ($id) $volta = "usuarios.php?editar=$id";
            }
        }
    }
    header("Location: $volta");
    exit;
}

$edit = isset($_GET['editar']) ? q('SELECT id, nome, email, perfil FROM usuarios WHERE id = ?', [(int)$_GET['editar']])->fetch() : null;
$lista = q('SELECT id, nome, email, perfil, criado_em FROM usuarios ORDER BY nome')->fetchAll();
$titulo = 'Usuários';
require __DIR__ . '/includes/header.php';
?>
<h1>Usuários e permissões</h1>
<section class="cartao">
  <h2><?= $edit ? 'Editar ' . e($edit['nome']) : 'Novo usuário' ?></h2>
  <form method="post"><?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
    <div class="form-grade">
      <label>Nome <input name="nome" required value="<?= e($edit['nome'] ?? '') ?>"></label>
      <label>E-mail <input type="email" name="email" required value="<?= e($edit['email'] ?? '') ?>"></label>
      <label><?= $edit ? 'Nova senha' : 'Senha' ?>
        <input type="password" name="senha" <?= $edit ? '' : 'required' ?> minlength="8" autocomplete="new-password" placeholder="<?= $edit ? 'Deixe em branco para manter a atual' : '' ?>"></label>
      <label>Perfil <select name="perfil"><?php foreach (PERFIS as $k => $v): ?><option value="<?= $k ?>" <?= ($edit['perfil'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
    </div>
    <?php if (!$edit): ?>
      <label><input type="checkbox" name="consentimento"> O usuário consente com o tratamento dos dados conforme a LGPD</label>
    <?php endif; ?>
    <p class="ia-fonte">A senha precisa ter pelo menos 8 caracteres, com letra maiúscula, número e símbolo.</p>
    <button class="btn"><?= $edit ? 'Salvar alterações' : 'Cadastrar' ?></button>
    <?php if ($edit): ?><a class="btn btn-sec" href="usuarios.php">Cancelar</a><?php endif; ?>
  </form>
</section>
<section class="cartao tabela">
  <h2>Usuários (<?= count($lista) ?>)</h2>
  <table><thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Desde</th><th></th></tr></thead><tbody>
  <?php foreach ($lista as $x): ?>
    <tr><td><?= e($x['nome']) ?><?= $x['id'] === $u['id'] ? ' <span class="tag">você</span>' : '' ?></td><td><?= e($x['email']) ?></td><td><?= PERFIS[$x['perfil']] ?></td><td><?= date('d/m/Y', strtotime($x['criado_em'])) ?></td>
      <td class="acoes">
        <a class="btn btn-sec btn-peq" href="?editar=<?= $x['id'] ?>">Editar</a>
        <?php if ($x['id'] !== $u['id']): ?><form method="post" onsubmit="return confirm('Excluir este usuário?')"><?= csrf_campo() ?><button class="btn-perigo btn-peq" name="excluir" value="<?= $x['id'] ?>">Excluir</button></form><?php endif; ?>
      </td></tr>
  <?php endforeach; ?>
  </tbody></table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
