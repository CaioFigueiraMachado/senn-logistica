<?php
/** RF08, RF09 e RF12 - Módulo de IA: previsão de demanda, risco de atraso e análise inteligente */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ia.php';
$u = exigir_perfil('gestor');

$horizonte = ($_GET['h'] ?? '7') === '30' ? 30 : 7;
$previsao = prever_demanda($horizonte);
$riscos = analisar_riscos(15);
$analise = isset($_GET['analise']) ? gerar_insights(30) : null;
if ($analise) registrar_log('analise_ia', $analise['fonte']);

// Total histórico dos últimos 30 dias para comparação
$hist = q('SELECT DATE(janela_inicio) dia, COUNT(*) n FROM pedidos WHERE janela_inicio >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND janela_inicio < CURDATE() GROUP BY dia ORDER BY dia')->fetchAll(PDO::FETCH_KEY_PAIR);
$totPrev = array_sum(array_map('array_sum', $previsao));

$titulo = 'Análise IA';
$usaGraficos = true;
require __DIR__ . '/includes/header.php';
?>
<h1>Inteligência artificial</h1>

<section class="cartao">
  <h2>Previsão de demanda · próximos <?= $horizonte ?> dias</h2>
  <p class="acoes">
    <a class="btn <?= $horizonte === 7 ? '' : 'btn-sec' ?>" href="?h=7">7 dias</a>
    <a class="btn <?= $horizonte === 30 ? '' : 'btn-sec' ?>" href="?h=30">30 dias</a>
    <span>Total previsto: <strong><?= number_format($totPrev, 0, ',', '.') ?> entregas</strong> · cerca de <?= max(1, (int)ceil($totPrev / $horizonte / 15)) ?> veículos/dia</span>
  </p>
  <canvas id="g-prev" aria-label="Gráfico da previsão de demanda"></canvas>
  <p class="ia-fonte">Modelo: tendência linear + sazonalidade semanal por região, ajustado nos últimos 90 dias de histórico.</p>
  <details><summary>Ver tabela</summary><div class="tabela"><table>
    <tr><th>Região</th><?php foreach (array_keys(reset($previsao) ?: []) as $d): ?><th><?= date('d/m', strtotime($d)) ?></th><?php endforeach; ?><th>Total</th></tr>
    <?php foreach ($previsao as $reg => $dias): ?><tr><td><?= e($reg) ?></td><?php foreach ($dias as $v): ?><td><?= round($v) ?></td><?php endforeach; ?><td><strong><?= round(array_sum($dias)) ?></strong></td></tr><?php endforeach; ?>
  </table></div></details>
</section>

<section class="cartao tabela">
  <h2>Entregas com maior risco de atraso ou falha</h2>
  <table><thead><tr><th>Pedido</th><th>Região</th><th>Janela</th><th>Risco</th><th>Fatores</th><th>Ação sugerida</th></tr></thead><tbody>
  <?php foreach ($riscos as $p): ?>
    <tr><td><?= e($p['codigo']) ?><br><span class="tag tag-<?= $p['status'] ?>"><?= e($p['status']) ?></span></td><td><?= e($p['regiao']) ?></td>
      <td><?= date('d/m H:i', strtotime($p['janela_inicio'])) ?>-<?= date('H:i', strtotime($p['janela_fim'])) ?></td>
      <td><span class="tag tag-<?= $p['risco'] >= 60 ? 'erro' : ($p['risco'] >= 35 ? 'alerta' : 'ok') ?>"><?= $p['risco'] ?>%</span></td>
      <td><?= e(implode(', ', $p['motivos']) ?: 'sem fatores relevantes') ?></td><td><?= e($p['acao']) ?><?= $p['status'] === 'pendente' ? ' · <a href="rotas.php?data=' . date('Y-m-d', strtotime($p['janela_inicio'])) . '">roteirizar</a>' : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php if (!$riscos): ?><p>Nenhuma entrega em aberto.</p><?php endif; ?>
  <p class="ia-fonte">Modelo: regressão logística com pesos calibrados pela taxa histórica de atraso por região e horário, ajustada por janela, distância, carga e status.</p>
</section>

<section class="cartao">
  <h2>Análise Inteligente de desempenho</h2>
  <?php if (!$analise): ?>
    <p>Gera insights sobre gargalos, regiões de maior custo e recomendações a partir dos últimos 30 dias.</p>
    <a class="btn" href="?h=<?= $horizonte ?>&analise=1#analise">✨ Gerar relatório analítico</a>
  <?php else: ?>
    <div id="analise">
      <?php foreach ($analise['insights'] as $i): ?><p class="insight"><?= e($i) ?></p><?php endforeach; ?>
      <p class="ia-fonte">Fonte: <?= e($analise['fonte']) ?></p>
    </div>
    <div class="grade">
      <div><h3>Custo estimado por entrega (R$)</h3><canvas id="g-custo"></canvas></div>
      <div><h3>% de atraso por horário de início</h3><canvas id="g-hora"></canvas></div>
    </div>
  <?php endif; ?>
</section>
<script>
const PREV = <?= json_encode($previsao) ?>;
const HIST = <?= json_encode($hist) ?>;
const AN = <?= json_encode($analise['dados'] ?? null) ?>;
const CORES_G = ['#0b5cd6', '#c2410c', '#15803d', '#7c3aed', '#be123c', '#0e7490'];
document.addEventListener('DOMContentLoaded', () => {
  if (typeof Chart === 'undefined') return;
  const regs = Object.keys(PREV);
  const dias = regs.length ? Object.keys(PREV[regs[0]]) : [];
  new Chart('g-prev', { type: 'bar', data: { labels: dias.map(d => d.slice(8, 10) + '/' + d.slice(5, 7)),
    datasets: regs.map((r, i) => ({ label: r, data: dias.map(d => PREV[r][d]), backgroundColor: CORES_G[i % 6] })) },
    options: { scales: { x: { stacked: true }, y: { stacked: true, title: { display: true, text: 'entregas previstas' } } } } });
  if (AN) {
    new Chart('g-custo', { type: 'bar', data: { labels: AN.porRegiao.map(r => r.regiao), datasets: [{ label: 'R$/entrega', data: AN.porRegiao.map(r => r.custo_entrega), backgroundColor: '#c2410c' }] } });
    new Chart('g-hora', { type: 'line', data: { labels: AN.porHora.map(h => h.h + 'h'), datasets: [{ label: '% atraso', data: AN.porHora.map(h => h.atraso), borderColor: '#b3261e' }] } });
  }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
