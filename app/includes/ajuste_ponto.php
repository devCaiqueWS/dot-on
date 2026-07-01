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

/** Escala (jornada) de um funcionário + quantos a compartilham. */
function ap_escala_do_funcionario(int $usuario_id, int $empresa_id): ?array {
    $st = db()->prepare("SELECT e.* FROM dot_escalas e
        JOIN dot_usuarios u ON u.escala_id=e.id
        WHERE u.id=? AND u.empresa_id=? AND e.empresa_id=? LIMIT 1");
    $st->execute([$usuario_id, $empresa_id, $empresa_id]);
    $e = $st->fetch();
    if (!$e) return null;
    $c = db()->prepare("SELECT COUNT(*) FROM dot_usuarios WHERE escala_id=? AND empresa_id=?");
    $c->execute([$e['id'], $empresa_id]);
    $e['_compartilhada_por'] = (int)$c->fetchColumn();
    return $e;
}

/** Edita a jornada/escala. Retorna ['ok','msg']. */
function ap_editar_escala(int $escala_id, int $empresa_id, array $d, int $gestor_id): array {
    $st = db()->prepare("SELECT * FROM dot_escalas WHERE id=? AND empresa_id=? LIMIT 1");
    $st->execute([$escala_id, $empresa_id]);
    $e = $st->fetch();
    if (!$e) return ['ok'=>false, 'msg'=>'Escala não encontrada.'];

    $h = function($v, $obrig) {
        $v = trim((string)$v);
        if ($v === '') return $obrig ? false : null;
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $v)) return false;
        return strlen($v) === 5 ? $v.':00' : $v;
    };
    $entrada = $h($d['entrada'] ?? '', true);
    $saida   = $h($d['saida'] ?? '', true);
    $ini     = $h($d['intervalo_inicio'] ?? '', false);
    $fim     = $h($d['intervalo_fim'] ?? '', false);
    if ($entrada === false || $saida === false || $ini === false || $fim === false) {
        return ['ok'=>false, 'msg'=>'Horário inválido (use HH:MM).'];
    }
    $carga = max(0, min(1440, (int)($d['carga_diaria_minutos'] ?? $e['carga_diaria_minutos'])));
    $tol   = max(0, min(120, (int)($d['tolerancia_minutos'] ?? $e['tolerancia_minutos'])));
    $nome  = mb_substr(trim($d['nome'] ?? $e['nome']), 0, 100) ?: $e['nome'];

    db()->prepare("UPDATE dot_escalas SET nome=?, entrada=?, intervalo_inicio=?, intervalo_fim=?, saida=?,
                   carga_diaria_minutos=?, tolerancia_minutos=? WHERE id=? AND empresa_id=?")
        ->execute([$nome, $entrada, $ini, $fim, $saida, $carga, $tol, $escala_id, $empresa_id]);
    auditar($gestor_id, 'ajuste_editar_escala', 'escala', $escala_id, ['nome'=>$nome, 'entrada'=>$entrada, 'saida'=>$saida]);
    return ['ok'=>true, 'msg'=>'Jornada atualizada.'];
}
