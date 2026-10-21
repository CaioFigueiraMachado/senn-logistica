<?php
/** RF11 - Alertas e notificações automáticas */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/alertas.php';
$u = exigir_perfil('gestor', 'despachante');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['resolver'])) {
        q('UPDATE alertas SET resolvido = 1, resolvido_em = NOW() WHERE id = ?', [(int)$_POST['resolver']]);
        registrar_log('alerta_resolvido', $_POST['resolver']);
        flash('Alerta marcado como resolvido.');
    } elseif (isset($_POST['verificar'])) {
        verificar_alertas(true);
        flash('Monitoramento executado.');
    }
    header('Location: alertas.php');
    exit;
}

verificar_alertas();
const TIPOS_A = ['atraso' => 'Entrega atrasada', 'veiculo_parado' => 'Veículo parado', 'fora_rota' => 'Fora da rota', 'manutencao' => 'Manutenção', 'cnh' => 'CNH a vencer', 'risco' => 'Risco (IA)'];
$abertos = q('SELECT * FROM alertas WHERE resolvido = 0 ORDER BY criado_em DESC')->fetchAll();
$resolvidos = q('SELECT * FROM alertas WHERE resolvido = 1 ORDER BY resolvido_em DESC LIMIT 20')->fetchAll();
$logs = q("SELECT * FROM logs WHERE acao LIKE 'notificacao%' ORDER BY id DESC LIMIT 15")->fetchAll();

$titulo = 'Alertas';
require __DIR__ . '/includes/header.php';
?>
<h1>Alertas e notificações</h1>
<form method="post" class="acoes"><?= csrf_campo() ?><button name="verificar" value="1">↻ Executar monitoramento agora</button></form>
<section class="cartao tabela">
  <h2>Abertos (<?= count($abertos) ?>)</h2>
  <table><thead><tr><th>Tipo</th><th>Mensagem</th><th>Criado</th><th>E-mail</th><th></th></tr></thead><tbody>
  <?php foreach ($abertos as $a): ?>
    <tr><td><span class="tag tag-erro"><?= TIPOS_A[$a['tipo']] ?></span></td><td><?= e($a['mensagem']) ?></td>
      <td><?= date('d/m H:i', strtotime($a['criado_em'])) ?></td><td><?= $a['email_enviado'] ? 'Enviado' : 'Registrado em log' ?></td>
      <td><form method="post"><?= csrf_campo() ?><button class="btn-peq" name="resolver" value="<?= $a['id'] ?>">Resolver</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php if (!$abertos): ?><p>Nenhum alerta aberto. 🎉</p><?php endif; ?>
</section>
<div class="grade">
  <section class="cartao tabela"><h2>Resolvidos recentemente</h2>
    <table><tbody><?php foreach ($resolvidos as $a): ?><tr><td><?= TIPOS_A[$a['tipo']] ?></td><td><?= e($a['mensagem']) ?></td><td><?= date('d/m H:i', strtotime($a['resolvido_em'])) ?></td></tr><?php endforeach; ?></tbody></table>
  </section>
  <section class="cartao tabela"><h2>Log de notificações</h2>
    <table><tbody><?php foreach ($logs as $l): ?><tr><td><?= date('d/m H:i', strtotime($l['criado_em'])) ?></td><td><?= e($l['detalhe']) ?></td></tr><?php endforeach; ?></tbody></table>
  </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
