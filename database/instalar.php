<?php
/**
 * Instala o banco e popula dados de demonstração (inclui ~90 dias de histórico
 * para alimentar os modelos de IA). Uso: php database/instalar.php
 * ATENÇÃO: apaga e recria o banco senn_logistica.
 */
require_once __DIR__ . '/../config.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('DROP DATABASE IF EXISTS ' . DB_NAME);
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
$pdo->exec('USE ' . DB_NAME);
mt_srand(2026);

// Usuários
$hash = password_hash('Senai@2026', PASSWORD_DEFAULT);
$st = $pdo->prepare('INSERT INTO usuarios (nome,email,senha_hash,perfil,consentimento_lgpd) VALUES (?,?,?,?,1)');
foreach ([
    ['Ana Admin', 'admin@senn.com', 'admin'],
    ['Gustavo Gestor', 'gestor@senn.com', 'gestor'],
    ['Denise Despacho', 'despacho@senn.com', 'despachante'],
    ['Marcos Motorista', 'motorista@senn.com', 'motorista'],
] as [$n, $e, $p]) $st->execute([$n, $e, $hash, $p]);

// Veículos
$st = $pdo->prepare('INSERT INTO veiculos (placa,tipo,capacidade_kg,capacidade_m3,autonomia_km,custo_km,status,proxima_manutencao,lat,lng,posicao_em) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())');
$veiculos = [
    ['ABC1D23', 'van', 1200, 8, 450, 1.8, 'disponivel', '+40 days'],
    ['EFG4H56', 'carro', 400, 2, 500, 1.1, 'disponivel', '+5 days'],
    ['IJK7L89', 'caminhao', 4000, 25, 700, 3.2, 'disponivel', '+90 days'],
    ['MNO0P12', 'moto', 30, 0.15, 300, 0.5, 'disponivel', '+20 days'],
    ['QRS3T45', 'van', 1000, 7, 420, 1.7, 'manutencao', '+1 days'],
];
foreach ($veiculos as $v) {
    $st->execute([$v[0], $v[1], $v[2], $v[3], $v[4], $v[5], $v[6], date('Y-m-d', strtotime($v[7])), DEPOSITO_LAT, DEPOSITO_LNG]);
}

// Motoristas (o primeiro é vinculado ao usuário motorista)
$st = $pdo->prepare('INSERT INTO motoristas (usuario_id,nome,cnh_categoria,cnh_validade,telefone,disponivel,veiculo_id,consentimento_lgpd) VALUES (?,?,?,?,?,1,?,1)');
$st->execute([4, 'Marcos Motorista', 'D', date('Y-m-d', strtotime('+2 years')), '(12) 99111-0001', 1]);
$st->execute([null, 'Paula Souza', 'B', date('Y-m-d', strtotime('+20 days')), '(12) 99111-0002', 2]);
$st->execute([null, 'Ricardo Lima', 'E', date('Y-m-d', strtotime('+1 year')), '(12) 99111-0003', 3]);
$st->execute([null, 'Juliana Reis', 'A', date('Y-m-d', strtotime('+8 months')), '(12) 99111-0004', 4]);

// Regiões de Taubaté e arredores (centro aproximado)
$regioes = [
    'Centro' => [-23.0255, -45.5560, 1.4],
    'Independência' => [-23.0050, -45.5400, 1.0],
    'Jardim das Nações' => [-23.0180, -45.5800, 1.1],
    'Gurilândia' => [-23.0400, -45.5300, 0.8],
    'Quiririm' => [-23.0600, -45.6050, 0.6],
    'Tremembé' => [-22.9580, -45.5490, 0.5],
];
$ruas = ['Rua XV de Novembro', 'Av. Independência', 'Rua Visconde do Rio Branco', 'Av. Charles Schnneider', 'Rua Dr. Pedro Costa', 'Av. Tiradentes', 'Rua Jacques Félix', 'Av. Bandeirantes'];
$nomes = ['Carlos', 'Mariana', 'João', 'Fernanda', 'Pedro', 'Beatriz', 'Lucas', 'Camila', 'Rafael', 'Larissa', 'Thiago', 'Patrícia'];
$sobrenomes = ['Silva', 'Santos', 'Oliveira', 'Souza', 'Pereira', 'Costa', 'Almeida', 'Ferreira'];

