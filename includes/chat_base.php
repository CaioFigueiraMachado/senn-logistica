<?php
/**
 * RF13 - Base de conhecimento do assistente SENN AI-X Helper.
 *
 * Cada item tem:
 *  - chaves: trechos (sem acento, minúsculos) que indicam a intenção. Quanto mais
 *    trechos e mais longos baterem com a pergunta, maior a pontuação do item.
 *  - perfis: (opcional) perfis que podem receber a resposta.
 *  - resp:   texto fixo ou função que consulta o banco e devolve [texto, ações, contexto].
 * O item de maior pontuação responde. Sem nenhum, o chat cai na resposta padrão.
 */
require_once __DIR__ . '/indicadores.php';

/** Minúsculas e sem acentos, para comparar "previsão", "previsao" e "PREVISÃO" do mesmo jeito. */
function normalizar(string $t): string {
    $t = mb_strtolower($t, 'UTF-8');
    $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'î' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
    return ' ' . trim(preg_replace('/[^a-z0-9\-]+/', ' ', $t)) . ' ';
}

/**
 * Pontua a pergunta contra as chaves. Chaves curtas (até 3 letras, como "oi" ou "otd")
 * precisam ser a palavra inteira; as demais funcionam como prefixo ("atrasad" pega
 * "atrasada" e "atrasadas"). Chaves com mais palavras valem mais. Chaves com "+"
 * exigem todas as partes em qualquer ordem ("cadastr+veiculo" pega "como cadastro um veículo").
 */
function pontuar(string $texto, array $chaves): float {
    $p = 0;
    foreach ($chaves as $c) {
        if (str_contains($c, '+')) {
            $partes = explode('+', $c);
            $todas = !array_filter($partes, fn($x) => !str_contains($texto, strlen($x) <= 3 ? " $x " : " $x"));
            if ($todas) $p += 1 + count($partes) * 0.9;
            continue;
        }
        $alvo = strlen($c) <= 3 ? " $c " : " $c";
        if (str_contains($texto, $alvo)) $p += 1 + substr_count($c, ' ') * 0.8 + min(strlen($c), 12) / 24;
    }
    return $p;
}

