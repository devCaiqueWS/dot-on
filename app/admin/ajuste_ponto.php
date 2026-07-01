<?php
$titulo = 'Ajuste de Ponto & Jornada'; $pagina = 'ajuste_ponto';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/ajuste_ponto.php';

// Função restrita a gestores/admins
if (!in_array($user['perfil'], ['admin','gestor','rh'], true)) {
    echo '<div class="alert alert-erro">Acesso restrito a gestores e administradores.</div></main></body></html>';
    exit;
}

$emp_id = (int)$user['empresa_id'];
$msg = ''; $msg_tipo = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';
    if ($acao === 'add_batida') {
        $r = ap_adicionar_batida($emp_id, (int)$_POST['usuario_id'], $_POST['tipo'] ?? '',
            $_POST['data'] ?? '', $_POST['hora'] ?? '', (int)$user['id'], trim($_POST['motivo'] ?? ''));
    } elseif ($acao === 'anular') {
        $r = ap_anular_batida((int)$_POST['batida_id'], $emp_id, (int)$user['id'], trim($_POST['motivo'] ?? ''));
    } elseif ($acao === 'corrigir') {
        $r = ap_corrigir_horario((int)$_POST['batida_id'], $emp_id, $_POST['nova_hora'] ?? '', (int)$user['id'], trim($_POST['motivo'] ?? ''));
    } elseif ($acao === 'editar_escala') {
        $r = ap_editar_escala((int)$_POST['escala_id'], $emp_id, $_POST, (int)$user['id']);
    } else {
        $r = ['ok'=>false, 'msg'=>'Ação inválida.'];
    }
    $msg = $r['msg']; $msg_tipo = $r['ok'] ? 'ok' : 'error';
}

// Filtros (mantém o funcionário/data selecionados após qualquer ação POST)
$func = (int)($_GET['func'] ?? $_POST['func'] ?? $_POST['usuario_id'] ?? 0);
$data = $_GET['data'] ?? $_POST['data'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) $data = date('Y-m-d');

$funcs = db()->prepare("SELECT id, nome_completo, matricula FROM dot_usuarios WHERE empresa_id=? AND ativo=1 ORDER BY nome_completo");
$funcs->execute([$emp_id]); $funcs = $funcs->fetchAll();

$batidas = $func ? ap_batidas_do_dia($emp_id, $func, $data) : [];
$escala  = $func ? ap_escala_do_funcionario($func, $emp_id) : null;
$func_nome = '';
foreach ($funcs as $f) if ((int)$f['id'] === $func) $func_nome = $f['nome_completo'];

