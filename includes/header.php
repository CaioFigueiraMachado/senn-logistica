<?php
/** Cabeçalho comum. Espera $titulo e $u (usuário logado) definidos pela página. */
$menu = [
    'dashboard.php'    => ['Dashboard', ['gestor']],
    'rastreamento.php' => ['Rastreamento', ['gestor', 'despachante']],
    'rotas.php'        => ['Rotas (IA)', ['gestor', 'despachante']],
    'pedidos.php'      => ['Pedidos', ['gestor', 'despachante']],
    'veiculos.php'     => ['Veículos', ['gestor']],
    'motoristas.php'   => ['Motoristas', ['gestor']],
    'alertas.php'      => ['Alertas', ['gestor', 'despachante']],
    'ia.php'           => ['Análise IA', ['gestor']],
    'usuarios.php'     => ['Usuários', []],
    'motorista.php'    => ['Minhas entregas', ['motorista']],
    'manual.php'       => ['Manual', ['gestor', 'despachante', 'motorista']],
];
$atual = basename($_SERVER['SCRIPT_NAME']);
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= csrf_token() ?>">
<title><?= e($titulo ?? '') ?> · <?= APP_NOME ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<?php if (!empty($usaMapa)): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>
<?php if (!empty($usaGraficos)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<?php endif; ?>
</head>
<body>
<a class="pular" href="#conteudo">Pular para o conteúdo</a>
<header class="topo">
  <div class="marca"><span class="logo" aria-hidden="true">◆</span> <?= APP_NOME ?></div>
  <button class="menu-btn" aria-controls="nav" aria-expanded="false" onclick="this.setAttribute('aria-expanded', document.getElementById('nav').classList.toggle('aberto'))">Menu</button>
  <nav id="nav" aria-label="Principal">
    <?php foreach ($menu as $arq => [$rotulo, $perfis]):
        if ($u['perfil'] !== 'admin' && !in_array($u['perfil'], $perfis, true)) continue; ?>
      <a href="<?= $arq ?>" <?= $arq === $atual ? 'aria-current="page"' : '' ?>><?= $rotulo ?></a>
    <?php endforeach; ?>
    <span class="quem"><?= e($u['nome']) ?> · <?= PERFIS[$u['perfil']] ?></span>
    <a href="logout.php">Sair</a>
  </nav>
</header>
<main id="conteudo" class="conteudo">
<?php if ($f = flash()): ?>
  <div class="aviso aviso-<?= e($f[1]) ?>" role="status"><?= e($f[0]) ?></div>
<?php endif; ?>