function brl($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function pct($v): string { return str_replace('.', ',', (string)$v) . '%'; }
function plural(int $n, string $um, string $varios): string { return $n === 1 ? "1 $um" : "$n $varios"; }

function base_conhecimento(array $u): array {
    $nome = explode(' ', $u['nome'])[0];
    $h = (int)date('G');
    $saudacao = $h < 12 ? 'Bom dia' : ($h < 18 ? 'Boa tarde' : 'Boa noite');
    $gestao = ['admin', 'gestor', 'despachante'];
    $dias = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $motoristaAtual = fn() => q('SELECT * FROM motoristas WHERE usuario_id = ?', [$u['id']])->fetch();
    $abertos = "status IN ('pendente','roteirizado','a_caminho')";

    return [
        /* ===================== Conversa casual ===================== */
        ['chaves' => ['oi', 'ola', 'oie', 'oii', 'eai', 'e ai', 'eae', 'iae', 'opa', 'hey', 'hello', 'salve', 'fala ai', 'alo', 'bom dia', 'boa tarde', 'boa noite', 'bom diaa'],
         'resp' => function () use ($saudacao, $nome) {
             // responde com o mesmo cumprimento que a pessoa usou
             $t = $GLOBALS['texto_chat'] ?? '';
             $s = str_contains($t, 'bom dia') ? 'Bom dia' : (str_contains($t, 'boa tarde') ? 'Boa tarde' : (str_contains($t, 'boa noite') ? 'Boa noite' : 'Oi'));
             return ["$s, $nome! Tudo bem? Como posso te ajudar hoje?"];
         }],
        ['chaves' => ['tudo bem', 'tudo bom', 'como vai', 'como voce esta', 'como vc esta', 'como ta', 'como esta', 'beleza', 'blz', 'tranquilo', 'suave', 'de boa'],
         'resp' => "Tudo certo por aqui, obrigado por perguntar! E com você, $nome? Se quiser, posso dar uma olhada em como estão as entregas de hoje."],
        ['chaves' => ['estou bem', 'to bem', 'tô bem', 'estou otimo', 'tudo otimo', 'tudo certo', 'estou de boa', 'bem e voce', 'bem e vc'],
         'resp' => "Que bom, $nome! Fico feliz. No que posso te ajudar?"],
        ['chaves' => ['obrigad', 'valeu', 'vlw', 'agradeco', 'brigad', 'muito obrigado', 'obg', 'thanks', 'grato', 'grata'],
         'resp' => "Por nada, $nome! Se precisar de mais alguma coisa, é só chamar."],
        ['chaves' => ['tchau', 'ate mais', 'ate logo', 'ate amanha', 'falou', 'flw', 'fui', 'bom descanso', 'adeus', 'te mais'],
         'resp' => "Até mais, $nome! Bom trabalho por aí."],
        ['chaves' => ['quem e voce', 'quem e vc', 'quem e tu', 'o que voce e', 'qual seu nome', 'qual o seu nome', 'como voce se chama', 'seu nome', 'se apresenta'],
         'resp' => "Sou o SENN AI-X Helper, o assistente da plataforma. Estou aqui para te ajudar a acompanhar entregas, indicadores, alertas e a usar qualquer tela do sistema."],
        ['chaves' => ['o que voce faz', 'o que vc faz', 'o que voce sabe', 'o que voce pode', 'como voce pode me ajudar', 'me ajuda', 'ajuda', 'socorro', 'help', 'o que posso perguntar', 'quais perguntas', 'comandos'],
         'resp' => "Posso te ajudar com bastante coisa, $nome. Por exemplo: \"como está o pedido PED-1001\", \"quantas entregas temos hoje\", \"quais estão atrasadas\", \"qual o OTD do mês\", \"previsão para a semana\", \"veículos disponíveis\", \"CNH vencendo\" ou \"como importar CSV\". Também explico qualquer tela do sistema."],
        ['chaves' => ['voce e um robo', 'voce e robo', 'voce e humano', 'voce e real', 'voce e uma ia', 'voce e ia', 'e uma pessoa', 'falo com quem', 'tem alguem ai', 'vc e um robo', 'vc e robo', 'robo', 'bot', 'vc e humano', 'e humano', 'e gente'],
         'resp' => "Sou um assistente virtual, não uma pessoa. Mas uso os dados reais do sistema para te responder. Se precisar de alguém da equipe, fale com o gestor da operação."],
        ['chaves' => ['que horas', 'horas sao', 'hora agora', 'horario agora'],
         'resp' => 'Agora são ' . date('H:i') . '.'],
        ['chaves' => ['que dia e hoje', 'qual a data', 'data de hoje', 'dia e hoje', 'que dia e', 'dia da semana'],
         'resp' => 'Hoje é ' . $dias[(int)date('w')] . ', ' . date('j') . ' de ' . $meses[(int)date('n')] . ' de ' . date('Y') . '.'],
        ['chaves' => ['bom trabalho', 'voce e bom', 'voce e otimo', 'muito bom', 'show', 'top', 'gostei', 'perfeito', 'excelente', 'mandou bem', 'parabens', 'incrivel'],
         'resp' => "Valeu, $nome! Fico feliz em ajudar."],
        ['chaves' => ['desculpa', 'foi mal', 'perdao', 'me desculpe'],
         'resp' => "Sem problema nenhum, $nome! Pode perguntar à vontade."],
        ['chaves' => ['nao entendi', 'como assim', 'explica melhor', 'nao entendo', 'confuso'],
         'resp' => "Desculpa se não fui claro, $nome. Tenta me perguntar de outro jeito, ou abre o Manual que lá está tudo explicado passo a passo.",
         'acoes' => [['Abrir manual', 'manual.php']]],
        ['chaves' => ['burro', 'inutil', 'nao serve', 'ruim', 'pessimo', 'idiota', 'lixo'],
         'resp' => "Poxa, desculpa se não ajudei como deveria. Me conta de outro jeito o que você precisa que eu tento de novo."],
        ['chaves' => ['piada', 'me faz rir', 'conta uma', 'algo engracado'],
         'resp' => "Por que o caminhão foi ao psicólogo? Porque estava carregando muito peso! Agora, voltando ao trabalho: posso ver algo da operação para você?"],
        ['chaves' => ['estou cansado', 'to cansado', 'estou cansada', 'dia dificil', 'dia puxado', 'estressado', 'estressada'],
         'resp' => "Imagino, $nome, logística não para. Se quiser, te passo um resumo rápido do dia para facilitar."],
        ['chaves' => ['bom almoco', 'vou almocar', 'hora do almoco', 'cafe'],
         'resp' => "Aproveita a pausa, $nome! Fico de olho nos alertas por aqui."],
        ['chaves' => ['teste', 'testando', 'funciona', 'ta funcionando', 'esta funcionando'],
         'resp' => "Estou funcionando sim, $nome! Pode mandar sua pergunta."],

        /* ===================== Motorista ===================== */
        ['chaves' => ['minhas entregas', 'minha rota', 'minhas rotas', 'minhas paradas', 'quantas entregas eu', 'quantas paradas', 'tenho entrega', 'tenho rota'],
         'perfis' => ['motorista'],
         'resp' => function () use ($motoristaAtual, $nome) {
             $m = $motoristaAtual();
             if (!$m) return ["Sua conta ainda não está ligada a um cadastro de motorista. Fale com o gestor, $nome."];
             $n = q("SELECT COUNT(*) FROM pedidos p JOIN rotas r ON r.id = p.rota_id WHERE r.motorista_id = ? AND r.status IN ('liberada','em_andamento') AND p.status IN ('roteirizado','a_caminho')", [$m['id']])->fetchColumn();
             return [$n ? "Você tem $n entrega(s) para fazer nas rotas liberadas. A ordem certinha está em Minhas entregas." : "No momento você não tem entregas pendentes, $nome. Quando o despachante liberar uma rota, ela aparece em Minhas entregas.", [['Minhas entregas', 'motorista.php']]];
         }],
        ['chaves' => ['proxima entrega', 'proxima parada', 'onde vou agora', 'pra onde vou', 'para onde vou', 'qual o proximo'],
         'perfis' => ['motorista'],
         'resp' => function () use ($motoristaAtual) {
             $m = $motoristaAtual();
             $p = $m ? q("SELECT p.* FROM pedidos p JOIN rotas r ON r.id = p.rota_id WHERE r.motorista_id = ? AND p.status = 'a_caminho' ORDER BY p.ordem_rota LIMIT 1", [$m['id']])->fetch() : null;
             if (!$p) return ['Não encontrei nenhuma entrega em andamento. Se a rota ainda não foi iniciada, toque em "Iniciar rota" em Minhas entregas.', [['Minhas entregas', 'motorista.php']]];
             return ["Sua próxima parada é o pedido {$p['codigo']}, para {$p['destinatario']}, em {$p['endereco']}. A janela vai até " . date('H:i', strtotime($p['janela_fim'])) . '. Em Minhas entregas tem o botão para abrir a navegação.', [['Minhas entregas', 'motorista.php']]];
         }],
        ['chaves' => ['iniciar rota', 'comecar rota', 'comecar a rota', 'como inicio', 'como comeco', 'sair para entrega'],
         'resp' => 'Em Minhas entregas, toque em "Iniciar rota". A partir daí as entregas ficam "a caminho" e o sistema começa a compartilhar sua localização com a central. Deixe a localização do navegador ativada.',
         'acoes' => [['Minhas entregas', 'motorista.php']]],
        ['chaves' => ['cliente nao estava', 'ninguem em casa', 'cliente ausente', 'nao consegui entregar', 'endereco errado', 'recusou', 'falha na entrega', 'registrar falha', 'deu errado a entrega'],
         'resp' => 'Sem problema, acontece. Em Minhas entregas, abra "Registrar comprovante", escolha "Falha na entrega" e escreva o motivo (por exemplo, destinatário ausente). A central é avisada e pode reagendar.'],
        ['chaves' => ['comprovante', 'comprovacao', 'pod', 'assinatura', 'foto da entrega', 'tirar foto', 'codigo de confirmacao', 'confirmar entrega', 'registrar entrega', 'finalizar entrega', 'dar baixa', 'baixa na entrega'],
         'resp' => 'Em Minhas entregas, toque em "Registrar comprovante" na parada, escolha foto, assinatura digital ou código de confirmação e confirme. Data, hora e localização são registradas sozinhas.'],
        ['chaves' => ['localizacao', 'gps', 'nao aparece no mapa', 'compartilhar localizacao', 'minha posicao'],
         'resp' => 'A localização é enviada pelo navegador do celular enquanto a rota está em andamento. Se aparecer o pedido de permissão, toque em "Permitir". Se já negou, libere a localização nas configurações do navegador para este site.'],
        ['chaves' => ['navegacao', 'abrir mapa', 'google maps', 'waze', 'como chego', 'caminho ate'],
         'resp' => 'Em cada parada de Minhas entregas tem o link "Abrir navegação", que abre o Google Maps já com o destino.'],

        /* ===================== Dados da operação ===================== */
        ['chaves' => ['entregas hoje', 'entregas de hoje', 'pedidos hoje', 'pedidos de hoje', 'resumo do dia', 'resumo de hoje', 'como esta o dia', 'como ta o dia', 'como estao as entregas', 'situacao de hoje', 'movimento de hoje', 'quantas entregas temos', 'quantas entregas tem'],
         'perfis' => $gestao,
         'resp' => function () {
             $s = q('SELECT status, COUNT(*) n FROM pedidos WHERE DATE(janela_inicio) = CURDATE() GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
             $tot = array_sum($s);
             if (!$tot) return ['Ainda não há entregas marcadas para hoje.', [['Cadastrar pedido', 'pedidos.php']]];
             $nomes = ['pendente' => 'sem rota', 'roteirizado' => 'roteirizadas', 'a_caminho' => 'a caminho', 'entregue' => 'entregues', 'falha' => 'com falha'];
             $partes = array_map(fn($k, $v) => "$v " . $nomes[$k], array_keys($s), $s);
             $acoes = ($s['pendente'] ?? 0) ? [['Roteirizar pendentes', 'rotas.php']] : [['Ver rastreamento', 'rastreamento.php']];
             return ["Hoje temos $tot entregas: " . implode(', ', $partes) . '.', $acoes, ['hoje' => $s]];
         }],
        ['chaves' => ['pendente', 'sem rota', 'falta roteirizar', 'nao roteirizad', 'aguardando rota'],
         'perfis' => $gestao,
         'resp' => function () {
             $n = (int)q("SELECT COUNT(*) FROM pedidos WHERE status = 'pendente'")->fetchColumn();
             $hoje = (int)q("SELECT COUNT(*) FROM pedidos WHERE status = 'pendente' AND DATE(janela_inicio) = CURDATE()")->fetchColumn();
             return [$n ? "Há $n pedido(s) pendentes de rota, sendo $hoje para hoje." : 'Não há nenhum pedido esperando rota. Tudo roteirizado!', $n ? [['Gerar rotas', 'rotas.php']] : []];
         }],
        ['chaves' => ['entregues hoje', 'entregas feitas', 'ja entregou', 'ja foram entregues', 'quantas foram entregues', 'concluidas hoje', 'finalizadas'],
         'perfis' => $gestao,
         'resp' => function () {
             $n = (int)q("SELECT COUNT(*) FROM pedidos WHERE status = 'entregue' AND DATE(entregue_em) = CURDATE()")->fetchColumn();
             return [$n ? "Hoje já foram concluídas $n entrega(s)." : 'Nenhuma entrega foi concluída hoje até agora.'];
         }],
        ['chaves' => ['falha', 'falharam', 'nao entregues', 'deram errado', 'problema nas entregas'],
         'perfis' => $gestao,
         'resp' => function () {
             $hoje = (int)q("SELECT COUNT(*) FROM pedidos WHERE status = 'falha' AND DATE(janela_inicio) = CURDATE()")->fetchColumn();
             $mes = (int)q("SELECT COUNT(*) FROM pedidos WHERE status = 'falha' AND janela_inicio >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
             return ["Hoje tivemos $hoje falha(s) de entrega, e $mes nos últimos 30 dias. Os motivos ficam registrados nos comprovantes."];
         }],
        ['chaves' => ['atrasad', 'atraso', 'fora do prazo', 'passou do prazo', 'fora da janela', 'vai atrasar', 'risco', 'critica', 'preocup', 'perigo'],
         'perfis' => $gestao,
         'resp' => function () use ($abertos) {
             $n = (int)q("SELECT COUNT(*) FROM pedidos WHERE $abertos AND janela_fim < NOW()")->fetchColumn();
             $r = q("SELECT codigo, risco_atraso FROM pedidos WHERE $abertos AND risco_atraso >= 35 ORDER BY risco_atraso DESC LIMIT 5")->fetchAll();
             $txt = ($n ? "Neste momento, $n entrega(s) em aberto já passaram da janela combinada." : 'Boa notícia: nenhuma entrega em aberto está fora da janela agora.')
                 . ($r ? ' As que mais me preocupam são ' . implode(', ', array_map(fn($x) => "{$x['codigo']} (" . pct($x['risco_atraso']) . ' de risco)', $r)) . '.' : ' E nenhuma aparece com risco alto de atrasar.');
             return [$txt, [['Abrir análise de risco', 'ia.php']], ['atrasadas' => $n, 'riscos' => $r]];
         }],
        ['chaves' => ['otd', 'no prazo', 'pontualidade', 'indicador', 'kpi', 'desempenho', 'como estamos', 'performance', 'resultado do mes', 'metricas', 'numeros'],
         'perfis' => $gestao,
         'resp' => function () {
             $k = indicadores(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
             $txt = 'Nos últimos 30 dias, ' . pct($k['otd']) . " das entregas chegaram no prazo (OTD). Foram {$k['entregues']} entregas concluídas e {$k['falhas']} falhas, com "
                 . number_format($k['km'], 0, ',', '.') . ' km rodados e custo médio de ' . brl($k['custo_entrega']) . " por entrega. Agora, {$k['ocupacao_frota']}% da frota está na rua.";
             unset($k['serie']);
             return [$txt, [['Abrir dashboard', 'dashboard.php']], ['indicadores' => $k]];
         }],
        ['chaves' => ['custo', 'gasto', 'quanto gastamos', 'quanto custa', 'preco por entrega', 'dinheiro', 'despesa'],
         'perfis' => $gestao,
         'resp' => function () {
             $k = indicadores(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
             return ['Nos últimos 30 dias, o custo médio foi de ' . brl($k['custo_entrega']) . ' por entrega, somando cerca de ' . brl($k['custo_total']) . ' no total.', [['Abrir dashboard', 'dashboard.php']]];
         }],
        ['chaves' => ['km', 'quilometr', 'quilometragem', 'distancia percorrida', 'rodamos', 'rodado'],
         'perfis' => $gestao,
         'resp' => function () {
             $k = indicadores(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
             $hoje = (float)q('SELECT COALESCE(SUM(distancia_km),0) FROM rotas WHERE data_rota = CURDATE()')->fetchColumn();
             return ['A frota rodou cerca de ' . number_format($k['km'], 0, ',', '.') . ' km nos últimos 30 dias. As rotas de hoje somam ' . number_format($hoje, 1, ',', '.') . ' km planejados.'];
         }],
        ['chaves' => ['regiao com mais atraso', 'pior regiao', 'regiao mais lenta', 'onde atrasa', 'regiao problema', 'gargalo', 'regiao+atras', 'regiao mais atrasa', 'mais atrasa'],
         'perfis' => $gestao,
         'resp' => function () {
             $r = q("SELECT regiao, ROUND(100 * SUM(status='entregue' AND entregue_em <= janela_fim) / NULLIF(SUM(status IN ('entregue','falha')),0),1) otd
                     FROM pedidos WHERE janela_inicio >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY regiao ORDER BY otd LIMIT 1")->fetch();
             return $r ? ["A região que mais atrasa é {$r['regiao']}: só " . pct($r['otd']) . ' das entregas chegam no prazo. Vale revisar janelas e a ordem das paradas por lá.', [['Ver análise', 'ia.php']]] : ['Ainda não tenho dados suficientes por região.'];
         }],
        ['chaves' => ['melhor regiao', 'regiao mais pontual', 'regiao que mais entrega no prazo', 'regiao+melhor', 'regiao+pontual'],
         'perfis' => $gestao,
         'resp' => function () {
             $r = q("SELECT regiao, ROUND(100 * SUM(status='entregue' AND entregue_em <= janela_fim) / NULLIF(SUM(status IN ('entregue','falha')),0),1) otd
                     FROM pedidos WHERE janela_inicio >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY regiao ORDER BY otd DESC LIMIT 1")->fetch();
             return $r ? ["A região mais pontual é {$r['regiao']}, com " . pct($r['otd']) . ' das entregas no prazo.'] : ['Ainda não tenho dados suficientes por região.'];
         }],
        ['chaves' => ['regiao mais cara', 'regiao com maior custo', 'onde gasta mais', 'mais caro de entregar', 'regiao+car', 'regiao+custo'],
         'perfis' => $gestao,
         'resp' => function () {
             $est = estatisticas_operacao(30)['porRegiao'];
             if (!$est) return ['Ainda não tenho dados suficientes por região.'];
             usort($est, fn($a, $b) => $b['custo_entrega'] <=> $a['custo_entrega']);
             return ["{$est[0]['regiao']} é a região mais cara, com cerca de " . brl($est[0]['custo_entrega']) . ' por entrega. Juntar os pedidos de lá numa rota própria ajuda a baixar esse custo.'];
         }],
        ['chaves' => ['regioes', 'regiao com mais entregas', 'mais pedidos por regiao', 'bairros', 'por regiao', 'onde mais entregamos'],
         'perfis' => $gestao,
         'resp' => function () {
             $r = q('SELECT regiao, COUNT(*) n FROM pedidos WHERE janela_inicio >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY regiao ORDER BY n DESC')->fetchAll();
             return ['Entregas por região nos últimos 30 dias: ' . implode(', ', array_map(fn($x) => "{$x['regiao']} ({$x['n']})", $r)) . '.'];
         }],
        ['chaves' => ['previs', 'demanda', 'proxima semana', 'proximos dias', 'vai ter quantas', 'quantas entregas vamos ter', 'futuro', 'estimativa'],
         'perfis' => $gestao,
         'resp' => function () {
             $prev = prever_demanda(7);
             $tot = array_sum(array_map('array_sum', $prev));
             $porReg = array_map('array_sum', $prev);
             arsort($porReg);
             return ['Para os próximos 7 dias, a previsão é de umas ' . round($tot) . ' entregas. A maior parte deve vir de ' . array_key_first($porReg) . '. Por região: '
                 . implode(', ', array_map(fn($r, $v) => "$r " . round($v), array_keys($porReg), $porReg)) . '.', [['Ver previsão completa', 'ia.php']], ['previsao_7d' => $porReg]];
         }],
        ['chaves' => ['amanha', 'para amanha', 'entregas amanha'],
         'perfis' => $gestao,
         'resp' => function () {
             $prev = prever_demanda(2);
             $amanha = date('Y-m-d', strtotime('+1 day'));
             $tot = array_sum(array_map(fn($d) => $d[$amanha] ?? 0, $prev));
             $cad = (int)q('SELECT COUNT(*) FROM pedidos WHERE DATE(janela_inicio) = ?', [$amanha])->fetchColumn();
             return ["Para amanhã, o modelo prevê cerca de " . round($tot) . " entregas. Já temos $cad pedido(s) cadastrados para essa data."];
         }],
        ['chaves' => ['proximo mes', 'proximos 30', '30 dias', 'mes que vem'],
         'perfis' => $gestao,
         'resp' => function () {
             $tot = array_sum(array_map('array_sum', prever_demanda(30)));
             return ['Para os próximos 30 dias, a previsão é de cerca de ' . round($tot) . ' entregas, umas ' . round($tot / 30) . ' por dia em média.', [['Ver previsão', 'ia.php?h=30']]];
         }],
        ['chaves' => ['quantos veiculos preciso', 'tamanho da frota', 'dimensionar', 'preciso de mais veiculo', 'falta veiculo', 'frota suficiente'],
         'perfis' => $gestao,
         'resp' => function () {
             $tot = array_sum(array_map('array_sum', prever_demanda(7)));
             $nec = max(1, (int)ceil($tot / 7 / 15));
             $disp = (int)q("SELECT COUNT(*) FROM veiculos WHERE status <> 'manutencao'")->fetchColumn();
             return ["Pela previsão, você vai precisar de cerca de $nec veículo(s) por dia (15 entregas cada). Hoje há $disp fora de manutenção, " . ($disp >= $nec ? 'então a frota dá conta.' : 'então vale reforçar a frota.')];
         }],
        ['chaves' => ['veiculo', 'frota', 'caminhao', 'caminhoes', 'carros', 'vans', 'motos', 'disponiveis', 'disponivel'],
         'perfis' => $gestao,
         'resp' => function () {
             $f = q('SELECT status, COUNT(*) n FROM veiculos GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
             $disp = q("SELECT placa FROM veiculos WHERE status = 'disponivel' ORDER BY placa")->fetchAll(PDO::FETCH_COLUMN);
             return ['A frota tem ' . array_sum($f) . ' veículos: ' . ($f['disponivel'] ?? 0) . ' disponíveis, ' . ($f['em_rota'] ?? 0) . ' em rota e ' . ($f['manutencao'] ?? 0) . ' em manutenção.'
                 . ($disp ? ' Livres agora: ' . implode(', ', $disp) . '.' : ''), [['Ver veículos', 'veiculos.php']]];
         }],
        ['chaves' => ['manutencao', 'revisao', 'oficina', 'quebrado'],
         'perfis' => $gestao,
         'resp' => function () {
             $em = q("SELECT placa FROM veiculos WHERE status = 'manutencao'")->fetchAll(PDO::FETCH_COLUMN);
             $prox = q('SELECT placa, proxima_manutencao FROM veiculos WHERE proxima_manutencao <= DATE_ADD(CURDATE(), INTERVAL 15 DAY) ORDER BY proxima_manutencao')->fetchAll();
             return [($em ? 'Em manutenção agora: ' . implode(', ', $em) . '. ' : 'Nenhum veículo está em manutenção agora. ')
                 . ($prox ? 'Manutenções nos próximos 15 dias: ' . implode(', ', array_map(fn($v) => $v['placa'] . ' em ' . date('d/m', strtotime($v['proxima_manutencao'])), $prox)) . '.' : 'Nenhuma manutenção vence nos próximos 15 dias.'), [['Ver veículos', 'veiculos.php']]];
         }],
        ['chaves' => ['cnh', 'carteira de motorista', 'habilitacao', 'carteira vencendo'],
         'perfis' => $gestao,
         'resp' => function () {
             $v = q('SELECT nome, cnh_validade FROM motoristas WHERE cnh_validade <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) ORDER BY cnh_validade')->fetchAll();
             return [$v ? 'CNHs que vencem nos próximos 60 dias: ' . implode(', ', array_map(fn($m) => $m['nome'] . ' (' . date('d/m/Y', strtotime($m['cnh_validade'])) . ')', $v)) . '.' : 'Nenhuma CNH vence nos próximos 60 dias.', [['Ver motoristas', 'motoristas.php']]];
         }],
        ['chaves' => ['motoristas', 'motorista disponivel', 'quantos motoristas', 'equipe', 'quem esta trabalhando', 'quem esta na rua', 'na rua', 'quem+rota'],
         'perfis' => $gestao,
         'resp' => function () {
             $m = q('SELECT m.nome, m.disponivel, v.placa FROM motoristas m LEFT JOIN veiculos v ON v.id = m.veiculo_id ORDER BY m.nome')->fetchAll();
             $disp = array_filter($m, fn($x) => $x['disponivel']);
             $rua = q("SELECT m.nome FROM rotas r JOIN motoristas m ON m.id = r.motorista_id WHERE r.status = 'em_andamento'")->fetchAll(PDO::FETCH_COLUMN);
             return ['Temos ' . count($m) . ' motoristas, ' . count($disp) . ' disponíveis.' . ($rua ? ' Na rua agora: ' . implode(', ', $rua) . '.' : ' Ninguém está em rota neste momento.'), [['Ver motoristas', 'motoristas.php']]];
         }],
        ['chaves' => ['rotas de hoje', 'rotas hoje', 'quantas rotas', 'rotas do dia', 'rotas planejadas', 'rotas em andamento'],
         'perfis' => $gestao,
         'resp' => function () {
             $r = q('SELECT r.status, COUNT(*) n, SUM(r.distancia_km) km FROM rotas r WHERE r.data_rota = CURDATE() GROUP BY r.status')->fetchAll();
             if (!$r) return ['Ainda não há rotas para hoje. Quer gerar?', [['Gerar rotas', 'rotas.php']]];
             return ['Rotas de hoje: ' . implode(', ', array_map(fn($x) => "{$x['n']} {$x['status']}", $r)) . ', somando ' . number_format(array_sum(array_column($r, 'km')), 1, ',', '.') . ' km.', [['Ver rotas', 'rotas.php']]];
         }],
        ['chaves' => ['onde esta o veiculo', 'onde esta o caminhao', 'onde estao os veiculos', 'posicao do veiculo', 'rastrear', 'localizar veiculo', 'mapa'],
         'perfis' => $gestao,
         'resp' => function () {
             $v = q("SELECT v.placa, v.posicao_em FROM veiculos v WHERE v.status = 'em_rota'")->fetchAll();
             return [$v ? 'Em rota agora: ' . implode(', ', array_map(fn($x) => $x['placa'] . ' (posição às ' . date('H:i', strtotime($x['posicao_em'])) . ')', $v)) . '. No Rastreamento você vê todos no mapa, atualizando a cada 5 segundos.' : 'Nenhum veículo está em rota agora. No Rastreamento você acompanha tudo no mapa assim que saírem.', [['Abrir rastreamento', 'rastreamento.php']]];
         }],
        ['chaves' => ['alerta', 'aviso', 'notificac', 'problema', 'pendencia'],
         'perfis' => $gestao,
         'resp' => function () {
             $a = q('SELECT mensagem FROM alertas WHERE resolvido = 0 ORDER BY criado_em DESC LIMIT 5')->fetchAll(PDO::FETCH_COLUMN);
             $n = (int)q('SELECT COUNT(*) FROM alertas WHERE resolvido = 0')->fetchColumn();
             return [$a ? "Há $n alerta(s) abertos. Os mais recentes:\n- " . implode("\n- ", $a) : 'Nenhum alerta aberto agora. Tudo em ordem!', [['Ver alertas', 'alertas.php']]];
         }],
        ['chaves' => ['total de pedidos', 'quantos pedidos temos', 'quantos pedidos no sistema', 'historico', 'quantas entregas ja'],
         'perfis' => $gestao,
         'resp' => function () {
             $t = (int)q('SELECT COUNT(*) FROM pedidos')->fetchColumn();
             $e = (int)q("SELECT COUNT(*) FROM pedidos WHERE status = 'entregue'")->fetchColumn();
             return ["O sistema tem $t pedidos registrados, dos quais $e já foram entregues."];
         }],
        ['chaves' => ['ocupacao', 'carga dos veiculos', 'veiculos cheios', 'aproveitamento'],
         'perfis' => $gestao,
         'resp' => function () {
             $k = indicadores(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'));
             return ["Agora {$k['ocupacao_frota']}% da frota ativa está em rota." . ($k['ocupacao_carga'] !== null ? " Nas rotas recentes, os veículos saíram em média com {$k['ocupacao_carga']}% da capacidade de carga." : '')];
         }],
        ['chaves' => ['dica', 'sugestao', 'recomenda', 'o que melhorar', 'como melhorar', 'insight', 'conselho'],
         'perfis' => $gestao,
         'resp' => function () {
             $i = gerar_insights(30);
             return ['Algumas sugestões com base nos últimos 30 dias:' . "\n- " . implode("\n- ", array_slice($i['insights'], 0, 3)), [['Ver análise completa', 'ia.php']]];
         }],

        /* ===================== Como usar o sistema ===================== */
        ['chaves' => ['cadastrar veiculo', 'novo veiculo', 'adicionar veiculo', 'incluir veiculo', 'registrar veiculo', 'cadastro de veiculo', 'cadastrar caminhao', 'cadastr+veiculo', 'cri+veiculo', 'adicion+veiculo', 'inclu+veiculo', 'cadastr+caminhao', 'cadastr+carro', 'cadastr+frota'],
         'resp' => 'Vá em Veículos, preencha placa, tipo, capacidade em kg e m³, autonomia, custo por km, status e data da próxima manutenção, e clique em Salvar.', 'acoes' => [['Ir para Veículos', 'veiculos.php']]],
        ['chaves' => ['editar veiculo', 'alterar veiculo', 'mudar status do veiculo', 'excluir veiculo', 'apagar veiculo', 'edit+veiculo', 'alter+veiculo', 'exclu+veiculo', 'apag+veiculo'],
         'resp' => 'Em Veículos, na lista da frota, use "Editar" para alterar os dados ou o status, e "Excluir" para remover. Veículos que já têm rotas não podem ser excluídos, só colocados em manutenção.', 'acoes' => [['Ir para Veículos', 'veiculos.php']]],
        ['chaves' => ['cadastrar motorista', 'novo motorista', 'adicionar motorista', 'incluir motorista', 'cadastro de motorista', 'cadastr+motorista', 'cri+motorista', 'adicion+motorista', 'inclu+motorista'],
         'resp' => 'Vá em Motoristas, preencha nome, categoria e validade da CNH, telefone, veículo vinculado e, se ele for usar o app, a conta de acesso. Marque o consentimento de dados (LGPD) e salve.', 'acoes' => [['Ir para Motoristas', 'motoristas.php']]],
        ['chaves' => ['vincular motorista', 'vincular veiculo', 'trocar veiculo do motorista', 'motorista com veiculo', 'vincul+motorista', 'vincul+veiculo'],
         'resp' => 'Em Motoristas, clique em "Editar" no motorista e escolha o veículo no campo "Veículo vinculado". As rotas desse veículo passam a aparecer para ele.', 'acoes' => [['Ir para Motoristas', 'motoristas.php']]],
        ['chaves' => ['cadastrar pedido', 'novo pedido', 'criar pedido', 'adicionar pedido', 'lancar pedido', 'nova entrega', 'cadastrar entrega', 'cadastr+pedido', 'cri+pedido', 'novo+pedido', 'lanc+pedido', 'cadastr+entrega', 'fac+pedido'],
         'resp' => 'Em Pedidos, preencha destinatário, região, endereço, janela de entrega, peso, volume e prioridade, e clique em "Cadastrar pedido". Se não informar as coordenadas, o sistema localiza o endereço sozinho.', 'acoes' => [['Ir para Pedidos', 'pedidos.php']]],
        ['chaves' => ['csv', 'importar', 'planilha', 'excel para importar', 'em lote', 'varios pedidos de uma vez', 'subir arquivo', 'import+csv', 'import+planilha', 'import+pedido'],
         'resp' => 'Em Pedidos, use "Importar CSV". As colunas são: codigo;destinatario;endereco;regiao;janela_inicio;janela_fim;peso_kg;volume_m3;prioridade;lat;lng (separador ; ou ,). Na própria tela tem um arquivo de exemplo para baixar. Linhas com erro são listadas e as outras entram normalmente.', 'acoes' => [['Ir para Pedidos', 'pedidos.php']]],
        ['chaves' => ['erro no csv', 'csv nao importa', 'csv deu erro', 'colunas incorretas', 'linha rejeitada', 'csv+erro', 'csv+problema', 'planilha+erro'],
         'resp' => 'Confira se o arquivo tem o cabeçalho certo, se as datas estão no formato 2026-10-22 09:00, se peso e volume são maiores que zero e se a prioridade é baixa, media ou alta. O sistema mostra a linha e o motivo de cada rejeição.'],
        ['chaves' => ['buscar pedido', 'procurar pedido', 'encontrar pedido', 'filtrar pedido', 'pesquisar', 'busc+pedido', 'procur+pedido', 'ach+pedido', 'filtr+pedido'],
         'resp' => 'Em Pedidos, use os filtros no meio da tela: busca por código ou nome, status, região e data. Ou me mande o código aqui, tipo "PED-1001", que eu te digo como ele está.', 'acoes' => [['Ir para Pedidos', 'pedidos.php']]],
        ['chaves' => ['excluir pedido', 'apagar pedido', 'cancelar pedido', 'remover pedido', 'exclu+pedido', 'apag+pedido', 'cancel+pedido'],
         'resp' => 'Em Pedidos, clique em "Excluir" na linha do pedido. Só dá para excluir pedidos que ainda estão pendentes (sem rota). Se já estiver roteirizado, desfaça a rota antes.'],
        ['chaves' => ['gerar rota', 'criar rota', 'montar rota', 'roteirizar', 'otimizar rota', 'como faco a rota', 'planejar rota', 'rota'],
         'resp' => 'Em Rotas (IA), escolha a data, marque os pedidos e veículos e clique em "Gerar rotas otimizadas". A IA divide as entregas entre os veículos respeitando capacidade e janelas e escolhe a ordem que roda menos. Depois, confira no mapa e clique em "Liberar para motorista".', 'acoes' => [['Ir para Rotas', 'rotas.php']]],
        ['chaves' => ['como funciona a rota', 'algoritmo', 'como a ia monta', 'funciona+rota', 'funciona+roteiriz', 'calcula+rota', 'tsp', 'vrp', 'caixeiro', '2-opt', 'vizinho mais proximo'],
         'resp' => 'A roteirização usa uma heurística de VRP com capacidade e janelas de tempo: primeiro monta a rota indo sempre para a entrega viável mais próxima, respeitando peso, volume, janela e autonomia; depois aplica o 2-opt, que inverte trechos enquanto a distância diminuir. Na tela de Rotas aparece quanto isso economiza.', 'acoes' => [['Ver rotas', 'rotas.php']]],
        ['chaves' => ['liberar rota', 'enviar rota', 'mandar rota para o motorista', 'motorista nao ve a rota', 'liber+rota', 'envi+rota', 'mand+rota'],
         'resp' => 'Depois de gerar, clique em "Liberar para motorista" no cartão da rota. Ela aparece em Minhas entregas para o motorista vinculado àquele veículo. Se ele não vê, confira em Motoristas se o veículo está vinculado e se a conta de acesso está certa.', 'acoes' => [['Ir para Rotas', 'rotas.php']]],
        ['chaves' => ['desfazer rota', 'cancelar rota', 'refazer rota', 'apagar rota', 'gerar de novo', 'desfaz+rota', 'cancel+rota', 'refaz+rota', 'apag+rota'],
         'resp' => 'Em Rotas, use "Desfazer" no cartão da rota (só enquanto ela não foi iniciada). Os pedidos voltam para pendente e você pode gerar de novo.'],
        ['chaves' => ['nao coube', 'nao couberam', 'pedido nao entrou', 'nao alocado', 'sobrou pedido', 'coube+rota', 'cab+rota', 'sobr+rota'],
         'resp' => 'Quando sobra pedido, é porque nenhum veículo selecionado tinha capacidade ou dava para chegar dentro da janela. Tente marcar mais veículos, alargar a janela do pedido ou gerar uma rota extra só com ele.'],
        ['chaves' => ['rastreamento', 'acompanhar entrega', 'tempo real', 'simular', 'simulacao'],
         'resp' => 'O Rastreamento mostra veículos e entregas no mapa, atualizando sozinho a cada 5 segundos. Dá para filtrar por rota, motorista, região e status. Para demonstração, marque "Simular movimento" e os veículos andam até as próximas paradas.', 'acoes' => [['Abrir rastreamento', 'rastreamento.php']]],
        ['chaves' => ['exportar', 'relatorio', 'pdf', 'baixar dados', 'imprimir', 'export+pdf', 'export+excel', 'baix+relatorio', 'ger+relatorio'],
         'resp' => 'No Dashboard, escolha o período e a região e clique em "Exportar Excel" ou "Exportar PDF". O PDF abre pronto para salvar ou imprimir.', 'acoes' => [['Abrir dashboard', 'dashboard.php']]],
        ['chaves' => ['filtro do dashboard', 'mudar periodo', 'filtrar por data', 'filtrar por regiao', 'outro periodo'],
         'resp' => 'No topo do Dashboard há os campos "De", "Até" e "Região". Ajuste e clique em "Aplicar" que todos os números e gráficos se atualizam.'],
        ['chaves' => ['o que e otd', 'que+otd', 'significa+otd', 'significa otd', 'on time delivery', 'o que significa no prazo'],
         'resp' => 'OTD (On Time Delivery) é a porcentagem de entregas finalizadas dentro da janela combinada com o cliente. Quanto mais perto de 100%, melhor o nível de serviço.'],
        ['chaves' => ['como calcula o custo', 'calcula+custo', 'custo por entrega significa', 'de onde vem o custo'],
         'resp' => 'O custo por entrega soma o custo das rotas (km rodados vezes o custo por km de cada veículo) e divide pelo número de entregas finalizadas no período.'],
        ['chaves' => ['como funciona a previsao', 'como preve', 'funciona+previs', 'calcula+previs', 'modelo de previsao'],
         'resp' => 'A previsão olha os últimos 90 dias de cada região, separa o padrão de cada dia da semana (sexta costuma ter mais, domingo menos) e a tendência de crescimento, e projeta os próximos 7 ou 30 dias.', 'acoes' => [['Ver previsão', 'ia.php']]],
        ['chaves' => ['como calcula o risco', 'como funciona o risco', 'funciona+risco', 'calcula+risco', 'porcentagem de risco', 'risco significa'],
         'resp' => 'O risco é uma probabilidade de 0 a 100% calculada a partir do histórico de atrasos da região e do horário, ajustada pela janela, distância, peso e situação do pedido. Acima de 60% o sistema cria um alerta e sugere replanejar.'],
        ['chaves' => ['janela de entrega', 'o que e janela', 'horario permitido'],
         'resp' => 'A janela de entrega é o intervalo de horário em que o cliente pode receber, por exemplo das 9h às 12h. A IA usa essas janelas para montar a ordem das paradas.'],
        ['chaves' => ['prioridade', 'urgente', 'pedido urgente'],
         'resp' => 'Cada pedido pode ter prioridade baixa, média ou alta. Os de prioridade alta são atendidos primeiro na hora de montar as rotas.'],
        ['chaves' => ['resolver alerta', 'marcar como resolvido', 'resolv+alerta', 'fech+alerta', 'tir+alerta', 'fechar alerta', 'limpar alerta'],
         'resp' => 'Em Alertas, clique em "Resolver" ao lado do alerta. Ele sai da lista de abertos e fica no histórico.', 'acoes' => [['Ver alertas', 'alertas.php']]],
        ['chaves' => ['tipos de alerta', 'quando gera alerta', 'quais alertas', 'email de alerta', 'receber email'],
         'resp' => 'O sistema avisa sozinho sobre entrega atrasada, veículo parado por mais de 20 minutos, veículo fora da rota, manutenção a vencer, CNH a vencer e entregas com risco alto. Os gestores recebem por e-mail quando o envio está configurado; senão fica tudo registrado no log de notificações.'],
        ['chaves' => ['cadastrar usuario', 'criar usuario', 'novo usuario', 'criar login', 'criar acesso', 'dar acesso', 'cadastr+usuario', 'cri+usuario', 'adicion+usuario', 'novo+usuario'],
         'resp' => 'Só o administrador cria usuários, na tela Usuários. É preciso informar nome, e-mail, uma senha forte (8 caracteres com maiúscula, número e símbolo) e o perfil.'],
        ['chaves' => ['perfis', 'permissao', 'permissoes', 'quem pode', 'nao tenho acesso', 'acesso negado'],
         'resp' => 'São quatro perfis: Administrador (tudo), Gestor de frota (dashboard, frota, motoristas, IA, alertas e rotas), Despachante (pedidos, rotas, rastreamento e alertas) e Motorista (suas entregas e comprovantes). Se precisar de outro acesso, peça ao administrador.'],
        ['chaves' => ['esqueci a senha', 'trocar senha', 'mudar senha', 'redefinir senha', 'senha errada', 'nao consigo entrar', 'nao consigo logar', 'esquec+senha', 'troc+senha', 'mud+senha', 'senha', 'esqueci'],
         'resp' => 'Para trocar ou recuperar a senha, fale com o administrador do sistema. No login, confira também se escolheu o perfil certo, porque e-mail, senha e perfil precisam bater.'],
        ['chaves' => ['sair do sistema', 'deslogar', 'logout', 'como saio'],
         'resp' => 'Clique no ícone de saída no canto inferior esquerdo, ao lado do seu nome.'],
        ['chaves' => ['modo escuro', 'tema escuro', 'dark', 'mudar cor', 'tema claro', 'escuro', 'claro+tema'],
         'resp' => 'Clique no ícone de lua no canto superior direito para alternar entre o tema claro e o escuro. A escolha fica salva no seu navegador.'],
        ['chaves' => ['celular', 'mobile', 'aplicativo', 'app'],
         'resp' => 'O sistema funciona no navegador do celular, sem instalar nada. No celular, o menu abre pelo botão no canto superior esquerdo.'],
        ['chaves' => ['lgpd', 'dados pessoais', 'privacidade', 'excluir meus dados', 'apagar dados', 'anonimizar', 'consentimento'],
         'resp' => 'Os dados pessoais só são usados para gerenciar as entregas e exigem consentimento no cadastro. Motoristas podem ter os dados excluídos em Motoristas, e destinatários podem ser anonimizados em Pedidos, mantendo só os números para estatística.'],
        ['chaves' => ['backup', 'copia de seguranca', 'restaurar', 'recuperar dados', 'perdi dados'],
         'resp' => 'O backup do banco é feito pelo script cron/backup.php (pode ser agendado no Windows) e guarda as 14 últimas cópias. Para restaurar, o administrador roda cron/restaurar.php.'],
        ['chaves' => ['sem internet', 'offline', 'internet caiu', 'fallback', 'ia fora do ar', 'mapa nao carrega', 'mapa nao aparece', 'internet', 'cair+internet', 'sem+conexao', 'caiu'],
         'resp' => 'Sem internet, o sistema continua funcionando: rotas, previsão, risco e este chat rodam com modelos locais. Se o mapa não carregar, as informações aparecem em lista, e se só o fundo do mapa falhar, os veículos continuam visíveis.'],
        ['chaves' => ['qual ia', 'gemini', 'chatgpt', 'openai', 'inteligencia artificial', 'como funciona a ia', 'voce usa ia'],
         'resp' => 'A plataforma usa modelos próprios para rotas, previsão de demanda e risco de atraso, que funcionam até sem internet. Quando o Google Gemini está configurado, ele também ajuda a escrever os insights e as respostas deste chat.'],
        ['chaves' => ['manual', 'tutorial', 'passo a passo', 'como usar o sistema', 'como funciona o sistema', 'instrucoes'],
         'resp' => 'O Manual tem o passo a passo de todas as telas e o fluxo recomendado do dia.', 'acoes' => [['Abrir manual', 'manual.php']]],
        ['chaves' => ['suporte', 'contato', 'falar com alguem', 'telefone do suporte', 'reclamacao', 'bug', 'erro no sistema', 'deu erro'],
         'resp' => 'Se algo não funcionou, tente recarregar a página. Se a mensagem for "Token de segurança inválido", é só a sessão que expirou. Persistindo, fale com o administrador do sistema e diga em que tela aconteceu.'],
        ['chaves' => ['senn', 'empresa', 'sobre o sistema', 'o que e esse sistema', 'pra que serve'],
         'resp' => 'Esta é a plataforma de gestão de entregas de última milha da SENN AI-X. Ela ajuda gestores, despachantes e motoristas a planejar rotas com IA, acompanhar entregas em tempo real e tomar decisões com base em dados.'],
    ];
}
