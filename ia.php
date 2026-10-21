<?php
/** RF08, RF09 e RF12 - Módulo de IA: previsão de demanda, risco de atraso e análise inteligente */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ia.php';
$u = exigir_perfil('gestor');

$horizonte = ($_GET['h'] ?? '7') === '30' ? 30 : 7;
$previsao = prever_demanda($horizonte);
$riscos = analisar_riscos(15);
$status = ia_status();
$totPrev = array_sum(array_map('array_sum', $previsao));
$criticos = count(array_filter($riscos, fn($p) => $p['risco'] >= 60));
$atencao = count(array_filter($riscos, fn($p) => $p['risco'] >= 35 && $p['risco'] < 60));

$titulo = 'Análise IA';
$usaGraficos = true;
require __DIR__ . '/includes/header.php';
?>
<div class="acoes" style="justify-content: space-between">
  <h1 style="margin: 0">Inteligência artificial</h1>
  <span class="selo <?= $status['modo'] === 'online' ? 'selo-on' : 'selo-local' ?>" role="status"><?= $status['modo'] === 'online' ? '✨' : '⚙' ?> <?= e($status['texto']) ?></span>
</div>

<div class="kpis">
  <div class="kpi"><div class="v"><?= number_format($totPrev, 0, ',', '.') ?></div><div class="r">Entregas previstas em <?= $horizonte ?> dias</div></div>
  <div class="kpi"><div class="v"><?= max(1, (int)ceil($totPrev / $horizonte / 15)) ?></div><div class="r">Veículos sugeridos por dia</div></div>
  <div class="kpi"><div class="v" style="color: var(--erro)"><?= $criticos ?></div><div class="r">Entregas em risco crítico</div></div>
  <div class="kpi"><div class="v" style="color: var(--alerta)"><?= $atencao ?></div><div class="r">Entregas que pedem atenção</div></div>
</div>

<section class="cartao ia-card">
  <div class="acoes" style="justify-content: space-between">
    <h2 style="margin: 0">📈 Previsão de demanda</h2>
    <div class="segmentado" role="group" aria-label="Horizonte da previsão">
      <a href="?h=7" <?= $horizonte === 7 ? 'aria-current="true"' : '' ?>>7 dias</a>
      <a href="?h=30" <?= $horizonte === 30 ? 'aria-current="true"' : '' ?>>30 dias</a>
    </div>
  </div>
  <div class="grafico"><canvas id="g-prev" aria-label="Gráfico da previsão de demanda por região"></canvas></div>
  <details><summary>Ver tabela por região</summary><div class="tabela"><table>
    <tr><th>Região</th><?php foreach (array_keys(reset($previsao) ?: []) as $d): ?><th><?= date('d/m', strtotime($d)) ?></th><?php endforeach; ?><th>Total</th></tr>
    <?php foreach ($previsao as $reg => $dias): ?><tr><td><?= e($reg) ?></td><?php foreach ($dias as $v): ?><td><?= round($v) ?></td><?php endforeach; ?><td><strong><?= round(array_sum($dias)) ?></strong></td></tr><?php endforeach; ?>
  </table></div></details>
  <p class="ia-fonte">⚙ Modelo local: tendência linear + sazonalidade semanal por região, ajustado nos últimos 90 dias.</p>
</section>

<section class="cartao ia-card">
  <h2>⚠ Risco de atraso ou falha</h2>
  <div class="riscos">
  <?php foreach ($riscos as $p):
      $nivel = $p['risco'] >= 60 ? 'erro' : ($p['risco'] >= 35 ? 'alerta' : 'ok'); ?>
    <article class="risco risco-<?= $nivel ?>">
      <header>
        <strong><?= e($p['codigo']) ?></strong> · <?= e($p['regiao']) ?>
        <span class="tag tag-<?= $p['status'] ?>"><?= e($p['status']) ?></span>
      </header>
      <div class="barra" role="meter" aria-valuenow="<?= $p['risco'] ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Risco <?= $p['risco'] ?>%"><span style="width: <?= $p['risco'] ?>%"></span></div>
      <p class="risco-num"><?= $p['risco'] ?>% de risco · janela <?= date('d/m H:i', strtotime($p['janela_inicio'])) ?>-<?= date('H:i', strtotime($p['janela_fim'])) ?></p>
      <p class="ia-fonte"><?= e(implode(' · ', $p['motivos']) ?: 'sem fatores relevantes') ?></p>
      <p class="sugestao">→ <?= e($p['acao']) ?><?= $p['status'] === 'pendente' ? ' · <a href="rotas.php?data=' . date('Y-m-d', strtotime($p['janela_inicio'])) . '">roteirizar</a>' : '' ?></p>
    </article>
  <?php endforeach; ?>
  </div>
  <?php if (!$riscos): ?><p>Nenhuma entrega em aberto.</p><?php endif; ?>
  <p class="ia-fonte">⚙ Modelo local: regressão logística calibrada pela taxa histórica de atraso por região e horário, ajustada por janela, distância, carga e status.</p>
