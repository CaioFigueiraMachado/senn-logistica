<?php
/** RNF05 - Backup e recuperação do banco (usado pelo painel do admin, pelo cron e pelo backup automático). */
require_once __DIR__ . '/db.php';

const BACKUP_DIR = __DIR__ . '/../storage/backups';
const BACKUP_MANTER = 14;

function binario_mysql(string $nome): string {
    $xampp = "C:\\xampp\\mysql\\bin\\$nome.exe";
    return is_file($xampp) ? $xampp : $nome;
}

/** Gera um .sql com mysqldump. Retorna o caminho do arquivo ou null se falhar. */
function fazer_backup(): ?string {
    if (!is_dir(BACKUP_DIR)) mkdir(BACKUP_DIR, 0775, true);
    $arquivo = BACKUP_DIR . '/backup_' . date('Ymd_His') . '.sql';
    $cmd = sprintf('"%s" --host=%s --user=%s %s --single-transaction --routines %s > "%s"',
        binario_mysql('mysqldump'), escapeshellarg(DB_HOST), escapeshellarg(DB_USER),
        DB_PASS !== '' ? '--password=' . escapeshellarg(DB_PASS) : '', escapeshellarg(DB_NAME), $arquivo);
    exec($cmd, $saida, $codigo);
    if ($codigo !== 0 || !is_file($arquivo) || filesize($arquivo) === 0) { @unlink($arquivo); return null; }
    // mantém só os mais recentes
    $todos = listar_backups();
    foreach (array_slice($todos, BACKUP_MANTER) as $velho) unlink($velho);
    return $arquivo;
}

function restaurar_backup(string $arquivo): bool {
    $cmd = sprintf('"%s" --host=%s --user=%s %s %s < "%s"',
        binario_mysql('mysql'), escapeshellarg(DB_HOST), escapeshellarg(DB_USER),
        DB_PASS !== '' ? '--password=' . escapeshellarg(DB_PASS) : '', escapeshellarg(DB_NAME), $arquivo);
    exec($cmd, $saida, $codigo);
    return $codigo === 0;
}

/** Backups do mais recente para o mais antigo. */
function listar_backups(): array {
    $t = glob(BACKUP_DIR . '/backup_*.sql') ?: [];
    rsort($t);
    return $t;
}

/** Backup automático: se o último tiver mais de 24 h, faz um novo (chamado ao abrir o painel). */
function backup_automatico(): void {
    $ultimo = listar_backups()[0] ?? null;
    if (!$ultimo || time() - filemtime($ultimo) > 86400) {
        if (fazer_backup()) q('INSERT INTO logs (acao, detalhe) VALUES (?, ?)', ['backup_automatico', date('d/m/Y H:i')]);
    }
}
