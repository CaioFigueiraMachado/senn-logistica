<?php
/** Cabeçalho comum (barra lateral + barra superior). Espera $titulo e $u definidos pela página. */
// Ícones no estilo Lucide (traço 2px)
$icones = [
    'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
    'mapa' => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    'rota' => '<circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/>',
    'pacote' => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
    'caminhao' => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.62l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
    'pessoa' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'sino' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    'brilho' => '<path d="M9.94 14.06 4 20"/><path d="m12 2 1.8 5.2L19 9l-5.2 1.8L12 16l-1.8-5.2L5 9l5.2-1.8Z"/><path d="M20 15v4"/><path d="M22 17h-4"/>',
    'escudo' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>',
    'check' => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
    'livro' => '<path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20"/>',
    'banco' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
    'sair' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
];
function icone(string $nome, array $icones): string {
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icones[$nome] . '</svg>';
}
$menu = [
    'Operação' => [
        'dashboard.php'    => ['Dashboard', 'dashboard', ['gestor']],
        'rastreamento.php' => ['Rastreamento', 'mapa', ['gestor', 'despachante']],
        'rotas.php'        => ['Rotas (IA)', 'rota', ['gestor', 'despachante']],
        'pedidos.php'      => ['Pedidos', 'pacote', ['gestor', 'despachante']],
        'alertas.php'      => ['Alertas', 'sino', ['gestor', 'despachante']],
        'motorista.php'    => ['Minhas entregas', 'check', ['motorista']],
    ],
    'Inteligência' => [
        'ia.php' => ['Análise IA', 'brilho', ['gestor']],
    ],
    'Cadastros' => [
        'veiculos.php'   => ['Veículos', 'caminhao', ['gestor']],
        'motoristas.php' => ['Motoristas', 'pessoa', ['gestor']],
    ],
    'Sistema' => [
        'usuarios.php' => ['Usuários', 'escudo', []],
        'backup.php'   => ['Backup', 'banco', []],
    ],
    'Ajuda' => [
        'manual.php' => ['Manual', 'livro', ['gestor', 'despachante', 'motorista']],
    ],
];
$atual = basename($_SERVER['SCRIPT_NAME']);
$nAlertas = in_array($u['perfil'], ['admin', 'gestor', 'despachante'], true) ? (int)q('SELECT COUNT(*) FROM alertas WHERE resolvido = 0')->fetchColumn() : 0;
$iniciais = mb_strtoupper(implode('', array_map(fn($p) => mb_substr($p, 0, 1), array_slice(explode(' ', $u['nome']), 0, 2))));
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= csrf_token() ?>">
<title><?= e($titulo ?? '') ?> · <?= APP_NOME ?></title>
<script>try { if (localStorage.getItem('tema') === 'escuro') document.documentElement.dataset.tema = 'escuro'; } catch (e) {}</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap">
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
<div class="app">
<aside class="lateral" id="lateral" aria-label="Menu principal">
  <div class="marca"><span class="logo" aria-hidden="true">S</span> <span>SENN AI-X</span></div>
  <nav>
    <?php foreach ($menu as $grupo => $itens):
        $visiveis = array_filter($itens, fn($i) => $u['perfil'] === 'admin' || in_array($u['perfil'], $i[2], true));
        if (!$visiveis) continue; ?>
      <p class="grupo"><?= $grupo ?></p>
      <?php foreach ($visiveis as $arq => [$rotulo, $ic]): ?>
        <a href="<?= $arq ?>" <?= $arq === $atual ? 'aria-current="page"' : '' ?>><?= icone($ic, $icones) ?><span><?= $rotulo ?></span>
          <?php if ($arq === 'alertas.php' && $nAlertas): ?><span class="contador"><?= $nAlertas ?></span><?php endif; ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="perfil-box">
    <span class="avatar" aria-hidden="true"><?= e($iniciais) ?></span>
    <div class="perfil-txt"><strong><?= e($u['nome']) ?></strong><small><?= PERFIS[$u['perfil']] ?></small></div>
    <a href="logout.php" class="btn-icone" title="Sair" aria-label="Sair"><?= icone('sair', $icones) ?></a>
  </div>
</aside>
<div class="lateral-fundo" onclick="alternarMenu(false)"></div>
<div class="principal">
  <header class="topo">
    <button class="btn-icone" id="menu-btn" aria-controls="lateral" aria-expanded="false" aria-label="Abrir ou fechar menu" onclick="alternarMenu()">
      <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/></svg>
    </button>
    <span class="divisor" aria-hidden="true"></span>
    <span class="topo-titulo"><?= e($titulo ?? '') ?></span>
    <div class="topo-acoes">
      <?php if ($nAlertas): ?><a href="alertas.php" class="btn-icone" aria-label="<?= $nAlertas ?> alertas abertos" title="Alertas"><?= icone('sino', $icones) ?><span class="ponto"></span></a><?php endif; ?>
      <button class="btn-icone" onclick="alternarTema()" aria-label="Alternar tema claro/escuro" title="Tema">
        <svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
      </button>
      <span class="avatar avatar-peq" title="<?= e($u['nome']) ?>" aria-hidden="true"><?= e($iniciais) ?></span>
    </div>
  </header>
  <main id="conteudo" class="conteudo">
<?php if ($f = flash()): ?>
  <div class="aviso aviso-<?= e($f[1]) ?>" role="status"><?= e($f[0]) ?></div>
<?php endif; ?>
