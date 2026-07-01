<?php
$titulo = 'Dashboard'; $pagina = 'dashboard';
require __DIR__ . '/_layout.php';

$emp_id = $user['empresa_id'];
$hoje = date('Y-m-d');

// Todas as queries filtram por empresa
$st = db()->prepare("SELECT COUNT(*) FROM dot_usuarios WHERE empresa_id=? AND perfil='funcionario' AND ativo=1");
$st->execute([$emp_id]);
$total_func = $st->fetchColumn();

$st = db()->prepare("SELECT COUNT(*) FROM dot_sessoes s JOIN dot_usuarios u ON u.id=s.usuario_id WHERE u.empresa_id=? AND s.data_ref=?");
$st->execute([$emp_id, $hoje]);
$sessoes_hoje = $st->fetchColumn();

$st = db()->prepare("SELECT COUNT(*) FROM dot_horas_extras he JOIN dot_usuarios u ON u.id=he.usuario_id WHERE u.empresa_id=? AND he.status='pendente'");
$st->execute([$emp_id]);
$extras_pend = $st->fetchColumn();

$st = db()->prepare("SELECT COUNT(*) FROM dot_batidas WHERE empresa_id=? AND DATE(momento)=?");
$st->execute([$emp_id, $hoje]);
$batidas_hoje = $st->fetchColumn();

$st = db()->prepare("SELECT b.nsr, b.tipo, b.momento, u.nome_completo
    FROM dot_batidas b JOIN dot_usuarios u ON u.id=b.usuario_id
    WHERE b.empresa_id=?
    ORDER BY b.momento DESC LIMIT 15");
$st->execute([$emp_id]);
$ultimas = $st->fetchAll();
?>
<?php if (!empty($_GET['msg']) && $_GET['msg']==='senha_trocada'): ?>
<div style="background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;padding:12px 16px;border-radius:8px;margin-bottom:16px">
✅ <strong>Senha alterada com sucesso!</strong> Você já está usando sua nova senha.
</div>
<?php endif; ?>

<?php if ($empresa && $empresa['plano']==='free' && !empty($empresa['trial_expira'])): 
    $dias = max(0, round((strtotime($empresa['trial_expira']) - time()) / 86400));
?>
<div style="background:#fef3c7;border:1px solid #fcd34d;color:#92400e;padding:12px 16px;border-radius:8px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center">
<div>⏰ <strong>Plano Grátis</strong> · <?= $dias ?> dias de trial restantes</div>
<a href="#" style="background:#92400e;color:white;padding:6px 14px;border-radius:6px;text-decoration:none;font-size:.85rem;font-weight:600">Fazer upgrade</a>
</div>
<?php endif; ?>

<div class="cards">
    <div class="card"><div class="num"><?= $total_func ?></div><div class="lbl">Funcionários ativos</div></div>
    <div class="card"><div class="num"><?= $sessoes_hoje ?></div><div class="lbl">Sessões hoje</div></div>
    <div class="card"><div class="num"><?= $batidas_hoje ?></div><div class="lbl">Batidas hoje</div></div>
    <div class="card alert-card"><div class="num"><?= $extras_pend ?></div><div class="lbl">Extras pendentes</div></div>
</div>

<div class="panel">
    <h2>Últimas batidas da sua empresa</h2>
    <table class="tbl">
        <thead><tr><th>NSR</th><th>Funcionário</th><th>Tipo</th><th>Momento</th></tr></thead>
        <tbody>
        <?php foreach ($ultimas as $b): ?>
            <tr>
                <td><code><?= str_pad($b['nsr'], 9, '0', STR_PAD_LEFT) ?></code></td>
                <td><?= htmlspecialchars($b['nome_completo']) ?></td>
                <td><span class="tag tag-<?= $b['tipo'] ?>"><?= str_replace('_',' ',$b['tipo']) ?></span></td>
                <td><?= date('d/m/Y H:i:s', strtotime($b['momento'])) ?></td>
            </tr>
        <?php endforeach; if (!$ultimas): ?>
            <tr><td colspan="4" class="empty">📭 Nenhuma batida registrada ainda. <a href="downloads.php">Distribua o agente Windows</a> para seus funcionários começarem.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($total_func == 0): ?>
<div class="panel" style="background:linear-gradient(135deg,#dbeafe,#eff6ff);border:1px solid #93c5fd">
<h2 style="color:#1e3a8a">🚀 Próximos passos</h2>
<ol style="line-height:2;color:#1e40af">
<li><a href="funcionarios.php">Cadastre seus funcionários</a> (ou importe via planilha)</li>
<li><a href="empresa.php">Complete os dados da sua empresa</a> (endereço, CNAE, etc.)</li>
<li><a href="downloads.php">Distribua o DOT-ON-Agent.exe</a> para os funcionários</li>
<li>Acompanhe as batidas em tempo real aqui no Dashboard</li>
</ol>
</div>
<?php endif; ?>

</main></body></html>
