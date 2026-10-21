<?php
/** RNF09 - Manual do usuário */
require_once __DIR__ . '/includes/auth.php';
$u = exigir_perfil('gestor', 'despachante', 'motorista');
$titulo = 'Manual';
require __DIR__ . '/includes/header.php';
?>
<h1>Manual do usuário</h1>
<div class="grade">
<section class="cartao">
  <h2>Acesso</h2>
  <p>Na tela de login, informe e-mail, senha e o seu perfil. Cada perfil abre o painel correspondente:</p>
  <ul>
    <li><strong>Administrador</strong>: acesso total, incluindo usuários.</li>
    <li><strong>Gestor de frota</strong>: dashboard, veículos, motoristas, IA, alertas, rotas e rastreamento.</li>
    <li><strong>Despachante</strong>: pedidos, importação CSV, rotas, rastreamento e alertas.</li>
    <li><strong>Motorista</strong>: suas rotas e o registro de comprovantes.</li>
  </ul>
</section>
<section class="cartao">
  <h2>Fluxo diário recomendado</h2>
  <ol>
    <li><strong>Pedidos</strong>: cadastre ou importe o CSV dos pedidos do dia.</li>
    <li><strong>Rotas (IA)</strong>: escolha a data, selecione pedidos e veículos e gere as rotas otimizadas. Confira no mapa e libere para os motoristas.</li>
    <li><strong>Minhas entregas</strong> (motorista): inicie a rota, siga a ordem das paradas e registre o comprovante de cada entrega.</li>
    <li><strong>Rastreamento</strong>: acompanhe veículos e status em tempo real, com filtros por rota, motorista, região e status.</li>
    <li><strong>Alertas</strong>: trate atrasos, veículos parados, rotas fora do plano, manutenção e CNH a vencer.</li>
    <li><strong>Dashboard</strong> e <strong>Análise IA</strong>: acompanhe indicadores, previsões, riscos e recomendações. Exporte em Excel ou PDF.</li>
  </ol>
</section>
<section class="cartao">
  <h2>Comprovante de entrega</h2>
  <p>Em cada parada, toque em <em>Registrar comprovante</em> e escolha foto, assinatura digital ou código de confirmação. Em caso de falha, informe o motivo. Data, hora e localização são registradas automaticamente (permita a localização no navegador).</p>
</section>
<section class="cartao">
  <h2>Assistente SENN AI-X Helper</h2>
  <p>Clique no botão de chat no canto inferior direito e pergunte em linguagem natural. Exemplos:</p>
  <ul><li>"status do PED-1001"</li><li>"quantas entregas atrasadas?"</li><li>"qual o OTD do mês?"</li><li>"previsão da próxima semana"</li><li>"como importar CSV?"</li></ul>
</section>
<section class="cartao">
  <h2>Funcionamento sem internet (fallback)</h2>
  <p>Sem acesso à IA externa, previsões, riscos, rotas, insights e chatbot continuam funcionando com os modelos locais. Sem o serviço de mapas, as telas exibem as informações em lista.</p>
</section>
<section class="cartao">
  <h2>Privacidade (LGPD)</h2>
  <p>Os dados pessoais são usados apenas para a gestão das entregas e exigem consentimento no cadastro. Motoristas podem ter os dados excluídos e destinatários podem ser anonimizados, mantendo apenas dados estatísticos.</p>
</section>
<section class="cartao">
  <h2>Suporte técnico básico</h2>
  <ul>
    <li><strong>Erro de conexão com o banco</strong>: verifique se o MySQL está ativo no XAMPP.</li>
    <li><strong>"Token de segurança inválido"</strong>: a sessão expirou; recarregue a página.</li>
    <li><strong>Mapa não aparece</strong>: verifique a internet; os dados seguem disponíveis em lista.</li>
    <li><strong>Backup</strong>: o administrador executa <code>php cron/backup.php</code> (ou o agendador do Windows) e restaura com <code>php cron/restaurar.php arquivo.sql</code>.</li>
  </ul>
</section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
