<?php
/** Painel do motorista: rota do dia e RF07 - comprovação de entrega (POD) */
require_once __DIR__ . '/includes/auth.php';
$u = exigir_perfil('motorista');

// O administrador pode visualizar o painel de qualquer motorista
$mot = $u['perfil'] === 'admin'
    ? q('SELECT * FROM motoristas WHERE id = ?', [(int)($_GET['m'] ?? 0)])->fetch() ?: q('SELECT * FROM motoristas ORDER BY id LIMIT 1')->fetch()
    : q('SELECT * FROM motoristas WHERE usuario_id = ?', [$u['id']])->fetch();
if (!$mot) exit('Sua conta não está vinculada a um cadastro de motorista. Procure o gestor.');

function salvar_imagem(string $bin, string $prefixo): ?string {
    $info = @getimagesizefromstring($bin);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return null;
    $dir = __DIR__ . '/storage/pod';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $nome = $prefixo . '_' . bin2hex(random_bytes(8)) . image_type_to_extension($info[2]);
    file_put_contents("$dir/$nome", $bin);
    return $nome;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'iniciar') {
        $rid = (int)$_POST['rota'];
        $r = q("SELECT * FROM rotas WHERE id = ? AND motorista_id = ? AND status = 'liberada'", [$rid, $mot['id']])->fetch();
        if ($r) {
            q("UPDATE rotas SET status = 'em_andamento' WHERE id = ?", [$rid]);
            q("UPDATE pedidos SET status = 'a_caminho' WHERE rota_id = ? AND status = 'roteirizado'", [$rid]);
            q("UPDATE veiculos SET status = 'em_rota', lat = ?, lng = ?, posicao_em = NOW() WHERE id = ?", [DEPOSITO_LAT, DEPOSITO_LNG, $r['veiculo_id']]);
            registrar_log('rota_iniciada', (string)$rid);
            flash('Rota iniciada. Boa viagem!');
        }
    } elseif ($acao === 'pod') {
        $pid = (int)$_POST['pedido'];
        $p = q("SELECT p.* FROM pedidos p JOIN rotas r ON r.id = p.rota_id WHERE p.id = ? AND r.motorista_id = ? AND p.status = 'a_caminho'", [$pid, $mot['id']])->fetch();
        $tipo = $_POST['tipo'] ?? '';
        $resultado = ($_POST['resultado'] ?? 'entregue') === 'falha' ? 'falha' : 'entregue';
        $evid = null;
        if ($tipo === 'foto' && !empty($_FILES['foto']['tmp_name']) && $_FILES['foto']['size'] < 5_000_000) {
            $evid = salvar_imagem(file_get_contents($_FILES['foto']['tmp_name']), 'foto');
        } elseif ($tipo === 'assinatura' && str_starts_with($_POST['assinatura'] ?? '', 'data:image/png;base64,')) {
            $evid = salvar_imagem(base64_decode(substr($_POST['assinatura'], 22)), 'assin');
        } elseif ($tipo === 'codigo' && preg_match('/^[A-Za-z0-9]{4,10}$/', $_POST['codigo'] ?? '')) {
            $evid = 'Código: ' . $_POST['codigo'];
        }
        if ($resultado === 'falha') { $tipo = 'codigo'; $evid = 'Falha: ' . mb_substr(trim($_POST['motivo'] ?? 'não informado'), 0, 200); }
        if (!$p) flash('Entrega não encontrada ou já finalizada.', 'erro');
        elseif (!$evid) flash('Evidência inválida. Envie foto, assinatura ou código de confirmação.', 'erro');
        else {
            $lat = is_numeric($_POST['lat'] ?? '') ? (float)$_POST['lat'] : null;
            $lng = is_numeric($_POST['lng'] ?? '') ? (float)$_POST['lng'] : null;
            q('INSERT INTO comprovantes (pedido_id, motorista_id, tipo, evidencia, lat, lng) VALUES (?,?,?,?,?,?)', [$pid, $mot['id'], $tipo, $evid, $lat, $lng]);
            q('UPDATE pedidos SET status = ?, entregue_em = NOW() WHERE id = ?', [$resultado, $pid]);
            if ($lat) q('UPDATE veiculos v JOIN rotas r ON r.veiculo_id = v.id SET v.lat = ?, v.lng = ?, v.posicao_em = NOW() WHERE r.id = ?', [$lat, $lng, $p['rota_id']]);
            // Rota concluída quando não houver mais entregas em aberto
            if (!q("SELECT COUNT(*) FROM pedidos WHERE rota_id = ? AND status = 'a_caminho'", [$p['rota_id']])->fetchColumn()) {
                q("UPDATE rotas SET status = 'concluida' WHERE id = ?", [$p['rota_id']]);
                q("UPDATE veiculos v JOIN rotas r ON r.veiculo_id = v.id SET v.status = 'disponivel' WHERE r.id = ?", [$p['rota_id']]);
            }
            registrar_log('pod_' . $resultado, $p['codigo']);
            flash("Entrega {$p['codigo']} registrada como " . ($resultado === 'entregue' ? 'entregue' : 'falha') . '.');
        }
    }
    header('Location: motorista.php' . ($u['perfil'] === 'admin' ? '?m=' . $mot['id'] : ''));
    exit;
}