</section>

<section class="cartao ia-card" id="analise">
  <div class="acoes" style="justify-content: space-between">
    <h2 style="margin: 0">✨ Análise Inteligente de desempenho</h2>
    <div class="acoes" style="margin: 0">
      <label class="sr" for="dias">Período</label>
      <select id="dias" style="width: auto; margin: 0"><option value="7">7 dias</option><option value="30" selected>30 dias</option><option value="90">90 dias</option></select>
      <button id="gerar">Gerar relatório analítico</button>
    </div>
  </div>
  <p id="analise-intro">Insights automáticos sobre gargalos, regiões de maior custo e recomendações de otimização.</p>
  <div id="insights" class="insights" aria-live="polite"></div>
  <div id="analise-graficos" class="grade" hidden>
    <div><h3>Custo estimado por entrega (R$)</h3><div class="grafico"><canvas id="g-custo"></canvas></div></div>
    <div><h3>% de atraso por horário de início</h3><div class="grafico"><canvas id="g-hora"></canvas></div></div>
  </div>
</section>
<script>
const PREV = <?= json_encode($previsao) ?>;
const CORES_G = ['#0b5cd6', '#c2410c', '#15803d', '#7c3aed', '#be123c', '#0e7490'];
const ICONE = { gargalo: '⚠', custo: '💰', previsao: '📈', recomendacao: '💡' };
const temGraficos = () => typeof Chart !== 'undefined';
let gCusto, gHora;

document.addEventListener('DOMContentLoaded', () => {
  if (!temGraficos()) {
    document.querySelectorAll('.grafico').forEach(g => g.innerHTML = '<p class="aviso aviso-info">Gráficos indisponíveis offline. Veja os valores na tabela.</p>');
  } else {
    const regs = Object.keys(PREV);
    const dias = regs.length ? Object.keys(PREV[regs[0]]) : [];
    new Chart('g-prev', { type: 'bar', data: { labels: dias.map(d => d.slice(8, 10) + '/' + d.slice(5, 7)),
      datasets: regs.map((r, i) => ({ label: r, data: dias.map(d => PREV[r][d]), backgroundColor: CORES_G[i % 6] })) },
      options: { maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, title: { display: true, text: 'entregas previstas' } } } } });
  }
  document.getElementById('gerar').onclick = gerarAnalise;
});

async function gerarAnalise() {
  const btn = document.getElementById('gerar');
  const alvo = document.getElementById('insights');
  btn.disabled = true; btn.textContent = 'Analisando...';
  alvo.innerHTML = Array(4).fill('<div class="insight-card esqueleto"></div>').join('');
  try {
    const r = await (await fetch('api/insights.php?dias=' + document.getElementById('dias').value)).json();
    document.getElementById('analise-intro').innerHTML = seloIA(r.fonte) + ` <span class="ia-fonte">Gerado em ${esc(r.gerado)}</span>`;
    alvo.innerHTML = r.cartoes.map(c => `
      <article class="insight-card insight-${c.tipo}">
        <span class="insight-tipo">${ICONE[c.tipo]} ${esc(c.rotulo)}</span>
        <p>${esc(c.texto)}</p>
      </article>`).join('');
    if (temGraficos()) {
      document.getElementById('analise-graficos').hidden = false;
      gCusto?.destroy(); gHora?.destroy();
      gCusto = new Chart('g-custo', { type: 'bar', data: { labels: r.dados.porRegiao.map(x => x.regiao), datasets: [{ label: 'R$/entrega', data: r.dados.porRegiao.map(x => x.custo_entrega), backgroundColor: '#c2410c' }] }, options: { maintainAspectRatio: false } });
      gHora = new Chart('g-hora', { type: 'line', data: { labels: r.dados.porHora.map(h => h.h + 'h'), datasets: [{ label: '% atraso', data: r.dados.porHora.map(h => h.atraso), borderColor: '#b3261e', tension: .3 }] }, options: { maintainAspectRatio: false } });
    }
  } catch {
    alvo.innerHTML = '<p class="aviso aviso-erro">Não foi possível gerar a análise agora. Tente novamente.</p>';
  }
  btn.disabled = false; btn.textContent = 'Gerar novamente';
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
