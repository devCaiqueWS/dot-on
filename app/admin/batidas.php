<?php
$titulo = 'Batidas (registros de ponto)'; $pagina = 'batidas';
require __DIR__ . '/_layout.php';

$emp_id = $user['empresa_id'];
$data = $_GET['data'] ?? date('Y-m-d');
$func = (int)($_GET['func'] ?? 0);

$where = "b.empresa_id = ? AND DATE(b.momento) = ?";
$params = [$emp_id, $data];
if ($func) { $where .= " AND b.usuario_id = ?"; $params[] = $func; }

$stmt = db()->prepare("SELECT b.*, u.nome_completo, u.matricula
    FROM dot_batidas b JOIN dot_usuarios u ON u.id=b.usuario_id
    WHERE $where ORDER BY b.momento DESC");
$stmt->execute($params);
$batidas = $stmt->fetchAll();

$stmt = db()->prepare("SELECT id, nome_completo FROM dot_usuarios WHERE empresa_id=? AND ativo=1 ORDER BY nome_completo");
$stmt->execute([$emp_id]);
$funcs = $stmt->fetchAll();
?>
<form method="get" class="form-inline">
    <label>Data <input type="date" name="data" value="<?= htmlspecialchars($data) ?>"></label>
    <label>Funcionário
        <select name="func">
            <option value="0">— todos —</option>
            <?php foreach ($funcs as $f): ?>
                <option value="<?= $f['id'] ?>" <?= $func===(int)$f['id']?'selected':'' ?>><?= htmlspecialchars($f['nome_completo']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="btn btn-primary">Filtrar</button>
</form>

<div class="panel">
    <h2><?= count($batidas) ?> batidas em <?= date('d/m/Y', strtotime($data)) ?></h2>
    <table class="tbl">
        <thead><tr><th>NSR</th><th>Funcionário</th><th>Tipo</th><th>Momento</th><th>Origem</th><th>Hash</th></tr></thead>
        <tbody>
        <?php foreach ($batidas as $b): ?>
            <tr>
                <td><code><?= str_pad($b['nsr'], 9, '0', STR_PAD_LEFT) ?></code></td>
                <td><?= htmlspecialchars($b['nome_completo']) ?> <small>(<?= $b['matricula'] ?>)</small></td>
                <td><span class="tag tag-<?= $b['tipo'] ?>"><?= str_replace('_',' ',$b['tipo']) ?></span></td>
                <td><?= date('H:i:s', strtotime($b['momento'])) ?></td>
                <td><small><?= $b['origem'] ?> · <?= htmlspecialchars($b['hostname'] ?? '') ?></small></td>
                <td><code class="token" title="<?= $b['hash_registro'] ?>"><?= substr($b['hash_registro'], 0, 12) ?>…</code></td>
            </tr>
        <?php endforeach; if (!$batidas): ?>
            <tr><td colspan="6" class="empty">Nenhuma batida nesta data/filtro.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

</main></body></html>
