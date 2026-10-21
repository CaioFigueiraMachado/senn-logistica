<?php
/** RF06 - Dados do rastreamento em tempo real (consumido via polling a cada 5 s). */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alertas.php';
exigir_perfil('gestor', 'despachante');
header('Content-Type: application/json; charset=utf-8');

// Modo demonstração: coloca as rotas de hoje na rua e faz os veículos andarem até as paradas
$simulacao = null;
if (!empty($_GET['simular'])) {
    // Pedidos de hoje sem rota: a IA monta as rotas antes de começar
    $pendentes = q("SELECT * FROM pedidos WHERE status = 'pendente' AND DATE(janela_inicio) = CURDATE()")->fetchAll();
    $livres = q("SELECT * FROM veiculos WHERE status = 'disponivel'")->fetchAll();
    if ($pendentes && $livres) {
        $res = otimizar_rotas($pendentes, $livres, date('Y-m-d'));
        salvar_rotas($res['rotas'], date('Y-m-d'));
    }
    // Rotas de hoje ainda não iniciadas saem do depósito
    $paraIniciar = q("SELECT id, veiculo_id FROM rotas WHERE data_rota = CURDATE() AND status IN ('planejada','liberada')")->fetchAll();
    foreach ($paraIniciar as $r) {
        q("UPDATE rotas SET status = 'em_andamento' WHERE id = ?", [$r['id']]);
        q("UPDATE pedidos SET status = 'a_caminho' WHERE rota_id = ? AND status = 'roteirizado'", [$r['id']]);
        q("UPDATE veiculos SET status = 'em_rota', lat = ?, lng = ?, posicao_em = NOW() WHERE id = ?", [DEPOSITO_LAT, DEPOSITO_LNG, $r['veiculo_id']]);
    }
    $emRota = q("SELECT r.id rota, r.motorista_id, v.id, v.lat, v.lng FROM rotas r JOIN veiculos v ON v.id = r.veiculo_id WHERE r.status = 'em_andamento'")->fetchAll();
    $entregues = 0;
    foreach ($emRota as $v) {
        $prox = q("SELECT id, lat, lng FROM pedidos WHERE rota_id = ? AND status = 'a_caminho' ORDER BY ordem_rota LIMIT 1", [$v['rota']])->fetch();
        if (!$prox) {
            // sem paradas: volta ao depósito e encerra a rota
            q("UPDATE rotas SET status = 'concluida' WHERE id = ?", [$v['rota']]);
            q("UPDATE veiculos SET status = 'disponivel', lat = ?, lng = ?, posicao_em = NOW() WHERE id = ?", [DEPOSITO_LAT, DEPOSITO_LNG, $v['id']]);
            continue;
        }
        // anda até 1,5 km por ciclo (5 s) na direção da próxima parada (acelerado para a demonstração)
        $dist = distancia_km($v['lat'], $v['lng'], $prox['lat'], $prox['lng']);
        $f = $dist > 0 ? min(1, 1.5 / $dist) : 1;
        $lat = $v['lat'] + ($prox['lat'] - $v['lat']) * $f;
        $lng = $v['lng'] + ($prox['lng'] - $v['lng']) * $f;
        q('UPDATE veiculos SET lat = ?, lng = ?, posicao_em = NOW() WHERE id = ?', [$lat, $lng, $v['id']]);
        if ($f >= 1) {
            // chegou: registra a entrega com comprovante simulado
            q("UPDATE pedidos SET status = 'entregue', entregue_em = NOW() WHERE id = ?", [$prox['id']]);
            q("INSERT INTO comprovantes (pedido_id, motorista_id, tipo, evidencia, lat, lng) VALUES (?, ?, 'codigo', 'Simulação de demonstração', ?, ?)", [$prox['id'], $v['motorista_id'], $lat, $lng]);
            $entregues++;
        }
    }
    $simulacao = $emRota
        ? 'Simulação ligada: ' . count($emRota) . ' veículo(s) em rota andando até as próximas paradas. Cada chegada registra a entrega automaticamente.'
        : 'Simulação ligada, mas todas as entregas de hoje já foram feitas. Cadastre novos pedidos para ver os veículos andando.';
}

verificar_alertas();

$where = ['DATE(p.janela_inicio) = ?']; $par = [$_GET['data'] ?? date('Y-m-d')];
if (!empty($_GET['rota'])) { $where[] = 'p.rota_id = ?'; $par[] = (int)$_GET['rota']; }
if (!empty($_GET['motorista'])) { $where[] = 'r.motorista_id = ?'; $par[] = (int)$_GET['motorista']; }
if (!empty($_GET['regiao'])) { $where[] = 'p.regiao = ?'; $par[] = $_GET['regiao']; }
if (!empty($_GET['status'])) { $where[] = 'p.status = ?'; $par[] = $_GET['status']; }

$entregas = q('SELECT p.id, p.codigo, p.destinatario, p.endereco, p.regiao, p.lat, p.lng, p.status, p.janela_fim, p.risco_atraso, p.rota_id, r.motorista_id
    FROM pedidos p LEFT JOIN rotas r ON r.id = p.rota_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.rota_id, p.ordem_rota', $par)->fetchAll();

$veiculos = q("SELECT v.id, v.placa, v.tipo, v.status, v.lat, v.lng, v.posicao_em, r.id rota, m.nome motorista, m.id motorista_id
    FROM veiculos v LEFT JOIN rotas r ON r.veiculo_id = v.id AND r.status = 'em_andamento' LEFT JOIN motoristas m ON m.id = r.motorista_id
    WHERE v.lat IS NOT NULL")->fetchAll();
if (!empty($_GET['motorista'])) $veiculos = array_values(array_filter($veiculos, fn($v) => $v['motorista_id'] == $_GET['motorista']));
if (!empty($_GET['rota'])) $veiculos = array_values(array_filter($veiculos, fn($v) => $v['rota'] == $_GET['rota']));

// Trajeto que falta para cada veículo em rota (paradas ainda não visitadas, na ordem)
foreach ($veiculos as &$v) {
    $v['caminho'] = $v['rota'] ? q("SELECT lat, lng FROM pedidos WHERE rota_id = ? AND status = 'a_caminho' ORDER BY ordem_rota", [$v['rota']])->fetchAll(PDO::FETCH_NUM) : [];
}
unset($v);

$alertas = (int)q('SELECT COUNT(*) FROM alertas WHERE resolvido = 0')->fetchColumn();
echo json_encode(['atualizado' => date('H:i:s'), 'veiculos' => $veiculos, 'entregas' => $entregas, 'alertas' => $alertas, 'simulacao' => $simulacao]);
