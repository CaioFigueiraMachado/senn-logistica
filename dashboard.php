<?php
/** RF10 - Dashboard gerencial */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/indicadores.php';
require_once __DIR__ . '/includes/alertas.php';
$u = exigir_perfil('gestor');

verificar_alertas();
require_once __DIR__ . '/includes/backup.php';
backup_automatico(); // RNF05: no máximo um backup a cada 24 h
[$ini, $fim, $regiao] = filtros_periodo($_GET);
$k = indicadores($ini, $fim, $regiao);
$regioes = q('SELECT DISTINCT regiao FROM pedidos ORDER BY regiao')->fetchAll(PDO::FETCH_COLUMN);
$alertas = q('SELECT * FROM alertas WHERE resolvido = 0 ORDER BY criado_em DESC LIMIT 5')->fetchAll();
$qs = http_build_query(['inicio' => $ini, 'fim' => $fim, 'regiao' => $regiao]);
// RF08: previsão de demanda (total por dia) para 7 e 30 dias
$prev30 = prever_demanda(30, $regiao ?: null);
$porDia = [];
foreach ($prev30 as $diasReg) foreach ($diasReg as $d => $v) $porDia[$d] = ($porDia[$d] ?? 0) + $v;
// RF09: entregas críticas sinalizadas no painel
$criticas = array_values(array_filter(analisar_riscos(8), fn($p) => $p['risco'] >= 35));
$statusIA = ia_status();
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
  <a class="btn btn-sec" href="exportar.php?formato=excel&<?= e($qs) ?>">Exportar Excel</a>
  <a class="btn btn-sec" href="exportar.php?formato=pdf&<?= e($qs) ?>" target="_blank">Exportar PDF</a>
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
    <?php foreach ($alertas as $a): ?><p class="insight"><span class="tag tag-erro"><?= ['atraso' => 'Atraso', 'veiculo_parado' => 'Veículo parado', 'fora_rota' => 'Fora da rota', 'manutencao' => 'Manutenção', 'cnh' => 'CNH', 'risco' => 'Risco'][$a['tipo']] ?></span> <?= e($a['mensagem']) ?></p><?php endforeach; ?>
    <?php if (!$alertas): ?><p>Nenhum alerta aberto.</p><?php endif; ?>
    <a href="alertas.php">Ver todos os alertas</a>
  </section>
</div>

<div class="grade">
  <section class="cartao ia-card">
    <div class="acoes" style="justify-content: space-between">
      <h2 style="margin: 0">Previsão de demanda</h2>
      <div class="segmentado" role="group" aria-label="Horizonte da previsão">
        <a href="#" data-h="7" aria-current="true">7 dias</a><a href="#" data-h="30">30 dias</a>
      </div>
    </div>
    <p id="prev-total" class="ia-fonte"></p>
    <div class="grafico"><canvas id="g-prev" aria-label="Gráfico da previsão de entregas por dia"></canvas></div>
    <p class="ia-fonte">Modelo local: tendência + sazonalidade semanal<?= $regiao ? ' para ' . e($regiao) : ' de todas as regiões' ?>. <a href="ia.php">Ver por região</a></p>
  </section>
  <section class="cartao ia-card">
    <h2>Entregas críticas (risco de atraso)</h2>
    <?php foreach ($criticas as $p): $nivel = $p['risco'] >= 60 ? 'erro' : 'alerta'; ?>
      <div class="risco risco-<?= $nivel ?>" style="margin-bottom: 10px">
        <header><a href="pedido.php?id=<?= $p['id'] ?>"><strong><?= e($p['codigo']) ?></strong></a> · <?= e($p['regiao']) ?> · <?= str_replace('.', ',', $p['risco']) ?>%</header>
        <div class="barra"><span style="width: <?= $p['risco'] ?>%"></span></div>
        <p class="sugestao"><strong>Sugestão:</strong> <?= e($p['acao']) ?></p>
      </div>
    <?php endforeach; ?>
    <?php if (!$criticas): ?><p>Nenhuma entrega em aberto com risco relevante agora.</p><?php endif; ?>
    <a href="ia.php">Ver todas as análises de risco</a>
  </section>
</div>

