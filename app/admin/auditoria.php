<?php
$titulo = 'Auditoria Fiscal · Cadeia de Hashes'; $pagina = 'auditoria';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/repp.php';

$validacao = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['validar'])) {
    csrf_check();
    $validacao = repp_validar_cadeia($user['empresa_id']);
    auditar($user['id'], 'auditoria_cadeia', 'empresa', $user['empresa_id'], [
        'verificadas' => $validacao['verificadas'],
        'divergencias' => count($validacao['divergencias']),
    ]);
}

// Estatísticas
$stmt = db()->prepare("SELECT
    COUNT(*) AS total_batidas,
    MIN(nsr) AS primeiro_nsr,
    MAX(nsr) AS ultimo_nsr,
    SUM(CASE WHEN extemporanea=1 THEN 1 ELSE 0 END) AS extemporaneas,
    SUM(CASE WHEN crp_emitido=1 THEN 1 ELSE 0 END) AS com_crp
    FROM dot_batidas WHERE empresa_id=?");
$stmt->execute([$user['empresa_id']]);
$stat = $stmt->fetch();

$stmt = db()->prepare("SELECT b.nsr, b.tipo, b.momento, b.hash_registro, b.hash_anterior, b.extemporanea, b.crp_emitido,
    u.nome_completo, c.qr_token
    FROM dot_batidas b
    JOIN dot_usuarios u ON u.id=b.usuario_id
    LEFT JOIN dot_crp c ON c.batida_id=b.id
    WHERE b.empresa_id=?
    ORDER BY b.nsr DESC LIMIT 50");
$stmt->execute([$user['empresa_id']]);
$batidas = $stmt->fetchAll();
?>

<div class="cards">
    <div class="card"><div class="num"><?= (int)$stat['total_batidas'] ?></div><div class="lbl">Batidas registradas</div></div>
    <div class="card"><div class="num">#<?= str_pad($stat['ultimo_nsr'] ?? 0, 6, '0', STR_PAD_LEFT) ?></div><div class="lbl">Último NSR</div></div>
    <div class="card"><div class="num"><?= (int)$stat['extemporaneas'] ?></div><div class="lbl">Extemporâneas</div></div>
    <div class="card"><div class="num"><?= (int)$stat['com_crp'] ?></div><div class="lbl">Com CRP emitido</div></div>
</div>

<div class="panel">
    <h2>🔗 Validação da Cadeia de Hashes</h2>
    <p style="color:#6b7280;font-size:13px;margin-bottom:12px">
        Recalcula o hash SHA-256 de TODAS as batidas e verifica se cada uma aponta corretamente para o hash anterior.
        Qualquer adulteração no banco será detectada.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <button name="validar" value="1" class="btn btn-primary">▶ Executar verificação completa</button>
    </form>

    <?php if ($validacao): ?>
        <div class="alert <?= $validacao['ok'] ? 'alert-ok' : 'alert-erro' ?>" style="margin-top:16px">
            <?php if ($validacao['ok']): ?>
                ✅ <b>Cadeia íntegra.</b> Todas as <?= $validacao['verificadas'] ?> batidas foram verificadas com sucesso.<br>
                Último hash: <code style="font-size:11px"><?= $validacao['ultimo_hash'] ?></code>
            <?php else: ?>
                ❌ <b>Cadeia comprometida!</b> Detectadas <?= count($validacao['divergencias']) ?> divergências em <?= $validacao['verificadas'] ?> batidas verificadas.
                <table class="tbl" style="margin-top:12px;background:#fff">
                    <thead><tr><th>NSR</th><th>Problema</th><th>Esperado</th><th>Encontrado</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($validacao['divergencias'], 0, 20) as $d): ?>
                    <tr>
                        <td><code><?= str_pad($d['nsr'], 9, '0', STR_PAD_LEFT) ?></code></td>
                        <td><?= $d['problema'] ?></td>
                        <td><code style="font-size:10px"><?= substr($d['esperado'], 0, 24) ?>…</code></td>
                        <td><code style="font-size:10px"><?= substr($d['encontrado'] ?? '', 0, 24) ?>…</code></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="panel">
    <h2>📜 Últimas 50 batidas da cadeia</h2>
    <table class="tbl">
        <thead><tr>
            <th>NSR</th><th>Funcionário</th><th>Tipo</th><th>Momento</th>
            <th>Hash</th><th>Validador</th><th>CRP</th>
        </tr></thead>
        <tbody>
        <?php foreach ($batidas as $b):
            $url_val = $b['qr_token'] ? "../validar.php?nsr={$b['nsr']}&t={$b['qr_token']}" : "../validar.php?nsr={$b['nsr']}";
        ?>
            <tr>
                <td><code><?= str_pad($b['nsr'], 9, '0', STR_PAD_LEFT) ?></code></td>
                <td><?= htmlspecialchars($b['nome_completo']) ?></td>
                <td><span class="tag tag-<?= $b['tipo'] ?>"><?= str_replace('_',' ',$b['tipo']) ?></span></td>
                <td><?= date('d/m H:i:s', strtotime($b['momento'])) ?>
                    <?php if ($b['extemporanea']): ?><span class="tag" style="background:#fef3c7;color:#854d0e">extemp.</span><?php endif; ?>
                </td>
                <td><code class="token" title="<?= $b['hash_registro'] ?>"><?= substr($b['hash_registro'], 0, 10) ?>…</code></td>
                <td><a href="<?= $url_val ?>" target="_blank" class="btn btn-secondary" style="padding:4px 10px;font-size:11px">🔍 Validar</a></td>
                <td><?= $b['crp_emitido']?'✓':'—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

</main></body></html>
