<?php
$titulo = 'Justificativas & Correções'; $pagina = 'justificativas';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/justificativas.php';

$pode_decidir = in_array($user['perfil'], ['admin','gestor','rh'], true);
$msg = ''; $msg_tipo = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pode_decidir) {
    csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $acao = $_POST['acao'] ?? '';
    $obs = trim($_POST['motivo'] ?? '');
    if ($acao === 'aprovar') {
        $r = jus_aprovar($id, (int)$user['empresa_id'], (int)$user['id'], $obs);
    } elseif ($acao === 'rejeitar') {
        $r = jus_rejeitar($id, (int)$user['empresa_id'], (int)$user['id'], $obs);
    } else {
        $r = ['ok'=>false, 'msg'=>'Ação inválida.'];
    }
    $msg = $r['msg']; $msg_tipo = $r['ok'] ? 'ok' : 'error';
}

$emp_id = (int)$user['empresa_id'];
$cat = $_GET['cat'] ?? 'todas';                 // todas | justificativa | correcao
$status = $_GET['status'] ?? 'pendente';        // pendente | aprovada | rejeitada | todos
if (!in_array($cat, ['todas','justificativa','correcao'], true)) $cat = 'todas';
if (!in_array($status, ['pendente','aprovada','rejeitada','todos'], true)) $status = 'pendente';

// Isolamento: quem não pode decidir só enxerga as próprias solicitações
$somente = $pode_decidir ? null : (int)$user['id'];
$lista = jus_listar_empresa($emp_id, $cat, $status, 300, $somente);
$pend_total = $pode_decidir ? jus_contar_pendentes($emp_id) : 0;

