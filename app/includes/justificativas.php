<?php
/**
 * DOT-ON · Justificativas e Correções de Ponto
 * =================================================================
 * Camada de negócio para:
 *  - Justificar faltas, atrasos, saídas antecipadas, etc. (com upload
 *    de comprovações: atestados, declarações, etc.)
 *  - Solicitar correção de ponto quando o funcionário esquece de bater
 *    (também justificada e sujeita à aprovação do admin/gestor).
 *  - Aprovação/rejeição pelo admin, gestor ou RH (junto das horas extras).
 *
 * Ao aprovar uma CORREÇÃO, a batida faltante é inserida de fato na
 * cadeia REP-P (hash encadeado) marcada como extemporânea/correção,
 * preservando a integridade fiscal exigida pela Portaria MTP 671/2021.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/repp.php';
require_once __DIR__ . '/crp.php';

// Diretório (fora da árvore pública servida diretamente — protegido por .htaccess)
function jus_dir_uploads(): string {
    return __DIR__ . '/../uploads/justificativas';
}

// Tipos aceitos no upload de comprovações
const JUS_MIMES_OK = [
    'application/pdf'  => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/heic'      => 'heic',
    'image/webp'      => 'webp',
];
const JUS_MAX_BYTES = 8 * 1024 * 1024; // 8 MB por comprovante

/** Categorias e tipos válidos */
const JUS_TIPOS_JUSTIFICATIVA = ['atraso','falta','saida_antecipada','medico','atestado','abono','outro'];
const JUS_TIPOS_BATIDA        = ['entrada','saida_intervalo','retorno_intervalo','saida'];

/**
 * Cria a tabela dot_justificativas se ainda não existir (idempotente).
 * O projeto não usa migrations; seguimos o padrão defensivo do código.
 */
function jus_garantir_schema(): void {
    static $ok = false;
    if ($ok) return;
    db()->exec("CREATE TABLE IF NOT EXISTS dot_justificativas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        empresa_id INT NOT NULL,
        usuario_id INT NOT NULL,
        categoria ENUM('justificativa','correcao') NOT NULL DEFAULT 'justificativa',
        tipo VARCHAR(40) NOT NULL DEFAULT 'outro',
        data_ref DATE NOT NULL,
        batida_tipo VARCHAR(30) NULL,
        horario_correto TIME NULL,
        motivo TEXT NOT NULL,
        anexo_arquivo VARCHAR(255) NULL,
        anexo_nome_original VARCHAR(255) NULL,
        anexo_mime VARCHAR(100) NULL,
        status ENUM('pendente','aprovada','rejeitada') NOT NULL DEFAULT 'pendente',
        batida_id INT NULL,
        solicitado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        decidido_em DATETIME NULL,
        decidido_por INT NULL,
        motivo_decisao VARCHAR(255) NULL,
        INDEX idx_empresa_status (empresa_id, status),
        INDEX idx_usuario (usuario_id),
        INDEX idx_categoria (empresa_id, categoria, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ok = true;
}

/**
 * Processa o upload de uma comprovação ($_FILES['anexo']).
 * Retorna ['arquivo'=>..., 'nome_original'=>..., 'mime'=>...] ou null se
 * não houver arquivo. Lança RuntimeException em arquivo inválido.
 */
function jus_processar_upload(?array $file, int $empresa_id, int $usuario_id): ?array {
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no upload do comprovante (código ' . $file['error'] . ').');
    }
    if ($file['size'] > JUS_MAX_BYTES) {
        throw new RuntimeException('Comprovante muito grande (máx. 8 MB).');
    }
    $mime = '';
    if (class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    }
    if (!$mime) $mime = $file['type'] ?? '';
    if (!isset(JUS_MIMES_OK[$mime])) {
        throw new RuntimeException('Formato não aceito. Envie PDF, JPG, PNG ou WEBP.');
    }
    $ext = JUS_MIMES_OK[$mime];

    $dir = jus_dir_uploads();
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }

    $nome = sprintf('e%d_u%d_%s.%s', $empresa_id, $usuario_id, bin2hex(random_bytes(12)), $ext);
    $destino = $dir . '/' . $nome;
    if (!move_uploaded_file($file['tmp_name'], $destino)) {
        // fallback para ambientes onde o arquivo não veio via HTTP upload
        if (!@rename($file['tmp_name'], $destino)) {
            throw new RuntimeException('Não foi possível salvar o comprovante.');
        }
    }
    @chmod($destino, 0640);

    return [
        'arquivo'       => $nome,
        'nome_original' => mb_substr(basename($file['name'] ?? 'comprovante.' . $ext), 0, 255),
        'mime'          => $mime,
    ];
}

