<?php
/** RF05 - Roteirização e otimização de rotas */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ia.php';
$u = exigir_perfil('despachante', 'gestor');

$data = $_GET['data'] ?? date('Y-m-d');
if (!strtotime($data)) $data = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'gerar') {
        $ids = array_map('intval', $_POST['pedidos'] ?? []);
        $vids = array_map('intval', $_POST['veiculos'] ?? []);
        if (!$ids || !$vids) {
            flash('Selecione ao menos um pedido e um veículo.', 'erro');
        } else {
            $in = fn($a) => implode(',', array_fill(0, count($a), '?'));
            $pedidos = q("SELECT * FROM pedidos WHERE status = 'pendente' AND id IN ({$in($ids)})", $ids)->fetchAll();
            $veiculos = q("SELECT * FROM veiculos WHERE status = 'disponivel' AND id IN ({$in($vids)})", $vids)->fetchAll();
            $res = otimizar_rotas($pedidos, $veiculos, $data);
            salvar_rotas($res['rotas'], $data);
            $n = count($res['rotas']);
            $km = array_sum(array_column($res['rotas'], 'distancia_km'));
            registrar_log('rotas_geradas', "$n rotas, $km km");
            $msg = "$n rota(s) otimizada(s), total de " . number_format($km, 1, ',', '.') . ' km.';
            if ($res['nao_alocados']) $msg .= ' ' . count($res['nao_alocados']) . ' pedido(s) não couberam (capacidade ou janela): ' . implode(', ', array_column($res['nao_alocados'], 'codigo')) . '.';
            flash($msg, $res['nao_alocados'] ? 'info' : 'ok');
        }
    } elseif ($acao === 'liberar') {
        $rid = (int)$_POST['rota'];
        q("UPDATE rotas SET status = 'liberada' WHERE id = ? AND status = 'planejada'", [$rid]);
        registrar_log('rota_liberada', (string)$rid);
        flash('Rota liberada para o motorista.');
    } elseif ($acao === 'desfazer') {
        $rid = (int)$_POST['rota'];
        q("UPDATE pedidos SET status = 'pendente', rota_id = NULL, ordem_rota = NULL WHERE rota_id = ? AND status = 'roteirizado'", [$rid]);
        q("DELETE FROM rotas WHERE id = ? AND status IN ('planejada','liberada')", [$rid]);
        flash('Rota desfeita. Os pedidos voltaram para pendente.');
    }
    header("Location: rotas.php?data=$data");
    exit;
}

