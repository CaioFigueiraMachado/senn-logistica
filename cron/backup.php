<?php
/**
 * RNF05 - Backup do banco pela linha de comando. Mantém os 14 backups mais recentes.
 * O sistema também faz backup automático a cada 24 h ao abrir o dashboard.
 * Agendar no Windows: schtasks /create /sc daily /st 23:00 /tn SennBackup /tr "C:\xampp\php\php.exe C:\xampp\htdocs\senn-logistica\cron\backup.php"
 */
if (PHP_SAPI !== 'cli') exit('Executar via linha de comando.');
require_once __DIR__ . '/../includes/backup.php';

$arquivo = fazer_backup();
if (!$arquivo) { fwrite(STDERR, "Falha no backup.\n"); exit(1); }
echo "Backup criado: $arquivo\n";
