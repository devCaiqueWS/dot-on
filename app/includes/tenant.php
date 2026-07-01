<?php
/**
 * Multi-Tenant helpers - DOT-ON SaaS
 * Garante que cada empresa só veja seus próprios dados
 */

/**
 * Retorna empresa_id do usuário logado.
 * Falha se não houver sessão ativa.
 */
function tenant_empresa_id() {
    if (!isset($_SESSION['dot_user'])) {
        http_response_code(401);
        exit('Sessão expirada');
    }
    return (int)$_SESSION['dot_user']['empresa_id'];
}

/**
 * Retorna usuário logado ou null
 */
function tenant_user() {
    return $_SESSION['dot_user'] ?? null;
}

/**
 * Verifica se usuário é admin/RH (pode ver dados de outras empresas se super_admin)
 */
function tenant_is_admin() {
    $u = tenant_user();
    if (!$u) return false;
    return in_array($u['perfil'] ?? '', ['admin','rh']);
}

/**
 * SQL helper: adiciona filtro de empresa_id em queries.
 * Uso: $sql = tenant_filter("SELECT * FROM dot_usuarios WHERE 1=1", "u");
 *      → "SELECT ... WHERE 1=1 AND u.empresa_id = ?"
 */
function tenant_filter($sql, $table_alias = '') {
    $col = $table_alias ? "$table_alias.empresa_id" : 'empresa_id';
    return $sql . " AND $col = " . tenant_empresa_id();
}

/**
 * Verifica se um registro pertence à empresa do usuário.
 * Usado em ações de update/delete.
 */
function tenant_check_ownership($table, $id) {
    if (tenant_is_admin() && isset($_GET['super']) && $_GET['super'] === '1') {
        return true; // super admin bypass
    }
    $st = db()->prepare("SELECT empresa_id FROM $table WHERE id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return false;
    return (int)$row['empresa_id'] === tenant_empresa_id();
}

/**
 * Retorna dados da empresa atual (com cache)
 */
function tenant_empresa() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $st = db()->prepare("SELECT * FROM dot_empresas WHERE id = ?");
    $st->execute([tenant_empresa_id()]);
    $cache = $st->fetch();
    return $cache;
}

/**
 * Retorna trial expirado (true) ou ativo (false)
 */
function tenant_trial_expirado() {
    $emp = tenant_empresa();
    if (!$emp) return true;
    if ($emp['plano'] !== 'free') return false;
    if (!$emp['trial_expira']) return false;
    return strtotime($emp['trial_expira']) < time();
}
