<?php
/** RF02 - Cadastro de frota */
require_once __DIR__ . '/includes/auth.php';
$u = exigir_perfil('gestor');

const TIPOS = ['moto' => 'Moto', 'carro' => 'Carro', 'van' => 'Van', 'caminhao' => 'Caminhão'];
const STATUS_V = ['disponivel' => 'Disponível', 'em_rota' => 'Em rota', 'manutencao' => 'Em manutenção'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['excluir'])) {
        try {
            q('DELETE FROM veiculos WHERE id = ?', [(int)$_POST['excluir']]);
            registrar_log('veiculo_excluido', $_POST['excluir']);
            flash('Veículo excluído.');
        } catch (PDOException) {
            flash('Não é possível excluir: o veículo possui rotas registradas.', 'erro');
        }
    } else {
        $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['placa'] ?? ''));
        $d = [
            $placa, $_POST['tipo'] ?? '', (float)$_POST['capacidade_kg'], (float)$_POST['capacidade_m3'],
            (float)$_POST['autonomia_km'], (float)$_POST['custo_km'], $_POST['status'] ?? '', $_POST['proxima_manutencao'] ?: null,
        ];
        $erros = [];
        if (!preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $placa)) $erros[] = 'Placa inválida (padrão ABC1D23 ou ABC1234).';
        if (!isset(TIPOS[$d[1]]) || !isset(STATUS_V[$d[6]])) $erros[] = 'Tipo ou status inválido.';
        if ($d[2] <= 0 || $d[3] <= 0 || $d[4] <= 0) $erros[] = 'Capacidades e autonomia devem ser positivas.';
        if ($erros) {
            flash(implode(' ', $erros), 'erro');
        } else {
            try {
                if (!empty($_POST['id'])) {
                    q('UPDATE veiculos SET placa=?, tipo=?, capacidade_kg=?, capacidade_m3=?, autonomia_km=?, custo_km=?, status=?, proxima_manutencao=? WHERE id=?', [...$d, (int)$_POST['id']]);
                    flash('Veículo atualizado.');
                } else {
                    q('INSERT INTO veiculos (placa,tipo,capacidade_kg,capacidade_m3,autonomia_km,custo_km,status,proxima_manutencao,lat,lng) VALUES (?,?,?,?,?,?,?,?,?,?)', [...$d, DEPOSITO_LAT, DEPOSITO_LNG]);
                    flash('Veículo cadastrado.');
                }
                registrar_log('veiculo_salvo', $placa);
            } catch (PDOException) {
                flash('Já existe um veículo com essa placa.', 'erro');
            }
        }
    }
    header('Location: veiculos.php');
    exit;
}

$edit = isset($_GET['editar']) ? q('SELECT * FROM veiculos WHERE id = ?', [(int)$_GET['editar']])->fetch() : null;
$lista = q('SELECT v.*, m.nome motorista FROM veiculos v LEFT JOIN motoristas m ON m.veiculo_id = v.id ORDER BY v.placa')->fetchAll();
$titulo = 'Veículos';
require __DIR__ . '/includes/header.php';
?>
<h1>Cadastro de veículos</h1>
<section class="cartao">
  <h2><?= $edit ? 'Editar veículo ' . e($edit['placa']) : 'Novo veículo' ?></h2>
  <form method="post">
    <?= csrf_campo() ?>
    <input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
    <div class="form-grade">
      <label>Placa <input name="placa" required maxlength="8" value="<?= e($edit['placa'] ?? '') ?>" placeholder="ABC1D23"></label>
      <label>Tipo <select name="tipo"><?php foreach (TIPOS as $k => $v): ?><option value="<?= $k ?>" <?= ($edit['tipo'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
      <label>Capacidade (kg) <input type="number" step="0.01" min="0.01" name="capacidade_kg" required value="<?= e($edit['capacidade_kg'] ?? '') ?>"></label>
      <label>Capacidade (m³) <input type="number" step="0.01" min="0.01" name="capacidade_m3" required value="<?= e($edit['capacidade_m3'] ?? '') ?>"></label>
      <label>Autonomia (km) <input type="number" step="0.1" min="1" name="autonomia_km" required value="<?= e($edit['autonomia_km'] ?? '') ?>"></label>
      <label>Custo por km (R$) <input type="number" step="0.01" min="0" name="custo_km" required value="<?= e($edit['custo_km'] ?? '1.50') ?>"></label>
      <label>Status <select name="status"><?php foreach (STATUS_V as $k => $v): ?><option value="<?= $k ?>" <?= ($edit['status'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
      <label>Próxima manutenção <input type="date" name="proxima_manutencao" value="<?= e($edit['proxima_manutencao'] ?? '') ?>"></label>
    </div>
    <button class="btn">Salvar</button>
    <?php if ($edit): ?><a class="btn btn-sec" href="veiculos.php">Cancelar</a><?php endif; ?>
  </form>
</section>
<section class="cartao tabela">
  <h2>Frota (<?= count($lista) ?>)</h2>
  <table>
    <thead><tr><th>Placa</th><th>Tipo</th><th>Capacidade</th><th>Autonomia</th><th>Status</th><th>Próx. manutenção</th><th>Motorista</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $v):
        $venc = $v['proxima_manutencao'] && strtotime($v['proxima_manutencao']) < strtotime('+7 days'); ?>
      <tr>
        <td><strong><?= e($v['placa']) ?></strong></td>
        <td><?= TIPOS[$v['tipo']] ?></td>
        <td><?= e($v['capacidade_kg']) ?> kg · <?= e($v['capacidade_m3']) ?> m³</td>
        <td><?= e($v['autonomia_km']) ?> km</td>
        <td><span class="tag tag-<?= $v['status'] ?>"><?= STATUS_V[$v['status']] ?></span></td>
        <td><?= $v['proxima_manutencao'] ? date('d/m/Y', strtotime($v['proxima_manutencao'])) : '-' ?><?= $venc ? ' <span class="tag tag-erro">em breve</span>' : '' ?></td>
        <td><?= e($v['motorista'] ?? '-') ?></td>
        <td class="acoes">
          <a class="btn btn-sec btn-peq" href="?editar=<?= $v['id'] ?>">Editar</a>
          <form method="post" onsubmit="return confirm('Excluir o veículo <?= e($v['placa']) ?>?')"><?= csrf_campo() ?><button class="btn-perigo btn-peq" name="excluir" value="<?= $v['id'] ?>">Excluir</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
