<?php
/**
 * DOT-ON · Ajuste de Ponto & Jornada (uso de gestores/admins)
 * =================================================================
 * Ações diretas do gestor/admin sobre as batidas de ponto:
 *  - Adicionar batida esquecida (inserida na cadeia REP-P, extemporânea)
 *  - Anular batida feita por engano (preserva a cadeia/AFD; sai das
 *    visões tratadas: espelho, AEJ, cálculo de horas)
 *  - Corrigir horário (anula a errada + insere a correta)
 *  - Editar a jornada/escala do funcionário
 *
 * Tudo auditado. Anular NÃO apaga a batida — mantém a integridade fiscal
 * exigida pela Portaria MTP 671/2021.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/repp.php';
require_once __DIR__ . '/crp.php';
require_once __DIR__ . '/justificativas.php';

const AP_TIPOS_BATIDA = ['entrada','saida_intervalo','retorno_intervalo','saida'];

/** Confere que o usuário pertence à empresa (isolamento multi-tenant). */
function ap_usuario_da_empresa(int $usuario_id, int $empresa_id): ?array {
    $st = db()->prepare("SELECT * FROM dot_usuarios WHERE id=? AND empresa_id=? LIMIT 1");
    $st->execute([$usuario_id, $empresa_id]);
    return $st->fetch() ?: null;
}

