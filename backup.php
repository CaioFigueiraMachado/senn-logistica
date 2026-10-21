<?php
/** RNF05 - Backup e recuperação pelo painel (somente administrador). */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/backup.php';
$u = exigir_perfil();
if ($u['perfil'] !== 'admin') { http_response_code(403); exit('Acesso restrito ao administrador.'); }

/** Aceita só nomes de arquivo gerados pelo sistema, para evitar acesso a outros caminhos. */
function arquivo_backup(string $nome): ?string {
    if (!preg_match('/^backup_\d{8}_\d{6}\.sql$/', $nome)) return null;
    $c = BACKUP_DIR . '/' . $nome;
    return is_file($c) ? $c : null;
}

if (isset($_GET['baixar']) && ($arq = arquivo_backup($_GET['baixar']))) {
    registrar_log('backup_baixado', basename($arq));
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename=' . basename($arq));
    readfile($arq);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['fazer'])) {
        $arq = fazer_backup();
        registrar_log('backup_manual', $arq ? basename($arq) : 'falha');
        flash($arq ? 'Backup criado: ' . basename($arq) : 'Não foi possível criar o backup. Verifique se o MySQL está ativo.', $arq ? 'ok' : 'erro');
    } elseif (isset($_POST['restaurar']) && ($arq = arquivo_backup($_POST['restaurar']))) {
        // guarda o estado atual antes de restaurar, para poder desfazer
        fazer_backup();
        $ok = restaurar_backup($arq);
        registrar_log('backup_restaurado', basename($arq));
        flash($ok ? 'Banco restaurado a partir de ' . basename($arq) . '. Um backup do estado anterior foi guardado.' : 'Falha ao restaurar o backup.', $ok ? 'ok' : 'erro');
    }
    header('Location: backup.php');
    exit;
}

$lista = listar_backups();
$titulo = 'Backup';
require __DIR__ . '/includes/header.php';
?>
<h1>Backup e recuperação</h1>
<section class="cartao">
  <p>O sistema faz um backup automático do banco a cada 24 horas e guarda os <?= BACKUP_MANTER ?> mais recentes. Você também pode fazer um backup agora, baixar uma cópia ou restaurar uma versão anterior.</p>
  <form method="post"><?= csrf_campo() ?><button name="fazer" value="1">Fazer backup agora</button></form>
</section>
<section class="cartao tabela">
  <h2>Backups disponíveis (<?= count($lista) ?>)</h2>
  <table><thead><tr><th>Arquivo</th><th>Data</th><th>Tamanho</th><th></th></tr></thead><tbody>
  <?php foreach ($lista as $b): $n = basename($b); ?>
    <tr>
      <td><?= e($n) ?></td>
      <td><?= date('d/m/Y H:i', filemtime($b)) ?></td>
      <td><?= number_format(filesize($b) / 1024, 0, ',', '.') ?> KB</td>
      <td class="acoes">
        <a class="btn btn-sec btn-peq" href="?baixar=<?= urlencode($n) ?>">Baixar</a>
        <form method="post" onsubmit="return confirm('Restaurar o banco para <?= e($n) ?>? O estado atual será salvo antes.')"><?= csrf_campo() ?><button class="btn-perigo btn-peq" name="restaurar" value="<?= e($n) ?>">Restaurar</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php if (!$lista): ?><p>Nenhum backup ainda.</p><?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