$rotas = q("SELECT r.*, v.placa FROM rotas r JOIN veiculos v ON v.id = r.veiculo_id WHERE r.motorista_id = ? AND r.status IN ('liberada','em_andamento') ORDER BY r.data_rota, r.id", [$mot['id']])->fetchAll();
$titulo = 'Minhas entregas';
$usaMapa = true;
require __DIR__ . '/includes/header.php';
?>
<h1>Olá, <?= e($mot['nome']) ?></h1>
<?php if (!$rotas): ?>
  <div class="cartao"><p>Nenhuma rota liberada para você no momento.</p></div>
<?php endif; ?>
<?php foreach ($rotas as $r):
    $paradas = q('SELECT * FROM pedidos WHERE rota_id = ? ORDER BY ordem_rota', [$r['id']])->fetchAll(); ?>
  <section class="cartao">
    <h2>Rota #<?= $r['id'] ?> · <?= e($r['placa']) ?> · <?= date('d/m/Y', strtotime($r['data_rota'])) ?></h2>
    <p><?= count($paradas) ?> paradas · <?= number_format($r['distancia_km'], 1, ',', '.') ?> km · cerca de <?= $r['tempo_min'] ?> min</p>
    <?php if ($r['status'] === 'liberada'): ?>
      <form method="post"><?= csrf_campo() ?><input type="hidden" name="acao" value="iniciar"><button class="btn" name="rota" value="<?= $r['id'] ?>">▶ Iniciar rota</button></form>
    <?php else: ?>
      <p class="aviso aviso-info" id="gps-status" data-rota="<?= $r['id'] ?>">Compartilhando localização em tempo real...</p>
    <?php endif; ?>
    <?php foreach ($paradas as $p): ?>
      <article class="cartao">
        <h3><?= $p['ordem_rota'] ?>. <?= e($p['codigo']) ?> · <?= e($p['destinatario']) ?> <span class="tag tag-<?= $p['status'] ?>"><?= e($p['status']) ?></span></h3>
        <p><?= e($p['endereco']) ?><br>Janela: <?= date('H:i', strtotime($p['janela_inicio'])) ?> - <?= date('H:i', strtotime($p['janela_fim'])) ?> · <?= e($p['peso_kg']) ?> kg
          · <a href="https://www.google.com/maps/dir/?api=1&destination=<?= $p['lat'] ?>,<?= $p['lng'] ?>" target="_blank" rel="noopener">Abrir navegação</a></p>
        <?php if ($p['status'] === 'a_caminho'): ?>
        <details>
          <summary class="btn btn-peq">Registrar comprovante</summary>
          <form method="post" enctype="multipart/form-data" class="pod">
            <?= csrf_campo() ?>
            <input type="hidden" name="acao" value="pod"><input type="hidden" name="pedido" value="<?= $p['id'] ?>">
            <input type="hidden" name="lat"><input type="hidden" name="lng"><input type="hidden" name="assinatura">
            <label>Resultado <select name="resultado"><option value="entregue">Entregue</option><option value="falha">Falha na entrega</option></select></label>
            <label>Evidência
              <select name="tipo"><option value="foto">Foto</option><option value="assinatura">Assinatura digital</option><option value="codigo">Código de confirmação</option></select></label>
            <label data-ev="foto">Foto <input type="file" name="foto" accept="image/*" capture="environment"></label>
            <div data-ev="assinatura" hidden><p>Assine abaixo:</p><canvas width="320" height="140" style="border:1px solid #b9c2d3;border-radius:6px;touch-action:none;background:#fff;max-width:100%"></canvas><br><button type="button" class="btn-sec btn-peq limpar">Limpar</button></div>
            <label data-ev="codigo" hidden>Código informado pelo destinatário <input name="codigo" pattern="[A-Za-z0-9]{4,10}"></label>
            <label data-falha hidden>Motivo da falha <input name="motivo" maxlength="200"></label>
            <p class="ia-fonte geo">Capturando localização...</p>
            <button class="btn">Confirmar</button>
          </form>
        </details>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>