// Conta horas extras pendentes (para o atalho de aprovação conjunta)
$he_pend = 0;
try {
    $st = db()->prepare("SELECT COUNT(*) FROM dot_horas_extras he JOIN dot_usuarios u ON u.id=he.usuario_id
                         WHERE u.empresa_id=? AND he.status='pendente' AND he.minutos_solicitados>0");
    $st->execute([$emp_id]); $he_pend = (int)$st->fetchColumn();
} catch (Throwable $e) {}
?>
<style>
.aprov-toolbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:16px}
.aprov-toolbar .sep{flex:1}
.jcard{background:#fff;border:1px solid #e2e8f0;border-left:5px solid #94a3b8;border-radius:12px;padding:16px;margin-bottom:12px}
.jcard.correcao{border-left-color:#0284c7}
.jcard.justificativa{border-left-color:#8b5cf6}
.jcard.aprovada{border-left-color:#10b981}
.jcard.rejeitada{border-left-color:#ef4444}
.jcard .jhead{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.jcard .jhead strong{font-size:1.02rem}
.jcard .jhead .who-meta{color:#64748b;font-size:.82rem}
.jbadge{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:3px 9px;border-radius:20px}
.jbadge.cat-correcao{background:#dbeafe;color:#1e3a8a}
.jbadge.cat-justificativa{background:#ede9fe;color:#5b21b6}
.jbadge.st-pendente{background:#fef3c7;color:#92400e}
.jbadge.st-aprovada{background:#d1fae5;color:#065f46}
.jbadge.st-rejeitada{background:#fee2e2;color:#991b1b}
.jbody p{margin:3px 0;font-size:.9rem;color:#334155}
.jbody .lbl{color:#64748b;font-weight:600}
.correcao-box{background:#f0f9ff;border:1px dashed #7dd3fc;border-radius:8px;padding:10px 12px;margin:8px 0;font-size:.88rem}
.jactions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px;padding-top:12px;border-top:1px solid #f1f5f9}
.jactions input[type=text]{flex:1;min-width:220px;padding:9px 12px;border:1.5px solid #cbd5e1;border-radius:8px}
.anexo-link{display:inline-flex;align-items:center;gap:6px;background:#eff6ff;color:#1d4ed8;padding:6px 12px;border-radius:8px;text-decoration:none;font-size:.84rem;font-weight:600;margin-top:6px}
.alert-info{background:#dbeafe;color:#1e3a8a;border:1px solid #93c5fd;border-radius:10px;padding:12px 14px;margin-bottom:14px}
</style>

<?php if ($msg): ?><div class="alert alert-<?= $msg_tipo==='ok'?'ok':'erro' ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="alert alert-info" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <span>📋 Central de aprovações: <strong><?= $pend_total ?></strong> justificativa(s)/correção(ões) pendente(s).</span>
    <a href="horas_extras.php" class="btn btn-secondary">⏰ Horas extras<?= $he_pend>0 ? " ($he_pend)" : '' ?> →</a>
</div>

<div class="aprov-toolbar">
    <strong style="color:#64748b;font-size:.8rem">Categoria:</strong>
    <a href="?cat=todas&status=<?= $status ?>" class="btn <?= $cat==='todas'?'btn-primary':'btn-secondary' ?>">Todas</a>
    <a href="?cat=justificativa&status=<?= $status ?>" class="btn <?= $cat==='justificativa'?'btn-primary':'btn-secondary' ?>">📝 Justificativas</a>
    <a href="?cat=correcao&status=<?= $status ?>" class="btn <?= $cat==='correcao'?'btn-primary':'btn-secondary' ?>">🛠 Correções</a>
    <span class="sep"></span>
    <strong style="color:#64748b;font-size:.8rem">Status:</strong>
    <a href="?cat=<?= $cat ?>&status=pendente" class="btn <?= $status==='pendente'?'btn-primary':'btn-secondary' ?>">Pendentes</a>
    <a href="?cat=<?= $cat ?>&status=aprovada" class="btn <?= $status==='aprovada'?'btn-primary':'btn-secondary' ?>">Aprovadas</a>
    <a href="?cat=<?= $cat ?>&status=rejeitada" class="btn <?= $status==='rejeitada'?'btn-primary':'btn-secondary' ?>">Rejeitadas</a>
    <a href="?cat=<?= $cat ?>&status=todos" class="btn <?= $status==='todos'?'btn-primary':'btn-secondary' ?>">Todas</a>
</div>

<div class="panel">
<?php foreach ($lista as $j):
    $is_corr = $j['categoria'] === 'correcao';
    $tipo_label = jus_label_tipo($is_corr ? ($j['batida_tipo'] ?: 'esquecimento') : $j['tipo']);
?>
    <div class="jcard <?= $j['categoria'] ?> <?= $j['status']!=='pendente' ? $j['status'] : '' ?>">
        <div class="jhead">
            <strong><?= htmlspecialchars($j['nome_completo']) ?></strong>
            <span class="who-meta"><?= htmlspecialchars($j['matricula']) ?> · enviado <?= date('d/m/Y H:i', strtotime($j['solicitado_em'])) ?></span>
            <span class="jbadge cat-<?= $j['categoria'] ?>"><?= $is_corr ? '🛠 Correção' : '📝 Justificativa' ?></span>
            <span class="jbadge st-<?= $j['status'] ?>"><?= $j['status'] ?></span>
        </div>
        <div class="jbody">
            <p><span class="lbl">Data de referência:</span> <?= date('d/m/Y', strtotime($j['data_ref'])) ?></p>
            <p><span class="lbl"><?= $is_corr ? 'Batida a corrigir' : 'Tipo' ?>:</span> <?= htmlspecialchars($tipo_label) ?></p>
            <?php if ($is_corr): ?>
                <div class="correcao-box">
                    ⏱ Inserir batida de <strong><?= htmlspecialchars($tipo_label) ?></strong>
                    às <strong><?= $j['horario_correto'] ? substr($j['horario_correto'],0,5) : '--:--' ?></strong>
                    em <strong><?= date('d/m/Y', strtotime($j['data_ref'])) ?></strong>.
                    <?php if ($j['batida_id']): ?><br><small>✅ Batida registrada (id <?= (int)$j['batida_id'] ?>).</small><?php endif; ?>
                </div>
            <?php endif; ?>
            <p><span class="lbl">Motivo:</span> <?= nl2br(htmlspecialchars($j['motivo'])) ?></p>
            <?php if (!empty($j['anexo_arquivo'])): ?>
                <a class="anexo-link" href="anexo.php?id=<?= (int)$j['id'] ?>" target="_blank">📎 Ver comprovação<?= $j['anexo_nome_original'] ? ' · '.htmlspecialchars($j['anexo_nome_original']) : '' ?></a>
            <?php endif; ?>
            <?php if ($j['status'] !== 'pendente'): ?>
                <p style="margin-top:8px"><span class="lbl">Decisão:</span> <?= date('d/m/Y H:i', strtotime($j['decidido_em'])) ?>
                <?php if ($j['motivo_decisao']): ?> · <em><?= htmlspecialchars($j['motivo_decisao']) ?></em><?php endif; ?></p>
            <?php endif; ?>
        </div>
        <?php if ($j['status'] === 'pendente' && $pode_decidir): ?>
        <form method="post" class="jactions">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
            <input type="text" name="motivo" placeholder="Observação da decisão (opcional)">
            <button name="acao" value="aprovar" class="btn btn-success">✓ Aprovar<?= $is_corr ? ' e registrar batida' : '' ?></button>
            <button name="acao" value="rejeitar" class="btn btn-danger">✗ Rejeitar</button>
        </form>
        <?php endif; ?>
    </div>
<?php endforeach; if (!$lista): ?>
    <p class="empty">Nenhuma solicitação <?= $status!=='todos' ? htmlspecialchars($status) : '' ?> nesta categoria.</p>
<?php endif; ?>
</div>

</main></body></html>
