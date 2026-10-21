<?php
/** Detalhe do pedido com o comprovante de entrega (RF07) e o risco calculado (RF09). */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ia.php';
$u = exigir_perfil('gestor', 'despachante');

$p = q('SELECT p.*, r.data_rota, r.status rota_status, v.placa, m.nome motorista FROM pedidos p
        LEFT JOIN rotas r ON r.id = p.rota_id LEFT JOIN veiculos v ON v.id = r.veiculo_id LEFT JOIN motoristas m ON m.id = r.motorista_id
        WHERE p.id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
if (!$p) { http_response_code(404); exit('Pedido não encontrado.'); }
$comps = q('SELECT c.*, m.nome motorista FROM comprovantes c LEFT JOIN motoristas m ON m.id = c.motorista_id WHERE c.pedido_id = ? ORDER BY c.registrado_em DESC', [$p['id']])->fetchAll();
$risco = !in_array($p['status'], ['entregue', 'falha'], true) ? calcular_risco($p) : null;
const NOMES_ST = ['pendente' => 'Pendente', 'roteirizado' => 'Roteirizado', 'a_caminho' => 'A caminho', 'entregue' => 'Entregue', 'falha' => 'Falha'];
const NOMES_EV = ['foto' => 'Foto', 'assinatura' => 'Assinatura digital', 'codigo' => 'Código de confirmação'];

$titulo = 'Pedido ' . $p['codigo'];
$usaMapa = true;
require __DIR__ . '/includes/header.php';
?>
<p><a href="javascript:history.back()">Voltar</a></p>
<div class="acoes" style="justify-content: space-between">
  <h1 style="margin: 0">Pedido <?= e($p['codigo']) ?></h1>
  <span class="tag tag-<?= $p['status'] ?>"><?= NOMES_ST[$p['status']] ?></span>
</div>
<div class="grade">
  <section class="cartao">
    <h2>Dados da entrega</h2>
    <table>
      <tr><th>Destinatário</th><td><?= e($p['destinatario']) ?></td></tr>
      <tr><th>Endereço</th><td><?= e($p['endereco']) ?></td></tr>
      <tr><th>Região</th><td><?= e($p['regiao']) ?></td></tr>
      <tr><th>Janela</th><td><?= date('d/m/Y H:i', strtotime($p['janela_inicio'])) ?> às <?= date('H:i', strtotime($p['janela_fim'])) ?></td></tr>
      <tr><th>Carga</th><td><?= e($p['peso_kg']) ?> kg · <?= e($p['volume_m3']) ?> m³</td></tr>
      <tr><th>Prioridade</th><td><span class="tag tag-<?= $p['prioridade'] ?>"><?= ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta'][$p['prioridade']] ?></span></td></tr>
      <tr><th>Rota</th><td><?= $p['rota_id'] ? '#' . $p['rota_id'] . ' · ' . e($p['placa']) . ($p['motorista'] ? ' · ' . e($p['motorista']) : '') . " · parada {$p['ordem_rota']}" : 'Ainda sem rota' ?></td></tr>
      <?php if ($p['entregue_em']): ?><tr><th>Finalizado em</th><td><?= date('d/m/Y H:i', strtotime($p['entregue_em'])) ?><?= strtotime($p['entregue_em']) > strtotime($p['janela_fim']) ? ' <span class="tag tag-erro">fora do prazo</span>' : ' <span class="tag tag-ok">no prazo</span>' ?></td></tr><?php endif; ?>
    </table>
    <?php if ($risco): ?>
      <div class="risco risco-<?= $risco['risco'] >= 60 ? 'erro' : ($risco['risco'] >= 35 ? 'alerta' : 'ok') ?>" style="margin-top: 14px">
        <strong>Risco de atraso: <?= str_replace('.', ',', $risco['risco']) ?>%</strong>
        <div class="barra"><span style="width: <?= $risco['risco'] ?>%"></span></div>
        <p class="ia-fonte"><?= e(implode(' · ', $risco['motivos']) ?: 'sem fatores relevantes') ?></p>
        <p class="sugestao"><strong>Sugestão:</strong> <?= e($risco['acao']) ?></p>
      </div>
    <?php endif; ?>
  </section>
  <section class="cartao">
    <h2>Localização</h2>
    <div id="mapa" class="mapa" style="height: 320px" role="img" aria-label="Mapa com o endereço da entrega"></div>
  </section>
</div>

<section class="cartao">
  <h2>Comprovante de entrega</h2>
  <?php if (!$comps): ?>
    <p>Ainda não há comprovante registrado para este pedido.</p>
  <?php endif; ?>
  <div class="grade">
  <?php foreach ($comps as $c):
      $ehImagem = in_array($c['tipo'], ['foto', 'assinatura'], true) && preg_match('/^[a-z]+_[a-f0-9]{16}\.(jpe?g|png|webp)$/', $c['evidencia']); ?>
    <article class="cartao" style="margin: 0">
      <h3><?= NOMES_EV[$c['tipo']] ?></h3>
      <?php if ($ehImagem): ?>
        <img src="comprovante.php?id=<?= $c['id'] ?>" alt="<?= NOMES_EV[$c['tipo']] ?> do pedido <?= e($p['codigo']) ?>" style="max-width: 100%; max-height: 260px; border-radius: 8px; border: 1px solid var(--borda); background: #fff">
      <?php else: ?>
        <p><?= e($c['evidencia']) ?></p>
      <?php endif; ?>
      <p class="ia-fonte">Registrado em <?= date('d/m/Y \à\s H:i:s', strtotime($c['registrado_em'])) ?><?= $c['motorista'] ? ' por ' . e($c['motorista']) : '' ?><br>
        <?= $c['lat'] ? 'Localização: ' . number_format($c['lat'], 5, ',', '') . ', ' . number_format($c['lng'], 5, ',', '') . ' · <a target="_blank" rel="noopener" href="https://www.openstreetmap.org/?mlat=' . $c['lat'] . '&mlon=' . $c['lng'] . '#map=17/' . $c['lat'] . '/' . $c['lng'] . '">ver no mapa</a>' : 'Localização não informada' ?></p>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const pos = [<?= (float)$p['lat'] ?>, <?= (float)$p['lng'] ?>];
  const m = criarMapa('mapa', pos);
  if (!m) return;
  m.setView(pos, 15);
  L.circleMarker(pos, { radius: 9, color: '#fff', weight: 3, fillColor: '#2563eb', fillOpacity: 1 }).addTo(m).bindPopup(<?= json_encode(e($p['endereco'])) ?>);
  <?php foreach ($comps as $c): if ($c['lat']): ?>
  L.circleMarker([<?= (float)$c['lat'] ?>, <?= (float)$c['lng'] ?>], { radius: 7, color: '#fff', weight: 2, fillColor: '#16a34a', fillOpacity: 1 }).addTo(m).bindPopup('Local do comprovante');
  <?php endif; endforeach; ?>
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