<script>
document.querySelectorAll('form.pod').forEach(f => {
  const tipo = f.tipo, res = f.resultado;
  const mostrar = () => {
    f.querySelectorAll('[data-ev]').forEach(el => el.hidden = res.value === 'falha' || el.dataset.ev !== tipo.value);
    f.querySelector('[data-falha]').hidden = res.value !== 'falha';
    tipo.closest('label').hidden = res.value === 'falha';
  };
  tipo.onchange = res.onchange = mostrar; mostrar();
  // Assinatura no canvas (mouse e toque)
  const cv = f.querySelector('canvas'), ctx = cv.getContext('2d');
  let desenhando = false, assinou = false;
  const pos = e => { const r = cv.getBoundingClientRect(); return [(e.clientX - r.left) * cv.width / r.width, (e.clientY - r.top) * cv.height / r.height]; };
  cv.onpointerdown = e => { desenhando = true; ctx.beginPath(); ctx.moveTo(...pos(e)); };
  cv.onpointermove = e => { if (desenhando) { ctx.lineTo(...pos(e)); ctx.lineWidth = 2; ctx.stroke(); assinou = true; } };
  cv.onpointerup = cv.onpointerleave = () => desenhando = false;
  f.querySelector('.limpar').onclick = () => { ctx.clearRect(0, 0, cv.width, cv.height); assinou = false; };
  // Captura automática de geolocalização
  const geo = f.querySelector('.geo');
  if (navigator.geolocation) navigator.geolocation.getCurrentPosition(
    p => { f.lat.value = p.coords.latitude; f.lng.value = p.coords.longitude; geo.textContent = `Localização capturada (${p.coords.latitude.toFixed(5)}, ${p.coords.longitude.toFixed(5)}).`; },
    () => geo.textContent = 'Localização indisponível; data e hora serão registradas.');
  f.onsubmit = e => {
    if (res.value === 'entregue' && tipo.value === 'assinatura') {
      if (!assinou) { e.preventDefault(); alert('Colete a assinatura.'); return; }
      f.assinatura.value = cv.toDataURL('image/png');
    }
  };
});
// Envio periódico da posição do veículo durante a rota (RF06)
const gps = document.getElementById('gps-status');
if (gps && navigator.geolocation) {
  navigator.geolocation.watchPosition(p => {
    postJSON('api/gps.php', { rota: gps.dataset.rota, lat: p.coords.latitude, lng: p.coords.longitude })
      .then(() => gps.textContent = 'Localização enviada às ' + new Date().toLocaleTimeString('pt-BR'));
  }, () => gps.textContent = 'Ative a localização do navegador para o rastreamento.', { enableHighAccuracy: true, maximumAge: 15000 });
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
