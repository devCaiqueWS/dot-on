<?php
$titulo = 'Importar Funcionários'; $pagina = 'funcionarios';
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/mailer.php';

if (!in_array($user['perfil'], ['admin','rh','gestor'])) {
    echo "<div class='alert alert-erro'>Acesso negado</div></main></body></html>";
    exit;
}

$emp_id = $user['empresa_id'];
$msg = ''; $erro = '';
$preview = []; $importados = []; $erros_imp = [];

// Função parser XLSX (compartilhada)
function parse_xlsx_admin($file) {
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) return [];
    $strings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml && preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $ssXml, $m)) {
        foreach ($m[1] as $si) {
            $t = '';
            if (preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tm)) {
                foreach ($tm[1] as $tt) $t .= $tt;
            }
            $strings[] = html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if (!$sheetXml) return [];
    $rows = [];
    if (preg_match_all('/<row\b[^>]*>(.*?)<\/row>/s', $sheetXml, $rm)) {
        foreach ($rm[1] as $rowXml) {
            $cells = [];
            if (preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/s', $rowXml, $cm, PREG_SET_ORDER)) {
                foreach ($cm as $cMatch) {
                    $is_str = strpos($cMatch[1], 't="s"') !== false;
                    if (preg_match('/<v>(.*?)<\/v>/', $cMatch[2], $vm)) {
                        $val = $vm[1];
                        if ($is_str) $val = $strings[(int)$val] ?? '';
                        $cells[] = $val;
                    } else $cells[] = '';
                }
            }
            $rows[] = $cells;
        }
    }
    return $rows;
}

function gerar_senha_imp($len = 10) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i=0; $i<$len; $i++) $s .= $chars[random_int(0, strlen($chars)-1)];
    return $s;
}

define('IMPORT_MAX_BYTES', 2 * 1024 * 1024);   // 2 MB
define('IMPORT_MAX_LINHAS', 2000);              // teto de linhas processadas