/**
 * Registra uma justificativa ou correção. $dados:
 *   categoria, tipo, data_ref, batida_tipo, horario_correto, motivo
 * $anexo: retorno de jus_processar_upload() ou null.
 * Retorna o id criado.
 */
function jus_criar(int $empresa_id, int $usuario_id, array $dados, ?array $anexo = null): int {
    jus_garantir_schema();

    $categoria = ($dados['categoria'] ?? 'justificativa') === 'correcao' ? 'correcao' : 'justificativa';
    $data_ref  = $dados['data_ref'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_ref)) {
        throw new RuntimeException('Data inválida.');
    }
    $motivo = trim($dados['motivo'] ?? '');
    if (mb_strlen($motivo) < 10) {
        throw new RuntimeException('Descreva o motivo com pelo menos 10 caracteres.');
    }

    $batida_tipo = null;
    $horario = null;
    if ($categoria === 'correcao') {
        $batida_tipo = $dados['batida_tipo'] ?? '';
        if (!in_array($batida_tipo, JUS_TIPOS_BATIDA, true)) {
            throw new RuntimeException('Selecione qual batida precisa ser corrigida.');
        }
        $horario = trim($dados['horario_correto'] ?? '');
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $horario)) {
            throw new RuntimeException('Informe o horário correto da batida (HH:MM).');
        }
        if (strlen($horario) === 5) $horario .= ':00';
        $tipo = 'esquecimento';
    } else {
        $tipo = $dados['tipo'] ?? 'outro';
        if (!in_array($tipo, JUS_TIPOS_JUSTIFICATIVA, true)) $tipo = 'outro';
    }

    db()->prepare("INSERT INTO dot_justificativas
        (empresa_id, usuario_id, categoria, tipo, data_ref, batida_tipo, horario_correto,
         motivo, anexo_arquivo, anexo_nome_original, anexo_mime, status, solicitado_em)
        VALUES (?,?,?,?,?,?,?,?,?,?,?, 'pendente', NOW())")
        ->execute([
            $empresa_id, $usuario_id, $categoria, $tipo, $data_ref, $batida_tipo, $horario,
            $motivo, $anexo['arquivo'] ?? null, $anexo['nome_original'] ?? null, $anexo['mime'] ?? null,
        ]);
    $id = (int)db()->lastInsertId();
    auditar($usuario_id, 'criar_' . $categoria, 'justificativa', $id, ['tipo'=>$tipo, 'data_ref'=>$data_ref]);
    return $id;
}

