<?php
/** RF11 - Monitoramento agendado de alertas (ex.: a cada 5 minutos pelo Agendador de Tarefas). */
if (PHP_SAPI !== 'cli') exit('Executar via linha de comando.');
require_once __DIR__ . '/../includes/alertas.php';
verificar_alertas(true);
echo 'Monitoramento executado em ' . date('d/m/Y H:i:s') . PHP_EOL;
