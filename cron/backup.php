<?php
/**
 * RNF05 - Backup automatizado do banco. Mantém os 14 backups mais recentes.
 * Agendar no Windows: schtasks /create /sc daily /st 23:00 /tn SennBackup /tr "C:\xampp\php\php.exe C:\xampp\htdocs\senn-logistica\cron\backup.php"
 */
if (PHP_SAPI !== 'cli') exit('Executar via linha de comando.');
require_once __DIR__ . '/../config.php';

$dir = __DIR__ . '/../storage/backups';
if (!is_dir($dir)) mkdir($dir, 0775, true);
$arquivo = "$dir/backup_" . date('Ymd_His') . '.sql';

$mysqldump = is_file('C:\xampp\mysql\bin\mysqldump.exe') ? 'C:\xampp\mysql\bin\mysqldump.exe' : 'mysqldump';
$cmd = sprintf('"%s" --host=%s --user=%s %s --single-transaction --routines %s > "%s"',
    $mysqldump, escapeshellarg(DB_HOST), escapeshellarg(DB_USER), DB_PASS !== '' ? '--password=' . escapeshellarg(DB_PASS) : '', escapeshellarg(DB_NAME), $arquivo);
exec($cmd, $saida, $codigo);
if ($codigo !== 0 || filesize($arquivo) === 0) { fwrite(STDERR, "Falha no backup.\n"); exit(1); }

$todos = glob("$dir/backup_*.sql");
rsort($todos);
foreach (array_slice($todos, 14) as $velho) unlink($velho);
echo "Backup criado: $arquivo\n";
