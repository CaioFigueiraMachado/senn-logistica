<?php
/** RF06 - Rastreamento de entregas em tempo real */
require_once __DIR__ . '/includes/auth.php';
$u = exigir_perfil('gestor', 'despachante');

$data = date('Y-m-d');
$rotas = q('SELECT r.id, v.placa FROM rotas r JOIN veiculos v ON v.id = r.veiculo_id WHERE r.data_rota = ? ORDER BY r.id', [$data])->fetchAll();
$motoristas = q('SELECT id, nome FROM motoristas ORDER BY nome')->fetchAll();
$regioes = q('SELECT DISTINCT regiao FROM pedidos ORDER BY regiao')->fetchAll(PDO::FETCH_COLUMN);

$titulo = 'Rastreamento';
$usaMapa = true;
require __DIR__ . '/includes/header.php';
?>
<h1>Rastreamento em tempo real</h1>
<form id="filtros" class="acoes cartao">
  <label>Rota <select name="rota"><option value="">Todas</option><?php foreach ($rotas as $r): ?><option value="<?= $r['id'] ?>">#<?= $r['id'] ?> · <?= e($r['placa']) ?></option><?php endforeach; ?></select></label>
  <label>Motorista <select name="motorista"><option value="">Todos</option><?php foreach ($motoristas as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['nome']) ?></option><?php endforeach; ?></select></label>
  <label>Região <select name="regiao"><option value="">Todas</option><?php foreach ($regioes as $r): ?><option><?= e($r) ?></option><?php endforeach; ?></select></label>
  <label>Status <select name="status"><option value="">Todos</option><option value="roteirizado">Roteirizado</option><option value="a_caminho">A caminho</option><option value="entregue">Entregue</option><option value="falha">Falha</option><option value="pendente">Pendente</option></select></label>
  <label><input type="checkbox" name="simular" value="1"> Simular movimento (demonstração)</label>
  <span aria-live="polite"><span id="conexao" class="conexao">Tempo real (atualiza a cada 5 s)</span> · Atualizado às <strong id="hora">--</strong> · <a href="alertas.php"><span id="n-alertas">0</span> alertas abertos</a></span>
</form>
<div class="grade">
  <section class="cartao" style="grid-column: span 2; min-width: 0">
    <div id="mapa" class="mapa" role="img" aria-label="Mapa com veículos e entregas"></div>
  </section>
  <section class="cartao tabela">
    <h2>Entregas (<span id="n-entregas">0</span>)</h2>
    <table><thead><tr><th>Código</th><th>Região</th><th>Status</th><th>Risco</th></tr></thead><tbody id="lista"></tbody></table>
  </section>
</div>
<script>
const DEPOSITO = [<?= DEPOSITO_LAT ?>, <?= DEPOSITO_LNG ?>];
const COR_STATUS = { pendente: '#6b7280', roteirizado: '#a65d00', a_caminho: '#0b5cd6', entregue: '#137a3a', falha: '#b3261e' };
const NOME_STATUS = { pendente: 'Pendente', roteirizado: 'Roteirizado', a_caminho: 'A caminho', entregue: 'Entregue', falha: 'Falha' };
let mapa, camada, primeiro = true;
document.addEventListener('DOMContentLoaded', () => {
  mapa = criarMapa('mapa', DEPOSITO);
  if (mapa) camada = L.layerGroup().addTo(mapa);
  atualizar();
  setInterval(atualizar, 5000);
  document.getElementById('filtros').addEventListener('change', () => { primeiro = true; atualizar(); });
});
async function atualizar() {
  const qs = new URLSearchParams(new FormData(document.getElementById('filtros')));
  const conexao = document.getElementById('conexao');
  let d;
  try {
    d = await (await fetch('api/posicoes.php?' + qs)).json();
    conexao.className = 'conexao';
    conexao.textContent = 'Tempo real (atualiza a cada 5 s)';
  } catch {
    // mantém os últimos dados na tela e tenta de novo no próximo ciclo
    conexao.className = 'conexao off';
    conexao.textContent = 'Sem conexão, tentando novamente...';
    return;
  }
  document.getElementById('hora').textContent = d.atualizado;
  document.getElementById('n-alertas').textContent = d.alertas;
  document.getElementById('n-entregas').textContent = d.entregas.length;
  document.getElementById('lista').innerHTML = d.entregas.map(e =>
    `<tr><td>${esc(e.codigo)}</td><td>${esc(e.regiao)}</td><td><span class="tag tag-${e.status}">${NOME_STATUS[e.status]}</span></td><td>${e.risco_atraso ? esc(e.risco_atraso) + '%' : '-'}</td></tr>`).join('');
  if (!mapa) return;
  camada.clearLayers();
  const pts = [DEPOSITO];
  L.marker(DEPOSITO).addTo(camada).bindPopup('<strong>Depósito</strong>');
  d.entregas.forEach(e => {
    L.circleMarker([+e.lat, +e.lng], { radius: 8, color: '#fff', weight: 2, fillColor: COR_STATUS[e.status], fillOpacity: 1 })
      .addTo(camada).bindPopup(`<strong>${esc(e.codigo)}</strong><br>${esc(e.destinatario)}<br>${esc(e.endereco)}<br>${NOME_STATUS[e.status]}`);
    pts.push([+e.lat, +e.lng]);
  });
  d.veiculos.forEach(v => {
    const icone = L.divIcon({ className: '', html: `<div style="background:#171717;color:#fff;padding:3px 7px;border-radius:6px;font-size:12px;font-weight:700;white-space:nowrap;box-shadow:0 1px 4px #0008">${esc(v.placa)}</div>`, iconAnchor: [30, 12] });
    L.marker([+v.lat, +v.lng], { icon: icone }).addTo(camada)
      .bindPopup(`<strong>${esc(v.placa)}</strong> (${esc(v.tipo)})<br>${esc(v.motorista || 'Sem rota ativa')}<br>Status: ${esc(v.status)}<br>Posição: ${esc(v.posicao_em || '-')}`);
  });
  if (primeiro && pts.length > 1) { mapa.fitBounds(pts, { padding: [30, 30] }); primeiro = false; }
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
