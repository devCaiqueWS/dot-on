<?php
$titulo = 'Solicitações de Horas Extras'; $pagina = 'extras';
require __DIR__ . '/_layout.php';

// Decidir (aprovar/rejeitar)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($user['perfil'], ['admin','gestor','rh'])) {
    csrf_check();
    $id = (int)$_POST['id'];
    $acao = $_POST['acao'];
    $motivo = trim($_POST['motivo'] ?? '');
    $minutos_aprovados = (int)($_POST['minutos_aprovados'] ?? 0);
    // Garante que a solicitação pertence a um funcionário DESTA empresa (isolamento multi-tenant)
    $owns = "AND usuario_id IN (SELECT id FROM dot_usuarios WHERE empresa_id = ?)";
    if ($acao === 'aprovar') {
        db()->prepare("UPDATE dot_horas_extras
            SET status='aprovada', minutos_aprovados=?, decidido_em=NOW(), decidido_por=?, motivo_decisao=?
            WHERE id=? AND status='pendente' $owns")
            ->execute([$minutos_aprovados, $user['id'], $motivo, $id, $user['empresa_id']]);
        auditar($user['id'], 'aprovar_extra', 'hora_extra', $id, ['minutos'=>$minutos_aprovados]);
        $msg = "Solicitação #$id aprovada ($minutos_aprovados min).";
    } elseif ($acao === 'rejeitar') {
        db()->prepare("UPDATE dot_horas_extras
            SET status='rejeitada', decidido_em=NOW(), decidido_por=?, motivo_decisao=?
            WHERE id=? AND status='pendente' $owns")
            ->execute([$user['id'], $motivo, $id, $user['empresa_id']]);
        auditar($user['id'], 'rejeitar_extra', 'hora_extra', $id);
        $msg = "Solicitação #$id rejeitada.";
    }
}

$emp_id = $user['empresa_id'];
$filtro_status = $_GET['status'] ?? 'pendente';
$stmt = db()->prepare("SELECT h.*, u.nome_completo, u.matricula
    FROM dot_horas_extras h JOIN dot_usuarios u ON u.id=h.usuario_id
    WHERE u.empresa_id = ? AND (? = 'todos' OR h.status = ?)
    ORDER BY h.solicitado_em DESC LIMIT 200");
$stmt->execute([$emp_id, $filtro_status, $filtro_status]);
$lista = $stmt->fetchAll();
?>
<?php if (!empty($msg)): ?><div class="alert alert-ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="filtros">
    <a href="?status=pendente" class="btn <?= $filtro_status==='pendente'?'btn-primary':'btn-secondary' ?>">Pendentes</a>
    <a href="?status=aprovada" class="btn <?= $filtro_status==='aprovada'?'btn-primary':'btn-secondary' ?>">Aprovadas</a>
    <a href="?status=rejeitada" class="btn <?= $filtro_status==='rejeitada'?'btn-primary':'btn-secondary' ?>">Rejeitadas</a>
    <a href="?status=todos" class="btn <?= $filtro_status==='todos'?'btn-primary':'btn-secondary' ?>">Todas</a>
</div>

<div class="panel">
    <?php foreach ($lista as $h): ?>
        <div class="extra-card <?= $h['status'] ?>">
            <div class="extra-head">
                <strong><?= htmlspecialchars($h['nome_completo']) ?></strong>
                <small><?= $h['matricula'] ?> · <?= date('d/m/Y H:i', strtotime($h['solicitado_em'])) ?></small>
                <span class="tag tag-<?= $h['status'] ?>"><?= $h['status'] ?></span>
            </div>
            <div class="extra-body">
                <p><strong>Minutos solicitados:</strong> <?= $h['minutos_solicitados'] ?> (<?= fmt_minutos($h['minutos_solicitados']) ?>)</p>
                <p><strong>Data ref.:</strong> <?= date('d/m/Y', strtotime($h['data_ref'])) ?></p>
                <p><strong>Justificativa:</strong> <?= nl2br(htmlspecialchars($h['justificativa'])) ?></p>
                <?php if ($h['status'] !== 'pendente'): ?>
                    <p><strong>Decisão:</strong> <?= $h['minutos_aprovados'] ?> min · <?= date('d/m H:i', strtotime($h['decidido_em'])) ?></p>
                    <?php if ($h['motivo_decisao']): ?><p><em><?= htmlspecialchars($h['motivo_decisao']) ?></em></p><?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if ($h['status'] === 'pendente' && in_array($user['perfil'], ['admin','gestor','rh'])): ?>
            <form method="post" class="extra-actions">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $h['id'] ?>">
                <input type="number" name="minutos_aprovados" value="<?= $h['minutos_solicitados'] ?>" min="0" max="<?= $h['minutos_solicitados'] ?>" title="Minutos a aprovar">
                <input type="text" name="motivo" placeholder="Motivo / observação" style="flex:1">
                <button name="acao" value="aprovar" class="btn btn-success">✓ Aprovar</button>
                <button name="acao" value="rejeitar" class="btn btn-danger">✗ Rejeitar</button>
            </form>
            <?php endif; ?>
        </div>
    <?php endforeach; if (!$lista): ?>
        <p class="empty">Nenhuma solicitação <?= $filtro_status ?>.</p>
    <?php endif; ?>
</div>

</main></body></html>
