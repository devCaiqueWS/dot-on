<?php
/**
 * DOT-ON Mailer - SMTP via fila + biblioteca PHPMailer-like minimalista
 */

require_once __DIR__ . '/db.php';

function smtp_config() {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $st = db()->query("SELECT * FROM dot_smtp_config WHERE ativo=1 ORDER BY id LIMIT 1");
    $cfg = $st->fetch();
    return $cfg ?: null;
}

/**
 * Coloca e-mail na fila (envio em background ou imediato)
 */
function email_enfileirar($para_email, $para_nome, $assunto, $html, $texto = '', $empresa_id = null) {
    $st = db()->prepare("INSERT INTO dot_email_fila (empresa_id, para_email, para_nome, assunto, corpo_html, corpo_texto) VALUES (?,?,?,?,?,?)");
    $st->execute([$empresa_id, $para_email, $para_nome, $assunto, $html, $texto]);
    return (int)db()->lastInsertId();
}

/**
 * Envio direto via SMTP (síncrono)
 * Implementação SMTP RAW para evitar dependências externas
 */
function email_enviar_smtp($para_email, $para_nome, $assunto, $html, $texto = '') {
    $cfg = smtp_config();
    if (!$cfg) return [false, 'SMTP não configurado'];

    $host = $cfg['host'];
    $port = (int)$cfg['port'];
    $enc  = $cfg['encryption'];
    $user = $cfg['username'];
    $pass = $cfg['password'];
    $from_email = $cfg['from_email'];
    $from_name  = $cfg['from_name'];

    $prefix = ($enc === 'ssl') ? 'ssl://' : '';
    $errno = 0; $errstr = '';
    $sock = @stream_socket_client($prefix . $host . ':' . $port, $errno, $errstr, 15);
    if (!$sock) return [false, "Conexão SMTP falhou: $errstr ($errno)"];

    stream_set_timeout($sock, 30);

    $read = function() use ($sock) {
        $r = '';
        while ($l = fgets($sock, 1024)) {
            $r .= $l;
            if (preg_match('/^\d{3} /', $l)) break;
        }
        return $r;
    };
    $send = function($cmd) use ($sock) { fwrite($sock, $cmd . "\r\n"); };

    $log = [];
    $log[] = $read();
    $send("EHLO " . $host); $log[] = $read();

    if ($enc === 'tls') {
        $send("STARTTLS"); $log[] = $read();
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $send("EHLO " . $host); $log[] = $read();
    }

    $send("AUTH LOGIN"); $log[] = $read();
    $send(base64_encode($user)); $log[] = $read();
    $send(base64_encode($pass)); $r = $read(); $log[] = $r;
    if (strpos($r, '235') === false) {
        fclose($sock);
        return [false, "Auth falhou: " . trim($r)];
    }

    $send("MAIL FROM:<$from_email>"); $log[] = $read();
    $send("RCPT TO:<$para_email>"); $r = $read(); $log[] = $r;
    if (strpos($r, '250') === false) {
        fclose($sock);
        return [false, "RCPT TO recusado: " . trim($r)];
    }
    $send("DATA"); $log[] = $read();

    $boundary = 'BND' . md5(uniqid());
    $headers = [
        "From: \"$from_name\" <$from_email>",
        "To: \"$para_nome\" <$para_email>",
        "Subject: =?UTF-8?B?" . base64_encode($assunto) . "?=",
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        "Date: " . date('r'),
        "Message-ID: <" . uniqid('dot-on.', true) . "@" . $host . ">",
        "X-Mailer: DOT-ON 1.0",
    ];
    $body = implode("\r\n", $headers) . "\r\n\r\n";
    if ($texto) {
        $body .= "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto)) . "\r\n";
    }
    $body .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n";
    $body .= "--$boundary--\r\n";
    $body .= ".";

    $send($body); $r = $read(); $log[] = $r;
    $send("QUIT"); $log[] = $read();
    fclose($sock);

    if (strpos($r, '250') !== false) return [true, 'OK'];
    return [false, "DATA recusado: " . trim($r)];
}

/**
 * Processa fila: envia até N e-mails pendentes
 */
