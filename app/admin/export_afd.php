<?php
/**
 * EXPORTAÇÃO AFD-REP-P (v3) - Arquivo Fonte de Dados
 * Conforme Portaria MTP 671/2021, Anexo I
 *
 * Layout AFD-REP-P:
 *  Tipo 1: Cabeçalho (300 chars)
 *  Tipo 2: Inclusão/alteração da empresa
 *  Tipo 3: Marcação de ponto (50 chars)
 *  Tipo 5: Inclusão/alteração/exclusão do empregado
 *  Tipo 6: Eventos sensíveis
 *  Tipo 7: Marcação extemporânea (com NSR original do erro)
 *  Tipo 9: Trailer (com contadores)
 *
 * Saída: arquivo .txt em ISO-8859-1 + assinatura .p7s (se houver cert)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/repp.php';
$user = requer_perfil(['admin','rh']);

$inicio = $_GET['inicio'] ?? date('Y-m-01');
$fim    = $_GET['fim']    ?? date('Y-m-t');

// Dados da empresa
$stmt = db()->prepare("SELECT * FROM dot_empresas WHERE id=?");
$stmt->execute([$user['empresa_id']]);
$emp = $stmt->fetch();

// Funcionários
$stmt = db()->prepare("SELECT * FROM dot_usuarios WHERE empresa_id=? AND perfil='funcionario'");
$stmt->execute([$user['empresa_id']]);
$funcs = $stmt->fetchAll();

// Batidas no período
$stmt = db()->prepare("SELECT b.*, u.pis, u.cpf, u.matricula
    FROM dot_batidas b JOIN dot_usuarios u ON u.id=b.usuario_id
    WHERE b.empresa_id=? AND DATE(b.momento) BETWEEN ? AND ?
    ORDER BY b.nsr");
$stmt->execute([$user['empresa_id'], $inicio, $fim]);
$batidas = $stmt->fetchAll();

function pad($s, $n, $char=' ', $right=true) {
    $s = (string)$s;
    if (mb_strlen($s) > $n) return mb_substr($s, 0, $n);
    return $right ? str_pad($s, $n, $char, STR_PAD_RIGHT) : str_pad($s, $n, $char, STR_PAD_LEFT);
}
function so_digitos($s) { return preg_replace('/\D/','', (string)$s); }

$linhas = [];
$nsr_global = 1;

// TIPO 1 - CABEÇALHO
$cnpj = so_digitos($emp['cnpj']);
$cei  = pad(so_digitos($emp['cei'] ?? ''), 12, '0', false);
$linha1 = pad($nsr_global, 9, '0', false)
    . '1'
    . pad($cnpj, 14, '0', false)
    . $cei
    . pad($emp['razao_social'], 150)
    . pad($emp['num_fabricacao'] ?? 'DOTON-000000000', 17)
    . date('dmY', strtotime($inicio))
    . date('dmY', strtotime($fim))
    . date('dmY')
    . date('Hi')
    . pad($emp['versao_layout'] ?? '003', 3);
$linhas[] = $linha1;
$nsr_global++;

// TIPO 2 - INCLUSÃO/ALTERAÇÃO DA EMPRESA
$linhas[] = pad($nsr_global, 9, '0', false)
    . '2'
    . date('dmY')
    . date('Hi')
    . pad($cnpj, 14, '0', false)
    . $cei
    . pad($emp['razao_social'], 150)
    . pad($emp['endereco'] ?? '', 100);
$nsr_global++;

// TIPO 5 - EMPREGADOS
foreach ($funcs as $f) {
    $linhas[] = pad($nsr_global, 9, '0', false)
        . '5'
        . date('dmY')
        . date('Hi')
        . 'I'  // Inclusão
        . pad(so_digitos($f['cpf']), 12, '0', false)
        . pad($f['nome_completo'], 52)
        . pad(so_digitos($f['pis']), 11, '0', false);
    $nsr_global++;
}

// TIPO 3 - MARCAÇÕES NORMAIS / TIPO 7 - EXTEMPORÂNEAS
$qtd3 = 0; $qtd7 = 0;
foreach ($batidas as $b) {
    $dt = strtotime($b['momento']);
    if ($b['extemporanea']) {
        $linhas[] = pad($b['nsr'], 9, '0', false)
            . '7'
            . 'I'
            . date('dmYHi', $dt)
            . pad(so_digitos($b['pis']), 12, '0', false)
            . pad($b['motivo_extemporanea'] ?? 'Registro corrigido pelo agente', 100);
        $qtd7++;
    } else {
        $linhas[] = pad($b['nsr'], 9, '0', false)
            . '3'
            . date('dmY', $dt)
            . date('Hi', $dt)
            . pad(so_digitos($b['pis']), 12, '0', false);
        $qtd3++;
    }
}

// TIPO 9 - TRAILER
$linhas[] = pad($nsr_global, 9, '0', false)
    . '9'
    . pad(1, 9, '0', false)              // tipo 2
    . pad($qtd3, 9, '0', false)          // tipo 3
    . pad(0, 9, '0', false)              // tipo 4 (não aplicável a REP-P)
    . pad(count($funcs), 9, '0', false)  // tipo 5
    . pad(0, 9, '0', false)              // tipo 6
    . pad($qtd7, 9, '0', false);         // tipo 7

// Conteúdo final
$conteudo = implode("\r\n", $linhas) . "\r\n";
$conteudo_iso = mb_convert_encoding($conteudo, 'ISO-8859-1', 'UTF-8');
$hash = hash('sha256', $conteudo_iso);

// Assinatura ICP-Brasil (se houver certificado)
$assinatura = repp_assinar_pkcs7($user['empresa_id'], $conteudo_iso);

// Carimbo de tempo
$carimbo = repp_carimbo_tempo($conteudo_iso);

// Registra na tabela de exports
$nsr_ini = $batidas ? min(array_column($batidas, 'nsr')) : null;
$nsr_fim = $batidas ? max(array_column($batidas, 'nsr')) : null;
db()->prepare("INSERT INTO dot_exports
    (empresa_id, usuario_id, tipo, periodo_inicio, periodo_fim, hash_sha256, assinatura_digital, tsa_token, tsa_em, nsr_inicial, nsr_final, qtd_registros, tamanho_bytes, arquivo)
    VALUES (?,?,'AFD',?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([
        $user['empresa_id'], $user['id'],
        $inicio, $fim, $hash,
        $assinatura ? base64_encode($assinatura) : null,
        $carimbo['token'] ?? null,
        $carimbo['momento'],
        $nsr_ini, $nsr_fim, count($linhas),
        strlen($conteudo_iso),
        "AFD_" . date('Ymd_His') . ".txt"
    ]);

auditar((int)$user['id'], 'export_afd', 'empresa', $emp['id'],
    ['inicio'=>$inicio,'fim'=>$fim,'linhas'=>count($linhas),'hash'=>$hash,'assinado'=>(bool)$assinatura,'tsa'=>$carimbo['fonte']]);

// Saída
$nome = "AFD_DOTON_" . date('Ymd_His') . ".txt";
header('Content-Type: text/plain; charset=ISO-8859-1');
header("Content-Disposition: attachment; filename=\"$nome\"");
echo $conteudo_iso;
