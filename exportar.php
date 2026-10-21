<?php
/** RF10 - Exportação do relatório em Excel (CSV compatível) ou PDF (página de impressão). */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/indicadores.php';
$u = exigir_perfil('gestor');

[$ini, $fim, $regiao] = filtros_periodo($_GET);
$k = indicadores($ini, $fim, $regiao);
registrar_log('relatorio_exportado', ($_GET['formato'] ?? '') . " $ini a $fim");

if (($_GET['formato'] ?? '') === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=relatorio_{$ini}_{$fim}.csv");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para o Excel reconhecer UTF-8
    $l = fn(...$c) => fputcsv($out, $c, ';');
    $l('Relatório SENN AI-X Logística', "$ini a $fim", $regiao ?: 'Todas as regiões');
    $l();
    $l('Indicador', 'Valor');
    $l('Pedidos', $k['total']);
    $l('Entregues', $k['entregues']);
    $l('Falhas', $k['falhas']);
    $l('OTD (%)', str_replace('.', ',', $k['otd']));
    $l('Custo por entrega (R$)', number_format($k['custo_entrega'], 2, ',', ''));
    $l('Custo total (R$)', number_format($k['custo_total'], 2, ',', ''));
    $l('Km rodados', number_format($k['km'], 1, ',', ''));
    $l('Ocupação da frota (%)', $k['ocupacao_frota']);
    $l();
    $l('Região', 'Entregas', 'OTD (%)');
    foreach ($k['por_regiao'] as $r) $l($r['regiao'], $r['n'], str_replace('.', ',', (string)$r['otd']));
    $l();
    $l('Dia', 'Total', 'No prazo', 'Atrasadas/falhas');
    foreach ($k['serie'] as $s) $l($s['dia'], $s['total'], $s['no_prazo'], $s['atrasadas']);
    exit;
}
$brl = fn($v) => 'R$ ' . number_format($v, 2, ',', '.');
?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><title>Relatório <?= e($ini) ?> a <?= e($fim) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<style>body{background:#fff;padding:24px} .kpis{grid-template-columns:repeat(4,1fr)}</style></head>
<body onload="window.print()">
<p class="acoes"><button onclick="window.print()">Salvar como PDF / Imprimir</button></p>
<h1><?= APP_NOME ?> · Relatório gerencial</h1>
<p>Período: <?= date('d/m/Y', strtotime($ini)) ?> a <?= date('d/m/Y', strtotime($fim)) ?> · Região: <?= e($regiao ?: 'Todas') ?> · Gerado em <?= date('d/m/Y H:i') ?> por <?= e($u['nome']) ?></p>
<div class="kpis">
  <div class="kpi"><div class="v"><?= $k['otd'] ?>%</div><div class="r">OTD</div></div>
  <div class="kpi"><div class="v"><?= $brl($k['custo_entrega']) ?></div><div class="r">Custo por entrega</div></div>
  <div class="kpi"><div class="v"><?= number_format($k['km'], 0, ',', '.') ?></div><div class="r">Km rodados</div></div>
  <div class="kpi"><div class="v"><?= $k['ocupacao_frota'] ?>%</div><div class="r">Ocupação da frota</div></div>
</div>
<h2>Por região</h2>
<table><tr><th>Região</th><th>Entregas</th><th>OTD</th></tr>
<?php foreach ($k['por_regiao'] as $r): ?><tr><td><?= e($r['regiao']) ?></td><td><?= $r['n'] ?></td><td><?= e($r['otd']) ?>%</td></tr><?php endforeach; ?></table>
<h2>Por status</h2>
<table><tr><th>Status</th><th>Quantidade</th></tr>
<?php foreach ($k['por_status'] as $s => $n): ?><tr><td><?= e($s) ?></td><td><?= $n ?></td></tr><?php endforeach; ?></table>
</body></html>
