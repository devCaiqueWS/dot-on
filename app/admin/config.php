<?php
$titulo = 'Configurações'; $pagina = 'config';
require __DIR__ . '/_layout.php';

if (!in_array($user['perfil'], ['admin','rh'])) {
    echo "<p>Acesso restrito.</p></main></body></html>"; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($_POST['cfg'] ?? [] as $k => $v) {
        db()->prepare("INSERT INTO dot_config (empresa_id, chave, valor) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE valor=VALUES(valor)")
            ->execute([$user['empresa_id'], $k, $v]);
    }
    $msg = 'Configurações salvas.';
}

$stmt = db()->prepare("SELECT * FROM dot_config WHERE empresa_id=?");
$stmt->execute([$user['empresa_id']]);
$cfgs = $stmt->fetchAll();

$stmt = db()->prepare("SELECT * FROM dot_escalas WHERE empresa_id=?");
$stmt->execute([$user['empresa_id']]);
$escalas = $stmt->fetchAll();
?>
<?php if (!empty($msg)): ?><div class="alert alert-ok"><?= $msg ?></div><?php endif; ?>

<div class="panel">
    <h2>Parâmetros operacionais</h2>
    <form method="post">
        <?= csrf_field() ?>
        <?php foreach ($cfgs as $c): ?>
        <label class="cfg-row">
            <span><strong><?= htmlspecialchars($c['chave']) ?></strong><br><small><?= htmlspecialchars($c['descricao'] ?? '') ?></small></span>
            <input name="cfg[<?= htmlspecialchars($c['chave'], ENT_QUOTES) ?>]" value="<?= htmlspecialchars($c['valor']) ?>">
        </label>
        <?php endforeach; ?>
        <button class="btn btn-primary">Salvar</button>
    </form>
</div>

<div class="panel">
    <h2>Escalas / Jornadas</h2>
    <table class="tbl">
        <thead><tr><th>Nome</th><th>Entrada</th><th>Intervalo</th><th>Saída</th><th>Carga</th><th>Tolerância</th></tr></thead>
        <tbody>
        <?php foreach ($escalas as $e): ?>
            <tr>
                <td><?= htmlspecialchars($e['nome']) ?></td>
                <td><?= $e['entrada'] ?></td>
                <td><?= $e['intervalo_inicio'] ?> – <?= $e['intervalo_fim'] ?></td>
                <td><?= $e['saida'] ?></td>
                <td><?= fmt_minutos($e['carga_diaria_minutos']) ?></td>
                <td><?= $e['tolerancia_minutos'] ?> min</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

</main></body></html>
