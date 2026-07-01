<?php
/**
 * DOT-ON · Validador Público de Batidas (REP-P)
 * ============================================
 * URL pública: /app/validar.php?nsr=XXX
 * Aceita QR Code de qualquer CRP. Mostra status de autenticidade.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/repp.php';

$nsr = (int)($_GET['nsr'] ?? 0);
$qr_token = trim((string)($_GET['t'] ?? ''));

$resultado = null;
$falta_token = false;
if ($qr_token !== '') {
    // Identifica a batida pelo qr_token do CRP (único por batida).
    // O NSR é sequencial POR EMPRESA, logo não identifica o registro sozinho.
    $stmt = db()->prepare("SELECT b.*, u.nome_completo, u.matricula, u.cpf, u.pis,
        e.razao_social, e.cnpj, e.cei, e.modelo_rep, e.num_fabricacao,
        c.qr_token AS crp_qr, c.hash_documento AS crp_hash
        FROM dot_crp c
        JOIN dot_batidas b ON b.id=c.batida_id
        JOIN dot_usuarios u ON u.id=b.usuario_id
        JOIN dot_empresas e ON e.id=b.empresa_id
        WHERE c.qr_token = ? LIMIT 1");
    $stmt->execute([$qr_token]);
    $b = $stmt->fetch();
    if ($b) {
        // Recalcula hash
        $recalc = repp_hash_batida([
            'nsr' => $b['nsr'], 'empresa_id' => $b['empresa_id'], 'usuario_id' => $b['usuario_id'],
            'cpf_snapshot' => $b['cpf_snapshot'], 'pis_snapshot' => $b['pis_snapshot'],
            'tipo' => $b['tipo'], 'momento' => $b['momento'],
        ], $b['hash_anterior']);
        $integro = ($recalc === $b['hash_registro']);

        // O token bateu (lookup por qr_token); confirma também que o NSR informado, se houver, corresponde
        $qr_valido = ($nsr === 0 || (int)$b['nsr'] === $nsr);

        // Valida cadeia desde esta batida até a anterior
        $cadeia = repp_validar_cadeia((int)$b['empresa_id'], max(1, $b['nsr']-1), $b['nsr']);

        $resultado = [
            'batida' => $b,
            'integro' => $integro,
            'qr_valido' => $qr_valido,
            'cadeia_ok' => $cadeia['ok'],
            'hash_recalc' => $recalc,
        ];
    }
} elseif ($nsr > 0) {
    // NSR digitado manualmente, sem token: não é possível identificar o registro com segurança.
    $falta_token = true;
}

$tipos = [
    'entrada' => '🟢 Entrada',
    'saida_intervalo' => '🟡 Saída de Intervalo',
    'retorno_intervalo' => '🟢 Retorno de Intervalo',
    'saida' => '🔴 Saída',
    'extra_inicio' => '🔵 Início Hora Extra',
    'extra_fim' => '🔵 Fim Hora Extra',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Validador de Registro de Ponto · DOT-ON</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Roboto, sans-serif; }
body { background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); min-height: 100vh; padding: 20px; color: #1f2937; }
.box { max-width: 600px; margin: 30px auto; background: #fff; border-radius: 12px; padding: 32px; box-shadow: 0 20px 50px rgba(0,0,0,.3); }
h1 { color: #2563eb; margin-bottom: 20px; font-size: 24px; }
.search { display: flex; gap: 8px; margin-bottom: 24px; }
.search input { flex: 1; padding: 12px 16px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 16px; }
.search button { padding: 12px 24px; background: #2563eb; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; }
.status { padding: 20px; border-radius: 8px; text-align: center; margin-bottom: 20px; }
.status.ok { background: #dcfce7; color: #166534; border: 2px solid #16a34a; }
.status.fail { background: #fee2e2; color: #991b1b; border: 2px solid #dc2626; }
.status.warn { background: #fef3c7; color: #854d0e; border: 2px solid #f59e0b; }
.status h2 { font-size: 28px; margin-bottom: 6px; }
.status p { font-size: 14px; }
.info { background: #f9fafb; padding: 16px; border-radius: 8px; margin-bottom: 16px; }
.info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #e5e7eb; font-size: 14px; }
.info-row:last-child { border-bottom: none; }
.info-row b { color: #4b5563; }
.hash { font-family: 'Courier New', monospace; font-size: 11px; background: #fff; padding: 8px; border-radius: 4px; word-break: break-all; margin-top: 6px; border: 1px solid #e5e7eb; }
.nsr-display { background: #1f2937; color: #fff; text-align: center; padding: 16px; border-radius: 8px; margin-bottom: 16px; font-size: 24px; font-weight: bold; letter-spacing: 4px; }
.checks { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 16px 0; }
.check { text-align: center; padding: 12px; background: #f9fafb; border-radius: 8px; }
.check.ok { background: #dcfce7; }
.check.fail { background: #fee2e2; }
.check .icon { font-size: 24px; }
.check .lbl { font-size: 11px; margin-top: 4px; color: #4b5563; }
.foot { text-align: center; color: #6b7280; font-size: 12px; margin-top: 20px; }
.foot a { color: #2563eb; }
.alert { padding: 16px; border-radius: 8px; margin: 16px 0; }
.alert.info { background: #dbeafe; color: #1e40af; }
</style>
</head>
<body>
<div class="box">
    <h1>🔍 Validador de Ponto Eletrônico</h1>
    <p style="color:#6b7280;margin-bottom:20px;font-size:14px">
        Verifique a autenticidade de um registro de ponto pelo NSR (Número Sequencial de Registro).
        Sistema em conformidade com a Portaria MTP 671/2021.
    </p>

    <form method="get" class="search">
        <input type="number" name="nsr" placeholder="Digite o NSR (ex: 12345)" value="<?= $nsr > 0 ? $nsr : '' ?>" required>
        <button>Validar</button>
    </form>

    <?php if (!empty($falta_token)): ?>
        <div class="status warn">
            <h2>🔒 Use o QR Code do comprovante</h2>
            <p>Para validar um registro, escaneie o QR Code do CRP (ele contém o token de verificação).
            O NSR sozinho não identifica o comprovante.</p>
        </div>
    <?php elseif ($qr_token !== '' && !$resultado): ?>
        <div class="status fail">
            <h2>❌ Comprovante não encontrado</h2>
            <p>O token informado não corresponde a nenhum registro.</p>
        </div>
    <?php elseif ($resultado): $r = $resultado; $b = $r['batida']; ?>
        <?php
        $status_geral = $r['integro'] && $r['cadeia_ok'] && $r['qr_valido'] !== false;
        ?>
        <div class="status <?= $status_geral ? 'ok' : 'fail' ?>">
            <h2><?= $status_geral ? '✅ Registro Autêntico' : '⚠️ Registro com Divergências' ?></h2>
            <p><?= $status_geral
                ? 'Todos os controles de integridade foram verificados com sucesso.'
                : 'Foram detectadas divergências - este registro pode ter sido adulterado.' ?></p>
        </div>

        <div class="nsr-display">NSR <?= str_pad($b['nsr'], 9, '0', STR_PAD_LEFT) ?></div>

        <div class="checks">
            <div class="check <?= $r['integro']?'ok':'fail' ?>">
                <div class="icon"><?= $r['integro']?'✓':'✗' ?></div>
                <div class="lbl">Hash íntegro</div>
            </div>
            <div class="check <?= $r['cadeia_ok']?'ok':'fail' ?>">
                <div class="icon"><?= $r['cadeia_ok']?'✓':'✗' ?></div>
                <div class="lbl">Cadeia válida</div>
            </div>
            <div class="check <?= $r['qr_valido']===null?'':($r['qr_valido']?'ok':'fail') ?>">
                <div class="icon"><?= $r['qr_valido']===null?'?':($r['qr_valido']?'✓':'✗') ?></div>
                <div class="lbl">QR <?= $r['qr_valido']===null?'(não informado)':'verificado' ?></div>
            </div>
        </div>

        <div class="info">
            <div class="info-row"><b>Tipo de batida:</b><span><?= $tipos[$b['tipo']] ?? $b['tipo'] ?></span></div>
            <div class="info-row"><b>Data/Hora:</b><span><?= date('d/m/Y H:i:s', strtotime($b['momento'])) ?></span></div>
            <div class="info-row"><b>Funcionário:</b><span><?= htmlspecialchars($b['nome_completo']) ?> (<?= htmlspecialchars($b['matricula']) ?>)</span></div>
            <div class="info-row"><b>CPF:</b><span><?= htmlspecialchars($b['cpf']) ?></span></div>
            <div class="info-row"><b>PIS:</b><span><?= htmlspecialchars($b['pis']) ?></span></div>
        </div>

        <div class="info">
            <div class="info-row"><b>Empregador:</b><span><?= htmlspecialchars($b['razao_social']) ?></span></div>
            <div class="info-row"><b>CNPJ:</b><span><?= htmlspecialchars($b['cnpj']) ?></span></div>
            <div class="info-row"><b>REP:</b><span><?= htmlspecialchars($b['modelo_rep']) ?> · <?= htmlspecialchars($b['num_fabricacao']) ?></span></div>
        </div>

        <div class="info">
            <div class="info-row"><b>Algoritmo:</b><span><?= $b['hash_alg'] ?? 'SHA-256' ?></span></div>
            <div class="info-row"><b>Hash do registro:</b></div>
            <div class="hash"><?= htmlspecialchars($b['hash_registro']) ?></div>
            <?php if ($b['hash_anterior']): ?>
            <div class="info-row" style="margin-top:8px"><b>Hash anterior (cadeia):</b></div>
            <div class="hash"><?= htmlspecialchars($b['hash_anterior']) ?></div>
            <?php endif; ?>
        </div>

        <?php if (!$status_geral): ?>
        <div class="alert" style="background:#fef3c7;color:#92400e">
            <b>⚠ Atenção:</b> Este registro apresenta divergências entre o hash armazenado e o hash recalculado.
            Isso pode indicar tentativa de adulteração. Em caso de fiscalização, este alerta deve ser investigado.
        </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="alert info">
            💡 <b>Como funciona:</b> Digite o NSR (Número Sequencial de Registro) impresso no Comprovante de Registro de Ponto (CRP).
            Você também pode escanear o QR Code do CRP com a câmera do celular — o link já contém o NSR e o token.
        </div>
    <?php endif; ?>

    <div class="foot">
        DOT-ON v1.0 · Sistema de Registro de Ponto<br>
        Conforme Portaria MTP 671/2021 · <a href="index.php">Voltar à página inicial</a>
    </div>
</div>
</body>
</html>
