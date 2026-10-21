<?php
/**
 * Módulo de IA da plataforma.
 *
 * Abordagem híbrida:
 *  - Modelos locais em PHP (sempre disponíveis = modo fallback, RNF06):
 *      * Roteirização: heurística VRP com janelas de tempo (inserção gulosa por
 *        vizinho mais próximo + melhoria 2-opt), respeitando capacidade kg/m³.
 *      * Previsão de demanda: decomposição sazonal semanal + tendência linear
 *        (regressão por mínimos quadrados) por região.
 *      * Risco de atraso: modelo de pontuação logística com pesos aprendidos
 *        da taxa histórica de atraso por região e faixa de horário.
 *  - LLM externo (Google Gemini) opcional, usado para redigir insights e
 *    responder o chatbot em linguagem natural. Sem chave ou sem internet,
 *    os textos são gerados por regras locais.
 */
require_once __DIR__ . '/db.php';

const VELOCIDADE_MEDIA_KMH = 28;   // média urbana
const TEMPO_PARADA_MIN = 6;        // tempo por entrega

/** Distância em km entre dois pontos (fórmula de Haversine). */
function distancia_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    // fator 1.3 aproxima a distância real pela malha viária
    return 2 * $r * asin(sqrt($a)) * 1.3;
}

/* ------------------------------------------------------------------ */
/* RF05 - Roteirização                                                 */
/* ------------------------------------------------------------------ */

/**
 * Distribui pedidos entre veículos e ordena as paradas.
 * @return array ['rotas' => [...], 'nao_alocados' => [...]]
 */
function otimizar_rotas(array $pedidos, array $veiculos, string $data): array {
    $peso = ['alta' => 0, 'media' => 1, 'baixa' => 2];
    // prioridade alta e janelas que fecham antes são atendidas primeiro
    usort($pedidos, fn($a, $b) => [$peso[$a['prioridade']], $a['janela_fim']] <=> [$peso[$b['prioridade']], $b['janela_fim']]);
    // veículos maiores primeiro reduzem o número de rotas
    usort($veiculos, fn($a, $b) => $b['capacidade_kg'] <=> $a['capacidade_kg']);

    $restantes = $pedidos;
    $rotas = [];
    foreach ($veiculos as $v) {
        if (!$restantes) break;
        $carga = 0; $vol = 0; $paradas = [];
        $lat = DEPOSITO_LAT; $lng = DEPOSITO_LNG;
        $relogio = strtotime("$data 08:00");
        $km = 0;

        // Vizinho mais próximo viável (capacidade + janela + autonomia)
        while (true) {
            $melhor = null; $melhorCusto = INF;
            foreach ($restantes as $i => $p) {
                if ($carga + $p['peso_kg'] > $v['capacidade_kg'] || $vol + $p['volume_m3'] > $v['capacidade_m3']) continue;
                $d = distancia_km($lat, $lng, $p['lat'], $p['lng']);
                $chegada = max($relogio + $d / VELOCIDADE_MEDIA_KMH * 3600, strtotime($p['janela_inicio']));
                if ($chegada > strtotime($p['janela_fim'])) continue;
                $volta = distancia_km($p['lat'], $p['lng'], DEPOSITO_LAT, DEPOSITO_LNG);
                if ($km + $d + $volta > $v['autonomia_km']) continue;
                // custo = distância + penalidade por espera + bônus de prioridade
                $espera = max(0, strtotime($p['janela_inicio']) - ($relogio + $d / VELOCIDADE_MEDIA_KMH * 3600)) / 3600 * 5;
                $custo = $d + $espera - ($p['prioridade'] === 'alta' ? 1.5 : 0);
                if ($custo < $melhorCusto) { $melhorCusto = $custo; $melhor = $i; }
            }
            if ($melhor === null) break;
            $p = $restantes[$melhor];
            $d = distancia_km($lat, $lng, $p['lat'], $p['lng']);
            $relogio = max($relogio + $d / VELOCIDADE_MEDIA_KMH * 3600, strtotime($p['janela_inicio'])) + TEMPO_PARADA_MIN * 60;
            $km += $d; $carga += $p['peso_kg']; $vol += $p['volume_m3'];
            $lat = $p['lat']; $lng = $p['lng'];
            $p['eta'] = date('H:i', (int)$relogio - TEMPO_PARADA_MIN * 60);
            $paradas[] = $p;
            unset($restantes[$melhor]);
        }
        if (!$paradas) continue;

        // referência sem otimização: mesmas paradas na ordem de início da janela
        $ingenua = $paradas;
        usort($ingenua, fn($a, $b) => $a['janela_inicio'] <=> $b['janela_inicio']);
        $kmBase = distancia_total($ingenua);

        $paradas = melhorar_2opt($paradas, $data);
        $km = distancia_total($paradas);
        $tempo = (int)round($km / VELOCIDADE_MEDIA_KMH * 60 + count($paradas) * TEMPO_PARADA_MIN);
        $rotas[] = [
            'veiculo' => $v, 'paradas' => $paradas, 'distancia_km' => round($km, 2), 'distancia_base_km' => round(max($kmBase, $km), 2),
            'tempo_min' => $tempo, 'custo' => round($km * $v['custo_km'], 2),
            'carga_kg' => $carga, 'ocupacao' => round($carga / $v['capacidade_kg'] * 100),
        ];
    }
    return ['rotas' => $rotas, 'nao_alocados' => array_values($restantes)];
}

