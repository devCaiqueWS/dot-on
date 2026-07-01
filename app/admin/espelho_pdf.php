<?php
/**
 * Espelho de Ponto - HTML imprimível (browser → "Salvar como PDF")
 * Solução leve e sem dependência externa.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/justificativas.php';
$user = requer_login();
batidas_garantir_cancelamento();

$func = (int)($_GET['func'] ?? $user['id']);
$mes = $_GET['mes'] ?? date('Y-m');
[$ano, $m] = explode('-', $mes);
$inicio = "$ano-$m-01";
$fim = date('Y-m-t', strtotime($inicio));

$stmt = db()->prepare("SELECT u.*, e.nome AS escala_nome, e.entrada, e.intervalo_inicio, e.intervalo_fim, e.saida, e.carga_diaria_minutos,
    emp.razao_social, emp.cnpj
    FROM dot_usuarios u
    LEFT JOIN dot_escalas e ON e.id=u.escala_id
    LEFT JOIN dot_empresas emp ON emp.id=u.empresa_id
    WHERE u.id=?");
$stmt->execute([$func]);
$f = $stmt->fetch();
if (!$f) die('Funcionário não encontrado.');

$stmt = db()->prepare("SELECT * FROM dot_sessoes WHERE usuario_id=? AND data_ref BETWEEN ? AND ? ORDER BY data_ref");
$stmt->execute([$func, $inicio, $fim]);
$sessoes = $stmt->fetchAll();

$stmt = db()->prepare("SELECT * FROM dot_batidas WHERE usuario_id=? AND DATE(momento) BETWEEN ? AND ? AND COALESCE(cancelada,0)=0 ORDER BY momento");
$stmt->execute([$func, $inicio, $fim]);
$batidas_por_dia = [];
foreach ($stmt->fetchAll() as $b) $batidas_por_dia[substr($b['momento'],0,10)][] = $b;

$total_trab = array_sum(array_column($sessoes, 'minutos_trabalhados'));
$total_extra = array_sum(array_column($sessoes, 'minutos_extras'));
$total_ocioso = array_sum(array_column($sessoes, 'minutos_ociosos'));
?>
<!DOCTYPE html><html lang="pt-BR"><head>
<meta charset="UTF-8"><title>Espelho de Ponto - <?= htmlspecialchars($f['nome_completo']) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:Arial,sans-serif;color:#222;padding:20px;font-size:11pt}
.cabec{border-bottom:2px solid #333;padding-bottom:10px;margin-bottom:16px}
.cabec h1{font-size:18pt;margin-bottom:4px}
.cabec .meta{font-size:9pt;color:#555}
.info{display:grid;grid-template-columns:1fr 1fr;gap:6px 24px;margin-bottom:16px;font-size:10pt}
.info b{color:#333}
.tot{display:flex;gap:10px;margin-bottom:14px}
.tot div{flex:1;background:#f3f4f6;padding:8px;border-radius:4px;text-align:center}
.tot strong{display:block;font-size:14pt;color:#1e40af}
table{width:100%;border-collapse:collapse;font-size:9pt}
th,td{border:1px solid #999;padding:5px 7px;text-align:center}
th{background:#1e40af;color:#fff;font-weight:600}
tr:nth-child(even){background:#f9fafb}
.assinatura{margin-top:40px;display:flex;justify-content:space-around;font-size:9pt}
.assinatura div{text-align:center;width:40%}
.assinatura div p{border-top:1px solid #333;padding-top:6px;margin-top:40px}
.no-print{margin-bottom:20px}
@media print{.no-print{display:none}body{padding:0}}
</style></head><body>
<div class="no-print">
    <button onclick="window.print()" style="padding:10px 20px;background:#2563eb;color:#fff;border:none;border-radius:4px;cursor:pointer">🖨 Imprimir / Salvar PDF</button>
    <a href="espelho.php?func=<?= $func ?>&mes=<?= $mes ?>" style="margin-left:10px">← Voltar</a>
</div>

<div class="cabec">
    <h1>Espelho de Ponto Eletrônico</h1>
    <div class="meta">Conforme Portaria MTP 671/2021 — Período: <?= date('d/m/Y', strtotime($inicio)) ?> a <?= date('d/m/Y', strtotime($fim)) ?></div>
</div>

<div class="info">
    <div><b>Empresa:</b> <?= htmlspecialchars($f['razao_social']) ?></div>
    <div><b>CNPJ:</b> <?= htmlspecialchars($f['cnpj']) ?></div>
    <div><b>Funcionário:</b> <?= htmlspecialchars($f['nome_completo']) ?></div>
    <div><b>Matrícula:</b> <?= htmlspecialchars($f['matricula']) ?></div>
    <div><b>CPF:</b> <?= htmlspecialchars($f['cpf'] ?? '—') ?></div>
    <div><b>PIS:</b> <?= htmlspecialchars($f['pis'] ?? '—') ?></div>
    <div><b>Escala:</b> <?= htmlspecialchars($f['escala_nome'] ?? '—') ?> (<?= $f['entrada'] ?>–<?= $f['saida'] ?>)</div>
    <div><b>Carga diária:</b> <?= fmt_minutos((int)$f['carga_diaria_minutos']) ?></div>
</div>

<div class="tot">
    <div><strong><?= fmt_minutos($total_trab) ?></strong>Total trabalhado</div>
    <div><strong><?= fmt_minutos($total_extra) ?></strong>Horas extras</div>
    <div><strong><?= fmt_minutos($total_ocioso) ?></strong>Ociosidade</div>
    <div><strong><?= count($sessoes) ?></strong>Dias com registro</div>
</div>

<table>
    <thead><tr>
        <th>Data</th><th>Dia</th><th>Entrada</th><th>Saída Int.</th><th>Retorno</th><th>Saída</th>
        <th>Trab.</th><th>Ocioso</th><th>Extra</th>
    </tr></thead>
    <tbody>
    <?php
    $dias = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
    foreach ($sessoes as $s):
        $b = $batidas_por_dia[$s['data_ref']] ?? [];
        $busca = fn($t) => current(array_filter($b, fn($x) => $x['tipo'] === $t));
        $dia_semana = $dias[date('w', strtotime($s['data_ref']))];
    ?>
        <tr>
            <td><?= date('d/m/Y', strtotime($s['data_ref'])) ?></td>
            <td><?= $dia_semana ?></td>
            <td><?= ($r=$busca('entrada')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
            <td><?= ($r=$busca('saida_intervalo')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
            <td><?= ($r=$busca('retorno_intervalo')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
            <td><?= ($r=$busca('saida')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
            <td><?= fmt_minutos($s['minutos_trabalhados']) ?></td>
            <td><?= fmt_minutos($s['minutos_ociosos']) ?></td>
            <td><?= fmt_minutos($s['minutos_extras']) ?></td>
        </tr>
    <?php endforeach; if(!$sessoes): ?>
        <tr><td colspan="9">Sem registros no período</td></tr>
    <?php endif; ?>
    </tbody>
</table>

<div class="assinatura">
    <div><p>Assinatura do Funcionário</p></div>
    <div><p>Assinatura da Empresa</p></div>
</div>

<p style="margin-top:20px;font-size:8pt;color:#777;text-align:center">
    Documento gerado eletronicamente em <?= date('d/m/Y H:i:s') ?> · DOT-ON v1.0 · NSRs hash-encadeados conforme Portaria 671/2021
</p>
</body></html>
