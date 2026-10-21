/* Scripts compartilhados: mapas (com fallback), chat e utilidades */
const CSRF = document.querySelector('meta[name=csrf]')?.content || '';
const CORES = ['#2563eb', '#ea580c', '#16a34a', '#7c3aed', '#e11d48', '#0891b2'];

/** Barra lateral: no desktop recolhe, no celular abre como gaveta. */
function alternarMenu(forcar) {
  const mobile = matchMedia('(max-width: 900px)').matches;
  const cls = mobile ? 'menu-aberto' : 'menu-recolhido';
  const ativo = document.body.classList.toggle(cls, forcar);
  document.getElementById('menu-btn')?.setAttribute('aria-expanded', mobile ? ativo : !ativo);
  if (!mobile) try { localStorage.setItem('menu', ativo ? 'recolhido' : ''); } catch (e) {}
}
try { if (localStorage.getItem('menu') === 'recolhido' && !matchMedia('(max-width: 900px)').matches) document.body.classList.add('menu-recolhido'); } catch (e) {}
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.body.classList.remove('menu-aberto'); });

function alternarTema() {
  const escuro = document.documentElement.dataset.tema !== 'escuro';
  if (escuro) document.documentElement.dataset.tema = 'escuro'; else delete document.documentElement.dataset.tema;
  try { localStorage.setItem('tema', escuro ? 'escuro' : 'claro'); } catch (e) {}
  location.reload(); // redesenha mapas e gráficos com as cores do tema
}

// Gráficos com a tipografia e as cores do tema
if (typeof Chart !== 'undefined') {
  const css = getComputedStyle(document.documentElement);
  Chart.defaults.font.family = css.getPropertyValue('--fonte');
  Chart.defaults.color = css.getPropertyValue('--sub').trim();
  Chart.defaults.borderColor = css.getPropertyValue('--borda').trim();
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.elements.bar.borderRadius = 6;
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

async function postJSON(url, dados) {
  const r = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(dados),
  });
  return r.json();
}

/** Cria um mapa Leaflet. Sem acesso aos tiles/biblioteca, retorna null (modo fallback). */
function criarMapa(id, centro) {
  const el = document.getElementById(id);
  if (!el) return null;
  if (typeof L === 'undefined') {
    el.innerHTML = '<div class="aviso aviso-info">Mapa indisponível (sem conexão com o serviço de mapas). As informações continuam disponíveis em lista.</div>';
    el.style.height = 'auto';
    return null;
  }
  const mapa = L.map(id).setView(centro, 13);
  const base = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19, attribution: '&copy; OpenStreetMap',
  }).addTo(mapa);
  // Fallback: se o fundo do mapa não carregar, os marcadores continuam sobre um fundo neutro
  let erros = 0;
  base.on('tileerror', () => {
    if (++erros !== 3) return;
    el.style.background = '#e8edf5';
    el.insertAdjacentHTML('beforebegin', '<div class="aviso aviso-info" role="status">Serviço de mapas offline: exibindo veículos e entregas sem o mapa de fundo.</div>');
  });
  return mapa;
}

/** Selo que indica se a resposta veio da IA externa ou do modelo local. */
function seloIA(fonte) {
  const online = /gemini/i.test(fonte || '');
  return `<span class="selo ${online ? 'selo-on' : 'selo-local'}">${online ? esc(fonte) : esc(fonte || 'Modelo local')}</span>`;
}

function iconeNumero(n, cor) {
  return L.divIcon({
    className: '',
    html: `<div style="background:${cor};color:#fff;border-radius:50%;width:24px;height:24px;display:grid;place-items:center;font-size:12px;font-weight:700;border:2px solid #fff;box-shadow:0 1px 4px #0006">${n}</div>`,
    iconSize: [24, 24], iconAnchor: [12, 12],
  });
}

function desenharRotas(id, rotas, deposito) {
  document.querySelectorAll('.cor').forEach(s => s.style.color = CORES[s.dataset.i % CORES.length]);
  const mapa = criarMapa(id, deposito);
  if (!mapa) return;
  L.marker(deposito).addTo(mapa).bindPopup('<strong>Depósito</strong>');
  const limites = [deposito];
  rotas.forEach((r, i) => {
    const cor = CORES[i % CORES.length];
    const pts = [deposito, ...r.paradas.map(p => [+p.lat, +p.lng]), deposito];
    L.polyline(pts, { color: cor, weight: 4, opacity: .8 }).addTo(mapa);
    r.paradas.forEach((p, k) => {
      L.marker([+p.lat, +p.lng], { icon: iconeNumero(k + 1, cor) }).addTo(mapa)
        .bindPopup(`<strong>${esc(p.codigo)}</strong><br>${esc(p.endereco)}<br>Rota ${r.id} · ${esc(r.placa)}`);
      limites.push([+p.lat, +p.lng]);
    });
  });
  mapa.fitBounds(limites, { padding: [30, 30] });
}

/* ---------------- Chat (RF13) ---------------- */
(() => {
  const btn = document.getElementById('chat-btn');
  if (!btn) return;
  const painel = document.getElementById('chat');
  const msgs = document.getElementById('chat-msgs');
  const form = document.getElementById('chat-form');
  const txt = document.getElementById('chat-txt');
  const alternar = abrir => {
    painel.hidden = !abrir;
    btn.setAttribute('aria-expanded', abrir);
    if (abrir) txt.focus();
  };
  btn.onclick = () => alternar(painel.hidden);
  document.getElementById('chat-fechar').onclick = () => { alternar(false); btn.focus(); };
  painel.addEventListener('keydown', e => { if (e.key === 'Escape') { alternar(false); btn.focus(); } });

  const add = (html, quem) => {
    const d = document.createElement('div');
    d.className = 'msg ' + quem;
    d.innerHTML = html;
    msgs.appendChild(d);
    msgs.scrollTop = msgs.scrollHeight;
    return d;
  };
  const perguntar = async pergunta => {
    add(esc(pergunta), 'eu');
    const espera = add('<span class="digitando" aria-label="Pensando"><i></i><i></i><i></i></span>', 'bot');
    try {
      const r = await postJSON('api/chat.php', { pergunta });
      let html = esc(r.resposta);
      (r.acoes || []).forEach(a => { html += `<br><a class="acao" href="${esc(a.url)}">${esc(a.rotulo)}</a>`; });
      espera.innerHTML = html + '<br>' + seloIA(r.fonte === 'gemini' ? 'Gemini' : 'Modelo local');
    } catch {
      espera.textContent = 'Sem conexão com o servidor. Tente novamente.';
    }
  };
  form.onsubmit = e => {
    e.preventDefault();
    const p = txt.value.trim();
    if (!p) return;
    txt.value = '';
    perguntar(p);
  };
  // Sugestões rápidas
  const chips = document.createElement('div');
  chips.className = 'chips';
  ['Entregas de hoje', 'Entregas atrasadas', 'Qual o OTD?', 'Previsão da semana', 'Como importar CSV?'].forEach(s => {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'chip'; b.textContent = s;
    b.onclick = () => perguntar(s);
    chips.appendChild(b);
  });
  msgs.appendChild(chips);
})();
