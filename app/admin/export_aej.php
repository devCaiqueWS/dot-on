<?php
/**
 * EXPORTAÇÃO AEJ v3 - Arquivo Eletrônico de Jornada
 * Com assinatura digital + carimbo de tempo
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/repp.php';
require_once __DIR__ . '/../includes/justificativas.php';
$user = requer_perfil(['admin','rh']);
batidas_garantir_cancelamento();

$inicio = $_GET['inicio'] ?? date('Y-m-01');
$fim    = $_GET['fim']    ?? date('Y-m-t');

$stmt = db()->prepare("SELECT * FROM dot_empresas WHERE id=?");
$stmt->execute([$user['empresa_id']]);
$emp = $stmt->fetch();

$stmt = db()->prepare("SELECT u.*, e.nome AS escala_nome, e.entrada, e.intervalo_inicio, e.intervalo_fim, e.saida,
    e.carga_diaria_minutos, e.tolerancia_minutos
    FROM dot_usuarios u LEFT JOIN dot_escalas e ON e.id=u.escala_id
    WHERE u.empresa_id=? AND u.perfil='funcionario'");
$stmt->execute([$user['empresa_id']]);
$funcs = $stmt->fetchAll();

$jornada = [];
foreach ($funcs as $f) {
    $stmt = db()->prepare("SELECT * FROM dot_sessoes WHERE usuario_id=? AND data_ref BETWEEN ? AND ? ORDER BY data_ref");
    $stmt->execute([$f['id'], $inicio, $fim]);
    $sessoes = $stmt->fetchAll();
    $dias = [];
    foreach ($sessoes as $s) {
        $stmt2 = db()->prepare("SELECT nsr, tipo, momento, hash_registro, hash_anterior, extemporanea FROM dot_batidas WHERE sessao_id=? AND COALESCE(cancelada,0)=0 ORDER BY momento");
        $stmt2->execute([$s['id']]);
        $marcacoes = $stmt2->fetchAll();
        $stmt3 = db()->prepare("SELECT inicio, fim, duracao_segundos, motivo FROM dot_ociosidade WHERE sessao_id=?");
        $stmt3->execute([$s['id']]);
        $ocios = $stmt3->fetchAll();
        $stmt4 = db()->prepare("SELECT minutos_solicitados, minutos_aprovados, status, justificativa FROM dot_horas_extras WHERE sessao_id=?");
        $stmt4->execute([$s['id']]);
        $extras = $stmt4->fetchAll();
        $dias[] = [
            'data' => $s['data_ref'], 'inicio' => $s['inicio'], 'fim' => $s['fim'],
            'minutos_trabalhados' => (int)$s['minutos_trabalhados'],
            'minutos_intervalo'   => (int)$s['minutos_intervalo'],
            'minutos_ociosos'     => (int)$s['minutos_ociosos'],
            'minutos_extras'      => (int)$s['minutos_extras'],
            'status'              => $s['status'],
            'marcacoes' => $marcacoes, 'periodos_ociosos' => $ocios, 'horas_extras' => $extras,
        ];
    }
    $jornada[] = [
        'empregado' => [
            'matricula' => $f['matricula'], 'nome' => $f['nome_completo'],
            'cpf' => $f['cpf'], 'pis' => $f['pis'],
            'data_admissao' => $f['data_admissao'], 'funcao' => $f['funcao'],
            'escala' => $f['escala_nome'],
            'jornada_diaria_min' => (int)$f['carga_diaria_minutos'],
            'tolerancia_min'     => (int)$f['tolerancia_minutos'],
        ],
        'jornada' => $dias,
    ];
}

$aej = [
    'tipo_arquivo' => 'AEJ',
    'layout'       => '1.0',
    'norma'        => 'Portaria MTP 671/2021',
    'gerado_em'    => date('c'),
    'gerado_por'   => ['id'=>$user['id'],'nome'=>$user['nome_completo']],
    'empresa' => [
        'cnpj' => $emp['cnpj'], 'cei' => $emp['cei'], 'caepf' => $emp['caepf'],
        'razao_social' => $emp['razao_social'], 'nome_fantasia' => $emp['nome_fantasia'],
        'endereco' => $emp['endereco'], 'cidade' => $emp['cidade'], 'uf' => $emp['uf'],
        'modelo_rep' => $emp['modelo_rep'], 'num_fabricacao' => $emp['num_fabricacao'],
    ],
    'periodo' => ['inicio'=>$inicio,'fim'=>$fim],
    'jornadas' => $jornada,
];

$json = json_encode($aej, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$hash = hash('sha256', $json);

$assinatura = repp_assinar_pkcs7($user['empresa_id'], $json);
$carimbo = repp_carimbo_tempo($json);

// Adiciona seção de assinatura ao próprio JSON (envelope)
$envelope = [
    'documento' => $aej,
    'integridade' => [
        'hash_sha256' => $hash,
        'assinatura_pkcs7_b64' => $assinatura ? base64_encode($assinatura) : null,
        'carimbo_tempo' => [
            'fonte' => $carimbo['fonte'],
            'momento' => $carimbo['momento'],
            'token_b64' => $carimbo['token'] ? base64_encode($carimbo['token']) : null,
        ],
    ],
];

$saida = json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// Registra na tabela de exports
db()->prepare("INSERT INTO dot_exports
    (empresa_id, usuario_id, tipo, periodo_inicio, periodo_fim, hash_sha256, assinatura_digital, tsa_token, tsa_em, qtd_registros, tamanho_bytes, arquivo)
    VALUES (?,?,'AEJ',?,?,?,?,?,?,?,?,?)")
    ->execute([
        $user['empresa_id'], $user['id'], $inicio, $fim, $hash,
        $assinatura ? base64_encode($assinatura) : null,
        $carimbo['token'] ?? null,
        $carimbo['momento'],
        count($jornada),
        strlen($saida),
        "AEJ_" . date('Ymd_His') . ".json"
    ]);

auditar((int)$user['id'], 'export_aej', 'empresa', $emp['id'],
    ['periodo'=>"$inicio a $fim", 'assinado'=>(bool)$assinatura, 'tsa'=>$carimbo['fonte']]);

$nome = "AEJ_DOTON_" . date('Ymd_His') . ".json";
header('Content-Type: application/json; charset=utf-8');
header("Content-Disposition: attachment; filename=\"$nome\"");
echo $saida;