$ICON = ['entrada'=>'▶','saida_intervalo'=>'⏸','retorno_intervalo'=>'⏯','saida'=>'⏹'];
?>
<style>
.ap-grid{display:grid;grid-template-columns:1fr;gap:16px}
.ap-batida{display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:8px}
.ap-batida.anulada{opacity:.6;background:#fef2f2;border-color:#fecaca}
.ap-batida.anulada .hora{text-decoration:line-through}
.ap-batida .ic{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0}
.ic.entrada{background:#16a34a}.ic.saida_intervalo{background:#f59e0b}.ic.retorno_intervalo{background:#2563eb}.ic.saida{background:#dc2626}
.ap-batida .det{flex:1}
.ap-batida .hora{font-size:1.1rem;font-weight:700;color:#0c4a6e}
.ap-batida .sub{font-size:.76rem;color:#64748b}
.ap-batida .acts{display:flex;gap:6px}
.mini-form{display:none;gap:6px;align-items:center;margin-top:8px;flex-wrap:wrap}
.mini-form.show{display:flex}
.mini-form input[type=text],.mini-form input[type=time]{padding:7px 10px;border:1.5px solid #cbd5e1;border-radius:7px}
.mini-form input[type=text]{flex:1;min-width:200px}
.tagx{font-size:.66rem;font-weight:700;text-transform:uppercase;padding:2px 7px;border-radius:12px;background:#fee2e2;color:#991b1b}
.jornada-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;align-items:end}
.jornada-form label{font-size:.8rem;font-weight:600;color:#475569;display:block}
.jornada-form input{width:100%;padding:9px 11px;border:1.5px solid #cbd5e1;border-radius:8px;margin-top:4px}
</style>

<?php if ($msg): ?><div class="alert alert-<?= $msg_tipo==='ok'?'ok':'erro' ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="alert alert-info" style="background:#dbeafe;color:#1e3a8a;border:1px solid #93c5fd;border-radius:10px;padding:12px 14px;margin-bottom:14px">
    🛠 Ajustes de ponto ficam <strong>registrados na auditoria</strong>. Anular uma batida <strong>não a apaga</strong> — ela sai do espelho e do cálculo de horas, mas continua no arquivo fiscal (AFD) para preservar a integridade legal (Portaria MTP 671).
</div>

<div class="panel">
    <form method="get" class="form-inline">
        <label>Funcionário
            <select name="func" required>
                <option value="0">— selecione —</option>
                <?php foreach ($funcs as $f): ?>
                    <option value="<?= $f['id'] ?>" <?= $func===(int)$f['id']?'selected':'' ?>><?= htmlspecialchars($f['nome_completo']) ?> (<?= htmlspecialchars($f['matricula']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Data <input type="date" name="data" value="<?= htmlspecialchars($data) ?>" max="<?= date('Y-m-d') ?>"></label>
        <button class="btn btn-primary">Ver batidas</button>
    </form>
</div>

<?php if ($func): ?>
<div class="ap-grid">

    <!-- BATIDAS DO DIA -->
    <div class="panel">
        <h2>⏱ Batidas de <?= htmlspecialchars($func_nome) ?> em <?= date('d/m/Y', strtotime($data)) ?></h2>

        <?php if (!$batidas): ?>
            <p class="empty">Nenhuma batida neste dia.</p>
        <?php else: foreach ($batidas as $b):
            $anulada = (int)($b['cancelada'] ?? 0) === 1; ?>
            <div class="ap-batida <?= $anulada?'anulada':'' ?>">
                <div class="ic <?= $b['tipo'] ?>"><?= $ICON[$b['tipo']] ?? '·' ?></div>
                <div class="det">
                    <span class="hora"><?= date('H:i', strtotime($b['momento'])) ?></span>
                    <span style="color:#475569;font-size:.9rem"><?= jus_label_tipo($b['tipo']) ?></span>
                    <?php if ($anulada): ?><span class="tagx">anulada</span><?php endif; ?>
                    <div class="sub">NSR <?= str_pad($b['nsr'],6,'0',STR_PAD_LEFT) ?> · origem <?= htmlspecialchars($b['origem']) ?><?= $b['extemporanea']?' · extemporânea':'' ?>
                        <?php if ($anulada): ?><br>Motivo: <?= htmlspecialchars($b['cancelada_motivo']) ?> (<?= $b['cancelada_em']?date('d/m H:i',strtotime($b['cancelada_em'])):'' ?>)<?php endif; ?>
                    </div>
                    <?php if (!$anulada): ?>
                    <form method="post" class="mini-form" id="corr-<?= $b['id'] ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="corrigir">
                        <input type="hidden" name="batida_id" value="<?= $b['id'] ?>">
                        <input type="hidden" name="func" value="<?= $func ?>"><input type="hidden" name="data" value="<?= $data ?>">
                        <input type="time" name="nova_hora" value="<?= date('H:i', strtotime($b['momento'])) ?>" required>
                        <input type="text" name="motivo" placeholder="Motivo da correção" required>
                        <button class="btn btn-sm btn-primary">Salvar horário</button>
                    </form>
                    <form method="post" class="mini-form" id="anul-<?= $b['id'] ?>" onsubmit="return confirm('Anular esta batida? Ela sairá do espelho e do cálculo de horas.')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="anular">
                        <input type="hidden" name="batida_id" value="<?= $b['id'] ?>">
                        <input type="hidden" name="func" value="<?= $func ?>"><input type="hidden" name="data" value="<?= $data ?>">
                        <input type="text" name="motivo" placeholder="Motivo da anulação (bateu por engano, etc.)" required>
                        <button class="btn btn-sm btn-danger">Confirmar anulação</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php if (!$anulada): ?>
                <div class="acts">
                    <button class="btn btn-sm" onclick="toggle('corr-<?= $b['id'] ?>')" title="Corrigir horário">✏️</button>
                    <button class="btn btn-sm" onclick="toggle('anul-<?= $b['id'] ?>')" title="Anular (bateu por engano)">🗑</button>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <!-- ADICIONAR BATIDA -->
    <div class="panel">
        <h2>➕ Adicionar batida (esqueceu de bater)</h2>
        <form method="post" class="form-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="add_batida">
            <input type="hidden" name="usuario_id" value="<?= $func ?>">
            <input type="hidden" name="func" value="<?= $func ?>">
            <label>Data <input type="date" name="data" value="<?= htmlspecialchars($data) ?>" max="<?= date('Y-m-d') ?>" required></label>
            <label>Batida
                <select name="tipo" required>
                    <option value="entrada">Entrada</option>
                    <option value="saida_intervalo">Saída p/ intervalo</option>
                    <option value="retorno_intervalo">Retorno do intervalo</option>
                    <option value="saida">Saída</option>
                </select>
            </label>
            <label>Horário <input type="time" name="hora" required></label>
            <input type="text" name="motivo" placeholder="Motivo (esqueceu de bater, etc.)" required style="flex:1;min-width:220px">
            <button class="btn btn-success">Adicionar à cadeia</button>
        </form>
    </div>

    <!-- JORNADA / ESCALA -->
    <div class="panel">
        <h2>🕗 Jornada (escala) do funcionário</h2>
        <?php if (!$escala): ?>
            <p class="empty">Este funcionário não tem escala atribuída.</p>
        <?php else: ?>
            <?php if ($escala['_compartilhada_por'] > 1): ?>
            <div class="alert alert-erro" style="background:#fff7ed;color:#9a3412;border:1px solid #fed7aa">
                ⚠️ Esta escala <strong>"<?= htmlspecialchars($escala['nome']) ?>"</strong> é usada por <strong><?= $escala['_compartilhada_por'] ?> funcionários</strong>. Alterá-la muda a jornada de todos eles.
            </div>
            <?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="editar_escala">
                <input type="hidden" name="escala_id" value="<?= $escala['id'] ?>">
                <input type="hidden" name="func" value="<?= $func ?>"><input type="hidden" name="data" value="<?= $data ?>">
                <div class="jornada-form">
                    <div><label>Nome da escala</label><input type="text" name="nome" value="<?= htmlspecialchars($escala['nome']) ?>"></div>
                    <div><label>Entrada</label><input type="time" name="entrada" value="<?= substr($escala['entrada'],0,5) ?>" required></div>
                    <div><label>Início intervalo</label><input type="time" name="intervalo_inicio" value="<?= $escala['intervalo_inicio']?substr($escala['intervalo_inicio'],0,5):'' ?>"></div>
                    <div><label>Fim intervalo</label><input type="time" name="intervalo_fim" value="<?= $escala['intervalo_fim']?substr($escala['intervalo_fim'],0,5):'' ?>"></div>
                    <div><label>Saída</label><input type="time" name="saida" value="<?= substr($escala['saida'],0,5) ?>" required></div>
                    <div><label>Carga diária (min)</label><input type="number" name="carga_diaria_minutos" value="<?= (int)$escala['carga_diaria_minutos'] ?>" min="0" max="1440"></div>
                    <div><label>Tolerância (min)</label><input type="number" name="tolerancia_minutos" value="<?= (int)$escala['tolerancia_minutos'] ?>" min="0" max="120"></div>
                    <div><button class="btn btn-primary" style="width:100%">Salvar jornada</button></div>
                </div>
            </form>
        <?php endif; ?>
    </div>

</div>
<?php else: ?>
<div class="panel"><p class="empty">Selecione um funcionário e a data para ver e ajustar as batidas.</p></div>
<?php endif; ?>

<script>
function toggle(id){ var el=document.getElementById(id); if(el) el.classList.toggle('show'); }
</script>

</main></body></html>
