<?php
/** RF04 - Cadastro manual e importação CSV de pedidos */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/geo.php';
$u = exigir_perfil('despachante', 'gestor');

const PRIORIDADES = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta'];
const STATUS_P = ['pendente' => 'Pendente', 'roteirizado' => 'Roteirizado', 'a_caminho' => 'A caminho', 'entregue' => 'Entregue', 'falha' => 'Falha'];

/** Valida um pedido e devolve [dados, erros]. Usado no cadastro manual e no CSV. */
function validar_pedido(array $r): array {
    $erros = [];
    $d = [
        'codigo' => trim($r['codigo'] ?? '') ?: 'PED-' . strtoupper(bin2hex(random_bytes(3))),
        'destinatario' => trim($r['destinatario'] ?? ''),
        'endereco' => trim($r['endereco'] ?? ''),
        'regiao' => trim($r['regiao'] ?? ''),
        'janela_inicio' => str_replace('T', ' ', trim($r['janela_inicio'] ?? '')),
        'janela_fim' => str_replace('T', ' ', trim($r['janela_fim'] ?? '')),
        'peso_kg' => (float)str_replace(',', '.', $r['peso_kg'] ?? 0),
        'volume_m3' => (float)str_replace(',', '.', $r['volume_m3'] ?? 0),
        'prioridade' => strtolower(trim($r['prioridade'] ?? 'media')),
        'lat' => trim($r['lat'] ?? ''), 'lng' => trim($r['lng'] ?? ''),
    ];
    if (mb_strlen($d['destinatario']) < 2) $erros[] = 'destinatário obrigatório';
    if (mb_strlen($d['endereco']) < 5) $erros[] = 'endereço obrigatório';
    if ($d['regiao'] === '') $erros[] = 'região obrigatória';
    $ini = strtotime($d['janela_inicio']); $fim = strtotime($d['janela_fim']);
    if (!$ini || !$fim || $fim <= $ini) $erros[] = 'janela de entrega inválida';
    if ($d['peso_kg'] <= 0 || $d['volume_m3'] <= 0) $erros[] = 'peso e volume devem ser positivos';
    if (!isset(PRIORIDADES[$d['prioridade']])) $erros[] = 'prioridade inválida';
    if ($d['lat'] !== '' && (!is_numeric($d['lat']) || !is_numeric($d['lng']))) $erros[] = 'coordenadas inválidas';
    return [$d, $erros];
}

function inserir_pedido(array $d): void {
    if ($d['lat'] === '' || $d['lng'] === '') [$d['lat'], $d['lng']] = geocodificar($d['endereco'], $d['regiao']);
    q('INSERT INTO pedidos (codigo,destinatario,endereco,regiao,lat,lng,janela_inicio,janela_fim,peso_kg,volume_m3,prioridade) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
      [$d['codigo'], $d['destinatario'], $d['endereco'], $d['regiao'], $d['lat'], $d['lng'], $d['janela_inicio'], $d['janela_fim'], $d['peso_kg'], $d['volume_m3'], $d['prioridade']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'novo') {
        [$d, $erros] = validar_pedido($_POST);
        if ($erros) flash('Pedido não salvo: ' . implode(', ', $erros) . '.', 'erro');
        else {
            try { inserir_pedido($d); registrar_log('pedido_criado', $d['codigo']); flash("Pedido {$d['codigo']} cadastrado."); }
            catch (PDOException) { flash('Código de pedido já existente.', 'erro'); }
        }
    } elseif ($acao === 'csv') {
        $arq = $_FILES['csv'] ?? null;
        if (!$arq || $arq['error'] !== UPLOAD_ERR_OK || $arq['size'] > 2_000_000 || strtolower(pathinfo($arq['name'], PATHINFO_EXTENSION)) !== 'csv') {
            flash('Envie um arquivo .csv de até 2 MB.', 'erro');
        } else {
            $fh = fopen($arq['tmp_name'], 'r');
            $primeira = fgets($fh); rewind($fh);
            $sep = substr_count($primeira, ';') >= substr_count($primeira, ',') ? ';' : ',';
            $cab = array_map(fn($c) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $c))), fgetcsv($fh, 0, $sep));
            $ok = 0; $falhas = []; $linha = 1;
            db()->beginTransaction();
            while (($row = fgetcsv($fh, 0, $sep)) !== false) {
                $linha++;
                if (count($row) !== count($cab)) { $falhas[] = "linha $linha: colunas incorretas"; continue; }
                [$d, $erros] = validar_pedido(array_combine($cab, $row));
                if ($erros) { $falhas[] = "linha $linha: " . implode(', ', $erros); continue; }
                try { inserir_pedido($d); $ok++; } catch (PDOException) { $falhas[] = "linha $linha: código duplicado"; }
            }
            db()->commit();
            fclose($fh);
            registrar_log('csv_importado', "$ok pedidos");
            flash("$ok pedido(s) importado(s)." . ($falhas ? ' Rejeitados: ' . implode('; ', array_slice($falhas, 0, 5)) . (count($falhas) > 5 ? '...' : '') : ''), $falhas && !$ok ? 'erro' : 'ok');
        }
    } elseif ($acao === 'anonimizar') {
        // LGPD: remove dados pessoais do destinatário mantendo o histórico estatístico
        q("UPDATE pedidos SET destinatario = 'Anonimizado (LGPD)', endereco = CONCAT('Endereço removido - ', regiao) WHERE id = ?", [(int)$_POST['id']]);
        registrar_log('pedido_anonimizado', $_POST['id']);
        flash('Dados pessoais do destinatário removidos.');
    } elseif ($acao === 'excluir') {
        q("DELETE FROM pedidos WHERE id = ? AND status = 'pendente'", [(int)$_POST['id']]);
        flash('Pedido excluído.');
    }
    header('Location: pedidos.php?' . http_build_query(array_intersect_key($_GET, ['status' => 1, 'regiao' => 1, 'busca' => 1, 'data' => 1])));
    exit;
}

