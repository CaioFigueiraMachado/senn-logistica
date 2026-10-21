<?php
/** RF06 - Dados do rastreamento em tempo real (consumido via polling a cada 5 s). */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alertas.php';
exigir_perfil('gestor', 'despachante');
header('Content-Type: application/json; charset=utf-8');

// Modo demonstração: avança os veículos em rota na direção da próxima parada
if (!empty($_GET['simular'])) {
    $emRota = q("SELECT r.id rota, v.id, v.lat, v.lng FROM rotas r JOIN veiculos v ON v.id = r.veiculo_id WHERE r.status = 'em_andamento'")->fetchAll();
    foreach ($emRota as $v) {
        $prox = q("SELECT lat, lng FROM pedidos WHERE rota_id = ? AND status = 'a_caminho' ORDER BY ordem_rota LIMIT 1", [$v['rota']])->fetch();
        if (!$prox) continue;
        $lat = $v['lat'] + ($prox['lat'] - $v['lat']) * 0.25;
        $lng = $v['lng'] + ($prox['lng'] - $v['lng']) * 0.25;
        q('UPDATE veiculos SET lat = ?, lng = ?, posicao_em = NOW() WHERE id = ?', [$lat, $lng, $v['id']]);
    }
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

$alertas = (int)q('SELECT COUNT(*) FROM alertas WHERE resolvido = 0')->fetchColumn();
echo json_encode(['atualizado' => date('H:i:s'), 'veiculos' => $veiculos, 'entregas' => $entregas, 'alertas' => $alertas]);
