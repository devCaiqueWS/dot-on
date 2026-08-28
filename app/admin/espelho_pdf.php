<?php
/**
 * Espelho de Ponto - HTML imprimível (browser → "Salvar como PDF")
 * Solução leve e sem dependência externa.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/justificativas.php';
require_once __DIR__ . '/../includes/banco_horas.php';
$user = requer_login();
batidas_garantir_cancelamento();

// Restringe à empresa do usuário logado (mesma regra do espelho.php).
// Perfis comuns só baixam o próprio espelho (o PDF expõe CPF/PIS).
$func = (int)($_GET['func'] ?? $user['id']);
if (!in_array($user['perfil'], ['admin','rh','gestor'])) { $func = (int)$user['id']; }
$st = db()->prepare("SELECT id FROM dot_usuarios WHERE id=? AND empresa_id=?");
$st->execute([$func, $user['empresa_id']]);
if (!$st->fetch()) { $func = (int)$user['id']; }

$mes = $_GET['mes'] ?? date('Y-m');
[$ano, $m] = explode('-', $mes);
$inicio = "$ano-$m-01";
$fim = date('Y-m-t', strtotime($inicio));

$stmt = db()->prepare("SELECT u.*, e.nome AS escala_nome, e.entrada, e.intervalo_inicio, e.intervalo_fim, e.saida, e.carga_diaria_minutos,
    emp.razao_social, emp.cnpj
    FROM dot_usuarios u
    LEFT JOIN dot_escalas e ON e.id=u.escala_id
    LEFT JOIN dot_empresas emp ON emp.id=u.empresa_id
    WHERE u.id=? AND u.empresa_id=?");
$stmt->execute([$func, $user['empresa_id']]);
$f = $stmt->fetch();
if (!$f) die('Funcionário não encontrado.');

// Horas a favor / em débito do mês — mesma apuração do Banco de Horas.
// Roda antes da consulta de sessões porque também recalcula os minutos.
$ap = bh_apurar($func, (int)$user['empresa_id'], $inicio, $fim);

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

// Mescla sessões com a apuração: faltas, faltas abonadas e feriados também
// entram no espelho impresso (dia sem batida deixa de sumir do documento).
$linhas = [];
foreach ($sessoes as $s) $linhas[$s['data_ref']]['sessao'] = $s;
foreach ($ap['dias'] as $d) $linhas[$d['data_ref']]['ap'] = $d;
ksort($linhas);
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
    <button onclick="window.print()" style="padding:10px 20px;background:#1d4ed8;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600">Imprimir / Salvar PDF</button>
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
    <div><strong style="color:#15803d"><?= fmt_minutos($ap['total_positivo']) ?></strong>Horas a favor</div>
    <div><strong style="color:#b91c1c"><?= fmt_minutos($ap['total_negativo']) ?></strong>Horas em débito</div>
    <div><strong style="color:<?= $ap['saldo_periodo']>=0?'#15803d':'#b91c1c' ?>"><?= fmt_minutos($ap['saldo_periodo']) ?></strong>Saldo do mês</div>
    <div><strong><?= count($sessoes) ?></strong>Dias com registro</div>
</div>

<table>
    <thead><tr>
        <th>Data</th><th>Dia</th><th>Entrada</th><th>Saída Int.</th><th>Retorno</th><th>Saída</th>
        <th>Trab.</th><th>Ocioso</th><th>Extra</th><th>Saldo</th>
    </tr></thead>
    <tbody>
    <?php
    $dias_semana = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
    foreach ($linhas as $data_ref => $ln):
        $s  = $ln['sessao'] ?? null;
        $sd = $ln['ap'] ?? null;
        $dia_semana = $dias_semana[date('w', strtotime($data_ref))];
    ?>
        <?php if ($s):
            $b = $batidas_por_dia[$data_ref] ?? [];
            $busca = fn($t) => current(array_filter($b, fn($x) => $x['tipo'] === $t));
        ?>
        <tr>
            <td><?= date('d/m/Y', strtotime($data_ref)) ?></td>
            <td><?= $dia_semana ?></td>
            <td><?= ($r=$busca('entrada')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
            <td><?= ($r=$busca('saida_intervalo')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
            <td><?= ($r=$busca('retorno_intervalo')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
            <td><?= ($r=$busca('saida')) ? date('H:i', strtotime($r['momento'])) : '—' ?></td>
            <td><?= fmt_minutos($s['minutos_trabalhados']) ?></td>
            <td><?= fmt_minutos($s['minutos_ociosos']) ?></td>
            <td><?= fmt_minutos($s['minutos_extras']) ?></td>
            <td style="color:<?= $sd && $sd['saldo']<0 ? '#b91c1c' : '#15803d' ?>"><?= $sd ? fmt_minutos($sd['saldo']) : '—' ?></td>
        </tr>
        <?php else: // falta, falta abonada ou feriado ?>
        <tr>
            <td><?= date('d/m/Y', strtotime($data_ref)) ?></td>
            <td><?= $dia_semana ?></td>
            <td colspan="4" style="color:#666"><?= htmlspecialchars($sd['situacao']) ?><?= !empty($sd['abono']) ? ' · ' . htmlspecialchars(jus_label_tipo($sd['abono']['tipo'])) : '' ?></td>
            <td><?= fmt_minutos($sd['minutos_trabalhados']) ?></td>
            <td>—</td>
            <td>—</td>
            <td style="color:<?= $sd['saldo']<0 ? '#b91c1c' : '#15803d' ?>"><?= fmt_minutos($sd['saldo']) ?></td>
        </tr>
        <?php endif; ?>
    <?php endforeach; if(!$linhas): ?>
        <tr><td colspan="10">Sem registros no período</td></tr>
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
