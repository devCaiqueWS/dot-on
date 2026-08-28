<?php
/**
 * Banco de Horas - apuração compartilhada.
 * Usada pelo painel (banco_horas.php), pelo Espelho de Ponto (espelho.php /
 * espelho_pdf.php) e pelo portal do funcionário (/app/me), para que "horas a
 * favor" e "horas em débito" saiam iguais em todas as telas.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ajuste_ponto.php';    // jornada_listar()
require_once __DIR__ . '/justificativas.php';  // jus_garantir_schema(), jus_recalcular_sessao()

/** Auto-migração idempotente: colunas de âncora do banco de horas em dot_usuarios. */
function bh_garantir_schema(): void {
    static $ok = false; if ($ok) return;
    $tem = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dot_usuarios'
          AND COLUMN_NAME='data_inicio_banco'")->fetchColumn();
    if (!$tem) {
        db()->exec("ALTER TABLE dot_usuarios
            ADD COLUMN data_inicio_banco DATE NULL,
            ADD COLUMN saldo_inicial_minutos INT NOT NULL DEFAULT 0");
    }
    $ok = true;
}

/**
 * Data-âncora do banco de horas: a apuração nunca conta dias anteriores a ela.
 * Prioridade: data_inicio_banco (definida pelo RH) → 1ª batida (menor data_ref
 * com sessão) → data de cadastro. É explícita e estável: ao contrário de
 * "recalcular sempre pela batida mais antiga", não se desloca se alguém corrigir
 * ou apagar a batida mais antiga — o cálculo do saldo continua determinístico.
 */
function bh_data_inicio_banco(array $f): ?string {
    if (!empty($f['data_inicio_banco'])) return (string)$f['data_inicio_banco'];
    $st = db()->prepare("SELECT MIN(data_ref) FROM dot_sessoes WHERE usuario_id=?");
    $st->execute([$f['id']]);
    $primeira = $st->fetchColumn();
    if ($primeira) return (string)$primeira;
    return !empty($f['criado_em']) ? substr((string)$f['criado_em'], 0, 10) : null;
}

/** Converte "±HH:MM" (ou minutos avulsos) em minutos inteiros com sinal. */
function bh_parse_hhmm(string $txt): int {
    $txt = trim($txt);
    if ($txt === '') return 0;
    $neg = strncmp($txt, '-', 1) === 0;
    $txt = ltrim($txt, '+-');
    if (strpos($txt, ':') !== false) {
        [$h, $m] = array_pad(explode(':', $txt, 2), 2, '0');
        $min = ((int)$h) * 60 + (int)$m;
    } else {
        $min = (int)$txt;
    }
    return $neg ? -$min : $min;
}

/**
 * Apura o banco de horas de um funcionário no período. Regras:
 *   • Dia útil trabalhado ....... saldo = trabalhado − carga do dia (jornada)
 *   • Falta em dia útil ......... saldo = −carga do dia (débito)
 *   • Falta abonada/feriado ..... dia neutro (justificativa aprovada)
 *   • Folga trabalhada .......... carga 0 → tudo é crédito
 *   • Folga não trabalhada ...... não entra na conta
 *   • Dia de hoje ............... só conta com a sessão encerrada
 * Varre TODOS os dias do período (até hoje), para que faltas gerem débito.
 * Nunca conta dias anteriores à âncora do funcionário (evita débito falso
 * do período em que a pessoa ainda não registrava ponto).
 *
 * Com $carry_anterior=true, apura também o trecho entre a âncora e o início do
 * período filtrado (sem recálculo de sessões, para não pesar), devolvendo-o em
 * 'saldo_anterior' — e o acumulado passa a partir de saldo_inicial + anterior.
 *
 * Retorna:
 *   funcionario       linha de dot_usuarios (null se não pertence à empresa)
 *   dias              apuração diária (data_ref, carga, minutos_*, situacao, abono, saldo, saldo_acumulado)
 *   total_positivo    minutos a favor no período
 *   total_negativo    minutos em débito no período (negativo)
 *   saldo_periodo     total_positivo + total_negativo
 *   saldo_inicial     saldo herdado (migração) do cadastro
 *   saldo_anterior    saldo apurado entre a âncora e o início do período (0 sem carry)
 *   saldo_acumulado   saldo_inicial + saldo_anterior + saldo_periodo
 *   inicio_efetivo    primeiro dia realmente apurado
 *   data_inicio_banco / data_inicio_fonte  âncora e sua origem
 */
function bh_apurar(int $func, int $emp_id, string $periodo_inicio, string $periodo_fim, bool $recalcular_sessoes = true, bool $carry_anterior = false): array {
    bh_garantir_schema();

    $stmt = db()->prepare("SELECT u.* FROM dot_usuarios u WHERE u.id=? AND u.empresa_id=?");
    $stmt->execute([$func, $emp_id]);
    $f = $stmt->fetch();
    if (!$f) {
        return ['funcionario'=>null, 'dias'=>[], 'total_positivo'=>0, 'total_negativo'=>0,
            'saldo_periodo'=>0, 'saldo_inicial'=>0, 'saldo_anterior'=>0, 'saldo_acumulado'=>0,
            'inicio_efetivo'=>$periodo_inicio, 'data_inicio_banco'=>null, 'data_inicio_fonte'=>'—'];
    }

    // Âncora efetiva: a apuração NUNCA conta dias anteriores a ela.
    $data_inicio_banco = bh_data_inicio_banco($f);
    $data_inicio_fonte = !empty($f['data_inicio_banco']) ? 'definida pelo RH'
                       : ($data_inicio_banco ? 'primeira batida / cadastro' : '—');
    $saldo_inicial     = (int)($f['saldo_inicial_minutos'] ?? 0);
    $inicio_efetivo    = $data_inicio_banco ? max($periodo_inicio, $data_inicio_banco) : $periodo_inicio;

    // A meta diária (carga) vem da jornada do funcionário por dia da semana.
    $jornada = jornada_listar($func);

    // Sessões podem ter minutos_trabalhados desatualizado (batidas antigas que
    // não recalcularam a sessão). Recalcula antes de apurar.
    if ($recalcular_sessoes) {
        $stmt = db()->prepare("SELECT id FROM dot_sessoes WHERE usuario_id=? AND data_ref BETWEEN ? AND ?");
        $stmt->execute([$func, $inicio_efetivo, $periodo_fim]);
        foreach ($stmt->fetchAll() as $row) {
            try { jus_recalcular_sessao((int)$row['id']); } catch (Throwable $e) {}
        }
    }

    $stmt = db()->prepare("SELECT s.data_ref, s.minutos_trabalhados, s.minutos_ociosos, s.minutos_extras, s.status
        FROM dot_sessoes s
        WHERE s.usuario_id=? AND s.data_ref BETWEEN ? AND ?");
    $stmt->execute([$func, $inicio_efetivo, $periodo_fim]);
    $sessoes = [];
    foreach ($stmt->fetchAll() as $s) $sessoes[$s['data_ref']] = $s;

    // Abonos: justificativas de falta APROVADAS pelo gestor. Não geram débito.
    jus_garantir_schema();
    $stmt = db()->prepare("SELECT id, data_ref, tipo, motivo, anexo_arquivo
        FROM dot_justificativas
        WHERE usuario_id=? AND empresa_id=? AND categoria='justificativa'
          AND status='aprovada' AND data_ref BETWEEN ? AND ?
        ORDER BY decidido_em ASC"); // último aprovado vence o slot do dia
    $stmt->execute([$func, $emp_id, $inicio_efetivo, $periodo_fim]);
    $abonos = [];
    foreach ($stmt->fetchAll() as $a) $abonos[$a['data_ref']] = $a; // 1 por dia basta

    $hoje = date('Y-m-d');
    $fim_efetivo = min($periodo_fim, $hoje); // não conta dias no futuro

    // Saldo carregado de períodos anteriores ao filtro (âncora → véspera do início).
    $saldo_anterior = 0;
    if ($carry_anterior && $data_inicio_banco && $data_inicio_banco < $inicio_efetivo) {
        $vespera = date('Y-m-d', strtotime($inicio_efetivo . ' -1 day'));
        $ant = bh_apurar($func, $emp_id, $data_inicio_banco, $vespera, false, false);
        $saldo_anterior = $ant['saldo_periodo'];
    }

    $dias = [];
    $saldo_acumulado = $saldo_inicial + $saldo_anterior; // parte do herdado + períodos anteriores
    $total_positivo = 0; $total_negativo = 0;

    $cur = new DateTime($inicio_efetivo);
    $lim = new DateTime($fim_efetivo);
    while ($cur <= $lim) {
        $data = $cur->format('Y-m-d');
        $dow  = (int)$cur->format('w');          // 0=domingo .. 6=sábado
        $cur->modify('+1 day');

        $jd       = $jornada[$dow] ?? null;
        $trabalha = $jd ? (int)$jd['trabalha'] : ($dow >= 1 && $dow <= 5 ? 1 : 0);
        $carga    = $trabalha ? (int)($jd['carga_minutos'] ?: 480) : 0;

        $sess       = $sessoes[$data] ?? null;
        $trabalhado = $sess ? (int)$sess['minutos_trabalhados'] : 0;

        // Dia de hoje: só apura depois que a pessoa registrar a saída (sessão
        // encerrada). Enquanto está em curso, não penaliza nem conta como falta.
        if ($data === $hoje && (!$sess || ($sess['status'] ?? '') !== 'encerrada')) continue;
        // Folga não trabalhada: não polui o relatório.
        if ($carga === 0 && $trabalhado === 0) continue;

        $abono = $abonos[$data] ?? null;

        if (!$sess && $carga > 0) {
            // Falta: abonada (justificativa aprovada) não gera débito.
            // Feriado é um abono especial: o dia é neutro e aparece como "Feriado".
            if ($abono) {
                $situacao = ($abono['tipo'] === 'feriado') ? 'Feriado' : 'Falta abonada';
            } else {
                $situacao = 'Falta';
            }
        } elseif ($carga === 0 && $trabalhado) {
            $situacao = 'Folga trab.';
        } else {
            $situacao = 'Normal';
        }

        // Saldo do dia. Falta abonada zera o débito (dia neutro).
        if (!$sess && $carga > 0 && $abono) {
            $saldo = 0;
        } else {
            $saldo = $trabalhado - $carga;
        }
        $saldo_acumulado += $saldo;
        if ($saldo > 0) $total_positivo += $saldo; else $total_negativo += $saldo;

        $dias[] = [
            'data_ref'            => $data,
            'carga'               => $carga,
            'minutos_trabalhados' => $trabalhado,
            'minutos_ociosos'     => $sess ? (int)$sess['minutos_ociosos'] : 0,
            'minutos_extras'      => $sess ? (int)$sess['minutos_extras'] : 0,
            'situacao'            => $situacao,
            'abono'               => $abono,
            'saldo'               => $saldo,
            'saldo_acumulado'     => $saldo_acumulado,
        ];
    }

    return [
        'funcionario'       => $f,
        'dias'              => $dias,
        'total_positivo'    => $total_positivo,
        'total_negativo'    => $total_negativo,
        'saldo_periodo'     => $total_positivo + $total_negativo,
        'saldo_inicial'     => $saldo_inicial,
        'saldo_anterior'    => $saldo_anterior,
        'saldo_acumulado'   => $saldo_acumulado,
        'inicio_efetivo'    => $inicio_efetivo,
        'data_inicio_banco' => $data_inicio_banco,
        'data_inicio_fonte' => $data_inicio_fonte,
    ];
}