// Step 1: Preview do arquivo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo']) && empty($_POST['confirmar'])) {
    csrf_check();
    if (($_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $erro = "Falha no upload do arquivo. Tente novamente.";
    } elseif (($_FILES['arquivo']['size'] ?? 0) > IMPORT_MAX_BYTES) {
        $erro = "Arquivo muito grande (máx. 2 MB).";
    } elseif (!is_uploaded_file($_FILES['arquivo']['tmp_name'])) {
        $erro = "Upload inválido.";
    }
    $tmp = $_FILES['arquivo']['tmp_name'];
    $nome_arq = $_FILES['arquivo']['name'];
    $ext = strtolower(pathinfo($nome_arq, PATHINFO_EXTENSION));

    $linhas = [];
    if (!$erro) {
        if ($ext === 'csv') {
            $f = fopen($tmp, 'r');
            $primeira_linha = fgets($f);
            rewind($f);
            $delim = (substr_count($primeira_linha, ';') > substr_count($primeira_linha, ',')) ? ';' : ',';
            while (($row = fgetcsv($f, 0, $delim)) !== false) {
                $linhas[] = array_map('trim', $row);
                if (count($linhas) > IMPORT_MAX_LINHAS) break;
            }
            fclose($f);
        } elseif ($ext === 'xlsx') {
            $linhas = parse_xlsx_admin($tmp);
            if (count($linhas) > IMPORT_MAX_LINHAS) $linhas = array_slice($linhas, 0, IMPORT_MAX_LINHAS);
        } else {
            $erro = "Formato não suportado. Use .csv ou .xlsx";
        }
    }

    if (!$erro && count($linhas) >= 2) {
        $cabec = array_map(function($c){ return strtolower(trim((string)$c)); }, $linhas[0]);
        $idx = ['nome'=>null, 'email'=>null, 'cpf'=>null, 'matricula'=>null];
        foreach ($cabec as $i => $col) {
            if (in_array($col, ['nome','funcionario','colaborador','nome completo'])) $idx['nome'] = $i;
            if (in_array($col, ['email','e-mail','mail'])) $idx['email'] = $i;
            if (in_array($col, ['cpf'])) $idx['cpf'] = $i;
            if (in_array($col, ['matricula','matrícula','mat','id'])) $idx['matricula'] = $i;
        }
        if ($idx['nome'] === null || $idx['email'] === null) {
            $erro = "Cabeçalho precisa ter as colunas <strong>nome</strong> e <strong>email</strong>.";
        } else {
            for ($i=1; $i<count($linhas); $i++) {
                $r = $linhas[$i];
                $nome = trim((string)($r[$idx['nome']] ?? ''));
                $email = strtolower(trim((string)($r[$idx['email']] ?? '')));
                if (!$nome || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
                $preview[] = [
                    'nome' => $nome,
                    'email' => $email,
                    'cpf' => $idx['cpf']!==null ? preg_replace('/\D/','', (string)($r[$idx['cpf']] ?? '')) : '',
                    'matricula' => $idx['matricula']!==null ? trim((string)($r[$idx['matricula']] ?? '')) : '',
                ];
            }
            $_SESSION['preview_funcionarios'] = $preview;
        }
    } elseif (!$erro) {
        $erro = "Planilha vazia ou sem cabeçalho.";
    }
}

// Step 2: Confirmar importação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['confirmar'])) {
    csrf_check();
    $lista = $_SESSION['preview_funcionarios'] ?? [];
    $enviar_email = !empty($_POST['enviar_email']);
    $url_exe = 'https://dot-on.com.br/app/downloads/DOT-ON-Agent.exe';
    $url_painel = 'https://dot-on.com.br/app/admin/login.php';
    $nome_empresa = $empresa['nome_fantasia'] ?: $empresa['razao_social'];

    foreach ($lista as $i => $f) {
        try {
            // Verifica duplicata
            $st = db()->prepare("SELECT id FROM dot_usuarios WHERE email=?");
            $st->execute([$f['email']]);
            if ($st->fetch()) { $erros_imp[] = htmlspecialchars($f['email']) . " - já existe"; continue; }

            $senha_temp = gerar_senha_imp();
            $hash = password_hash($senha_temp, PASSWORD_BCRYPT);
            $token = bin2hex(random_bytes(32));
            $mat = $f['matricula'] ?: sprintf('FUN%03d', $i+1);

            db()->prepare("INSERT INTO dot_usuarios (empresa_id, escala_id, matricula, nome_completo, email, cpf, senha_hash, perfil, api_token, precisa_trocar_senha, ativo, criado_em)
                VALUES (?, (SELECT id FROM dot_escalas WHERE empresa_id=? LIMIT 1), ?, ?, ?, ?, ?, 'funcionario', ?, 1, 1, NOW())")
                ->execute([$emp_id, $emp_id, $mat, $f['nome'], $f['email'], $f['cpf'], $hash, $token]);

            if ($enviar_email) {
                [$assunto, $html, $texto] = email_template_boasvindas_funcionario($f['nome'], $nome_empresa, $f['email'], $senha_temp, $url_exe, $url_painel);
                email_enfileirar($f['email'], $f['nome'], $assunto, $html, $texto, $emp_id);
            }
            $importados[] = $f['email'];
        } catch (Throwable $e) {
            error_log("DOT-ON import funcionario: " . $e->getMessage());
            $erros_imp[] = htmlspecialchars($f['email']) . " - não foi possível importar";
        }
    }
    if ($enviar_email) email_processar_fila(50);
    unset($_SESSION['preview_funcionarios']);
    $msg = "✅ <strong>" . count($importados) . " funcionários importados</strong>" . ($enviar_email ? " e e-mails enviados" : "");
    if ($erros_imp) $msg .= ". <details><summary>" . count($erros_imp) . " erros</summary><ul>";
    foreach ($erros_imp as $e) $msg .= "<li>$e</li>";
    if ($erros_imp) $msg .= "</ul></details>";
    $preview = []; // limpa
}
?>

<?php if ($msg): ?><div class="alert alert-ok"><?= $msg ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert alert-erro"><?= $erro ?></div><?php endif; ?>

<?php if (!$preview): ?>
<div class="panel">
<h2>📤 Importar funcionários via planilha</h2>
<p style="color:#94a3b8;margin-bottom:18px">Envie um arquivo <strong>CSV ou XLSX</strong> com as colunas: <code>nome, email, cpf, matricula</code> (cpf e matricula opcionais).</p>

<form method="post" enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:14px">
<?= csrf_field() ?>
<input type="file" name="arquivo" accept=".csv,.xlsx" required style="padding:10px;border:2px dashed #475569;border-radius:8px;background:#1e293b;color:#e2e8f0">
<button class="btn btn-primary">📋 Analisar arquivo</button>
</form>

<details style="margin-top:18px">
<summary style="cursor:pointer;color:#38bdf8">📥 Baixar modelo de planilha</summary>
<p style="margin:10px 0;color:#94a3b8">Use este modelo para garantir o formato correto:</p>
<a href="data:text/csv;base64,<?= base64_encode("nome,email,cpf,matricula\nMaria Silva,maria@empresa.com.br,12345678901,001\nJoão Souza,joao@empresa.com.br,23456789012,002\n") ?>" download="modelo_funcionarios.csv" class="btn">⬇ Baixar modelo_funcionarios.csv</a>
</details>
</div>
<?php else: ?>

<div class="panel">
<h2>👀 Pré-visualização: <?= count($preview) ?> funcionários encontrados</h2>
<p style="color:#94a3b8;margin-bottom:14px">Confira os dados e clique em <strong>Importar</strong> para confirmar.</p>

<table class="tbl">
<thead><tr><th>#</th><th>Nome</th><th>E-mail</th><th>CPF</th><th>Matrícula</th></tr></thead>
<tbody>
<?php foreach ($preview as $i => $f): ?>
<tr>
<td><?= $i+1 ?></td>
<td><?= htmlspecialchars($f['nome']) ?></td>
<td><?= htmlspecialchars($f['email']) ?></td>
<td><?= htmlspecialchars($f['cpf'] ?: '—') ?></td>
<td><?= htmlspecialchars($f['matricula'] ?: 'auto') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<form method="post" style="margin-top:18px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
<?= csrf_field() ?>
<input type="hidden" name="confirmar" value="1">
<label style="display:flex;align-items:center;gap:6px;color:#e2e8f0">
<input type="checkbox" name="enviar_email" value="1" checked>
📧 Enviar e-mail de boas-vindas com senha temporária para cada funcionário
</label>
<button class="btn btn-primary" type="submit" onclick="return confirm('Importar <?= count($preview) ?> funcionários?')">✅ Confirmar Importação</button>
<a href="funcionarios_importar.php" class="btn">❌ Cancelar</a>
</form>
</div>

<?php endif; ?>

</main></body></html>