function distancia_total(array $paradas): float {
    $km = 0; $lat = DEPOSITO_LAT; $lng = DEPOSITO_LNG;
    foreach ($paradas as $p) { $km += distancia_km($lat, $lng, $p['lat'], $p['lng']); $lat = $p['lat']; $lng = $p['lng']; }
    return $km + distancia_km($lat, $lng, DEPOSITO_LAT, DEPOSITO_LNG);
}

/** Recalcula horários; retorna null se alguma janela for violada. */
function agenda_viavel(array $paradas, string $data): ?array {
    $relogio = strtotime("$data 08:00"); $lat = DEPOSITO_LAT; $lng = DEPOSITO_LNG;
    foreach ($paradas as &$p) {
        $relogio = max($relogio + distancia_km($lat, $lng, $p['lat'], $p['lng']) / VELOCIDADE_MEDIA_KMH * 3600, strtotime($p['janela_inicio']));
        if ($relogio > strtotime($p['janela_fim'])) return null;
        $p['eta'] = date('H:i', (int)$relogio);
        $relogio += TEMPO_PARADA_MIN * 60;
        $lat = $p['lat']; $lng = $p['lng'];
    }
    return $paradas;
}

/** 2-opt: inverte trechos da rota enquanto reduzir a distância sem violar janelas. */
function melhorar_2opt(array $paradas, string $data): array {
    $n = count($paradas);
    if ($n < 4) return $paradas;
    $melhorou = true; $melhorKm = distancia_total($paradas); $iter = 0;
    while ($melhorou && $iter++ < 50) {
        $melhorou = false;
        for ($i = 0; $i < $n - 1; $i++) {
            for ($k = $i + 1; $k < $n; $k++) {
                $nova = array_merge(array_slice($paradas, 0, $i), array_reverse(array_slice($paradas, $i, $k - $i + 1)), array_slice($paradas, $k + 1));
                $km = distancia_total($nova);
                if ($km + 0.01 < $melhorKm && ($ok = agenda_viavel($nova, $data))) {
                    $paradas = $ok; $melhorKm = $km; $melhorou = true;
                }
            }
        }
    }
    return $paradas;
}

/* ------------------------------------------------------------------ */
/* RF08 - Previsão de demanda                                          */
/* ------------------------------------------------------------------ */

/**
 * Previsão diária por região para os próximos $dias.
 * Modelo: y(t) = (a + b·t) · s(dia_da_semana), ajustado nos últimos 90 dias.
 */
