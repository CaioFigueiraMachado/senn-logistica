<?php
/** RF03 - Cadastro de motoristas (com consentimento e exclusão LGPD) */
require_once __DIR__ . '/includes/auth.php';
$u = exigir_perfil('gestor');

const CATEGORIAS = ['A', 'B', 'AB', 'C', 'D', 'E'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['excluir'])) {
        // LGPD: exclusão definitiva dos dados pessoais do motorista
        q('DELETE FROM motoristas WHERE id = ?', [(int)$_POST['excluir']]);
        registrar_log('motorista_excluido_lgpd', $_POST['excluir']);
        flash('Dados do motorista excluídos (LGPD).');
    } else {
        $d = [
            trim($_POST['nome'] ?? ''), $_POST['cnh_categoria'] ?? '', $_POST['cnh_validade'] ?? '',
            trim($_POST['telefone'] ?? ''), isset($_POST['disponivel']) ? 1 : 0,
            $_POST['veiculo_id'] ? (int)$_POST['veiculo_id'] : null,
            $_POST['usuario_id'] ? (int)$_POST['usuario_id'] : null,
            isset($_POST['consentimento']) ? 1 : 0,
        ];
        $erros = [];
        if (mb_strlen($d[0]) < 3) $erros[] = 'Informe o nome.';
        if (!in_array($d[1], CATEGORIAS, true)) $erros[] = 'Categoria de CNH inválida.';
        if (!strtotime($d[2])) $erros[] = 'Validade da CNH inválida.';
        if (!preg_match('/^[0-9()+\-\s]{8,20}$/', $d[3])) $erros[] = 'Telefone inválido.';
        if (!$d[7]) $erros[] = 'É necessário o consentimento do motorista para o tratamento dos dados (LGPD).';
        if ($erros) {
            flash(implode(' ', $erros), 'erro');
        } else {
            if (!empty($_POST['id'])) {
                q('UPDATE motoristas SET nome=?, cnh_categoria=?, cnh_validade=?, telefone=?, disponivel=?, veiculo_id=?, usuario_id=?, consentimento_lgpd=? WHERE id=?', [...$d, (int)$_POST['id']]);
                flash('Motorista atualizado.');
            } else {
                q('INSERT INTO motoristas (nome,cnh_categoria,cnh_validade,telefone,disponivel,veiculo_id,usuario_id,consentimento_lgpd) VALUES (?,?,?,?,?,?,?,?)', $d);
                flash('Motorista cadastrado.');
            }
            registrar_log('motorista_salvo', $d[0]);
        }
    }
    header('Location: motoristas.php');
    exit;
}

$edit = isset($_GET['editar']) ? q('SELECT * FROM motoristas WHERE id = ?', [(int)$_GET['editar']])->fetch() : null;
$veiculos = q('SELECT id, placa, tipo FROM veiculos ORDER BY placa')->fetchAll();
$contas = q("SELECT id, nome, email FROM usuarios WHERE perfil = 'motorista' ORDER BY nome")->fetchAll();
$lista = q('SELECT m.*, v.placa FROM motoristas m LEFT JOIN veiculos v ON v.id = m.veiculo_id ORDER BY m.nome')->fetchAll();
$titulo = 'Motoristas';
require __DIR__ . '/includes/header.php';
?>
<h1>Cadastro de motoristas</h1>
<section class="cartao">
  <h2><?= $edit ? 'Editar ' . e($edit['nome']) : 'Novo motorista' ?></h2>
  <form method="post">
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
    <div class="form-grade">
      <label>Nome <input name="nome" required value="<?= e($edit['nome'] ?? '') ?>"></label>
      <label>Categoria CNH <select name="cnh_categoria"><?php foreach (CATEGORIAS as $c): ?><option <?= ($edit['cnh_categoria'] ?? '') === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></label>
      <label>Validade CNH <input type="date" name="cnh_validade" required value="<?= e($edit['cnh_validade'] ?? '') ?>"></label>
      <label>Telefone <input name="telefone" required value="<?= e($edit['telefone'] ?? '') ?>" placeholder="(12) 99999-0000"></label>
      <label>Veículo vinculado
        <select name="veiculo_id"><option value="">Nenhum</option>
          <?php foreach ($veiculos as $v): ?><option value="<?= $v['id'] ?>" <?= ($edit['veiculo_id'] ?? '') == $v['id'] ? 'selected' : '' ?>><?= e($v['placa']) ?> (<?= e($v['tipo']) ?>)</option><?php endforeach; ?>
        </select>
      </label>
      <label>Conta de acesso
        <select name="usuario_id"><option value="">Nenhuma</option>
          <?php foreach ($contas as $c): ?><option value="<?= $c['id'] ?>" <?= ($edit['usuario_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?> (<?= e($c['email']) ?>)</option><?php endforeach; ?>
        </select>
      </label>
    </div>
    <label><input type="checkbox" name="disponivel" <?= ($edit['disponivel'] ?? 1) ? 'checked' : '' ?>> Disponível para rotas</label>
    <label><input type="checkbox" name="consentimento" <?= ($edit['consentimento_lgpd'] ?? 0) ? 'checked' : '' ?>> O motorista consentiu com o uso dos dados pessoais para fins de gestão de entregas (LGPD)</label>
    <button class="btn">Salvar</button>
    <?php if ($edit): ?><a class="btn btn-sec" href="motoristas.php">Cancelar</a><?php endif; ?>
  </form>
</section>
<section class="cartao tabela">
  <h2>Motoristas (<?= count($lista) ?>)</h2>
  <table>
    <thead><tr><th>Nome</th><th>CNH</th><th>Validade</th><th>Contato</th><th>Disponível</th><th>Veículo</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $m):
        $vence = strtotime($m['cnh_validade']) < strtotime('+30 days'); ?>
      <tr>
        <td><?= e($m['nome']) ?></td>
        <td><?= e($m['cnh_categoria']) ?></td>
        <td><?= date('d/m/Y', strtotime($m['cnh_validade'])) ?><?= $vence ? ' <span class="tag tag-erro">a vencer</span>' : '' ?></td>
        <td><?= e($m['telefone']) ?></td>
        <td><span class="tag <?= $m['disponivel'] ? 'tag-ok' : '' ?>"><?= $m['disponivel'] ? 'Sim' : 'Não' ?></span></td>
        <td><?= e($m['placa'] ?? '-') ?></td>
        <td class="acoes">
          <a class="btn btn-sec btn-peq" href="?editar=<?= $m['id'] ?>">Editar</a>
          <form method="post" onsubmit="return confirm('Excluir definitivamente os dados de <?= e($m['nome']) ?>?')"><?= csrf_campo() ?><button class="btn-perigo btn-peq" name="excluir" value="<?= $m['id'] ?>">Excluir (LGPD)</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
