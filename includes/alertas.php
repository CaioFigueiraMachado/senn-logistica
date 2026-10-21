<?php
/**
 * RF11 - Monitoramento automático e geração de alertas.
 * Executado nas páginas de painel e no polling do rastreamento (no máximo 1x/min),
 * e também pode rodar via agendador: php cron/monitorar.php
 */
require_once __DIR__ . '/ia.php';

function criar_alerta(string $tipo, string $ref, string $msg): void {
    $existe = q('SELECT id FROM alertas WHERE tipo = ? AND referencia = ? AND resolvido = 0', [$tipo, $ref])->fetchColumn();
    if ($existe) return;
    q('INSERT INTO alertas (tipo, referencia, mensagem) VALUES (?,?,?)', [$tipo, $ref, $msg]);
    enviar_email_alerta($tipo, $msg);
}

/**
 * Envia e-mail para os gestores. Usa mail() do PHP; se o SMTP não estiver
 * configurado, grava em storage/emails.log (registro de notificações).
 */
function enviar_email_alerta(string $tipo, string $msg): void {
    $destinos = q("SELECT email FROM usuarios WHERE perfil IN ('admin','gestor')")->fetchAll(PDO::FETCH_COLUMN);
    $assunto = '[SENN AI-X] Alerta: ' . $tipo;
    $enviado = false;
    if (ini_get('SMTP') && env('MAIL_ENABLED', '0') === '1') {
        $enviado = @mail(implode(',', $destinos), $assunto, $msg, 'From: alertas@senn.com');
    }
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents("$dir/emails.log", sprintf("[%s] %s | para: %s | %s | %s\n",
        date('Y-m-d H:i:s'), $enviado ? 'ENVIADO' : 'REGISTRADO', implode(',', $destinos), $assunto, $msg), FILE_APPEND);
    q('UPDATE alertas SET email_enviado = ? WHERE id = LAST_INSERT_ID()', [$enviado ? 1 : 0]);
    q('INSERT INTO logs (acao, detalhe) VALUES (?,?)', ['notificacao_' . ($enviado ? 'email' : 'log'), mb_substr("$assunto: $msg", 0, 255)]);
}

function verificar_alertas(bool $forcar = false): void {
    $marca = __DIR__ . '/../storage/ultima_verificacao';
    if (!$forcar && is_file($marca) && time() - filemtime($marca) < 60) return;
    if (!is_dir(dirname($marca))) mkdir(dirname($marca), 0775, true);
    touch($marca);

    // Entregas atrasadas
    foreach (q("SELECT codigo, regiao, janela_fim FROM pedidos WHERE status IN ('roteirizado','a_caminho','pendente') AND janela_fim < NOW() AND janela_fim > DATE_SUB(NOW(), INTERVAL 2 DAY)")->fetchAll() as $p) {
        criar_alerta('atraso', $p['codigo'], "Entrega {$p['codigo']} ({$p['regiao']}) passou da janela de " . date('d/m H:i', strtotime($p['janela_fim'])) . '.');
    }
    // Veículo parado por tempo excessivo (sem mudança de posição há 20 min em rota)
    foreach (q("SELECT placa FROM veiculos WHERE status = 'em_rota' AND posicao_em < DATE_SUB(NOW(), INTERVAL 20 MINUTE)")->fetchAll() as $v) {
        criar_alerta('veiculo_parado', $v['placa'], "Veículo {$v['placa']} está parado há mais de 20 minutos.");
    }
    // Rota fora do plano: veículo a mais de 3 km de todas as paradas da rota e do depósito
    foreach (q("SELECT v.placa, v.lat, v.lng, r.id rota FROM veiculos v JOIN rotas r ON r.veiculo_id = v.id AND r.status = 'em_andamento' WHERE v.lat IS NOT NULL")->fetchAll() as $v) {
        $min = distancia_km($v['lat'], $v['lng'], DEPOSITO_LAT, DEPOSITO_LNG);
        foreach (q('SELECT lat, lng FROM pedidos WHERE rota_id = ?', [$v['rota']])->fetchAll() as $p) $min = min($min, distancia_km($v['lat'], $v['lng'], $p['lat'], $p['lng']));
        if ($min > 3) criar_alerta('fora_rota', $v['placa'], sprintf('Veículo %s está %.1f km fora do trajeto planejado da rota #%d.', $v['placa'], $min, $v['rota']));
    }
    // Manutenção a vencer (7 dias)
    foreach (q('SELECT placa, proxima_manutencao FROM veiculos WHERE proxima_manutencao <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)')->fetchAll() as $v) {
        criar_alerta('manutencao', $v['placa'], "Manutenção do veículo {$v['placa']} vence em " . date('d/m/Y', strtotime($v['proxima_manutencao'])) . '.');
    }
    // CNH a vencer (30 dias)
    foreach (q('SELECT id, nome, cnh_validade FROM motoristas WHERE cnh_validade <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)')->fetchAll() as $m) {
        criar_alerta('cnh', 'MOT-' . $m['id'], "CNH de {$m['nome']} vence em " . date('d/m/Y', strtotime($m['cnh_validade'])) . '.');
    }
    // Entregas com alto risco previsto pela IA
    foreach (analisar_riscos(50) as $p) {
        if ($p['risco'] >= 60) criar_alerta('risco', $p['codigo'], "IA: entrega {$p['codigo']} com {$p['risco']}% de risco de atraso. {$p['acao']}.");
    }
}