function prever_demanda(int $dias = 7, ?string $regiao = null): array {
    $sql = 'SELECT regiao, DATE(janela_inicio) dia, COUNT(*) n FROM pedidos
            WHERE janela_inicio >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND janela_inicio < CURDATE()'
         . ($regiao ? ' AND regiao = ?' : '') . ' GROUP BY regiao, dia';
    $hist = [];
    foreach (q($sql, $regiao ? [$regiao] : [])->fetchAll() as $r) $hist[$r['regiao']][$r['dia']] = (int)$r['n'];

    $inicio = strtotime('-90 days', strtotime('today'));
    $resultado = [];
    foreach ($hist as $reg => $serie) {
        // série completa (dias sem pedidos = 0)
        $y = [];
        for ($t = 0; $t < 90; $t++) $y[$t] = $serie[date('Y-m-d', strtotime("+$t days", $inicio))] ?? 0;

        // índice sazonal por dia da semana
        $somaDow = array_fill(1, 7, 0); $cntDow = array_fill(1, 7, 0);
        $media = array_sum($y) / 90 ?: 1;
        foreach ($y as $t => $v) { $dow = (int)date('N', strtotime("+$t days", $inicio)); $somaDow[$dow] += $v; $cntDow[$dow]++; }
        $saz = [];
        foreach ($somaDow as $dow => $s) $saz[$dow] = $cntDow[$dow] ? ($s / $cntDow[$dow]) / $media : 1;

        // tendência linear na série dessazonalizada
        $xs = 0; $ys = 0; $xy = 0; $xx = 0;
        foreach ($y as $t => $v) {
            $dow = (int)date('N', strtotime("+$t days", $inicio));
            $d = $saz[$dow] > 0 ? $v / $saz[$dow] : 0;
            $xs += $t; $ys += $d; $xy += $t * $d; $xx += $t * $t;
        }
        $b = (90 * $xy - $xs * $ys) / (90 * $xx - $xs * $xs);
        $a = ($ys - $b * $xs) / 90;

        for ($h = 0; $h < $dias; $h++) {
            $dia = strtotime("+$h days", strtotime('today'));
            $prev = max(0, ($a + $b * (90 + $h)) * $saz[(int)date('N', $dia)]);
            $resultado[$reg][date('Y-m-d', $dia)] = round($prev, 1);
        }
    }
    ksort($resultado);
    return $resultado;
}

/* ------------------------------------------------------------------ */
/* RF09 - Risco de atraso / falha                                      */
/* ------------------------------------------------------------------ */

/** Taxas históricas de atraso por região e por hora de início da janela. */
function taxas_historicas(): array {
    static $cache = null;
    if ($cache) return $cache;
    $atraso = "SUM(status='falha' OR entregue_em > janela_fim) / COUNT(*)";
    $reg = []; $hora = [];
    foreach (q("SELECT regiao, $atraso t FROM pedidos WHERE status IN ('entregue','falha') GROUP BY regiao")->fetchAll() as $r) $reg[$r['regiao']] = (float)$r['t'];
    foreach (q("SELECT HOUR(janela_inicio) h, $atraso t FROM pedidos WHERE status IN ('entregue','falha') GROUP BY h")->fetchAll() as $r) $hora[(int)$r['h']] = (float)$r['t'];
    $geral = (float)q("SELECT $atraso FROM pedidos WHERE status IN ('entregue','falha')")->fetchColumn();
    return $cache = compact('reg', 'hora', 'geral');
}

/**
 * Probabilidade (0-100) de atraso/falha de um pedido.
 * Regressão logística com pesos calibrados nas taxas históricas.
 */
