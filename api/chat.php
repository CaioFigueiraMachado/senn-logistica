<?php
/**
 * RF13 - Assistente Virtual SENN AI-X Helper.
 * 1) Interpreta a intenção localmente e consulta o banco (sempre funciona, fallback).
 * 2) Se houver chave do Gemini, envia a pergunta + dados reais como contexto para
 *    o modelo responder em linguagem natural.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/indicadores.php';
$u = exigir_perfil('gestor', 'despachante', 'motorista');
header('Content-Type: application/json; charset=utf-8');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$pergunta = mb_substr(trim($in['pergunta'] ?? ''), 0, 500);
$t = mb_strtolower($pergunta);
$tem = fn(string ...$p) => (bool)array_filter($p, fn($x) => str_contains($t, $x));

$resposta = null; $acoes = []; $contexto = [];
$podeGestao = in_array($u['perfil'], ['admin', 'gestor', 'despachante'], true);

if (preg_match('/(ped|csv)-?\s?([a-z0-9]+)/i', $pergunta, $m)) {
    $cod = strtoupper($m[1]) . '-' . strtoupper($m[2]);
    $p = q('SELECT p.*, v.placa FROM pedidos p LEFT JOIN rotas r ON r.id = p.rota_id LEFT JOIN veiculos v ON v.id = r.veiculo_id WHERE p.codigo = ?', [$cod])->fetch();
    if ($p) {
        $st = ['pendente' => 'pendente (ainda sem rota)', 'roteirizado' => 'roteirizado', 'a_caminho' => 'a caminho', 'entregue' => 'entregue', 'falha' => 'com falha na entrega'][$p['status']];
        $resposta = "O pedido {$p['codigo']} para {$p['destinatario']} ({$p['regiao']}) está $st. Janela: " . date('d/m H:i', strtotime($p['janela_inicio'])) . '-' . date('H:i', strtotime($p['janela_fim'])) . '.'
            . ($p['placa'] ? " Veículo: {$p['placa']}." : '') . ($p['entregue_em'] ? ' Finalizado em ' . date('d/m H:i', strtotime($p['entregue_em'])) . '.' : '')
            . ($p['risco_atraso'] !== null && !in_array($p['status'], ['entregue', 'falha']) ? " Risco de atraso estimado: {$p['risco_atraso']}%." : '');
        $contexto['pedido'] = $p;
        if ($podeGestao) $acoes[] = ['rotulo' => 'Ver no rastreamento', 'url' => 'rastreamento.php'];
    } else {
        $resposta = "Não encontrei o pedido $cod.";
    }
} elseif ($tem('atras', 'risco')) {
    $n = q("SELECT COUNT(*) FROM pedidos WHERE status IN ('pendente','roteirizado','a_caminho') AND janela_fim < NOW()")->fetchColumn();
    $r = q("SELECT codigo, risco_atraso FROM pedidos WHERE status IN ('pendente','roteirizado','a_caminho') AND risco_atraso >= 35 ORDER BY risco_atraso DESC LIMIT 5")->fetchAll();
    $resposta = "Há $n entrega(s) em aberto já fora da janela." . ($r ? ' Maior risco segundo a IA: ' . implode(', ', array_map(fn($x) => "{$x['codigo']} ({$x['risco_atraso']}%)", $r)) . '.' : '');
    $contexto['atrasadas'] = $n; $contexto['riscos'] = $r;
    $acoes[] = ['rotulo' => 'Abrir análise de risco', 'url' => 'ia.php'];
} elseif ($tem('otd', 'prazo', 'indicador', 'custo', 'km', 'quilômetro', 'ocupação', 'desempenho', 'dashboard')) {
    $k = indicadores(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
    $resposta = "Nos últimos 30 dias: OTD de {$k['otd']}%, custo médio de R$ " . number_format($k['custo_entrega'], 2, ',', '.') . ' por entrega, '
        . number_format($k['km'], 0, ',', '.') . " km rodados, {$k['entregues']} entregas concluídas e {$k['falhas']} falhas. Ocupação da frota agora: {$k['ocupacao_frota']}%.";
    unset($k['serie']);
    $contexto['indicadores'] = $k;
    $acoes[] = ['rotulo' => 'Abrir dashboard', 'url' => 'dashboard.php'];
} elseif ($tem('previs', 'demanda', 'próxima semana', 'proxima semana')) {
    $prev = prever_demanda(7);
    $tot = array_sum(array_map('array_sum', $prev));
    arsort($prev);
    $resposta = 'A previsão para os próximos 7 dias é de cerca de ' . round($tot) . ' entregas. Por região: '
        . implode(', ', array_map(fn($r, $d) => "$r " . round(array_sum($d)), array_keys($prev), $prev)) . '.';
    $contexto['previsao_7d'] = $prev;
    $acoes[] = ['rotulo' => 'Ver previsão completa', 'url' => 'ia.php'];
} elseif ($tem('alerta')) {
    $a = q('SELECT tipo, mensagem FROM alertas WHERE resolvido = 0 ORDER BY criado_em DESC LIMIT 5')->fetchAll();
    $resposta = $a ? count($a) . " alerta(s) recente(s):\n" . implode("\n", array_map(fn($x) => '• ' . $x['mensagem'], $a)) : 'Não há alertas abertos.';
    $acoes[] = ['rotulo' => 'Ver alertas', 'url' => 'alertas.php'];
} elseif ($tem('veículo', 'veiculo', 'frota', 'disponíve', 'disponive')) {
    $f = q('SELECT status, COUNT(*) n FROM veiculos GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $resposta = 'Frota: ' . ($f['disponivel'] ?? 0) . ' disponível(is), ' . ($f['em_rota'] ?? 0) . ' em rota e ' . ($f['manutencao'] ?? 0) . ' em manutenção.';
    $acoes[] = ['rotulo' => 'Ver veículos', 'url' => 'veiculos.php'];
} elseif ($tem('hoje', 'pendente', 'quantas entregas', 'quantos pedidos')) {
    $s = q('SELECT status, COUNT(*) n FROM pedidos WHERE DATE(janela_inicio) = CURDATE() GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $resposta = 'Entregas de hoje: ' . (array_sum($s) ?: 0) . ' no total (' . implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($s), $s)) . ').';
    $contexto['hoje'] = $s;
    if (($s['pendente'] ?? 0) > 0) $acoes[] = ['rotulo' => 'Roteirizar pendentes', 'url' => 'rotas.php'];
} elseif ($tem('rota', 'roteir')) {
    $resposta = 'Para gerar rotas: abra Rotas (IA), escolha a data, marque os pedidos e veículos e clique em "Gerar rotas otimizadas". A IA respeita capacidade e janelas e minimiza a distância. Depois, libere cada rota para o motorista.';
    $acoes[] = ['rotulo' => 'Ir para Rotas', 'url' => 'rotas.php'];
} elseif ($tem('csv', 'importar')) {
    $resposta = 'Em Pedidos, use "Importar CSV". Colunas: codigo;destinatario;endereco;regiao;janela_inicio;janela_fim;peso_kg;volume_m3;prioridade;lat;lng. Há um arquivo de exemplo para baixar na própria tela.';
    $acoes[] = ['rotulo' => 'Ir para Pedidos', 'url' => 'pedidos.php'];
} elseif ($tem('comprov', 'pod', 'assinatura', 'foto')) {
    $resposta = 'O motorista abre "Minhas entregas", toca em "Registrar comprovante", escolhe foto, assinatura digital ou código de confirmação e confirma. Data, hora e localização são capturadas automaticamente.';
} elseif ($tem('lgpd', 'dados pessoais', 'excluir meus')) {
    $resposta = 'Os dados pessoais são tratados só para a gestão das entregas, com consentimento. Motoristas podem ser excluídos em Motoristas e destinatários podem ser anonimizados em Pedidos.';
}

// Camada LLM: redige a resposta final com base nos dados reais consultados
$fonte = 'local';
$prompt = "Você é o SENN AI-X Helper, assistente de uma plataforma de gestão logística de última milha. "
    . "Responda em português do Brasil, em no máximo 4 frases, de forma objetiva. Use somente os dados fornecidos; se não souber, diga e indique a tela do sistema. "
    . "Telas: Dashboard, Rastreamento, Rotas (IA), Pedidos (cadastro e CSV), Veículos, Motoristas, Alertas, Análise IA, Minhas entregas (motorista), Manual.\n"
    . "Perfil do usuário: {$u['perfil']}.\nDados consultados: " . json_encode(['resposta_base' => $resposta] + $contexto, JSON_UNESCAPED_UNICODE)
    . "\nPergunta: $pergunta";
if ($pergunta !== '' && ($llm = gemini($prompt))) { $resposta = trim($llm); $fonte = 'gemini'; }

if (!$resposta) {
    $resposta = 'Posso ajudar com: status de um pedido (ex.: "status do PED-1001"), entregas atrasadas, indicadores (OTD, custo, km), previsão de demanda, alertas, frota e como usar as telas.';
    $acoes[] = ['rotulo' => 'Abrir manual', 'url' => 'manual.php'];
}
if ($u['perfil'] === 'motorista') $acoes = array_values(array_filter($acoes, fn($a) => !in_array($a['url'], ['dashboard.php', 'ia.php', 'veiculos.php', 'alertas.php', 'rotas.php', 'pedidos.php', 'rastreamento.php'])));
registrar_log('chat', mb_substr($pergunta, 0, 200));
echo json_encode(['resposta' => $resposta, 'acoes' => $acoes, 'fonte' => $fonte], JSON_UNESCAPED_UNICODE);