function email_processar_fila($limite = 10) {
    $st = db()->prepare("SELECT * FROM dot_email_fila WHERE enviado=0 AND tentativas<5 ORDER BY id LIMIT $limite");
    $st->execute();
    $emails = $st->fetchAll();
    $sucesso = 0; $erro = 0;
    foreach ($emails as $em) {
        [$ok, $msg] = email_enviar_smtp($em['para_email'], $em['para_nome'], $em['assunto'], $em['corpo_html'], $em['corpo_texto'] ?: '');
        if ($ok) {
            db()->prepare("UPDATE dot_email_fila SET enviado=1, enviado_em=NOW() WHERE id=?")->execute([$em['id']]);
            $sucesso++;
        } else {
            db()->prepare("UPDATE dot_email_fila SET tentativas=tentativas+1, erro=? WHERE id=?")->execute([$msg, $em['id']]);
            $erro++;
        }
    }
    return ['sucesso' => $sucesso, 'erro' => $erro];
}

/**
 * Envio imediato + enfileirar como backup
 */
function email_enviar($para_email, $para_nome, $assunto, $html, $texto = '', $empresa_id = null) {
    $id = email_enfileirar($para_email, $para_nome, $assunto, $html, $texto, $empresa_id);
    [$ok, $msg] = email_enviar_smtp($para_email, $para_nome, $assunto, $html, $texto);
    if ($ok) {
        db()->prepare("UPDATE dot_email_fila SET enviado=1, enviado_em=NOW() WHERE id=?")->execute([$id]);
    } else {
        db()->prepare("UPDATE dot_email_fila SET tentativas=1, erro=? WHERE id=?")->execute([$msg, $id]);
    }
    return $ok;
}

/* ============ TEMPLATES ============ */

function email_template_boasvindas_gestor($nome, $empresa, $email, $senha_temp, $url_painel) {
    $assunto = "Bem-vindo ao DOT-ON · $empresa";
    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='font-family:Segoe UI,Arial,sans-serif;background:#f1f5f9;padding:20px;margin:0;color:#1e293b'>
<div style='max-width:600px;margin:auto;background:white;border-radius:14px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)'>
<div style='background:linear-gradient(135deg,#0284c7,#38bdf8);color:white;padding:30px;text-align:center'>
<h1 style='margin:0;font-size:28px'>⏱ DOT-ON</h1>
<p style='margin:6px 0 0;opacity:.95'>Controle de Ponto Digital</p></div>
<div style='padding:32px'>
<h2 style='color:#0284c7;margin-top:0'>Bem-vindo(a), " . htmlspecialchars($nome) . "!</h2>
<p>Sua empresa <strong>" . htmlspecialchars($empresa) . "</strong> foi cadastrada com sucesso no DOT-ON.</p>
<p>Suas credenciais de gestor:</p>
<div style='background:#f1f5f9;border-left:4px solid #0284c7;padding:14px 18px;border-radius:6px;margin:18px 0'>
<strong>E-mail:</strong> $email<br>
<strong>Senha temporária:</strong> <code style='background:#1e293b;color:#38bdf8;padding:3px 8px;border-radius:4px'>$senha_temp</code></div>
<p style='text-align:center;margin:24px 0'><a href='$url_painel' style='display:inline-block;background:linear-gradient(135deg,#0284c7,#38bdf8);color:white;padding:13px 34px;border-radius:8px;text-decoration:none;font-weight:700'>🚀 Acessar Painel</a></p>
<p style='font-size:13px;color:#64748b'>Você será solicitado(a) a trocar a senha no primeiro acesso por segurança.</p>
<hr style='border:none;border-top:1px solid #e2e8f0;margin:24px 0'>
<h3 style='color:#0369a1'>📋 Próximos passos:</h3>
<ol style='line-height:1.8'>
<li>Faça login e troque sua senha</li>
<li>Cadastre seus funcionários (em Admin → Funcionários)</li>
<li>Configure os horários e jornadas</li>
<li>Cada funcionário receberá e-mail com link e senha</li>
<li>Funcionários baixam o DOT-ON-Agent.exe e começam a bater ponto</li>
</ol>
<p style='font-size:13px;color:#94a3b8;margin-top:30px;text-align:center'>Atendimento: <a href='mailto:robo@syscomai.com.br'>robo@syscomai.com.br</a></p></div>
<div style='background:#0f172a;color:#94a3b8;padding:16px;text-align:center;font-size:12px'>DOT-ON v1.0 · Conforme Portaria 671/2021 do MTP</div>
</div></body></html>";

    $texto = "Bem-vindo ao DOT-ON, $nome!\nEmpresa: $empresa\nE-mail: $email\nSenha temporária: $senha_temp\nAcesse: $url_painel";
    return [$assunto, $html, $texto];
}