function calcular_risco(array $p): array {
    $t = taxas_historicas();
    $g = max($t['geral'], 0.01);
    $logit = fn($x) => log(max($x, 0.001) / max(1 - $x, 0.001));
    $z = $logit($g)
       + 1.0 * ($logit($t['reg'][$p['regiao']] ?? $g) - $logit($g))
       + 0.8 * ($logit($t['hora'][(int)date('G', strtotime($p['janela_inicio']))] ?? $g) - $logit($g));

    $motivos = [];
    $janelaH = (strtotime($p['janela_fim']) - strtotime($p['janela_inicio'])) / 3600;
    if ($janelaH <= 2) { $z += 0.5; $motivos[] = 'janela de entrega curta'; }
    $dist = distancia_km(DEPOSITO_LAT, DEPOSITO_LNG, $p['lat'], $p['lng']);
    if ($dist > 6) { $z += 0.04 * $dist; $motivos[] = sprintf('distante do depósito (%.1f km)', $dist); }
    if ($p['peso_kg'] > 30) { $z += 0.3; $motivos[] = 'carga pesada'; }
    if (($t['reg'][$p['regiao']] ?? 0) > $g * 1.3) $motivos[] = 'região com histórico de atrasos';
    if ((int)date('G', strtotime($p['janela_inicio'])) >= 15) $motivos[] = 'horário de pico no fim da tarde';
    if ($p['status'] === 'pendente' && strtotime($p['janela_inicio']) < time() + 3600) { $z += 1.2; $motivos[] = 'ainda sem rota e janela próxima'; }
    if (strtotime($p['janela_fim']) < time() && !in_array($p['status'], ['entregue', 'falha'])) { $z += 3; $motivos[] = 'janela já expirada'; }

    $prob = round(100 / (1 + exp(-$z)), 1);
    $acao = $prob >= 60 ? 'Replanejar agora: antecipar na rota ou alocar veículo dedicado'
          : ($prob >= 35 ? 'Priorizar na sequência da rota e avisar o destinatário' : 'Manter planejamento');
    return ['risco' => $prob, 'motivos' => $motivos, 'acao' => $acao];
}

/** Atualiza o risco de todos os pedidos em aberto e devolve os mais críticos. */
function analisar_riscos(int $limite = 20): array {
    $abertos = q("SELECT * FROM pedidos WHERE status IN ('pendente','roteirizado','a_caminho')")->fetchAll();
    $up = db()->prepare('UPDATE pedidos SET risco_atraso = ? WHERE id = ?');
    foreach ($abertos as &$p) {
        $p += calcular_risco($p);
        $up->execute([$p['risco'], $p['id']]);
    }
    usort($abertos, fn($a, $b) => $b['risco'] <=> $a['risco']);
    return array_slice($abertos, 0, $limite);
}

/* ------------------------------------------------------------------ */
/* LLM externo (Gemini) com fallback                                    */
/* ------------------------------------------------------------------ */

/** Chama o Gemini. Retorna null se não houver chave ou a API falhar (fallback). */
function gemini(string $prompt): ?string {
    if (!GEMINI_API_KEY || !function_exists('curl_init')) return null;
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_API_KEY],
        CURLOPT_POSTFIELDS => json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 700],
        ]),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $texto = $resp !== false && $code === 200 ? (json_decode($resp, true)['candidates'][0]['content']['parts'][0]['text'] ?? null) : null;
    // guarda a falha para a interface avisar que está em modo fallback
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['ia_falhou_em'] = $texto ? null : time();
    return $texto;
}

/** Situação da IA externa, exibida nos componentes de IA. */
function ia_status(): array {
    if (!GEMINI_API_KEY) return ['modo' => 'local', 'texto' => 'Modo local: IA externa não configurada'];
    if (!empty($_SESSION['ia_falhou_em']) && time() - $_SESSION['ia_falhou_em'] < 300)
        return ['modo' => 'fallback', 'texto' => 'Gemini indisponível: usando modelo local (fallback)'];
    return ['modo' => 'online', 'texto' => 'Gemini ' . GEMINI_MODEL . ' conectado'];
}

/* ------------------------------------------------------------------ */
/* RF12 - Análise inteligente de desempenho                            */
/* ------------------------------------------------------------------ */