<section class="cartao ia-card" id="analise">
  <div class="acoes" style="justify-content: space-between">
    <h2 style="margin: 0">Análise inteligente</h2>
    <div class="acoes" style="margin: 0">
      <span class="selo <?= $statusIA['modo'] === 'online' ? 'selo-on' : 'selo-local' ?>"><?= e($statusIA['texto']) ?></span>
      <button id="gerar">Gerar relatório analítico</button>
    </div>
  </div>
  <p id="analise-intro">Insights automáticos sobre gargalos da operação, regiões de maior custo e recomendações de otimização.</p>
  <div id="insights" class="insights" aria-live="polite"></div>
  <div id="analise-graficos" class="grade" hidden>
    <div><h3>Custo estimado por entrega (R$)</h3><div class="grafico"><canvas id="g-custo"></canvas></div></div>
    <div><h3>% de atraso por horário de início</h3><div class="grafico"><canvas id="g-hora"></canvas></div></div>
  </div>
</section>
<noscript><p>Ative o JavaScript para ver os gráficos. Os números acima continuam válidos.</p></noscript>
<script>
const K = <?= json_encode($k) ?>;
const PREV = <?= json_encode($porDia) ?>;
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

  // Previsão: alterna entre 7 e 30 dias sem recarregar
  const dias = Object.keys(PREV);
  const gPrev = new Chart('g-prev', { type: 'bar', data: { labels: [], datasets: [{ label: 'Entregas previstas', data: [], backgroundColor: '#7c3aed' }] } });
  const mostrar = h => {
    const ds = dias.slice(0, h);
    gPrev.data.labels = ds.map(d => d.slice(8, 10) + '/' + d.slice(5, 7));
    gPrev.data.datasets[0].data = ds.map(d => Math.round(PREV[d]));
    gPrev.update();
    document.getElementById('prev-total').textContent = 'Total previsto: ' + Math.round(ds.reduce((s, d) => s + PREV[d], 0)) + ' entregas nos próximos ' + h + ' dias.';
    document.querySelectorAll('[data-h]').forEach(a => a.toggleAttribute('aria-current', +a.dataset.h === h));
  };
  document.querySelectorAll('[data-h]').forEach(a => a.onclick = e => { e.preventDefault(); mostrar(+a.dataset.h); });
  mostrar(7);
});

// RF12: Análise inteligente dentro do dashboard
const CLASSE = { gargalo: 'Gargalo', custo: 'Custo', previsao: 'Capacidade', recomendacao: 'Recomendação' };
let gCusto, gHora;
document.getElementById('gerar').onclick = async () => {
  const btn = document.getElementById('gerar'), alvo = document.getElementById('insights');
  btn.disabled = true; btn.textContent = 'Analisando...';
  alvo.innerHTML = Array(3).fill('<div class="insight-card esqueleto"></div>').join('');
  try {
    const r = await (await fetch('api/insights.php?dias=30')).json();
    document.getElementById('analise-intro').innerHTML = seloIA(r.fonte) + ` <span class="ia-fonte">Gerado em ${esc(r.gerado)}</span>`;
    alvo.innerHTML = r.cartoes.map(c => `<article class="insight-card insight-${c.tipo}"><span class="insight-tipo">${esc(c.rotulo)}</span><p>${esc(c.texto)}</p></article>`).join('');
    if (typeof Chart !== 'undefined') {
      document.getElementById('analise-graficos').hidden = false;
      gCusto?.destroy(); gHora?.destroy();
      gCusto = new Chart('g-custo', { type: 'bar', data: { labels: r.dados.porRegiao.map(x => x.regiao), datasets: [{ label: 'R$/entrega', data: r.dados.porRegiao.map(x => x.custo_entrega), backgroundColor: '#ea580c' }] } });
      gHora = new Chart('g-hora', { type: 'line', data: { labels: r.dados.porHora.map(h => h.h + 'h'), datasets: [{ label: '% atraso', data: r.dados.porHora.map(h => h.atraso), borderColor: '#dc2626', tension: .3 }] } });
    }
  } catch {
    alvo.innerHTML = '<p class="aviso aviso-erro">Não foi possível gerar a análise agora. Tente novamente.</p>';
  }
  btn.disabled = false; btn.textContent = 'Gerar novamente';
};
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
