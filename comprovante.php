<?php
/** Entrega a imagem do comprovante só para usuários autorizados (a pasta storage é bloqueada para a web). */
require_once __DIR__ . '/includes/auth.php';
exigir_perfil('gestor', 'despachante');

$c = q('SELECT evidencia FROM comprovantes WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
$arq = $c && preg_match('/^[a-z]+_[a-f0-9]{16}\.(jpe?g|png|webp)$/', $c['evidencia']) ? __DIR__ . '/storage/pod/' . $c['evidencia'] : null;
if (!$arq || !is_file($arq)) { http_response_code(404); exit; }

header('Content-Type: ' . (getimagesize($arq)['mime'] ?? 'application/octet-stream'));
header('Cache-Control: private, max-age=3600');
readfile($arq);