function estatisticas_operacao(int $dias = 30): array {
    $p = [$dias];
    $porRegiao = q("SELECT regiao, COUNT(*) total,
            ROUND(100 * SUM(status='entregue' AND entregue_em <= janela_fim) / NULLIF(SUM(status IN ('entregue','falha')),0), 1) otd,
            SUM(status='falha') falhas
        FROM pedidos WHERE janela_inicio >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY regiao ORDER BY otd", $p)->fetchAll();
    foreach ($porRegiao as &$r) {
        [$lat, $lng] = array_values(q('SELECT AVG(lat), AVG(lng) FROM pedidos WHERE regiao = ?', [$r['regiao']])->fetch(PDO::FETCH_NUM));
        // custo estimado por entrega: ida e volta do depósito rateada + custo fixo de parada
        $r['custo_entrega'] = round(distancia_km(DEPOSITO_LAT, DEPOSITO_LNG, $lat, $lng) * 2 * 1.6 / 4 + 3.5, 2);
    }
    $porHora = q("SELECT HOUR(janela_inicio) h, COUNT(*) total,
            ROUND(100 * SUM(status='falha' OR entregue_em > janela_fim) / NULLIF(SUM(status IN ('entregue','falha')),0), 1) atraso
        FROM pedidos WHERE janela_inicio >= DATE_SUB(CURDATE(), INTERVAL ? DAY) GROUP BY h ORDER BY h", $p)->fetchAll();
    $frota = q("SELECT status, COUNT(*) n FROM veiculos GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    return compact('porRegiao', 'porHora', 'frota', 'dias');
}

/** Gera insights: tenta o LLM com os números reais; se indisponível, usa regras locais. */
function gerar_insights(int $dias = 30): array {
    $est = estatisticas_operacao($dias);
    $prompt = "Você é um analista de logística de última milha. Com base nos dados JSON abaixo "
        . "(OTD = % entregue no prazo, custo_entrega em R$, atraso = % por hora de início da janela), "
        . "escreva de 4 a 6 insights curtos em português do Brasil, cada um em uma linha começando com '- ', "
        . "cobrindo gargalos de operação, regiões de maior custo e recomendações práticas de otimização. "
        . "Cite números. Não use markdown além do hífen.\n\n" . json_encode($est, JSON_UNESCAPED_UNICODE);
    $txt = gemini($prompt);
    if ($txt) {
        $linhas = array_values(array_filter(array_map(fn($l) => trim(ltrim(trim($l), '-*• ')), explode("\n", $txt))));
        return ['fonte' => 'Gemini (' . GEMINI_MODEL . ')', 'insights' => $linhas, 'dados' => $est];
    }

    // Fallback: regras analíticas locais
    $ins = [];
    $regs = $est['porRegiao'];
    if ($regs) {
        $pior = $regs[0]; $melhor = end($regs);
        $ins[] = "Gargalo: {$pior['regiao']} tem o menor índice de entrega no prazo ({$pior['otd']}%), contra {$melhor['otd']}% em {$melhor['regiao']}. Revise janelas e a sequência das rotas nessa região.";
        usort($regs, fn($a, $b) => $b['custo_entrega'] <=> $a['custo_entrega']);
        $caro = $regs[0];
        $ins[] = "Maior custo: {$caro['regiao']} custa cerca de R$ " . number_format($caro['custo_entrega'], 2, ',', '.') . " por entrega. Consolidar pedidos dessa região em uma rota dedicada reduz a quilometragem vazia.";
        $falhas = array_sum(array_column($regs, 'falhas'));
        if ($falhas) $ins[] = "Foram registradas $falhas falhas de entrega em {$dias} dias. Envie aviso prévio ao destinatário e use código de confirmação para reduzir ausências.";
    }
    $horas = $est['porHora'];
    if ($horas) {
        usort($horas, fn($a, $b) => $b['atraso'] <=> $a['atraso']);
        $ins[] = "As janelas que começam às {$horas[0]['h']}h têm {$horas[0]['atraso']}% de atraso, a pior faixa do dia. Antecipe a saída dos veículos ou amplie essas janelas.";
    }
    $prev = prever_demanda(7);
    $total7 = array_sum(array_map('array_sum', $prev));
    $ins[] = 'A previsão para os próximos 7 dias é de cerca de ' . round($total7) . ' entregas. Dimensione a frota para aproximadamente ' . max(1, (int)ceil($total7 / 7 / 15)) . ' veículos por dia (15 entregas por veículo).';
    if (($est['frota']['manutencao'] ?? 0) > 0) $ins[] = "Há {$est['frota']['manutencao']} veículo(s) em manutenção, o que reduz a capacidade disponível.";
    return ['fonte' => 'Modelo analítico local (fallback)', 'insights' => $ins, 'dados' => $est];
}
