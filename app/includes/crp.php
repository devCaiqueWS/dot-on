<?php
/**
 * DOT-ON · CRP - Comprovante de Registro de Ponto
 * ===============================================
 * Conforme Portaria MTP 671/2021, Art. 78:
 * A cada batida o trabalhador tem direito a um comprovante contendo:
 *  - Identificação do empregador (razão social, CNPJ, CEI/CAEPF)
 *  - Local da prestação do serviço
 *  - Identificação do empregado (nome, PIS)
 *  - Data e hora da marcação
 *  - NSR (Número Sequencial de Registro)
 *  - Identificação do REP (modelo, número de fabricação)
 *  - Hash de validação
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/repp.php';
require_once __DIR__ . '/mailer.php';

/**
 * Gera o CRP em HTML imprimível (uma página A4 pequena, recibo).
 */
function crp_html(int $batida_id): ?string {
    $stmt = db()->prepare("SELECT b.*, u.nome_completo, u.matricula, u.cpf, u.pis,
        e.razao_social, e.cnpj, e.cei, e.endereco, e.cidade, e.uf, e.modelo_rep, e.num_fabricacao, e.versao_layout,
        c.qr_token
        FROM dot_batidas b
        JOIN dot_usuarios u ON u.id = b.usuario_id
        JOIN dot_empresas e ON e.id = b.empresa_id
        LEFT JOIN dot_crp c ON c.batida_id = b.id
        WHERE b.id = ?");
    $stmt->execute([$batida_id]);
    $d = $stmt->fetch();
    if (!$d) return null;

    $url_val = repp_url_validacao((int)$d['nsr'], $d['qr_token'] ?? '');
    $qr_img = "https://api.qrserver.com/v1/create-qr-code/?size=130x130&data=" . urlencode($url_val);

    $tipos_legivel = [
        'entrada' => 'ENTRADA',
        'saida_intervalo' => 'SAÍDA INTERVALO',
        'retorno_intervalo' => 'RETORNO INTERVALO',
        'saida' => 'SAÍDA',
        'extra_inicio' => 'INÍCIO H.EXTRA',
        'extra_fim'   => 'FIM H.EXTRA',
    ];

    ob_start();
?>
<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8">
<title>CRP NSR <?= str_pad($d['nsr'], 9, '0', STR_PAD_LEFT) ?></title>
<style>
@page { size: A6; margin: 8mm; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Courier New', monospace; font-size: 11px; color: #000; padding: 16px; max-width: 480px; margin: auto; }
.hdr { text-align: center; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 8px; }
.hdr h1 { font-size: 16px; font-weight: bold; }
.hdr small { font-size: 10px; }
.row { display: flex; justify-content: space-between; margin: 3px 0; padding-bottom: 2px; border-bottom: 1px dotted #999; }
.row b { font-weight: bold; }
.nsr-box { background: #000; color: #fff; text-align: center; padding: 8px; margin: 10px 0; font-size: 18px; font-weight: bold; letter-spacing: 2px; }
.qr-box { text-align: center; margin: 12px 0; }
.qr-box img { display: block; margin: 0 auto; }
.qr-box .url { font-size: 9px; color: #555; word-break: break-all; margin-top: 4px; }
.foot { border-top: 2px solid #000; margin-top: 8px; padding-top: 6px; font-size: 9px; text-align: center; }
.hash { font-family: monospace; font-size: 9px; word-break: break-all; background: #f0f0f0; padding: 4px; margin: 4px 0; }
.no-print { margin: 16px 0; text-align: center; }
@media print { .no-print { display: none; } body { padding: 0; } }
</style>
</head><body>
<div class="no-print">
    <button onclick="window.print()" style="padding:8px 20px;background:#2563eb;color:#fff;border:none;border-radius:4px;cursor:pointer">🖨 Imprimir</button>
</div>

<div class="hdr">
    <h1>COMPROVANTE DE REGISTRO DE PONTO</h1>
    <small>Portaria MTP 671/2021 · Art. 78</small>
</div>

<div class="row"><b>Empregador:</b> <?= htmlspecialchars($d['razao_social']) ?></div>
<div class="row"><b>CNPJ:</b> <?= htmlspecialchars($d['cnpj']) ?></div>
<?php if (!empty($d['cei'])): ?><div class="row"><b>CEI/CAEPF:</b> <?= htmlspecialchars($d['cei']) ?></div><?php endif; ?>
<?php if (!empty($d['endereco'])): ?><div class="row"><b>Local:</b> <?= htmlspecialchars(trim($d['endereco'].' '.$d['cidade'].'/'.$d['uf'], ' /')) ?></div><?php endif; ?>

<div style="border-top:1px solid #000;margin:6px 0"></div>

<div class="row"><b>Empregado:</b> <?= htmlspecialchars($d['nome_completo']) ?></div>
<div class="row"><b>Matrícula:</b> <?= htmlspecialchars($d['matricula']) ?></div>
<div class="row"><b>PIS/PASEP:</b> <?= htmlspecialchars($d['pis']) ?></div>

<div style="border-top:1px solid #000;margin:6px 0"></div>

<div class="row"><b>REP:</b> <?= $d['modelo_rep'] ?> v<?= $d['versao_layout'] ?> · Nº <?= $d['num_fabricacao'] ?></div>
<div class="row"><b>Data:</b> <?= date('d/m/Y', strtotime($d['momento'])) ?> &nbsp; <b>Hora:</b> <?= date('H:i:s', strtotime($d['momento'])) ?></div>
<div class="row"><b>Tipo:</b> <?= $tipos_legivel[$d['tipo']] ?? $d['tipo'] ?></div>

<div class="nsr-box">NSR <?= str_pad($d['nsr'], 9, '0', STR_PAD_LEFT) ?></div>

<div><b>Hash SHA-256:</b></div>
<div class="hash"><?= htmlspecialchars($d['hash_registro']) ?></div>

<div class="qr-box">
    <img src="<?= $qr_img ?>" alt="QR de validação">
    <div>Aponte a câmera para validar este comprovante.</div>
    <div class="url"><?= htmlspecialchars($url_val) ?></div>
</div>

<div class="foot">
    Emitido em <?= date('d/m/Y H:i:s') ?> · DOT-ON v1.0 · Documento eletrônico válido<br>
    Conforme Portaria MTP 671/2021 — guarde este comprovante por até 5 anos
</div>
</body></html>
<?php
    return ob_get_clean();
}

/**
 * Registra o CRP no banco e (opcionalmente) envia por e-mail.
 * Retorna ['ok'=>bool,'qr_token'=>...,'url'=>...]
 */
function crp_emitir(int $batida_id, bool $enviar_email = true): array {
    $stmt = db()->prepare("SELECT b.*, u.email AS user_email, u.nome_completo
        FROM dot_batidas b JOIN dot_usuarios u ON u.id = b.usuario_id
        WHERE b.id = ?");
    $stmt->execute([$batida_id]);
    $b = $stmt->fetch();
    if (!$b) return ['ok'=>false, 'erro'=>'Batida não encontrada'];

    $qr = repp_qr_token($batida_id, (int)$b['nsr']);
    $html = crp_html($batida_id);
    $hash_doc = hash('sha256', $html);

    // Salva CRP
    db()->prepare("INSERT INTO dot_crp (batida_id, usuario_id, empresa_id, nsr, hash_documento, qr_token)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE hash_documento=VALUES(hash_documento)")
        ->execute([$batida_id, $b['usuario_id'], $b['empresa_id'], $b['nsr'], $hash_doc, $qr]);

    db()->prepare("UPDATE dot_batidas SET crp_emitido = 1 WHERE id = ?")->execute([$batida_id]);

    $url = repp_url_validacao((int)$b['nsr'], $qr);
    $result = ['ok'=>true, 'qr_token'=>$qr, 'url'=>$url, 'hash_doc'=>$hash_doc];

    // E-mail ao funcionário
    if ($enviar_email && !empty($b['user_email'])) {
        $tipos = ['entrada'=>'Entrada','saida'=>'Saída','saida_intervalo'=>'Saída de intervalo',
                  'retorno_intervalo'=>'Retorno de intervalo','extra_inicio'=>'Início H.Extra','extra_fim'=>'Fim H.Extra'];
        $tipo_txt = $tipos[$b['tipo']] ?? $b['tipo'];
        $nsr_fmt = str_pad($b['nsr'], 9, '0', STR_PAD_LEFT);
        $mom = date('d/m/Y H:i:s', strtotime($b['momento']));
        $nome_safe = htmlspecialchars($b['nome_completo'] ?? '', ENT_QUOTES, 'UTF-8');
        $hash_safe = htmlspecialchars($b['hash_registro'] ?? '', ENT_QUOTES, 'UTF-8');

        $corpo = "
        <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;border:1px solid #ddd;border-radius:8px'>
            <h2 style='color:#2563eb'>⏱ Comprovante de Registro de Ponto</h2>
            <p>Olá, <b>{$nome_safe}</b>.</p>
            <p>Sua batida foi registrada com sucesso:</p>
            <div style='background:#f9fafb;padding:16px;border-left:4px solid #2563eb;margin:16px 0'>
                <p><b>NSR:</b> {$nsr_fmt}</p>
                <p><b>Tipo:</b> {$tipo_txt}</p>
                <p><b>Momento:</b> {$mom}</p>
            </div>
            <p>Para verificar a autenticidade deste registro, acesse:<br>
            <a href='{$url}'>{$url}</a></p>
            <p><a href='{$url}' style='background:#2563eb;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none'>🔍 Validar este registro</a></p>
            <hr style='margin:20px 0'>
            <p style='font-size:11px;color:#6b7280'>
                Hash SHA-256: <code>{$hash_safe}</code><br>
                Documento conforme Portaria MTP 671/2021 · Guarde este comprovante por até 5 anos.
            </p>
        </div>";

        if (email_enviar($b['user_email'], $b['nome_completo'], "[DOT-ON] CRP NSR {$nsr_fmt} · {$tipo_txt} {$mom}", $corpo, '', (int)$b['empresa_id'])) {
            db()->prepare("UPDATE dot_crp SET enviado_email=1, enviado_em=NOW() WHERE batida_id=?")
                ->execute([$batida_id]);
            $result['email_enviado'] = true;
        } else {
            $result['email_enviado'] = false;
        }
    }
    return $result;
}
