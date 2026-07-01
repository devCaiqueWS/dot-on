<?php
$titulo = 'Empresa · Dados Fiscais'; $pagina = 'empresa';
require __DIR__ . '/_layout.php';

if (!in_array($user['perfil'], ['admin','rh'])) {
    echo "<p>Acesso restrito.</p></main></body></html>"; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = $_POST;
    db()->prepare("UPDATE dot_empresas SET
        cnpj=?, cei=?, caepf=?, cno=?, razao_social=?, nome_fantasia=?,
        endereco=?, cep=?, cidade=?, uf=?,
        cnae=?, modelo_rep=?, num_fabricacao=?, versao_layout=?
        WHERE id=?")->execute([
        $f['cnpj'], $f['cei'] ?: null, $f['caepf'] ?: null, $f['cno'] ?: null,
        $f['razao_social'], $f['nome_fantasia'] ?: null,
        $f['endereco'] ?: null, $f['cep'] ?: null, $f['cidade'] ?: null, $f['uf'] ?: null,
        $f['cnae'] ?: null, $f['modelo_rep'] ?: 'REP-P', $f['num_fabricacao'] ?: null, $f['versao_layout'] ?: '003',
        $user['empresa_id']
    ]);
    auditar($user['id'], 'empresa_atualizada', 'empresa', $user['empresa_id'], $f);
    $msg = "Dados atualizados.";
}

$stmt = db()->prepare("SELECT * FROM dot_empresas WHERE id=?");
$stmt->execute([$user['empresa_id']]);
$e = $stmt->fetch();
?>
<?php if (!empty($msg)): ?><div class="alert alert-ok"><?= $msg ?></div><?php endif; ?>

<div class="panel">
    <h2>🏢 Identificação Fiscal da Empresa</h2>
    <p style="color:#6b7280;font-size:13px;margin-bottom:16px">
        Esses dados são exportados no cabeçalho do AFD/AEJ conforme Portaria MTP 671/2021.
        Mantenha-os atualizados.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
            <label class="fld"><span>CNPJ *</span>
                <input name="cnpj" value="<?= htmlspecialchars($e['cnpj']) ?>" placeholder="00.000.000/0000-00" required>
            </label>
            <label class="fld"><span>CEI / NIT</span>
                <input name="cei" value="<?= htmlspecialchars($e['cei'] ?? '') ?>" placeholder="000000000000">
            </label>
            <label class="fld"><span>CAEPF</span>
                <input name="caepf" value="<?= htmlspecialchars($e['caepf'] ?? '') ?>" placeholder="00.000.000/000-00">
            </label>
            <label class="fld"><span>CNO (Obras)</span>
                <input name="cno" value="<?= htmlspecialchars($e['cno'] ?? '') ?>">
            </label>
            <label class="fld" style="grid-column:span 2"><span>Razão Social *</span>
                <input name="razao_social" value="<?= htmlspecialchars($e['razao_social']) ?>" required>
            </label>
            <label class="fld" style="grid-column:span 2"><span>Nome Fantasia</span>
                <input name="nome_fantasia" value="<?= htmlspecialchars($e['nome_fantasia'] ?? '') ?>">
            </label>
            <label class="fld" style="grid-column:span 2"><span>Endereço (Local de prestação do serviço)</span>
                <input name="endereco" value="<?= htmlspecialchars($e['endereco'] ?? '') ?>" placeholder="Rua, número, bairro">
            </label>
            <label class="fld"><span>CEP</span>
                <input name="cep" value="<?= htmlspecialchars($e['cep'] ?? '') ?>">
            </label>
            <label class="fld"><span>Cidade</span>
                <input name="cidade" value="<?= htmlspecialchars($e['cidade'] ?? '') ?>">
            </label>
            <label class="fld"><span>UF</span>
                <input name="uf" value="<?= htmlspecialchars($e['uf'] ?? '') ?>" maxlength="2">
            </label>
            <label class="fld"><span>CNAE</span>
                <input name="cnae" value="<?= htmlspecialchars($e['cnae'] ?? '') ?>" placeholder="0000-0/00">
            </label>
        </div>
        <h3 style="margin-top:20px;font-size:15px">📟 Identificação do REP-P</h3>
        <div style="display:grid;grid-template-columns:1fr 2fr 1fr;gap:16px">
            <label class="fld"><span>Modelo</span>
                <input name="modelo_rep" value="<?= htmlspecialchars($e['modelo_rep'] ?? 'REP-P') ?>">
            </label>
            <label class="fld"><span>Número de Fabricação (17 char)</span>
                <input name="num_fabricacao" value="<?= htmlspecialchars($e['num_fabricacao'] ?? '') ?>" maxlength="17">
            </label>
            <label class="fld"><span>Versão Layout</span>
                <input name="versao_layout" value="<?= htmlspecialchars($e['versao_layout'] ?? '003') ?>" maxlength="3">
            </label>
        </div>
        <button class="btn btn-primary" style="margin-top:16px">💾 Salvar dados da empresa</button>
    </form>
</div>

<style>
.fld { display:flex; flex-direction:column; font-size:12px; color:#6b7280; font-weight:600 }
.fld input { padding:9px 12px; border:1px solid #e5e7eb; border-radius:6px; font-size:14px; margin-top:4px; color:#1f2937 }
</style>
</main></body></html>
