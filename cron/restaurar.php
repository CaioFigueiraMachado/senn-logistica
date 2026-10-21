<?php
/** RNF05 - Recuperação: php cron/restaurar.php [arquivo.sql]. Sem argumento, usa o backup mais recente. */
if (PHP_SAPI !== 'cli') exit('Executar via linha de comando.');
require_once __DIR__ . '/../config.php';

$arquivo = $argv[1] ?? (function () { $t = glob(__DIR__ . '/../storage/backups/backup_*.sql'); rsort($t); return $t[0] ?? null; })();
if (!$arquivo || !is_file($arquivo)) { fwrite(STDERR, "Backup não encontrado.\n"); exit(1); }

$mysql = is_file('C:\xampp\mysql\bin\mysql.exe') ? 'C:\xampp\mysql\bin\mysql.exe' : 'mysql';
$cmd = sprintf('"%s" --host=%s --user=%s %s %s < "%s"',
    $mysql, escapeshellarg(DB_HOST), escapeshellarg(DB_USER), DB_PASS !== '' ? '--password=' . escapeshellarg(DB_PASS) : '', escapeshellarg(DB_NAME), $arquivo);
exec($cmd, $saida, $codigo);
echo $codigo === 0 ? "Banco restaurado a partir de $arquivo\n" : "Falha na restauração.\n";
exit($codigo);
