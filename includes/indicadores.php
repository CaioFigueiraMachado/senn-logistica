<?php
/** RF10 - Cálculo dos indicadores do dashboard (compartilhado com exportação e chatbot). */
require_once __DIR__ . '/ia.php';

function filtros_periodo(array $g): array {
    $ini = !empty($g['inicio']) && strtotime($g['inicio']) ? $g['inicio'] : date('Y-m-d', strtotime('-30 days'));
    $fim = !empty($g['fim']) && strtotime($g['fim']) ? $g['fim'] : date('Y-m-d');
    return [$ini, $fim, $g['regiao'] ?? ''];
}

function indicadores(string $ini, string $fim, string $regiao = ''): array {
    $w = 'DATE(janela_inicio) BETWEEN ? AND ?' . ($regiao ? ' AND regiao = ?' : '');
    $p = $regiao ? [$ini, $fim, $regiao] : [$ini, $fim];

    $k = q("SELECT COUNT(*) total,
              SUM(status = 'entregue') entregues,
              SUM(status = 'falha') falhas,
              SUM(status = 'entregue' AND entregue_em <= janela_fim) no_prazo,
              SUM(status IN ('entregue','falha')) finalizados
            FROM pedidos WHERE $w", $p)->fetch();
    $otd = $k['finalizados'] ? round($k['no_prazo'] / $k['finalizados'] * 100, 1) : 0;

    // Quilometragem: rotas reais do período + estimativa para entregas históricas sem rota
    $kmRotas = (float)q('SELECT COALESCE(SUM(distancia_km),0) FROM rotas WHERE data_rota BETWEEN ? AND ?', [$ini, $fim])->fetchColumn();
    $custoRotas = (float)q('SELECT COALESCE(SUM(custo),0) FROM rotas WHERE data_rota BETWEEN ? AND ?', [$ini, $fim])->fetchColumn();
    $semRota = q("SELECT lat, lng FROM pedidos WHERE $w AND rota_id IS NULL AND status IN ('entregue','falha')", $p)->fetchAll();
    $kmEst = 0;
    // estimativa: ida e volta rateada entre ~4 entregas por viagem
    foreach ($semRota as $s) $kmEst += distancia_km(DEPOSITO_LAT, DEPOSITO_LNG, $s['lat'], $s['lng']) * 2 / 4;
    $km = $kmRotas + $kmEst;
    $custo = $custoRotas + $kmEst * 1.6 + count($semRota) * 3.5;
    $custoEntrega = $k['finalizados'] ? round($custo / $k['finalizados'], 2) : 0;

    $frota = q("SELECT status, COUNT(*) n FROM veiculos GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $ativos = array_sum($frota) - ($frota['manutencao'] ?? 0);
    $ocupacao = $ativos ? round(($frota['em_rota'] ?? 0) / $ativos * 100) : 0;
    // ocupação média de carga das rotas do período
    $carga = q('SELECT AVG(c.kg / v.capacidade_kg) * 100 FROM rotas r JOIN veiculos v ON v.id = r.veiculo_id
                JOIN (SELECT rota_id, SUM(peso_kg) kg FROM pedidos GROUP BY rota_id) c ON c.rota_id = r.id
                WHERE r.data_rota BETWEEN ? AND ?', [$ini, $fim])->fetchColumn();

    $porStatus = q("SELECT status, COUNT(*) n FROM pedidos WHERE $w GROUP BY status", $p)->fetchAll(PDO::FETCH_KEY_PAIR);
    $porRegiao = q("SELECT regiao, COUNT(*) n, ROUND(100 * SUM(status='entregue' AND entregue_em <= janela_fim) / NULLIF(SUM(status IN ('entregue','falha')),0),1) otd
                    FROM pedidos WHERE $w GROUP BY regiao ORDER BY n DESC", $p)->fetchAll();
    $serie = q("SELECT DATE(janela_inicio) dia, COUNT(*) total, SUM(status='entregue' AND entregue_em <= janela_fim) no_prazo,
                SUM(status='falha' OR entregue_em > janela_fim) atrasadas FROM pedidos WHERE $w GROUP BY dia ORDER BY dia", $p)->fetchAll();

    return [
        'periodo' => [$ini, $fim, $regiao],
        'total' => (int)$k['total'], 'entregues' => (int)$k['entregues'], 'falhas' => (int)$k['falhas'],
        'otd' => $otd, 'km' => round($km, 1), 'custo_total' => round($custo, 2), 'custo_entrega' => $custoEntrega,
        'ocupacao_frota' => $ocupacao, 'ocupacao_carga' => $carga !== null && $carga !== false ? round($carga) : null,
        'frota' => $frota, 'por_status' => $porStatus, 'por_regiao' => $porRegiao, 'serie' => $serie,
    ];
}
