<?php
/** RF10 - Dashboard gerencial */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/indicadores.php';
require_once __DIR__ . '/includes/alertas.php';
$u = exigir_perfil('gestor');

verificar_alertas();
[$ini, $fim, $regiao] = filtros_periodo($_GET);
$k = indicadores($ini, $fim, $regiao);
$regioes = q('SELECT DISTINCT regiao FROM pedidos ORDER BY regiao')->fetchAll(PDO::FETCH_COLUMN);
$alertas = q('SELECT * FROM alertas WHERE resolvido = 0 ORDER BY criado_em DESC LIMIT 5')->fetchAll();
$qs = http_build_query(['inicio' => $ini, 'fim' => $fim, 'regiao' => $regiao]);
$brl = fn($v) => 'R$ ' . number_format($v, 2, ',', '.');

$titulo = 'Dashboard';
$usaGraficos = true;
require __DIR__ . '/includes/header.php';
?>
<h1>Dashboard gerencial</h1>
<form class="acoes cartao" method="get">
  <label>De <input type="date" name="inicio" value="<?= e($ini) ?>"></label>
  <label>Até <input type="date" name="fim" value="<?= e($fim) ?>"></label>
  <label>Região <select name="regiao"><option value="">Todas</option><?php foreach ($regioes as $r): ?><option <?= $regiao === $r ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
  <button>Aplicar</button>
  <a class="btn btn-sec" href="exportar.php?formato=excel&<?= e($qs) ?>">⬇ Excel</a>
  <a class="btn btn-sec" href="exportar.php?formato=pdf&<?= e($qs) ?>" target="_blank">⬇ PDF</a>
</form>

<div class="kpis">
  <div class="kpi"><div class="v"><?= $k['otd'] ?>%</div><div class="r">Entrega no prazo (OTD)</div></div>
  <div class="kpi"><div class="v"><?= $brl($k['custo_entrega']) ?></div><div class="r">Custo por entrega</div></div>
  <div class="kpi"><div class="v"><?= number_format($k['km'], 0, ',', '.') ?> km</div><div class="r">Quilômetros rodados</div></div>
  <div class="kpi"><div class="v"><?= $k['ocupacao_frota'] ?>%</div><div class="r">Ocupação da frota agora<?= $k['ocupacao_carga'] !== null ? " · carga média {$k['ocupacao_carga']}%" : '' ?></div></div>
  <div class="kpi"><div class="v"><?= number_format($k['total'], 0, ',', '.') ?></div><div class="r">Pedidos no período</div></div>
  <div class="kpi"><div class="v"><?= $k['falhas'] ?></div><div class="r">Falhas de entrega</div></div>
</div>

<div class="grade">
  <section class="cartao"><h2>Entregas por dia</h2><div class="grafico"><canvas id="g-serie" aria-label="Gráfico de entregas por dia"></canvas></div></section>
  <section class="cartao"><h2>Entregas por status</h2><div class="grafico"><canvas id="g-status" aria-label="Gráfico de entregas por status"></canvas></div></section>
  <section class="cartao"><h2>Entregas e OTD por região</h2><div class="grafico"><canvas id="g-regiao" aria-label="Gráfico por região"></canvas></div></section>
  <section class="cartao">
    <h2>Alertas recentes</h2>
    <?php foreach ($alertas as $a): ?><p class="insight"><span class="tag tag-erro"><?= e($a['tipo']) ?></span> <?= e($a['mensagem']) ?></p><?php endforeach; ?>
    <?php if (!$alertas): ?><p>Nenhum alerta aberto.</p><?php endif; ?>
    <a href="alertas.php">Ver todos os alertas</a> · <a href="ia.php">Abrir Análise Inteligente</a>
  </section>
</div>
<noscript><p>Ative o JavaScript para ver os gráficos. Os números acima continuam válidos.</p></noscript>
<script>
const K = <?= json_encode($k) ?>;
const NOMES = { pendente: 'Pendente', roteirizado: 'Roteirizado', a_caminho: 'A caminho', entregue: 'Entregue', falha: 'Falha' };
const COR = { pendente: '#6b7280', roteirizado: '#d97706', a_caminho: '#0b5cd6', entregue: '#15803d', falha: '#b3261e' };
document.addEventListener('DOMContentLoaded', () => {
  if (typeof Chart === 'undefined') {
    document.querySelectorAll('.grafico').forEach(c => c.outerHTML = '<p class="aviso aviso-info">Gráfico indisponível offline.</p>');
    return;
  }
  Chart.defaults.maintainAspectRatio = false;
  new Chart('g-serie', { type: 'line', data: { labels: K.serie.map(s => s.dia.slice(8, 10) + '/' + s.dia.slice(5, 7)),
    datasets: [{ label: 'No prazo', data: K.serie.map(s => s.no_prazo), borderColor: '#15803d', tension: .3 },
               { label: 'Atrasadas/falhas', data: K.serie.map(s => s.atrasadas), borderColor: '#b3261e', tension: .3 }] } });
  const st = Object.keys(K.por_status);
  new Chart('g-status', { type: 'doughnut', data: { labels: st.map(s => NOMES[s]), datasets: [{ data: st.map(s => K.por_status[s]), backgroundColor: st.map(s => COR[s]) }] } });
  new Chart('g-regiao', { data: { labels: K.por_regiao.map(r => r.regiao), datasets: [
    { type: 'bar', label: 'Entregas', data: K.por_regiao.map(r => r.n), backgroundColor: '#0b5cd6', yAxisID: 'y' },
    { type: 'line', label: 'OTD %', data: K.por_regiao.map(r => r.otd), borderColor: '#d97706', yAxisID: 'y1' }] },
    options: { scales: { y1: { position: 'right', min: 0, max: 100, grid: { drawOnChartArea: false } } } } });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
