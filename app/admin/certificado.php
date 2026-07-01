<?php
$titulo = 'Certificado Digital ICP-Brasil'; $pagina = 'certificado';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/repp.php';

if (!in_array($user['perfil'], ['admin','rh'])) {
    echo "<p>Acesso restrito.</p></main></body></html>"; exit;
}

$msg = ''; $erro = '';
$cert_dir = __DIR__ . '/../config/';
$cert_filename = 'cert_empresa_' . $user['empresa_id'] . '.pfx';

// REMOVER certificado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remover'])) {
    if (file_exists($cert_dir . $cert_filename)) @unlink($cert_dir . $cert_filename);
    db()->prepare("UPDATE dot_empresas SET cert_arquivo=NULL, cert_senha_cifrada=NULL, cert_validade=NULL, cert_subject=NULL WHERE id=?")
        ->execute([$user['empresa_id']]);
    auditar($user['id'], 'cert_removido', 'empresa', $user['empresa_id']);
    $msg = "Certificado removido.";
}

// UPLOAD
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['pfx']['tmp_name'])) {
    $senha = $_POST['senha'] ?? '';
    if (!$senha) {
        $erro = "Senha do certificado é obrigatória.";
    } else {
        // Move arquivo
        $tmp = $_FILES['pfx']['tmp_name'];
        $size = filesize($tmp);
        if ($size > 2*1024*1024) {
            $erro = "Arquivo .pfx muito grande (limite 2MB).";
        } else {
            // Tenta abrir com a senha
            $pfx_content = file_get_contents($tmp);
            $certs = [];
            if (!openssl_pkcs12_read($pfx_content, $certs, $senha)) {
                $erro = "Falha ao abrir o .pfx. Senha incorreta ou arquivo inválido.";
            } else {
                // Salva no disco
                if (!is_dir($cert_dir)) mkdir($cert_dir, 0700, true);
                $destino = $cert_dir . $cert_filename;
                if (move_uploaded_file($tmp, $destino)) {
                    chmod($destino, 0600);
                    $info = repp_info_certificado($certs['cert']);
                    $senha_cifrada = repp_cifrar($senha, repp_chave_mestra());
                    db()->prepare("UPDATE dot_empresas SET cert_arquivo=?, cert_senha_cifrada=?, cert_validade=?, cert_subject=? WHERE id=?")
                        ->execute([$cert_filename, $senha_cifrada, $info['validade_ate'], substr($info['subject_cn'] ?? '', 0, 255), $user['empresa_id']]);
                    auditar($user['id'], 'cert_instalado', 'empresa', $user['empresa_id'], [
                        'subject' => $info['subject_cn'],
                        'validade' => $info['validade_ate'],
                        'cnpj_cert' => $info['cnpj'],
                    ]);
                    $msg = "✓ Certificado instalado com sucesso. Subject: <code>" . htmlspecialchars($info['subject_cn']) . "</code>";
                } else {
                    $erro = "Falha ao salvar o arquivo no servidor.";
                }
            }
        }
    }
}

$stmt = db()->prepare("SELECT cnpj, cert_arquivo, cert_validade, cert_subject FROM dot_empresas WHERE id=?");
$stmt->execute([$user['empresa_id']]);
$e = $stmt->fetch();
$tem_cert = !empty($e['cert_arquivo']) && file_exists($cert_dir . $e['cert_arquivo']);

$dias_validade = null;
if ($tem_cert && $e['cert_validade']) {
    $dias_validade = (strtotime($e['cert_validade']) - time()) / 86400;
}
?>
<?php if ($msg): ?><div class="alert alert-ok"><?= $msg ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-erro"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<div class="panel">
    <h2>🔐 Certificado Digital ICP-Brasil (A1)</h2>
    <p style="color:#6b7280;font-size:13px">
        Usado para assinar digitalmente os arquivos AFD/AEJ conforme Portaria MTP 671/2021.
        Aceita certificados <code>.pfx</code> ou <code>.p12</code> (formato PKCS#12).
    </p>

    <?php if ($tem_cert): ?>
        <div class="alert alert-ok" style="margin:16px 0">
            ✅ <b>Certificado instalado</b><br>
            <b>Subject:</b> <code><?= htmlspecialchars($e['cert_subject']) ?></code><br>
            <b>Válido até:</b> <?= date('d/m/Y', strtotime($e['cert_validade'])) ?>
            <?php if ($dias_validade !== null): ?>
                <?php if ($dias_validade < 0): ?>
                    <span style="color:#dc2626;font-weight:bold">⚠ EXPIRADO há <?= abs((int)$dias_validade) ?> dias</span>
                <?php elseif ($dias_validade < 30): ?>
                    <span style="color:#f59e0b;font-weight:bold">⚠ Expira em <?= (int)$dias_validade ?> dias</span>
                <?php else: ?>
                    <span style="color:#16a34a">✓ Válido (<?= (int)$dias_validade ?> dias restantes)</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <form method="post" onsubmit="return confirm('Remover certificado? As próximas assinaturas voltarão a ser SHA-256 sem ICP-Brasil.')">
            <button name="remover" value="1" class="btn btn-danger">🗑 Remover certificado</button>
        </form>
        <hr style="margin:20px 0">
        <h3 style="font-size:15px">📤 Substituir por outro certificado</h3>
    <?php else: ?>
        <div class="alert alert-warn" style="background:#fef3c7;color:#854d0e;border-left:4px solid #f59e0b">
            ⚠ Sem certificado instalado. As batidas continuarão sendo registradas com hash SHA-256 íntegro,
            mas os arquivos AFD/AEJ não terão assinatura ICP-Brasil.
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <label class="fld" style="display:block;margin-bottom:12px">
            <span>Arquivo .pfx / .p12</span>
            <input type="file" name="pfx" accept=".pfx,.p12" required>
        </label>
        <label class="fld" style="display:block;margin-bottom:12px">
            <span>Senha do certificado</span>
            <input type="password" name="senha" required autocomplete="new-password">
        </label>
        <p style="font-size:11px;color:#6b7280;margin-bottom:12px">
            🔒 A senha é cifrada com AES-256-GCM antes de ser armazenada. Apenas o servidor consegue decifrar.
        </p>
        <button class="btn btn-primary">⬆ Enviar certificado</button>
    </form>
</div>

<div class="panel">
    <h2>ℹ Sobre certificados ICP-Brasil</h2>
    <ul style="margin-left:20px;font-size:14px;line-height:1.8">
        <li><b>A1 (.pfx)</b>: arquivo digital, válido por 1 ano. Recomendado para servidores.</li>
        <li><b>A3 (token/cartão)</b>: hardware físico, válido até 5 anos. Não suportado neste painel (requer cliente USB).</li>
        <li>O CN do certificado deve incluir o CNPJ da empresa no formato <code>NOME:CNPJ</code> (padrão e-CNPJ).</li>
        <li>Emissores aceitos: Serasa, Certisign, AC SOLUTI, Valid, AC Caixa, AC Receita Federal, etc.</li>
    </ul>
</div>

<style>
.fld { display:flex; flex-direction:column; font-size:12px; color:#6b7280; font-weight:600 }
.fld input { padding:9px 12px; border:1px solid #e5e7eb; border-radius:6px; font-size:14px; margin-top:4px; color:#1f2937 }
.alert-warn { padding:12px;border-radius:6px;font-size:14px }
</style>
</main></body></html>
