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
  <label title="Coloca as rotas de hoje na rua e faz os veículos andarem até as paradas"><input type="checkbox" name="simular" value="1"> Simular movimento (demonstração)</label>
  <span aria-live="polite"><span id="conexao" class="conexao">Tempo real (atualiza a cada 5 s)</span> · Atualizado às <strong id="hora">--</strong> · <a href="alertas.php"><span id="n-alertas">0</span> alertas abertos</a></span>
</form>
<p id="sim-aviso" class="aviso aviso-info" hidden role="status"></p>
<div class="rastreio">
  <section class="cartao">
    <div id="mapa" class="mapa" role="img" aria-label="Mapa com veículos e entregas"></div>
  </section>
  <section class="cartao lista-entregas">
    <h2>Entregas (<span id="n-entregas">0</span>)</h2>
    <div class="tabela rolagem">
      <table><thead><tr><th>Código</th><th>Região</th><th>Status</th><th>Risco</th></tr></thead><tbody id="lista"></tbody></table>
    </div>
  </section>
</div>
<script>
const DEPOSITO = [<?= DEPOSITO_LAT ?>, <?= DEPOSITO_LNG ?>];
const COR_STATUS = { pendente: '#737373', roteirizado: '#d97706', a_caminho: '#2563eb', entregue: '#16a34a', falha: '#dc2626' };
const NOME_STATUS = { pendente: 'Pendente', roteirizado: 'Roteirizado', a_caminho: 'A caminho', entregue: 'Entregue', falha: 'Falha' };
const ICONE_CAMINHAO = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></svg>';
const ICONE_DEPOSITO = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 8.35V20a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8.35A2 2 0 0 1 3.26 6.5l8-3.2a2 2 0 0 1 1.48 0l8 3.2A2 2 0 0 1 22 8.35Z"/><path d="M6 18h12"/><path d="M6 14h12"/><rect x="6" y="10" width="12" height="12"/></svg>';
const PERIODO = 5000;
let mapa, camadaEntregas, camadaTrajetos, deposito, primeiro = true;
const veiculosNoMapa = {}; // id -> { marker, cor, de, para, inicio }
const entregasNoMapa = {}; // id -> circleMarker

document.addEventListener('DOMContentLoaded', () => {
  mapa = criarMapa('mapa', DEPOSITO);
  if (mapa) {
    camadaTrajetos = L.layerGroup().addTo(mapa);
    camadaEntregas = L.layerGroup().addTo(mapa);
    deposito = L.marker(DEPOSITO, { icon: L.divIcon({ className: '', html: `<div class="pino-deposito">${ICONE_DEPOSITO}</div>`, iconSize: [34, 34], iconAnchor: [17, 17] }), zIndexOffset: 500 })
      .addTo(mapa).bindTooltip('Depósito', { direction: 'top', offset: [0, -16] });
    const legenda = L.control({ position: 'bottomleft' });
    legenda.onAdd = () => {
      const div = L.DomUtil.create('div', 'legenda-mapa');
      div.innerHTML = Object.keys(NOME_STATUS).map(s => `<span><i style="background:${COR_STATUS[s]}"></i>${NOME_STATUS[s]}</span>`).join('');
      return div;
    };
    legenda.addTo(mapa);
    requestAnimationFrame(animar);
  }
  atualizar();
  setInterval(atualizar, PERIODO);
  document.getElementById('filtros').addEventListener('change', () => { primeiro = true; atualizar(); });
});

/** Desliza cada veículo da posição anterior até a nova durante o intervalo do polling. */
function animar(agora) {
  for (const v of Object.values(veiculosNoMapa)) {
    if (!v.para) continue;
    const t = Math.min(1, (agora - v.inicio) / (PERIODO - 300));
    const s = t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2; // suaviza início e fim
    v.marker.setLatLng([v.de[0] + (v.para[0] - v.de[0]) * s, v.de[1] + (v.para[1] - v.de[1]) * s]);
  }
  requestAnimationFrame(animar);
}