$pendentes = q("SELECT * FROM pedidos WHERE status = 'pendente' AND DATE(janela_inicio) = ? ORDER BY janela_inicio", [$data])->fetchAll();
$disponiveis = q("SELECT * FROM veiculos WHERE status = 'disponivel' ORDER BY capacidade_kg DESC")->fetchAll();
$rotas = q('SELECT r.*, v.placa, v.tipo, m.nome motorista FROM rotas r JOIN veiculos v ON v.id = r.veiculo_id
            LEFT JOIN motoristas m ON m.id = r.motorista_id WHERE r.data_rota = ? ORDER BY r.id', [$data])->fetchAll();
$paradas = [];
foreach ($rotas as $r) {
    $paradas[$r['id']] = q('SELECT id, codigo, destinatario, endereco, lat, lng, status, janela_inicio, janela_fim, ordem_rota FROM pedidos WHERE rota_id = ? ORDER BY ordem_rota', [$r['id']])->fetchAll();
}

$titulo = 'Rotas';
$usaMapa = true;
require __DIR__ . '/includes/header.php';
?>
<h1>Roteirização inteligente</h1>
<form class="acoes" method="get"><label>Data <input type="date" name="data" value="<?= e($data) ?>"></label><button>Ver</button></form>

<details class="cartao ia-card">
  <summary><strong>Como a IA monta as rotas</strong> · VRP com capacidade e janelas de tempo (CVRPTW)</summary>
  <ol>
    <li><strong>Priorização:</strong> pedidos ordenados por prioridade e pelo fim da janela; veículos do maior para o menor.</li>
    <li><strong>Construção (vizinho mais próximo):</strong> a partir do depósito, cada veículo recebe a próxima entrega viável de menor custo, respeitando peso (kg), volume (m³), janela de entrega e autonomia.</li>
    <li><strong>Melhoria (2-opt):</strong> inverte trechos da rota enquanto a distância diminuir sem violar nenhuma janela.</li>
    <li><strong>Estimativas:</strong> distância por Haversine × 1,3 (malha urbana), 28 km/h de média, 6 min por parada; custo = km × custo/km do veículo.</li>
  </ol>
  <p class="ia-fonte">Algoritmo local em PHP (<code>includes/ia.php</code>): funciona sem internet. Detalhes no README.</p>
</details>
<?php if ($rotas):
    $kmT = array_sum(array_column($rotas, 'distancia_km'));
    $kmB = array_sum(array_map(fn($r) => max($r['distancia_base_km'], $r['distancia_km']), $rotas));
    $econ = $kmB > 0 ? round(($kmB - $kmT) / $kmB * 100, 1) : 0; ?>
<div class="kpis">
  <div class="kpi"><div class="v"><?= count($rotas) ?></div><div class="r">Rotas no dia</div></div>
  <div class="kpi"><div class="v"><?= number_format($kmT, 1, ',', '.') ?> km</div><div class="r">Distância otimizada</div></div>
  <div class="kpi"><div class="v"><?= $econ ?>%</div><div class="r">Economia vs. seguir só o horário (<?= number_format($kmB, 1, ',', '.') ?> km)</div></div>
  <div class="kpi"><div class="v">R$ <?= number_format(array_sum(array_column($rotas, 'custo')), 2, ',', '.') ?></div><div class="r">Custo total estimado</div></div>
</div>
<?php endif; ?>

<section class="cartao">
  <h2>1. Selecione entregas e veículos do dia</h2>
  <?php if (!$pendentes): ?>
    <p>Não há pedidos pendentes para <?= date('d/m/Y', strtotime($data)) ?>.</p>
  <?php else: ?>
  <form method="post">
    <?= csrf_campo() ?><input type="hidden" name="acao" value="gerar">
    <div class="grade">
      <fieldset>
        <legend><strong><?= count($pendentes) ?> pedidos pendentes</strong> <button type="button" class="btn-sec btn-peq" onclick="this.closest('fieldset').querySelectorAll('input').forEach(c=>c.checked=true)">Marcar todos</button></legend>
        <?php foreach ($pendentes as $p): ?>
          <label><input type="checkbox" name="pedidos[]" value="<?= $p['id'] ?>" checked>
            <?= e($p['codigo']) ?> · <?= e($p['regiao']) ?> · <?= date('H:i', strtotime($p['janela_inicio'])) ?>-<?= date('H:i', strtotime($p['janela_fim'])) ?> · <?= e($p['peso_kg']) ?> kg
            <span class="tag tag-<?= $p['prioridade'] ?>"><?= e($p['prioridade']) ?></span></label>
        <?php endforeach; ?>
      </fieldset>
      <fieldset>
        <legend><strong>Veículos disponíveis</strong></legend>
        <?php foreach ($disponiveis as $v): ?>
          <label><input type="checkbox" name="veiculos[]" value="<?= $v['id'] ?>" checked>
            <?= e($v['placa']) ?> · <?= e($v['tipo']) ?> · <?= e($v['capacidade_kg']) ?> kg / <?= e($v['capacidade_m3']) ?> m³</label>
        <?php endforeach; ?>
        <?php if (!$disponiveis): ?><p>Nenhum veículo disponível.</p><?php endif; ?>
      </fieldset>
    </div>
    <button class="btn">Gerar rotas otimizadas</button>
  </form>
  <?php endif; ?>
</section>

<?php if ($rotas): ?>
<section class="cartao">
  <h2>2. Rotas do dia</h2>
  <div id="mapa" class="mapa" role="img" aria-label="Mapa das rotas planejadas"></div>
  <div id="mapa-fallback"></div>
</section>
<div class="grade">
<?php foreach ($rotas as $i => $r): ?>
  <section class="cartao">
    <h2><span class="cor" data-i="<?= $i ?>" aria-hidden="true"></span> Rota #<?= $r['id'] ?> · <?= e($r['placa']) ?> (<?= e($r['tipo']) ?>)</h2>
    <p><?= e($r['motorista'] ?? 'Sem motorista vinculado') ?> · <?= number_format($r['distancia_km'], 1, ',', '.') ?> km · <?= $r['tempo_min'] ?> min · R$ <?= number_format($r['custo'], 2, ',', '.') ?>
      · <span class="tag tag-<?= $r['status'] === 'planejada' ? 'media' : 'ok' ?>"><?= e($r['status']) ?></span></p>
    <ol>
      <?php foreach ($paradas[$r['id']] as $p): ?>
        <li><?= e($p['codigo']) ?> · <?= e($p['endereco']) ?> (<?= date('H:i', strtotime($p['janela_inicio'])) ?>-<?= date('H:i', strtotime($p['janela_fim'])) ?>) <span class="tag tag-<?= $p['status'] ?>"><?= e($p['status']) ?></span></li>
      <?php endforeach; ?>
    </ol>
    <div class="acoes">
      <?php if ($r['status'] === 'planejada'): ?>
        <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="liberar"><button name="rota" value="<?= $r['id'] ?>">Liberar para motorista</button></form>
      <?php endif; ?>
      <?php if (in_array($r['status'], ['planejada', 'liberada'])): ?>
        <form method="post" onsubmit="return confirm('Desfazer esta rota?')"><?= csrf_campo() ?><input type="hidden" name="acao" value="desfazer"><button class="btn-sec" name="rota" value="<?= $r['id'] ?>">Desfazer</button></form>
      <?php endif; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>
<script>
const ROTAS = <?= json_encode(array_map(fn($r) => ['id' => $r['id'], 'placa' => $r['placa'], 'paradas' => $paradas[$r['id']]], $rotas)) ?>;
const DEPOSITO = [<?= DEPOSITO_LAT ?>, <?= DEPOSITO_LNG ?>];
document.addEventListener('DOMContentLoaded', () => desenharRotas('mapa', ROTAS, DEPOSITO));
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
