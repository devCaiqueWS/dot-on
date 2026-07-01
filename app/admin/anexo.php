<?php
/**
 * Servidor de comprovantes (gated).
 * Acesso permitido a:
 *   - admin / gestor / rh da MESMA empresa (sessão web)
 *   - o próprio funcionário dono da solicitação (via ?token=api_token)
 * Os arquivos ficam fora do alcance direto (uploads/.htaccess Require all denied).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/justificativas.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Requisição inválida.'); }

// Quem está pedindo?
$empresa_id = null; $usuario_id = null; $perfil = null;

$token = $_GET['token'] ?? null;
if ($token) {
    $u = autenticar_token($token);
    if ($u) { $empresa_id = (int)$u['empresa_id']; $usuario_id = (int)$u['id']; $perfil = $u['perfil']; }
} else {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['dot_user'])) {
        $s = $_SESSION['dot_user'];
        $empresa_id = (int)$s['empresa_id']; $usuario_id = (int)$s['id']; $perfil = $s['perfil'];
    }
}

if (!$empresa_id) { http_response_code(401); exit('Não autenticado.'); }

jus_garantir_schema();
$st = db()->prepare("SELECT usuario_id, empresa_id, anexo_arquivo, anexo_nome_original, anexo_mime
                     FROM dot_justificativas WHERE id=? LIMIT 1");
$st->execute([$id]);
$j = $st->fetch();

if (!$j || (int)$j['empresa_id'] !== $empresa_id) { http_response_code(404); exit('Comprovante não encontrado.'); }
if (!$j['anexo_arquivo']) { http_response_code(404); exit('Esta solicitação não possui comprovante.'); }

$gestor = in_array($perfil, ['admin','gestor','rh'], true);
$dono   = ((int)$j['usuario_id'] === $usuario_id);
if (!$gestor && !$dono) { http_response_code(403); exit('Acesso negado.'); }

// Impede path traversal: usa só o basename do nome guardado
$arquivo = basename($j['anexo_arquivo']);
$caminho = jus_dir_uploads() . '/' . $arquivo;
if (!is_file($caminho)) { http_response_code(404); exit('Arquivo ausente no servidor.'); }

$mime = $j['anexo_mime'] ?: 'application/octet-stream';
$nome = $j['anexo_nome_original'] ?: $arquivo;

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($caminho));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $nome) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');
readfile($caminho);