// Filtros e paginação
$where = ['1=1']; $p = [];
if (!empty($_GET['status'])) { $where[] = 'status = ?'; $p[] = $_GET['status']; }
if (!empty($_GET['regiao'])) { $where[] = 'regiao = ?'; $p[] = $_GET['regiao']; }
if (!empty($_GET['data'])) { $where[] = 'DATE(janela_inicio) = ?'; $p[] = $_GET['data']; }
if (!empty($_GET['busca'])) { $where[] = '(codigo LIKE ? OR destinatario LIKE ?)'; $p[] = "%{$_GET['busca']}%"; $p[] = "%{$_GET['busca']}%"; }
$w = implode(' AND ', $where);
$total = (int)q("SELECT COUNT(*) FROM pedidos WHERE $w", $p)->fetchColumn();
$pag = max(1, (int)($_GET['pag'] ?? 1)); $porPag = 25;
$lista = q("SELECT * FROM pedidos WHERE $w ORDER BY janela_inicio DESC LIMIT $porPag OFFSET " . (($pag - 1) * $porPag), $p)->fetchAll();
$regioes = q('SELECT DISTINCT regiao FROM pedidos ORDER BY regiao')->fetchAll(PDO::FETCH_COLUMN);

$titulo = 'Pedidos';
require __DIR__ . '/includes/header.php';
$hoje = date('Y-m-d');
?>
<h1>Pedidos e entregas</h1>
<div class="grade">
  <section class="cartao">
    <h2>Novo pedido</h2>
    <form method="post">
      <?= csrf_campo() ?><input type="hidden" name="acao" value="novo">
      <div class="form-grade">
        <label>Destinatário <input name="destinatario" required></label>
        <label>Região / bairro <input name="regiao" required list="regioes"></label>
      </div>
      <label>Endereço <input name="endereco" required placeholder="Rua, número - bairro"></label>
      <div class="form-grade">
        <label>Janela início <input type="datetime-local" name="janela_inicio" required value="<?= $hoje ?>T09:00"></label>
        <label>Janela fim <input type="datetime-local" name="janela_fim" required value="<?= $hoje ?>T12:00"></label>
        <label>Peso (kg) <input type="number" step="0.1" min="0.1" name="peso_kg" required></label>
        <label>Volume (m³) <input type="number" step="0.001" min="0.001" name="volume_m3" required></label>
        <label>Prioridade <select name="prioridade"><?php foreach (PRIORIDADES as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'media' ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
      </div>
      <details><summary>Coordenadas (opcional, senão o endereço é geocodificado)</summary>
        <div class="form-grade"><label>Latitude <input name="lat"></label><label>Longitude <input name="lng"></label></div>
      </details>
      <button class="btn">Cadastrar pedido</button>
    </form>
    <datalist id="regioes"><?php foreach ($regioes as $r): ?><option value="<?= e($r) ?>"><?php endforeach; ?></datalist>
  </section>
  <section class="cartao">
    <h2>Importar CSV</h2>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_campo() ?><input type="hidden" name="acao" value="csv">
      <label>Arquivo CSV <input type="file" name="csv" accept=".csv" required></label>
      <button class="btn">Importar</button>
    </form>
    <p>O arquivo precisa ter um cabeçalho com estas colunas, separadas por <code>;</code> ou <code>,</code>:</p>
    <ul class="colunas-csv">
      <?php foreach (['codigo', 'destinatario', 'endereco', 'regiao', 'janela_inicio', 'janela_fim', 'peso_kg', 'volume_m3', 'prioridade', 'lat', 'lng'] as $col): ?>
        <li><code><?= $col ?></code></li>
      <?php endforeach; ?>
    </ul>
    <p class="ia-fonte">Datas no formato 2026-10-22 09:00. Prioridade: baixa, media ou alta. Código, lat e lng são opcionais.</p>
    <p><a class="btn btn-sec" href="exemplos/pedidos_exemplo.csv" download>Baixar CSV de exemplo</a></p>
  </section>
</div>

<section class="cartao">
  <form class="acoes" method="get">
    <label>Buscar <input name="busca" value="<?= e($_GET['busca'] ?? '') ?>" placeholder="Código ou nome"></label>
    <label>Status <select name="status"><option value="">Todos</option><?php foreach (STATUS_P as $k => $v): ?><option value="<?= $k ?>" <?= ($_GET['status'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
    <label>Região <select name="regiao"><option value="">Todas</option><?php foreach ($regioes as $r): ?><option <?= ($_GET['regiao'] ?? '') === $r ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?></select></label>
    <label>Data <input type="date" name="data" value="<?= e($_GET['data'] ?? '') ?>"></label>
    <button>Filtrar</button> <a class="btn btn-sec" href="pedidos.php">Limpar</a>
  </form>
  <div class="tabela">
  <table>
    <thead><tr><th>Código</th><th>Destinatário</th><th>Região</th><th>Janela</th><th>Peso/Vol.</th><th>Prioridade</th><th>Status</th><th>Risco</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $pd): ?>
      <tr>
        <td><a href="pedido.php?id=<?= $pd['id'] ?>"><strong><?= e($pd['codigo']) ?></strong></a></td>
        <td><?= e($pd['destinatario']) ?><br><small><?= e($pd['endereco']) ?></small></td>
        <td><?= e($pd['regiao']) ?></td>
        <td><?= date('d/m H:i', strtotime($pd['janela_inicio'])) ?> - <?= date('H:i', strtotime($pd['janela_fim'])) ?></td>
        <td><?= number_format($pd['peso_kg'], 1, ',', '.') ?> kg<br><?= number_format($pd['volume_m3'], 3, ',', '.') ?> m³</td>
        <td><span class="tag tag-<?= $pd['prioridade'] ?>"><?= PRIORIDADES[$pd['prioridade']] ?></span></td>
        <td><span class="tag tag-<?= $pd['status'] ?>"><?= STATUS_P[$pd['status']] ?></span></td>
        <td><?= $pd['risco_atraso'] !== null && !in_array($pd['status'], ['entregue', 'falha'], true) ? number_format($pd['risco_atraso'], 1, ',', '') . '%' : '-' ?></td>
        <td class="acoes">
          <form method="post" onsubmit="return confirm('Remover os dados pessoais deste destinatário?')"><?= csrf_campo() ?><input type="hidden" name="acao" value="anonimizar"><button class="btn-sec btn-peq" name="id" value="<?= $pd['id'] ?>">Anonimizar</button></form>
          <?php if ($pd['status'] === 'pendente'): ?>
          <form method="post" onsubmit="return confirm('Excluir o pedido?')"><?= csrf_campo() ?><input type="hidden" name="acao" value="excluir"><button class="btn-perigo btn-peq" name="id" value="<?= $pd['id'] ?>">Excluir</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="acoes"><?= $total ?> pedido(s).
    <?php $q = $_GET; for ($i = 1, $n = (int)ceil($total / $porPag); $i <= min($n, 20); $i++): $q['pag'] = $i; ?>
      <a class="btn btn-peq <?= $i === $pag ? '' : 'btn-sec' ?>" href="?<?= e(http_build_query($q)) ?>"><?= $i ?></a>
    <?php endfor; ?>
  </p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
