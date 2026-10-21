<?php
/**
 * RF13 - Assistente Virtual SENN AI-X Helper.
 * 1) Procura a intenção na base de conhecimento local (includes/chat_base.php),
 *    que responde conversa casual, dúvidas de uso e consulta dados reais no banco.
 * 2) Se houver chave do Gemini, envia a pergunta + a resposta e os dados locais como
 *    contexto para o modelo redigir em linguagem natural. Sem Gemini, vale a resposta local.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/chat_base.php';
$u = exigir_perfil('gestor', 'despachante', 'motorista');
header('Content-Type: application/json; charset=utf-8');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$pergunta = mb_substr(trim($in['pergunta'] ?? ''), 0, 500);
$texto = normalizar($pergunta);
$GLOBALS['texto_chat'] = $texto;
$primeiro = explode(' ', $u['nome'])[0];
$podeGestao = in_array($u['perfil'], ['admin', 'gestor', 'despachante'], true);

$resposta = null; $acoes = []; $contexto = [];

// Código de pedido na pergunta tem prioridade: "como está o PED-1001?"
if (preg_match('/\b(ped|csv)\s?-?\s?([0-9][a-z0-9]*)\b/i', $pergunta, $m)) {
    $cod = strtoupper($m[1]) . '-' . strtoupper($m[2]);
    $p = q('SELECT p.*, v.placa, m.nome motorista FROM pedidos p LEFT JOIN rotas r ON r.id = p.rota_id LEFT JOIN veiculos v ON v.id = r.veiculo_id
            LEFT JOIN motoristas m ON m.id = r.motorista_id WHERE p.codigo = ?', [$cod])->fetch();
    if ($p) {
        $st = ['pendente' => 'ainda está sem rota', 'roteirizado' => 'já está numa rota, esperando sair', 'a_caminho' => 'está a caminho',
               'entregue' => 'já foi entregue', 'falha' => 'teve falha na entrega'][$p['status']];
        $resposta = "O pedido {$p['codigo']}, para {$p['destinatario']} ({$p['regiao']}), $st. A janela é de " . date('d/m H:i', strtotime($p['janela_inicio'])) . ' às ' . date('H:i', strtotime($p['janela_fim'])) . '.'
            . ($p['placa'] ? " Está no veículo {$p['placa']}" . ($p['motorista'] ? " com {$p['motorista']}" : '') . '.' : '')
            . ($p['entregue_em'] ? ' Foi finalizado em ' . date('d/m \à\s H:i', strtotime($p['entregue_em'])) . '.' : '')
            . ($p['risco_atraso'] !== null && !in_array($p['status'], ['entregue', 'falha']) ? ' O risco de atraso estimado é de ' . pct($p['risco_atraso']) . '.' : '');
        $contexto['pedido'] = $p;
        if ($podeGestao) $acoes[] = ['rotulo' => 'Ver detalhes e comprovante', 'url' => 'pedido.php?id=' . $p['id']];
    } else {
        $resposta = "Não encontrei nenhum pedido com o código $cod. Confere se digitou certinho?";
    }
} elseif ($texto !== '  ') {
    // Escolhe o item da base com maior pontuação que o perfil pode ver
    $melhor = null; $maior = 0;
    foreach (base_conhecimento($u) as $item) {
        if (!empty($item['perfis']) && $u['perfil'] !== 'admin' && !in_array($u['perfil'], $item['perfis'], true)) continue;
        $pts = pontuar($texto, $item['chaves']);
        if ($pts > $maior) { $maior = $pts; $melhor = $item; }
    }
    if ($melhor) {
        $r = is_callable($melhor['resp']) ? ($melhor['resp'])() : [$melhor['resp'], $melhor['acoes'] ?? []];
        $resposta = $r[0];
        foreach ($r[1] ?? [] as [$rotulo, $url]) $acoes[] = ['rotulo' => $rotulo, 'url' => $url];
        $contexto = $r[2] ?? [];
    }
}

// Camada LLM: redige a resposta final com base nos dados reais consultados
$fonte = 'local';
$prompt = "Você é o SENN AI-X Helper, assistente de uma plataforma de gestão logística de última milha. "
    . "Responda em português do Brasil, em no máximo 4 frases, num tom cordial e natural, como um colega de trabalho, sem emojis. "
    . "Chame o usuário pelo primeiro nome ($primeiro) quando fizer sentido. Para cumprimentos e conversa casual, responda com simpatia e ofereça ajuda. "
    . "Para perguntas sobre a operação, use somente os dados fornecidos; se não souber, diga e indique a tela do sistema. "
    . "Telas: Dashboard, Rastreamento, Rotas (IA), Pedidos (cadastro e CSV), Veículos, Motoristas, Alertas, Análise IA, Minhas entregas (motorista), Manual.\n"
    . "Perfil do usuário: {$u['perfil']}.\nResposta sugerida pela base local: " . json_encode(['resposta_base' => $resposta] + $contexto, JSON_UNESCAPED_UNICODE)
    . "\nPergunta: $pergunta";
if ($pergunta !== '' && ($llm = gemini($prompt))) { $resposta = trim($llm); $fonte = 'gemini'; }

if (!$resposta) {
    $resposta = "Hmm, essa eu não soube responder, $primeiro. Tenta perguntar de outro jeito, por exemplo: \"como está o pedido PED-1001\", \"quantas entregas temos hoje\", \"qual o OTD do mês\" ou \"como importar CSV\".";
    $acoes[] = ['rotulo' => 'Abrir manual', 'url' => 'manual.php'];
}
if ($u['perfil'] === 'motorista') $acoes = array_values(array_filter($acoes, fn($a) => in_array(strtok($a['url'], '?'), ['motorista.php', 'manual.php'], true)));
registrar_log('chat', mb_substr($pergunta, 0, 200));
echo json_encode(['resposta' => $resposta, 'acoes' => $acoes, 'fonte' => $fonte], JSON_UNESCAPED_UNICODE);
