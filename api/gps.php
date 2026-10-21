<?php
/** Recebe a posição GPS enviada pelo navegador do motorista. */
require_once __DIR__ . '/../includes/auth.php';
$u = exigir_perfil('motorista');
header('Content-Type: application/json; charset=utf-8');

$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (!hash_equals(csrf_token(), $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) { http_response_code(419); exit('{}'); }
$lat = filter_var($in['lat'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($in['lng'] ?? null, FILTER_VALIDATE_FLOAT);
if ($lat === false || $lng === false || abs($lat) > 90 || abs($lng) > 180) { http_response_code(422); exit('{"ok":false}'); }

$ok = q("UPDATE veiculos v JOIN rotas r ON r.veiculo_id = v.id JOIN motoristas m ON m.id = r.motorista_id
         SET v.lat = ?, v.lng = ?, v.posicao_em = NOW()
         WHERE r.id = ? AND r.status = 'em_andamento' AND (m.usuario_id = ? OR ? = 'admin')",
        [$lat, $lng, (int)($in['rota'] ?? 0), $u['id'], $u['perfil']])->rowCount();
echo json_encode(['ok' => $ok > 0]);