function email_template_boasvindas_funcionario($nome, $empresa, $email, $senha_temp, $url_download_exe, $url_painel) {
    $assunto = "DOT-ON · Acesso ao Registro de Ponto · $empresa";
    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='font-family:Segoe UI,Arial,sans-serif;background:#f1f5f9;padding:20px;margin:0;color:#1e293b'>
<div style='max-width:600px;margin:auto;background:white;border-radius:14px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)'>
<div style='background:linear-gradient(135deg,#059669,#10b981);color:white;padding:30px;text-align:center'>
<h1 style='margin:0;font-size:28px'>⏱ DOT-ON</h1>
<p style='margin:6px 0 0;opacity:.95'>Seu Registro de Ponto Digital</p></div>
<div style='padding:32px'>
<h2 style='color:#059669;margin-top:0'>Olá, " . htmlspecialchars($nome) . "!</h2>
<p>Sua empresa <strong>" . htmlspecialchars($empresa) . "</strong> adotou o <strong>DOT-ON</strong> para o registro de ponto eletrônico.</p>

<h3 style='color:#059669'>🔑 Seus dados de acesso:</h3>
<div style='background:#f0fdf4;border-left:4px solid #10b981;padding:14px 18px;border-radius:6px'>
<strong>E-mail:</strong> $email<br>
<strong>Senha temporária:</strong> <code style='background:#1e293b;color:#10b981;padding:3px 8px;border-radius:4px'>$senha_temp</code></div>

<h3 style='color:#059669;margin-top:30px'>💻 Como instalar (3 passos):</h3>
<ol style='line-height:1.8'>
<li><strong>Baixar:</strong> <a href='$url_download_exe' style='color:#0284c7'>DOT-ON-Agent.exe</a> (60 MB)</li>
<li><strong>Executar</strong> o arquivo baixado (Windows pode pedir confirmação — clicar em \"Mais informações\" → \"Executar assim mesmo\")</li>
<li><strong>Fazer login</strong> com seu e-mail e senha temporária acima. Você criará uma nova senha no primeiro acesso.</li>
</ol>

<p style='text-align:center;margin:24px 0'>
<a href='$url_download_exe' style='display:inline-block;background:linear-gradient(135deg,#059669,#10b981);color:white;padding:13px 28px;border-radius:8px;text-decoration:none;font-weight:700;margin:4px'>⬇ Baixar Agente Windows</a></p>

<hr style='border:none;border-top:1px solid #e2e8f0;margin:24px 0'>
<h3 style='color:#059669'>👤 Como bater ponto:</h3>
<p>Após o login, o DOT-ON fica no ícone próximo do relógio do Windows. Basta clicar com o botão direito e escolher:</p>
<ul style='line-height:1.8'>
<li>▶ <strong>Entrada</strong> — quando chegar ao trabalho</li>
<li>⏸ <strong>Saída para intervalo</strong> — quando for almoçar</li>
<li>⏯ <strong>Retorno do intervalo</strong> — ao voltar</li>
<li>⏹ <strong>Saída</strong> — ao final do expediente</li>
</ul>
<p style='font-size:13px;color:#64748b'>Você também pode acessar o painel web em: <a href='$url_painel'>$url_painel</a></p>
<p style='font-size:13px;color:#94a3b8;margin-top:30px;text-align:center'>Em caso de dúvida, contate seu gestor.</p></div>
<div style='background:#0f172a;color:#94a3b8;padding:16px;text-align:center;font-size:12px'>DOT-ON v1.0 · Conforme Portaria 671/2021 do MTP</div>
</div></body></html>";
    $texto = "Olá, $nome!\nSua empresa $empresa adotou o DOT-ON.\n\nE-mail: $email\nSenha temporária: $senha_temp\nDownload: $url_download_exe\nPainel: $url_painel";
    return [$assunto, $html, $texto];
}
