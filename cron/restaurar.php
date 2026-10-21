<?php
/** RNF05 - Recuperação: php cron/restaurar.php [arquivo.sql]. Sem argumento, usa o backup mais recente. */
if (PHP_SAPI !== 'cli') exit('Executar via linha de comando.');
require_once __DIR__ . '/../includes/backup.php';

$arquivo = $argv[1] ?? (listar_backups()[0] ?? null);
if (!$arquivo || !is_file($arquivo)) { fwrite(STDERR, "Backup não encontrado.\n"); exit(1); }
$ok = restaurar_backup($arquivo);
echo $ok ? "Banco restaurado a partir de $arquivo\n" : "Falha na restauração.\n";
exit($ok ? 0 : 1);