function iconeVeiculo(placa, cor, emRota) {
  return L.divIcon({
    className: '',
    html: `<div class="pino-veiculo${emRota ? '' : ' parado'}" style="--cor:${cor}"><span class="pino-circulo">${ICONE_CAMINHAO}</span><span class="pino-placa">${esc(placa)}</span></div>`,
    iconSize: [36, 36], iconAnchor: [18, 18],
  });
}
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
  const sim = document.getElementById('sim-aviso');
  sim.hidden = !d.simulacao;
  if (d.simulacao) sim.textContent = d.simulacao;
  document.getElementById('lista').innerHTML = d.entregas.map(e =>
    `<tr><td><a href="pedido.php?id=${e.id}">${esc(e.codigo)}</a></td><td>${esc(e.regiao)}</td><td><span class="tag tag-${e.status}">${NOME_STATUS[e.status]}</span></td><td>${e.risco_atraso && !['entregue', 'falha'].includes(e.status) ? Number(e.risco_atraso).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + '%' : '-'}</td></tr>`).join('');
  if (!mapa) return;
  const pts = [DEPOSITO];

  // Entregas: atualiza a cor das existentes em vez de redesenhar tudo
  const vistos = new Set();
  d.entregas.forEach(e => {
    vistos.add(e.id);
    const pos = [+e.lat, +e.lng];
    pts.push(pos);
    const popup = `<strong>${esc(e.codigo)}</strong> · ${NOME_STATUS[e.status]}<br>${esc(e.destinatario)}<br><small>${esc(e.endereco)}</small><br><a href="pedido.php?id=${e.id}">Ver detalhes e comprovante</a>`;
    if (entregasNoMapa[e.id]) {
      entregasNoMapa[e.id].setStyle({ fillColor: COR_STATUS[e.status] }).setPopupContent(popup);
    } else {
      entregasNoMapa[e.id] = L.circleMarker(pos, { radius: 7, color: '#fff', weight: 2, fillColor: COR_STATUS[e.status], fillOpacity: 1 })
        .addTo(camadaEntregas).bindPopup(popup);
    }
  });
  for (const id in entregasNoMapa) if (!vistos.has(+id)) { camadaEntregas.removeLayer(entregasNoMapa[id]); delete entregasNoMapa[id]; }

  // Veículos parados no depósito viram um contador no próprio depósito
  const emRota = d.veiculos.filter(v => v.rota);
  const livres = d.veiculos.filter(v => !v.rota);
  deposito.setTooltipContent(`<strong>Depósito</strong><br>${livres.length} veículo(s) no pátio${livres.length ? ': ' + livres.map(v => esc(v.placa)).join(', ') : ''}`);

  camadaTrajetos.clearLayers();
  const ativos = new Set();
  emRota.forEach((v, i) => {
    ativos.add(v.id);
    const cor = CORES[i % CORES.length];
    const pos = [+v.lat, +v.lng];
    // trajeto que falta: posição atual -> próximas paradas -> depósito
    const caminho = [pos, ...v.caminho.map(c => [+c[0], +c[1]]), DEPOSITO];
    L.polyline(caminho, { color: cor, weight: 4, opacity: .75, dashArray: '8 8', lineCap: 'round' }).addTo(camadaTrajetos);
    const popup = `<strong>${esc(v.placa)}</strong> · ${esc(v.tipo)}<br>${esc(v.motorista || 'Sem motorista')}<br>${v.caminho.length} parada(s) restante(s)`;
    const atual = veiculosNoMapa[v.id];
    if (atual) {
      atual.de = atual.marker.getLatLng() ? [atual.marker.getLatLng().lat, atual.marker.getLatLng().lng] : pos;
      atual.para = pos;
      atual.inicio = performance.now();
      if (atual.cor !== cor) { atual.cor = cor; atual.marker.setIcon(iconeVeiculo(v.placa, cor, true)); }
      atual.marker.setPopupContent(popup);
    } else {
      const marker = L.marker(pos, { icon: iconeVeiculo(v.placa, cor, true), zIndexOffset: 1000 }).addTo(mapa).bindPopup(popup);
      veiculosNoMapa[v.id] = { marker, cor, de: pos, para: pos, inicio: performance.now() };
    }
    pts.push(pos);
  });
  for (const id in veiculosNoMapa) if (!ativos.has(+id)) { mapa.removeLayer(veiculosNoMapa[id].marker); delete veiculosNoMapa[id]; }

  if (primeiro && pts.length > 1) { mapa.fitBounds(pts, { padding: [40, 40], maxZoom: 15 }); primeiro = false; }
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