/** Lista as solicitações de um funcionário (mais recentes primeiro). */
function jus_listar_do_funcionario(int $usuario_id, int $limite = 50): array {
    jus_garantir_schema();
    $st = db()->prepare("SELECT * FROM dot_justificativas WHERE usuario_id=? ORDER BY solicitado_em DESC LIMIT ?");
    $st->bindValue(1, $usuario_id, PDO::PARAM_INT);
    $st->bindValue(2, $limite, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/**
 * Lista para o painel admin, com filtro de categoria e status.
 * Se $somente_usuario for informado, restringe às solicitações desse
 * funcionário (isolamento para quem não é admin/gestor/rh).
 */
function jus_listar_empresa(int $empresa_id, string $categoria = 'todas', string $status = 'pendente', int $limite = 200, ?int $somente_usuario = null): array {
    jus_garantir_schema();
    $sql = "SELECT j.*, u.nome_completo, u.matricula
            FROM dot_justificativas j JOIN dot_usuarios u ON u.id=j.usuario_id
            WHERE j.empresa_id = ?";
    $params = [$empresa_id];
    if ($somente_usuario !== null) { $sql .= " AND j.usuario_id = ?"; $params[] = $somente_usuario; }
    if ($categoria !== 'todas') { $sql .= " AND j.categoria = ?"; $params[] = $categoria; }
    if ($status !== 'todos')    { $sql .= " AND j.status = ?";    $params[] = $status; }
    $sql .= " ORDER BY j.solicitado_em DESC LIMIT " . (int)$limite;
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Conta pendências (justificativas + correções) — usado no badge do menu. */
function jus_contar_pendentes(int $empresa_id): int {
    jus_garantir_schema();
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM dot_justificativas WHERE empresa_id=? AND status='pendente'");
        $st->execute([$empresa_id]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/** Busca uma solicitação garantindo o isolamento por empresa. */
function jus_buscar(int $id, int $empresa_id): ?array {
    jus_garantir_schema();
    $st = db()->prepare("SELECT j.*, u.nome_completo, u.matricula, u.cpf, u.pis
        FROM dot_justificativas j JOIN dot_usuarios u ON u.id=j.usuario_id
        WHERE j.id=? AND j.empresa_id=? LIMIT 1");
    $st->execute([$id, $empresa_id]);
    return $st->fetch() ?: null;
}

/**
 * Aprova uma solicitação. Para CORREÇÃO, insere a batida faltante na
 * cadeia REP-P. Retorna ['ok'=>bool, 'msg'=>string, 'nsr'=>?int].
 */
function jus_aprovar(int $id, int $empresa_id, int $aprovador_id, string $observacao = ''): array {
    jus_garantir_schema();
    $j = jus_buscar($id, $empresa_id);
    if (!$j) return ['ok'=>false, 'msg'=>'Solicitação não encontrada.'];
    if ($j['status'] !== 'pendente') return ['ok'=>false, 'msg'=>'Esta solicitação já foi decidida.'];

    $pdo = db();
    $nsr = null; $batida_id = null;

    if ($j['categoria'] === 'correcao') {
        // Insere a batida faltante na cadeia (extemporânea / correção)
        $momento = $j['data_ref'] . ' ' . ($j['horario_correto'] ?: '00:00:00');
        $tipo = $j['batida_tipo'];

        $pdo->beginTransaction();
        try {
            // Sessão do dia (cria se não existir)
            $st = $pdo->prepare("SELECT id FROM dot_sessoes WHERE usuario_id=? AND data_ref=?");
            $st->execute([$j['usuario_id'], $j['data_ref']]);
            $sessao_id = $st->fetchColumn();
            if (!$sessao_id) {
                $pdo->prepare("INSERT INTO dot_sessoes (usuario_id, data_ref, inicio, status) VALUES (?,?,?, 'aberta')")
                    ->execute([$j['usuario_id'], $j['data_ref'], $momento]);
                $sessao_id = (int)$pdo->lastInsertId();
            }

            $nsr = proximo_nsr($empresa_id);
            $hash_anterior = repp_ultimo_hash($empresa_id);
            $batida_arr = [
                'nsr'=>$nsr, 'empresa_id'=>$empresa_id, 'usuario_id'=>$j['usuario_id'],
                'cpf_snapshot'=>$j['cpf'] ?? '', 'pis_snapshot'=>$j['pis'] ?? '',
                'tipo'=>$tipo, 'momento'=>$momento,
            ];
            $hash_atual = repp_hash_batida($batida_arr, $hash_anterior);

            $obs = "Correção aprovada da solicitação #$id";
            $motivo_ext = mb_substr('Esquecimento de bater: ' . $j['motivo'], 0, 500);
            $pdo->prepare("INSERT INTO dot_batidas
                (nsr, sessao_id, empresa_id, usuario_id, tipo, momento, origem, ip_origem, hostname,
                 cpf_snapshot, pis_snapshot, hash_registro, hash_anterior, hash_alg, extemporanea,
                 motivo_extemporanea, observacao)
                VALUES (?,?,?,?,?,?, 'manual', ?, 'correcao-aprovada', ?, ?, ?, ?, 'SHA-256', 1, ?, ?)")
                ->execute([$nsr, $sessao_id, $empresa_id, $j['usuario_id'], $tipo, $momento,
                           $_SERVER['REMOTE_ADDR'] ?? null, $j['cpf'] ?? '', $j['pis'] ?? '',
                           $hash_atual, $hash_anterior, $motivo_ext, $obs]);
            $batida_id = (int)$pdo->lastInsertId();

            // Se for a saída, encerra a sessão
            if ($tipo === 'saida') {
                $pdo->prepare("UPDATE dot_sessoes SET fim=?, status='encerrada' WHERE id=?")
                    ->execute([$momento, $sessao_id]);
            }

            $pdo->prepare("UPDATE dot_justificativas
                SET status='aprovada', decidido_em=NOW(), decidido_por=?, motivo_decisao=?, batida_id=?
                WHERE id=? AND empresa_id=? AND status='pendente'")
                ->execute([$aprovador_id, $observacao, $batida_id, $id, $empresa_id]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[DOT-ON jus_aprovar correcao] ' . $e->getMessage());
            return ['ok'=>false, 'msg'=>'Erro ao inserir a batida corrigida.'];
        }

        // Recalcula a sessão e emite CRP (fora da transação, tolerante a falha)
        try { jus_recalcular_sessao((int)$sessao_id); } catch (Throwable $e) {}
        try { crp_emitir($batida_id, false); } catch (Throwable $e) { error_log('CRP correcao: ' . $e->getMessage()); }

        auditar($aprovador_id, 'aprovar_correcao', 'justificativa', $id, ['nsr'=>$nsr, 'batida_id'=>$batida_id]);
        return ['ok'=>true, 'msg'=>"Correção aprovada. Batida registrada (NSR " . str_pad((string)$nsr,6,'0',STR_PAD_LEFT) . ").", 'nsr'=>$nsr];
    }

    // Justificativa simples (sem inserir batida)
    db()->prepare("UPDATE dot_justificativas
        SET status='aprovada', decidido_em=NOW(), decidido_por=?, motivo_decisao=?
        WHERE id=? AND empresa_id=? AND status='pendente'")
        ->execute([$aprovador_id, $observacao, $id, $empresa_id]);
    auditar($aprovador_id, 'aprovar_justificativa', 'justificativa', $id);
    return ['ok'=>true, 'msg'=>'Justificativa aprovada.', 'nsr'=>null];
}

/** Rejeita uma solicitação. */
function jus_rejeitar(int $id, int $empresa_id, int $aprovador_id, string $observacao = ''): array {
    jus_garantir_schema();
    $st = db()->prepare("UPDATE dot_justificativas
        SET status='rejeitada', decidido_em=NOW(), decidido_por=?, motivo_decisao=?
        WHERE id=? AND empresa_id=? AND status='pendente'");
    $st->execute([$aprovador_id, $observacao, $id, $empresa_id]);
    if ($st->rowCount() === 0) return ['ok'=>false, 'msg'=>'Solicitação não encontrada ou já decidida.'];
    auditar($aprovador_id, 'rejeitar_justificativa', 'justificativa', $id);
    return ['ok'=>true, 'msg'=>'Solicitação rejeitada.'];
}

/**
 * Garante as colunas de anulação (cancelamento) de batidas em dot_batidas.
 * Idempotente — não usa migrations (padrão do projeto). Uma batida anulada
 * permanece na cadeia REP-P/AFD (imutável), mas sai das visões tratadas
 * (espelho, AEJ, cálculo de horas).
 */
function batidas_garantir_cancelamento(): void {
    static $ok = false;
    if ($ok) return;
    $existe = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dot_batidas' AND COLUMN_NAME = 'cancelada'")->fetchColumn();
    if (!$existe) {
        db()->exec("ALTER TABLE dot_batidas
            ADD COLUMN cancelada TINYINT(1) NOT NULL DEFAULT 0,
            ADD COLUMN cancelada_motivo VARCHAR(255) NULL,
            ADD COLUMN cancelada_por INT NULL,
            ADD COLUMN cancelada_em DATETIME NULL");
    }
    $ok = true;
}

/**
 * Recalcula minutos_trabalhados/intervalo de uma sessão a partir das batidas.
 * Usa a mesma lógica de pareamento entrada→saída do portal do funcionário.
 * Ignora batidas anuladas.
 */
function jus_recalcular_sessao(int $sessao_id): void {
    batidas_garantir_cancelamento();
    $st = db()->prepare("SELECT tipo, momento FROM dot_batidas WHERE sessao_id=? AND COALESCE(cancelada,0)=0 ORDER BY momento");
    $st->execute([$sessao_id]);
    $batidas = $st->fetchAll();
    if (!$batidas) return;

    $trabalho = 0; $intervalo = 0;
    $ts_in = null; $ts_int_in = null;
    foreach ($batidas as $b) {
        $ts = strtotime($b['momento']);
        switch ($b['tipo']) {
            case 'entrada': $ts_in = $ts; break;
            case 'saida_intervalo':
                if ($ts_in) { $trabalho += ($ts - $ts_in) / 60; $ts_in = null; }
                $ts_int_in = $ts; break;
            case 'retorno_intervalo':
                if ($ts_int_in) { $intervalo += ($ts - $ts_int_in) / 60; $ts_int_in = null; }
                $ts_in = $ts; break;
            case 'saida':
                if ($ts_in) { $trabalho += ($ts - $ts_in) / 60; $ts_in = null; }
                break;
        }
    }
    db()->prepare("UPDATE dot_sessoes SET minutos_trabalhados=?, minutos_intervalo=? WHERE id=?")
        ->execute([(int)round($trabalho), (int)round($intervalo), $sessao_id]);
}

/** Rótulos amigáveis */
function jus_label_tipo(string $tipo): string {
    return [
        'atraso'=>'Atraso', 'falta'=>'Falta', 'saida_antecipada'=>'Saída antecipada',
        'medico'=>'Consulta médica', 'atestado'=>'Atestado', 'abono'=>'Abono', 'outro'=>'Outro',
        'esquecimento'=>'Esquecimento de bater ponto',
        'entrada'=>'Entrada', 'saida_intervalo'=>'Saída p/ intervalo',
        'retorno_intervalo'=>'Retorno do intervalo', 'saida'=>'Saída',
    ][$tipo] ?? ucfirst(str_replace('_',' ',$tipo));
}