$ins = $pdo->prepare('INSERT INTO pedidos (codigo,destinatario,endereco,regiao,lat,lng,janela_inicio,janela_fim,peso_kg,volume_m3,prioridade,status,entregue_em,criado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$seq = 1000;
$rand = fn($a, $b) => $a + mt_rand() / mt_getrandmax() * ($b - $a);

// Histórico: 90 dias com sazonalidade semanal e tendência leve de crescimento
for ($d = 90; $d >= 1; $d--) {
    $dia = strtotime("-$d days");
    $dow = (int)date('N', $dia);
    $fator = [1 => 1.2, 2 => 1.0, 3 => 1.0, 4 => 1.1, 5 => 1.3, 6 => 0.6, 7 => 0.2][$dow];
    foreach ($regioes as $reg => [$lat, $lng, $peso]) {
        $qtd = (int)round($fator * $peso * $rand(2, 4) * (1 + (90 - $d) / 300));
        for ($i = 0; $i < $qtd; $i++) {
            $hIni = mt_rand(8, 16);
            $ini = date('Y-m-d', $dia) . sprintf(' %02d:00:00', $hIni);
            $fim = date('Y-m-d', $dia) . sprintf(' %02d:00:00', $hIni + mt_rand(2, 4));
            // Regiões distantes e fim de tarde atrasam/falham mais
            $chanceAtraso = 0.06 + ($reg === 'Quiririm' || $reg === 'Tremembé' ? 0.12 : 0) + ($hIni >= 15 ? 0.08 : 0);
            $r = $rand(0, 1);
            if ($r < 0.03) { $status = 'falha'; $entregue = null; }
            else {
                $status = 'entregue';
                $base = strtotime($fim) - mt_rand(600, 7000);
                $entregue = date('Y-m-d H:i:s', $r < $chanceAtraso + 0.03 ? strtotime($fim) + mt_rand(600, 5400) : $base);
            }
            $ins->execute([
                'PED-' . $seq++, $nomes[array_rand($nomes)] . ' ' . $sobrenomes[array_rand($sobrenomes)],
                $ruas[array_rand($ruas)] . ', ' . mt_rand(10, 2000) . ' - ' . $reg, $reg,
                $lat + $rand(-0.008, 0.008), $lng + $rand(-0.008, 0.008), $ini, $fim,
                round($rand(0.5, 40), 1), round($rand(0.01, 0.4), 3),
                ['baixa', 'media', 'media', 'alta'][mt_rand(0, 3)], $status, $entregue,
                date('Y-m-d H:i:s', $dia - 86400),
            ]);
        }
    }
}

// Pedidos de hoje pendentes, prontos para roteirizar
$hoje = date('Y-m-d');
for ($i = 0; $i < 18; $i++) {
    $reg = array_keys($regioes)[$i % count($regioes)];
    [$lat, $lng] = $regioes[$reg];
    $hIni = mt_rand(8, 15);
    $ins->execute([
        'PED-' . $seq++, $nomes[array_rand($nomes)] . ' ' . $sobrenomes[array_rand($sobrenomes)],
        $ruas[array_rand($ruas)] . ', ' . mt_rand(10, 2000) . ' - ' . $reg, $reg,
        $lat + $rand(-0.008, 0.008), $lng + $rand(-0.008, 0.008),
        "$hoje " . sprintf('%02d:00:00', $hIni), "$hoje " . sprintf('%02d:00:00', $hIni + mt_rand(2, 4)),
        round($rand(0.5, 40), 1), round($rand(0.01, 0.4), 3),
        ['baixa', 'media', 'alta'][mt_rand(0, 2)], 'pendente', null, date('Y-m-d H:i:s'),
    ]);
}

echo "Banco instalado. Pedidos criados: " . ($seq - 1000) . PHP_EOL;