/** Batidas de um funcionário num dia (inclui anuladas, marcadas). */
function ap_batidas_do_dia(int $empresa_id, int $usuario_id, string $data): array {
    batidas_garantir_cancelamento();
    $st = db()->prepare("SELECT * FROM dot_batidas
        WHERE empresa_id=? AND usuario_id=? AND DATE(momento)=? ORDER BY momento");
    $st->execute([$empresa_id, $usuario_id, $data]);
    return $st->fetchAll();
}

/** Sessão do dia (cria se não existir). Retorna o id. */
function ap_sessao_do_dia(int $usuario_id, string $data_ref, string $momento): int {
    $pdo = db();
    $st = $pdo->prepare("SELECT id FROM dot_sessoes WHERE usuario_id=? AND data_ref=?");
    $st->execute([$usuario_id, $data_ref]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO dot_sessoes (usuario_id, data_ref, inicio, status) VALUES (?,?,?, 'aberta')")
        ->execute([$usuario_id, $data_ref, $momento]);
    return (int)$pdo->lastInsertId();
}

/**
 * Insere uma batida na cadeia REP-P (uso interno). Assume transação aberta.
 * Retorna [batida_id, nsr].
 */
function ap_inserir_batida_na_cadeia(int $empresa_id, array $u, string $tipo, string $momento, string $obs, string $motivo_ext): array {
    $pdo = db();
    $sessao_id = ap_sessao_do_dia((int)$u['id'], substr($momento, 0, 10), $momento);

    $nsr = proximo_nsr($empresa_id);
    $hash_anterior = repp_ultimo_hash($empresa_id);
    $arr = [
        'nsr'=>$nsr, 'empresa_id'=>$empresa_id, 'usuario_id'=>$u['id'],
        'cpf_snapshot'=>$u['cpf'] ?? '', 'pis_snapshot'=>$u['pis'] ?? '',
        'tipo'=>$tipo, 'momento'=>$momento,
    ];
    $hash_atual = repp_hash_batida($arr, $hash_anterior);
    $extemporanea = (strtotime($momento) < time() - 1800) ? 1 : 0;

    $pdo->prepare("INSERT INTO dot_batidas
        (nsr, sessao_id, empresa_id, usuario_id, tipo, momento, origem, ip_origem, hostname,
         cpf_snapshot, pis_snapshot, hash_registro, hash_anterior, hash_alg, extemporanea,
         motivo_extemporanea, observacao)
        VALUES (?,?,?,?,?,?, 'manual', ?, 'ajuste-gestor', ?, ?, ?, ?, 'SHA-256', ?, ?, ?)")
        ->execute([$nsr, $sessao_id, $empresa_id, $u['id'], $tipo, $momento,
                   $_SERVER['REMOTE_ADDR'] ?? null, $u['cpf'] ?? '', $u['pis'] ?? '',
                   $hash_atual, $hash_anterior, $extemporanea,
                   mb_substr($motivo_ext, 0, 500), mb_substr($obs, 0, 500)]);
    return [(int)$pdo->lastInsertId(), $nsr, $sessao_id];
}

/**
 * Adiciona uma batida esquecida.
 * $data (Y-m-d), $hora (HH:MM). Retorna ['ok','msg','nsr'].
 */
function ap_adicionar_batida(int $empresa_id, int $usuario_id, string $tipo, string $data, string $hora, int $gestor_id, string $motivo): array {
    batidas_garantir_cancelamento();
    if (!in_array($tipo, AP_TIPOS_BATIDA, true)) return ['ok'=>false, 'msg'=>'Tipo de batida inválido.'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) return ['ok'=>false, 'msg'=>'Data inválida.'];
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $hora)) return ['ok'=>false, 'msg'=>'Horário inválido (use HH:MM).'];
    if (mb_strlen(trim($motivo)) < 5) return ['ok'=>false, 'msg'=>'Descreva o motivo do ajuste.'];
    if (strlen($hora) === 5) $hora .= ':00';
    $momento = "$data $hora";
    if (strtotime($momento) > time() + 300) return ['ok'=>false, 'msg'=>'Não é possível lançar batida no futuro.'];

    $u = ap_usuario_da_empresa($usuario_id, $empresa_id);
    if (!$u) return ['ok'=>false, 'msg'=>'Funcionário não encontrado.'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        [$batida_id, $nsr, $sessao_id] = ap_inserir_batida_na_cadeia(
            $empresa_id, $u, $tipo, $momento,
            "Batida adicionada pelo gestor. Motivo: $motivo", "Ajuste gestor: $motivo");
        if ($tipo === 'saida') {
            $pdo->prepare("UPDATE dot_sessoes SET fim=?, status='encerrada' WHERE id=?")->execute([$momento, $sessao_id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[DOT-ON ap_adicionar_batida] ' . $e->getMessage());
        return ['ok'=>false, 'msg'=>'Erro ao inserir a batida.'];
    }
    try { jus_recalcular_sessao((int)$sessao_id); } catch (Throwable $e) {}
    try { crp_emitir($batida_id, false); } catch (Throwable $e) {}
    auditar($gestor_id, 'ajuste_add_batida', 'batida', $batida_id, ['usuario'=>$usuario_id, 'tipo'=>$tipo, 'momento'=>$momento, 'nsr'=>$nsr, 'motivo'=>$motivo]);
    return ['ok'=>true, 'msg'=>"Batida de ".jus_label_tipo($tipo)." adicionada (NSR ".str_pad((string)$nsr,6,'0',STR_PAD_LEFT).").", 'nsr'=>$nsr];
}

/**
 * Anula uma batida (feita por engano). Não apaga — marca como cancelada.
 * Retorna ['ok','msg'].
 */
function ap_anular_batida(int $batida_id, int $empresa_id, int $gestor_id, string $motivo): array {
    batidas_garantir_cancelamento();
    if (mb_strlen(trim($motivo)) < 5) return ['ok'=>false, 'msg'=>'Informe o motivo da anulação.'];

    $st = db()->prepare("SELECT * FROM dot_batidas WHERE id=? AND empresa_id=? LIMIT 1");
    $st->execute([$batida_id, $empresa_id]);
    $b = $st->fetch();
    if (!$b) return ['ok'=>false, 'msg'=>'Batida não encontrada.'];
    if ((int)$b['cancelada'] === 1) return ['ok'=>false, 'msg'=>'Esta batida já está anulada.'];

    db()->prepare("UPDATE dot_batidas SET cancelada=1, cancelada_motivo=?, cancelada_por=?, cancelada_em=NOW()
                   WHERE id=? AND empresa_id=?")
        ->execute([mb_substr($motivo,0,255), $gestor_id, $batida_id, $empresa_id]);

    try { if ($b['sessao_id']) jus_recalcular_sessao((int)$b['sessao_id']); } catch (Throwable $e) {}
    auditar($gestor_id, 'ajuste_anular_batida', 'batida', $batida_id, ['usuario'=>$b['usuario_id'], 'nsr'=>$b['nsr'], 'tipo'=>$b['tipo'], 'motivo'=>$motivo]);
    return ['ok'=>true, 'msg'=>"Batida NSR ".str_pad((string)$b['nsr'],6,'0',STR_PAD_LEFT)." anulada."];
}

/**
 * Corrige o horário de uma batida: anula a original e insere uma nova (mesmo
 * tipo) com o horário correto. Retorna ['ok','msg','nsr'].
 */
function ap_corrigir_horario(int $batida_id, int $empresa_id, string $nova_hora, int $gestor_id, string $motivo): array {
    batidas_garantir_cancelamento();
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $nova_hora)) return ['ok'=>false, 'msg'=>'Horário inválido (use HH:MM).'];
    if (mb_strlen(trim($motivo)) < 5) return ['ok'=>false, 'msg'=>'Descreva o motivo da correção.'];
    if (strlen($nova_hora) === 5) $nova_hora .= ':00';

    $st = db()->prepare("SELECT * FROM dot_batidas WHERE id=? AND empresa_id=? LIMIT 1");
    $st->execute([$batida_id, $empresa_id]);
    $b = $st->fetch();
    if (!$b) return ['ok'=>false, 'msg'=>'Batida não encontrada.'];
    if ((int)$b['cancelada'] === 1) return ['ok'=>false, 'msg'=>'Esta batida está anulada; adicione uma nova batida.'];

    $data = substr($b['momento'], 0, 10);
    $u = ap_usuario_da_empresa((int)$b['usuario_id'], $empresa_id);
    if (!$u) return ['ok'=>false, 'msg'=>'Funcionário não encontrado.'];
    $momento_novo = "$data $nova_hora";
    if (strtotime($momento_novo) > time() + 300) return ['ok'=>false, 'msg'=>'Não é possível lançar batida no futuro.'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 1) anula a original
        $pdo->prepare("UPDATE dot_batidas SET cancelada=1, cancelada_motivo=?, cancelada_por=?, cancelada_em=NOW() WHERE id=? AND empresa_id=?")
            ->execute(["Correção de horário: $motivo", $gestor_id, $batida_id, $empresa_id]);
        // 2) insere a correta
        [$novo_id, $nsr, $sessao_id] = ap_inserir_batida_na_cadeia(
            $empresa_id, $u, $b['tipo'], $momento_novo,
            "Correção de horário (era ".substr($b['momento'],11,5).", batida NSR {$b['nsr']}). Motivo: $motivo",
            "Correção de horário: $motivo");
        if ($b['tipo'] === 'saida') {
            $pdo->prepare("UPDATE dot_sessoes SET fim=?, status='encerrada' WHERE id=?")->execute([$momento_novo, $sessao_id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[DOT-ON ap_corrigir_horario] ' . $e->getMessage());
        return ['ok'=>false, 'msg'=>'Erro ao corrigir o horário.'];
    }
    try { if ($b['sessao_id']) jus_recalcular_sessao((int)$b['sessao_id']); } catch (Throwable $e) {}
    try { jus_recalcular_sessao((int)$sessao_id); } catch (Throwable $e) {}
    try { crp_emitir($novo_id, false); } catch (Throwable $e) {}
    auditar($gestor_id, 'ajuste_corrigir_horario', 'batida', $batida_id, ['de'=>$b['momento'], 'para'=>$momento_novo, 'novo_nsr'=>$nsr, 'motivo'=>$motivo]);
    return ['ok'=>true, 'msg'=>"Horário corrigido para ".substr($nova_hora,0,5)." (nova batida NSR ".str_pad((string)$nsr,6,'0',STR_PAD_LEFT).").", 'nsr'=>$nsr];
}

// ===================================================================
// JORNADA DO FUNCIONÁRIO POR DIA DA SEMANA (dot_usuario_jornada)
// Cada funcionário tem a SUA jornada (uns entram 08h, outros 09h).
// O almoço é uma DURAÇÃO (minutos) definida pelo gestor — sem horário
// fixo: o funcionário cumpre o tempo quando quiser.
// ===================================================================

// Ordem de exibição (segunda → domingo) e rótulos. 0=domingo .. 6=sábado.
const AP_DOW_ORDEM  = [1,2,3,4,5,6,0];
const AP_DOW_LABEL  = [0=>'Domingo',1=>'Segunda',2=>'Terça',3=>'Quarta',4=>'Quinta',5=>'Sexta',6=>'Sábado'];

/** Garante a tabela e as 7 linhas (uma por dia) da jornada do funcionário. */
function jornada_garantir(int $usuario_id): void {
    static $tabela_ok = false;
    if (!$tabela_ok) {
        // Só roda DDL se a tabela não existir (CREATE faz commit implícito).
        $existe = db()->query("SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dot_usuario_jornada'")->fetchColumn();
        if (!$existe) {
            db()->exec("CREATE TABLE dot_usuario_jornada (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NOT NULL,
                dia_semana TINYINT NOT NULL,
                trabalha TINYINT(1) NOT NULL DEFAULT 1,
                entrada TIME NULL,
                saida TIME NULL,
                almoco_minutos INT NULL,
                carga_minutos INT NULL,
                UNIQUE KEY uq_usuario_dia (usuario_id, dia_semana)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $tabela_ok = true;
    }
    $tem = db()->prepare("SELECT COUNT(*) FROM dot_usuario_jornada WHERE usuario_id=?");
    $tem->execute([$usuario_id]);
    if ((int)$tem->fetchColumn() >= 7) return;

    // Semeia a partir da escala do funcionário (se houver); senão 08–17 / 60min.
    $st = db()->prepare("SELECT e.* FROM dot_usuarios u LEFT JOIN dot_escalas e ON e.id=u.escala_id WHERE u.id=?");
    $st->execute([$usuario_id]);
    $esc = $st->fetch() ?: [];
    $entrada = $esc['entrada'] ?? '08:00:00';
    $saida   = $esc['saida']   ?? '17:00:00';
    $almoco  = 60;
    if (!empty($esc['intervalo_inicio']) && !empty($esc['intervalo_fim'])) {
        $almoco = max(0, (int)round((strtotime($esc['intervalo_fim']) - strtotime($esc['intervalo_inicio'])) / 60));
    } elseif (isset($esc['intervalo_obrigatorio_minutos'])) {
        $almoco = (int)$esc['intervalo_obrigatorio_minutos'];
    }
    $carga = (int)($esc['carga_diaria_minutos'] ?? 480);

    $ins = db()->prepare("INSERT IGNORE INTO dot_usuario_jornada
        (usuario_id, dia_semana, trabalha, entrada, saida, almoco_minutos, carga_minutos)
        VALUES (?,?,?,?,?,?,?)");
    for ($dow = 0; $dow <= 6; $dow++) {
        $trab = ($dow >= 1 && $dow <= 5) ? 1 : 0;   // Seg–Sex por padrão
        $ins->execute([$usuario_id, $dow, $trab, $entrada, $saida, $trab ? $almoco : 0, $trab ? $carga : 0]);
    }
}

/** Lista as 7 linhas da jornada do funcionário (indexadas por dia_semana 0..6). */
function jornada_listar(int $usuario_id): array {
    jornada_garantir($usuario_id);
    $st = db()->prepare("SELECT * FROM dot_usuario_jornada WHERE usuario_id=?");
    $st->execute([$usuario_id]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['dia_semana']] = $r;
    return $out;
}

/** Jornada de um dia específico (0..6) do funcionário. Semeia se necessário. */
function jornada_dia(int $usuario_id, int $dow): ?array {
    $dias = jornada_listar($usuario_id);
    return $dias[$dow] ?? null;
}

/**
 * Salva a jornada do funcionário. $post arrays por dia (0..6):
 *   d_trab[dow], d_ent[dow], d_sai[dow], d_almoco[dow], d_carga[dow]
 * O almoço é duração em minutos (sem horário fixo). Retorna ['ok','msg'].
 */
function ap_salvar_jornada(int $usuario_id, int $empresa_id, array $post, int $gestor_id): array {
    if (!ap_usuario_da_empresa($usuario_id, $empresa_id)) return ['ok'=>false, 'msg'=>'Funcionário não encontrado.'];
    jornada_garantir($usuario_id);

    $h = function($v) {
        $v = trim((string)$v);
        if ($v === '') return null;
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $v)) return false;
        return strlen($v) === 5 ? $v.':00' : $v;
    };
    $up = db()->prepare("UPDATE dot_usuario_jornada
        SET trabalha=?, entrada=?, saida=?, almoco_minutos=?, carga_minutos=?
        WHERE usuario_id=? AND dia_semana=?");

    for ($dow = 0; $dow <= 6; $dow++) {
        $trab = !empty($post['d_trab'][$dow]) ? 1 : 0;
        $ent = $h($post['d_ent'][$dow] ?? '');
        $sai = $h($post['d_sai'][$dow] ?? '');
        if ($ent === false || $sai === false) {
            return ['ok'=>false, 'msg'=>'Horário inválido em '.AP_DOW_LABEL[$dow].' (use HH:MM).'];
        }
        if ($trab && (!$ent || !$sai)) {
            return ['ok'=>false, 'msg'=>AP_DOW_LABEL[$dow].': informe entrada e saída (ou marque como folga).'];
        }
        $almoco = ($trab && isset($post['d_almoco'][$dow]) && $post['d_almoco'][$dow] !== '')
            ? max(0, min(480, (int)$post['d_almoco'][$dow])) : ($trab ? 60 : 0);
        // carga: usa a informada; senão deriva de (saída - entrada - almoço)
        $carga = isset($post['d_carga'][$dow]) && $post['d_carga'][$dow] !== ''
            ? max(0, min(1440, (int)$post['d_carga'][$dow])) : null;
        if ($carga === null && $trab && $ent && $sai) {
            $carga = max(0, (int)round((strtotime($sai) - strtotime($ent)) / 60) - $almoco);
        }
        if (!$trab) { $carga = 0; $almoco = 0; }
        $up->execute([$trab, $ent, $sai, $almoco, $carga, $usuario_id, $dow]);
    }
    auditar($gestor_id, 'ajuste_jornada_funcionario', 'usuario', $usuario_id, ['por'=>'dia']);
    return ['ok'=>true, 'msg'=>'Jornada do funcionário atualizada.'];
}
