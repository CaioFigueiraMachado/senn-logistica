<?php
/** RNF08 - Política de privacidade (LGPD), pública. */
require_once __DIR__ . '/includes/auth.php';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Privacidade · <?= APP_NOME ?></title>
<script>try { if (localStorage.getItem('tema') === 'escuro') document.documentElement.dataset.tema = 'escuro'; } catch (e) {}</script>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap">
<link rel="icon" type="image/svg+xml" href="assets/img/logo.svg">
<link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body class="tela-login">
<main class="cartao" style="max-width: 760px; margin: 24px auto">
  <h1>Política de privacidade</h1>
  <p class="sub">Como a <?= APP_NOME ?> trata dados pessoais, conforme a Lei Geral de Proteção de Dados (Lei 13.709/2018).</p>

  <h2>Quais dados coletamos</h2>
  <ul>
    <li><strong>Usuários do sistema:</strong> nome, e-mail e perfil de acesso. A senha é guardada apenas como hash (bcrypt), nunca em texto.</li>
    <li><strong>Motoristas:</strong> nome, categoria e validade da CNH, telefone e a localização do veículo durante a rota.</li>
    <li><strong>Destinatários:</strong> nome e endereço de entrega, além do comprovante (foto, assinatura ou código) com data, hora e local.</li>
  </ul>

  <h2>Para que usamos</h2>
  <p>Exclusivamente para planejar, executar e comprovar as entregas, emitir alertas operacionais e gerar indicadores de desempenho. Os dados não são vendidos nem compartilhados com terceiros. A localização do motorista só é coletada com a rota em andamento.</p>

  <h2>Base legal e consentimento</h2>
  <p>O cadastro de usuários e motoristas exige o consentimento registrado no sistema. Os dados dos destinatários são tratados para a execução do contrato de entrega.</p>

  <h2>Seus direitos</h2>
  <p>O titular pode pedir acesso, correção ou exclusão dos seus dados ao administrador da transportadora. No sistema, motoristas podem ser excluídos definitivamente e destinatários podem ser anonimizados, mantendo apenas números para estatística.</p>

  <h2>Segurança</h2>
  <p>Acesso por login com perfis de permissão, proteção contra SQL Injection, XSS e CSRF, comprovantes guardados fora do acesso público e backups periódicos do banco de dados.</p>

  <p><a class="btn btn-sec" href="<?= usuario() ? 'index.php' : 'login.php' ?>">Voltar</a></p>
</main>
</body>
</html>
