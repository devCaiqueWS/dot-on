"""
DOT-ON Agent - Registro de Ponto Eletronico (Cliente Desktop Windows)
=====================================================================
Funcoes:
 - Login no servidor central via API REST
 - Troca de senha obrigatoria no primeiro acesso
 - Registro automatico de entrada/saida/intervalo
 - Deteccao de ociosidade (mouse/teclado parados)
 - Lembrete e bloqueio para cumprir o intervalo obrigatorio
 - Bloqueio de tela ao fim do expediente
 - Solicitacao de hora extra com aprovacao do gestor
 - Roda em segundo plano (system tray)

Configuracao:
 - O instalador personalizado da empresa entrega um arquivo "dot-on.ini"
   ao lado do executavel. O api_url desse arquivo tem prioridade.
 - Na ausencia do .ini, usa-se config.json (gerado na primeira execucao).
"""

import sys, os, json, time, socket, threading, ctypes, configparser, subprocess, hashlib, re, uuid
from datetime import datetime, timedelta
from pathlib import Path

# Versao do agent. Comparada com o manifesto do servidor (GET /agent/versao)
# para decidir se ha atualizacao disponivel. Incremente a cada build publicado.
AGENT_VERSION = "1.1.1"

import requests
from PyQt5.QtCore import Qt, QTimer, pyqtSignal, QObject
from PyQt5.QtGui import QIcon, QPixmap, QPainter, QColor, QFont
from PyQt5.QtWidgets import (QApplication, QMainWindow, QSystemTrayIcon, QMenu,
    QAction, QWidget, QVBoxLayout, QHBoxLayout, QLabel, QLineEdit, QPushButton,
    QMessageBox, QDialog, QTextEdit, QSpinBox, QFormLayout, QProgressBar,
    QFrame, QGraphicsDropShadowEffect)

# ------------------------------------------------------------------
# CONFIG
# ------------------------------------------------------------------
# Resolucao de caminhos robusta para PyInstaller "onefile".
# Em modo congelado, __file__ aponta para uma pasta temporaria (_MEIxxxx) que o
# Windows APAGA ao fechar o app. Gravar o .token ali faz a sessao se perder a cada
# fechamento (obrigando a logar de novo). Por isso:
#  - EXE_DIR  = pasta onde o .exe realmente esta (para LER o dot-on.ini do instalador)
#  - DATA_DIR = pasta persistente do usuario (%APPDATA%\DOT-ON) para GRAVAR token/config/log
if getattr(sys, 'frozen', False):
    EXE_DIR = Path(sys.executable).parent
    DATA_DIR = Path(os.environ.get('APPDATA') or os.environ.get('LOCALAPPDATA') or EXE_DIR) / 'DOT-ON'
else:
    EXE_DIR = Path(__file__).parent
    DATA_DIR = EXE_DIR
try:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
except Exception:
    DATA_DIR = EXE_DIR

APP_DIR = DATA_DIR  # compatibilidade
CONFIG_FILE = DATA_DIR / 'config.json'
TOKEN_FILE = DATA_DIR / '.token'
LOG_FILE = DATA_DIR / 'agent.log'
FILA_FILE = DATA_DIR / 'batidas_pendentes.json'  # batidas feitas sem internet
INI_FILE = EXE_DIR / 'dot-on.ini'  # entregue pelo instalador ao lado do .exe
LOG_MAX_BYTES = 2 * 1024 * 1024

# Migracao suave: se havia um .token antigo ao lado do .exe (versoes anteriores),
# reaproveita uma unica vez para nao forçar novo login apos a atualizacao.
try:
    _legacy = EXE_DIR / '.token'
    if _legacy.exists() and not TOKEN_FILE.exists():
        TOKEN_FILE.write_text(_legacy.read_text(encoding='utf-8'), encoding='utf-8')
except Exception:
    pass

def recurso(nome):
    """Caminho de um recurso embutido (datas do PyInstaller ou pasta do script)."""
    base = getattr(sys, '_MEIPASS', None) or str(EXE_DIR)
    return os.path.join(base, nome)

# URL padrao de producao. O instalador da empresa sobrescreve via dot-on.ini.
DEFAULT_API_URL = "https://dot-on.com.br/app/api/"

_log_escritas = 0

def _rotacionar_log():
    """Mantem no maximo um agent.log + um agent.log.old (o agente roda por dias)."""
    try:
        if LOG_FILE.exists() and LOG_FILE.stat().st_size > LOG_MAX_BYTES:
            velho = LOG_FILE.with_name('agent.log.old')
            if velho.exists():
                velho.unlink()
            LOG_FILE.rename(velho)
    except Exception:
        pass

def log(msg):
    global _log_escritas
    line = f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {msg}"
    print(line)
    try:
        with open(LOG_FILE, 'a', encoding='utf-8') as f:
            f.write(line + '\n')
        _log_escritas += 1
        if _log_escritas % 200 == 0:
            _rotacionar_log()
    except Exception:
        pass

def load_ini():
    """Le dot-on.ini (gerado pelo instalador personalizado da empresa)."""
    data = {}
    if not INI_FILE.exists():
        return data
    try:
        cp = configparser.ConfigParser()
        cp.read(INI_FILE, encoding='utf-8')
        if cp.has_option('server', 'api_url'):
            data['api_url'] = cp.get('server', 'api_url').strip()
        if cp.has_option('server', 'empresa_nome'):
            data['empresa_nome'] = cp.get('server', 'empresa_nome').strip()
    except Exception as e:
        log(f"Falha ao ler dot-on.ini: {e}")
    return data

def load_config():
    if CONFIG_FILE.exists():
        try:
            cfg = json.loads(CONFIG_FILE.read_text(encoding='utf-8'))
        except Exception:
            cfg = {}
    else:
        cfg = {}
    # defaults
    cfg.setdefault('api_url', DEFAULT_API_URL)
    cfg.setdefault('ociosidade_segundos', 300)
    cfg.setdefault('verificar_intervalo_segundos', 30)
    cfg.setdefault('heartbeat_segundos', 60)
    cfg.setdefault('sincronizar_sessao_segundos', 60)  # re-le as batidas na API
    # dot-on.ini (instalador) tem prioridade sobre o config.json
    ini = load_ini()
    if ini.get('api_url'):
        cfg['api_url'] = ini['api_url']
    if ini.get('empresa_nome'):
        cfg['empresa_nome'] = ini['empresa_nome']
    try:
        CONFIG_FILE.write_text(json.dumps(cfg, indent=2), encoding='utf-8')
    except Exception:
        pass
    return cfg

CFG = load_config()

# ------------------------------------------------------------------
# FILA DE BATIDAS OFFLINE
# ------------------------------------------------------------------
# Queda de internet nao pode custar a batida do funcionario. Quando o POST
# falha por rede, a batida (com o momento em que foi feita e um uid unico)
# fica em disco e e reenviada nos ciclos seguintes. O uid torna o reenvio
# idempotente no servidor; o proprio servidor marca como extemporanea.
_fila_lock = threading.Lock()

def fila_ler():
    try:
        dados = json.loads(FILA_FILE.read_text(encoding='utf-8'))
        return dados if isinstance(dados, list) else []
    except Exception:
        return []

def fila_gravar(itens):
    try:
        if itens:
            FILA_FILE.write_text(json.dumps(itens, indent=2), encoding='utf-8')
        elif FILA_FILE.exists():
            FILA_FILE.unlink()
    except Exception as e:
        log(f"Fila: falha ao gravar: {e}")

def fila_add(batida):
    with _fila_lock:
        itens = fila_ler()
        itens.append(batida)
        fila_gravar(itens)
    log(f"Fila: batida '{batida.get('tipo')}' de {batida.get('momento')} guardada para reenvio.")
    return len(itens)

# ------------------------------------------------------------------
# PALETA / TEMA
# ------------------------------------------------------------------
# Cores centralizadas para manter a identidade visual coerente em todo o app.
COR = {
    'primaria':   '#2563eb',
    'sucesso':    '#16a34a',
    'alerta':     '#f59e0b',
    'perigo':     '#dc2626',
    'roxo':       '#7c3aed',
    'cinza':      '#64748b',
    'texto':      '#0f172a',
    'texto_fraco':'#64748b',
    'fundo':      '#f1f5f9',
    'cartao':     '#ffffff',
    'borda':      '#e2e8f0',
}

def _shade(hex_color, factor):
    """Clareia (factor>1) ou escurece (factor<1) uma cor #rrggbb."""
    c = hex_color.lstrip('#')
    r, g, b = int(c[0:2], 16), int(c[2:4], 16), int(c[4:6], 16)
    r = max(0, min(255, int(r * factor)))
    g = max(0, min(255, int(g * factor)))
    b = max(0, min(255, int(b * factor)))
    return f'#{r:02x}{g:02x}{b:02x}'

def btn_css(color, alto=True):
    """Estilo de botao com estados hover/pressed/disabled a partir de uma cor base."""
    hover = _shade(color, 1.12)
    press = _shade(color, 0.82)
    pad = '12px 14px' if alto else '9px 12px'
    fs = '13px' if alto else '12px'
    return f"""
    QPushButton {{
        background:{color}; color:white; padding:{pad};
        font-weight:600; border:none; border-radius:10px; font-size:{fs};
    }}
    QPushButton:hover {{ background:{hover}; }}
    QPushButton:pressed {{ background:{press}; }}
    QPushButton:disabled {{ background:#e2e8f0; color:#94a3b8; }}
    """

# Folha de estilo global aplicada ao QApplication inteiro.
APP_QSS = f"""
    QWidget {{
        font-family: 'Segoe UI', 'Segoe UI Variable', Arial, sans-serif;
        color: {COR['texto']};
    }}
    QMainWindow, QDialog {{ background: {COR['fundo']}; }}
    QLineEdit, QTextEdit, QSpinBox {{
        border: 1px solid {COR['borda']}; border-radius: 8px; padding: 8px 10px;
        background: white; font-size: 13px; selection-background-color: {COR['primaria']};
    }}
    QLineEdit:focus, QTextEdit:focus, QSpinBox:focus {{ border: 1px solid {COR['primaria']}; }}
    QLabel {{ color: {COR['texto']}; }}
    QMenu {{ background: white; border: 1px solid {COR['borda']}; border-radius: 8px; padding: 4px; }}
    QMenu::item {{ padding: 8px 24px; border-radius: 6px; }}
    QMenu::item:selected {{ background: {COR['fundo']}; color: {COR['primaria']}; }}
    QProgressBar {{
        border: none; border-radius: 7px; background: {COR['borda']};
        height: 14px; text-align: center; font-size: 10px; color: {COR['texto_fraco']};
    }}
    QProgressBar::chunk {{ border-radius: 7px; background: {COR['sucesso']}; }}
"""

def cartao():
    """Cria um QFrame estilizado como cartao (fundo branco, borda arredondada, sombra)."""
    f = QFrame()
    f.setStyleSheet(f"""
        QFrame {{ background:{COR['cartao']}; border:1px solid {COR['borda']}; border-radius:14px; }}
    """)
    sombra = QGraphicsDropShadowEffect()
    sombra.setBlurRadius(18); sombra.setXOffset(0); sombra.setYOffset(3)
    sombra.setColor(QColor(15, 23, 42, 28))
    f.setGraphicsEffect(sombra)
    return f

# ------------------------------------------------------------------
# API CLIENT
# ------------------------------------------------------------------
class APIClient:
    def __init__(self, base_url):
        self.base = base_url.rstrip('/')
        self.token = None
        self.user = None
        self.precisa_trocar_senha = 0
        # Diferenca (segundos) entre o relogio do servidor e o desta maquina.
        # Toda conta de ponto usa agora(), entao um relogio local errado (ou em
        # outro fuso) nao desloca o tempo trabalhado.
        self.delta_relogio = 0.0
        if TOKEN_FILE.exists():
            try:
                data = json.loads(TOKEN_FILE.read_text())
                self.token, self.user = data.get('token'), data.get('user')
            except Exception:
                pass

    def _headers(self):
        h = {'Content-Type': 'application/json'}
        if self.token: h['X-Auth-Token'] = self.token
        return h

    # ---- Relogio do servidor ----
    def agora(self):
        """Hora atual na referencia do servidor (relogio local + delta)."""
        return datetime.now() + timedelta(seconds=self.delta_relogio)

    def _sincronizar_relogio(self, agora_srv):
        """Recalcula o delta a partir do campo 'agora' devolvido pela API."""
        if not agora_srv:
            return
        try:
            ts = datetime.strptime(str(agora_srv), '%Y-%m-%d %H:%M:%S')
        except Exception:
            return
        delta = (ts - datetime.now()).total_seconds()
        if abs(delta - self.delta_relogio) > 2:
            log(f"Relogio ajustado pelo servidor: delta={delta:+.0f}s")
        self.delta_relogio = delta

    def login(self, email, senha):
        try:
            r = requests.post(f"{self.base}/login", json={'email': email, 'senha': senha}, timeout=15)
            j = r.json()
            if j.get('ok'):
                self.token = j['token']; self.user = j['user']
                self.precisa_trocar_senha = int(j.get('precisa_trocar_senha') or 0)
                TOKEN_FILE.write_text(json.dumps({'token': self.token, 'user': self.user}))
                log(f"Login OK: {self.user['nome']}")
                return True, j['user']
            return False, j.get('erro', 'Erro desconhecido')
        except Exception as e:
            return False, f"Falha de conexao: {e}"

    def trocar_senha(self, senha_atual, nova_senha):
        try:
            r = requests.post(f"{self.base}/trocar-senha", headers=self._headers(),
                json={'senha_atual': senha_atual, 'nova_senha': nova_senha}, timeout=15)
            j = r.json()
            if j.get('ok'):
                self.precisa_trocar_senha = 0
            return j
        except Exception as e:
            return {'ok': False, 'erro': f"Falha de conexao: {e}"}

    def logout(self):
        self.token = None; self.user = None; self.precisa_trocar_senha = 0
        if TOKEN_FILE.exists(): TOKEN_FILE.unlink()

    def batida(self, tipo, momento=None, client_uid=None):
        """Registra uma batida. Em falha de rede devolve offline=True e o payload,
        para quem chamou guardar na fila e reenviar depois com o mesmo uid."""
        payload = {
            'tipo': tipo,
            'momento': momento or self.agora().strftime('%Y-%m-%d %H:%M:%S'),
            'hostname': socket.gethostname(),
            'client_uid': client_uid or uuid.uuid4().hex,
        }
        try:
            r = requests.post(f"{self.base}/batida", headers=self._headers(),
                              json=payload, timeout=15)
            j = r.json()
            self._sincronizar_relogio(j.get('agora'))
            return j
        except Exception as e:
            log(f"Erro batida (sem conexao): {e}")
            return {'ok': False, 'offline': True, 'erro': str(e), 'batida': payload}

    def ociosidade(self, inicio, fim, motivo=''):
        try:
            r = requests.post(f"{self.base}/ociosidade", headers=self._headers(),
                json={'inicio': inicio, 'fim': fim, 'motivo': motivo}, timeout=15)
            return r.json()
        except Exception as e:
            return {'ok': False, 'erro': str(e)}

    def solicitar_extra(self, minutos, justificativa):
        try:
            r = requests.post(f"{self.base}/hora-extra/solicitar", headers=self._headers(),
                json={'minutos': minutos, 'justificativa': justificativa}, timeout=15)
            return r.json()
        except Exception as e:
            return {'ok': False, 'erro': str(e)}

    def status_extra(self, id_extra):
        try:
            r = requests.get(f"{self.base}/hora-extra/status?id={id_extra}", headers=self._headers(), timeout=10)
            return r.json()
        except Exception:
            return {'ok': False}

    def escala(self):
        try:
            esc = requests.get(f"{self.base}/escala", headers=self._headers(), timeout=10).json().get('escala')
            return esc if isinstance(esc, dict) else None
        except Exception:
            return None

    def config(self):
        """Parametros da empresa. Sempre um dict: empresa sem nenhuma configuracao
        faz o PHP devolver [] (lista), e qualquer .get() depois quebraria o loop."""
        try:
            cfg = requests.get(f"{self.base}/config", headers=self._headers(), timeout=10).json().get('config')
            return cfg if isinstance(cfg, dict) else {}
        except Exception:
            return {}

    def sessao_atual(self):
        """Sessao do dia + batidas (fonte da verdade do tempo trabalhado)."""
        try:
            r = requests.get(f"{self.base}/sessao/atual", headers=self._headers(), timeout=10)
            j = r.json()
            self._sincronizar_relogio(j.get('agora'))
            return j
        except Exception:
            return {'ok': False}

# ------------------------------------------------------------------
# OCIOSIDADE (Windows: GetLastInputInfo)
# ------------------------------------------------------------------
class LASTINPUTINFO(ctypes.Structure):
    _fields_ = [('cbSize', ctypes.c_uint), ('dwTime', ctypes.c_uint)]

def get_idle_seconds():
    """Tempo (s) desde a ultima interacao com mouse/teclado (apenas Windows)."""
    try:
        lii = LASTINPUTINFO()
        lii.cbSize = ctypes.sizeof(LASTINPUTINFO)
        if ctypes.windll.user32.GetLastInputInfo(ctypes.byref(lii)):
            millis = ctypes.windll.kernel32.GetTickCount() - lii.dwTime
            return millis / 1000.0
    except Exception:
        pass
    return 0.0

def lock_workstation():
    """Bloqueia a tela do Windows."""
    try:
        ctypes.windll.user32.LockWorkStation()
        log("Tela bloqueada.")
    except Exception as e:
        log(f"Erro ao bloquear: {e}")

# ------------------------------------------------------------------
# AUTO-UPDATE
# ------------------------------------------------------------------
# O agent checa o manifesto do servidor no boot. Se houver versao mais nova,
# baixa o novo .exe (com verificacao de checksum), grava um .bat que espera este
# processo encerrar, substitui o executavel e reabre o app.
def _ver_tuple(v):
    """Converte '1.2.3' -> (1,2,3) para comparacao numerica robusta."""
    nums = re.findall(r'\d+', str(v or ''))
    return tuple(int(n) for n in nums[:4]) or (0,)

def verificar_atualizacao(api_base):
    """Retorna o manifesto do servidor se houver versao mais nova; senao None."""
    try:
        r = requests.get(f"{api_base.rstrip('/')}/agent/versao", timeout=10)
        j = r.json()
        if not j.get('ok'):
            return None
        if _ver_tuple(j.get('versao')) > _ver_tuple(AGENT_VERSION):
            log(f"Update disponivel: {AGENT_VERSION} -> {j.get('versao')}")
            return j
        log(f"Update: ja na versao mais recente ({AGENT_VERSION}).")
    except Exception as e:
        log(f"Update: falha ao checar versao: {e}")
    return None

def _lancar_updater(novo_exe: Path):
    """Grava e dispara o .bat que troca o executavel apos o app fechar."""
    alvo = Path(sys.executable)
    bat = novo_exe.parent / 'update.bat'
    pid = os.getpid()
    conteudo = (
        "@echo off\r\n"
        "setlocal\r\n"
        f'set "PID={pid}"\r\n'
        ":wait\r\n"
        'tasklist /FI "PID eq %PID%" 2>nul | find "%PID%" >nul\r\n'
        "if not errorlevel 1 (\r\n"
        "  timeout /t 1 /nobreak >nul\r\n"
        "  goto wait\r\n"
        ")\r\n"
        ":copyloop\r\n"
        f'copy /Y "{novo_exe}" "{alvo}" >nul\r\n'
        "if errorlevel 1 (\r\n"
        "  timeout /t 1 /nobreak >nul\r\n"
        "  goto copyloop\r\n"
        ")\r\n"
        f'start "" "{alvo}"\r\n'
        f'del "{novo_exe}" >nul 2>&1\r\n'
        '(goto) 2>nul & del "%~f0"\r\n'
    )
    # newline='' preserva os CRLF exatos (senao o modo texto do Windows gera CR duplo);
    # 'mbcs' grava no codepage ANSI que o cmd.exe usa por padrao (caminhos com acento).
    try:
        bat.write_text(conteudo, encoding='mbcs', newline='')
    except (LookupError, UnicodeEncodeError):
        bat.write_text(conteudo, encoding='utf-8', newline='')
    CREATE_NO_WINDOW = 0x08000000
    DETACHED_PROCESS = 0x00000008
    subprocess.Popen(['cmd', '/c', str(bat)],
                     creationflags=CREATE_NO_WINDOW | DETACHED_PROCESS, close_fds=True)
    log(f"Update: updater lançado (PID atual {pid}); aguardando encerramento.")

def baixar_e_instalar(manifest, api_base):
    """Baixa o novo .exe, valida checksum/tamanho e dispara o updater.
    Retorna (True, None) em sucesso ou (False, mensagem_erro)."""
    try:
        url = manifest.get('url') or f"{api_base.rstrip('/')}/agent/download"
        updir = DATA_DIR / 'update'
        updir.mkdir(parents=True, exist_ok=True)
        novo = updir / 'DOT-ON-Agent.new.exe'
        h = hashlib.sha256()
        with requests.get(url, stream=True, timeout=180) as resp:
            resp.raise_for_status()
            with open(novo, 'wb') as f:
                for chunk in resp.iter_content(65536):
                    if chunk:
                        f.write(chunk); h.update(chunk)
        sha = manifest.get('sha256')
        if sha and h.hexdigest().lower() != str(sha).lower():
            try: novo.unlink()
            except Exception: pass
            return False, "checksum invalido (download corrompido)"
        tam = manifest.get('tamanho')
        if tam and novo.stat().st_size != int(tam):
            try: novo.unlink()
            except Exception: pass
            return False, "tamanho invalido"
        _lancar_updater(novo)
        return True, None
    except Exception as e:
        log(f"Update: erro ao baixar/instalar: {e}")
        return False, str(e)

# ------------------------------------------------------------------
# LOGIN DIALOG
# ------------------------------------------------------------------
class LoginDialog(QDialog):
    def __init__(self, api: APIClient):
        super().__init__()
        self.api = api
        self.setWindowTitle("DOT-ON - Login")
        self.setFixedSize(400, 400)
        layout = QVBoxLayout()
        layout.setContentsMargins(28, 24, 28, 24)
        layout.setSpacing(12)

        logo = QLabel("DOT-ON")
        logo.setStyleSheet(f"font-size:30px;font-weight:800;color:{COR['primaria']};letter-spacing:1px")
        logo.setAlignment(Qt.AlignCenter)
        layout.addWidget(logo)
        sub = QLabel("Registro de ponto eletronico")
        sub.setStyleSheet(f"color:{COR['texto_fraco']};font-size:12px")
        sub.setAlignment(Qt.AlignCenter)
        layout.addWidget(sub)
        layout.addSpacing(8)

        form = QFormLayout()
        form.setSpacing(10)
        self.email = QLineEdit(); self.email.setPlaceholderText("seu.email@empresa.com")
        self.senha = QLineEdit(); self.senha.setEchoMode(QLineEdit.Password)
        self.senha.setPlaceholderText("Sua senha")
        self.servidor = QLineEdit(CFG.get('api_url', ''))
        form.addRow("E-mail", self.email)
        form.addRow("Senha", self.senha)
        form.addRow("Servidor", self.servidor)
        layout.addLayout(form)

        self.btn = QPushButton("Entrar")
        self.btn.setStyleSheet(btn_css(COR['primaria']))
        self.btn.setCursor(Qt.PointingHandCursor)
        self.btn.clicked.connect(self.fazer_login)
        layout.addWidget(self.btn)

        self.status = QLabel("")
        self.status.setStyleSheet(f"color:{COR['perigo']};font-size:12px")
        self.status.setWordWrap(True)
        self.status.setAlignment(Qt.AlignCenter)
        layout.addWidget(self.status)

        # Enter no campo senha dispara o login
        self.senha.returnPressed.connect(self.fazer_login)
        self.setLayout(layout)

    def fazer_login(self):
        global CFG
        api_url = self.servidor.text().strip()
        CFG['api_url'] = api_url
        try:
            CONFIG_FILE.write_text(json.dumps(CFG, indent=2), encoding='utf-8')
        except Exception:
            pass
        self.api.base = api_url.rstrip('/')
        self.btn.setEnabled(False); self.btn.setText("Entrando...")
        self.status.setText("")
        QApplication.processEvents()
        ok, info = self.api.login(self.email.text().strip(), self.senha.text())
        self.btn.setEnabled(True); self.btn.setText("Entrar")
        if not ok:
            self.status.setText(str(info))
            return
        # Troca de senha obrigatoria no primeiro acesso
        if self.api.precisa_trocar_senha:
            td = TrocarSenhaDialog(self.api, self.senha.text(), self)
            if td.exec_() != QDialog.Accepted:
                self.status.setText("E necessario trocar a senha para continuar.")
                return
        self.accept()

# ------------------------------------------------------------------
# TROCA DE SENHA OBRIGATORIA
# ------------------------------------------------------------------
class TrocarSenhaDialog(QDialog):
    def __init__(self, api: APIClient, senha_atual, parent=None):
        super().__init__(parent)
        self.api = api
        self.senha_atual = senha_atual
        self.setWindowTitle("DOT-ON - Trocar senha")
        self.setFixedSize(420, 320)
        l = QVBoxLayout()
        l.setContentsMargins(28, 24, 28, 24); l.setSpacing(12)
        titulo = QLabel("Defina sua nova senha")
        titulo.setStyleSheet(f"font-size:18px;font-weight:700;color:{COR['texto']}")
        l.addWidget(titulo)
        msg = QLabel("Por seguranca, e obrigatorio trocar a senha no primeiro acesso.")
        msg.setStyleSheet(f"color:{COR['texto_fraco']};font-size:12px")
        msg.setWordWrap(True)
        l.addWidget(msg)
        form = QFormLayout(); form.setSpacing(10)
        self.nova = QLineEdit(); self.nova.setEchoMode(QLineEdit.Password)
        self.conf = QLineEdit(); self.conf.setEchoMode(QLineEdit.Password)
        form.addRow("Nova senha", self.nova)
        form.addRow("Confirmar", self.conf)
        l.addLayout(form)
        dica = QLabel("Minimo de 8 caracteres.")
        dica.setStyleSheet(f"color:{COR['texto_fraco']};font-size:11px")
        l.addWidget(dica)
        btn = QPushButton("Salvar nova senha")
        btn.setStyleSheet(btn_css(COR['primaria']))
        btn.setCursor(Qt.PointingHandCursor)
        btn.clicked.connect(self.salvar)
        l.addWidget(btn)
        self.status = QLabel("")
        self.status.setStyleSheet(f"color:{COR['perigo']};font-size:12px")
        self.status.setWordWrap(True)
        l.addWidget(self.status)
        self.setLayout(l)

    def salvar(self):
        nova = self.nova.text()
        conf = self.conf.text()
        if len(nova) < 8:
            self.status.setText("A senha precisa ter no minimo 8 caracteres.")
            return
        if nova != conf:
            self.status.setText("As senhas nao coincidem.")
            return
        r = self.api.trocar_senha(self.senha_atual, nova)
        if r.get('ok'):
            QMessageBox.information(self, "DOT-ON", "Senha alterada com sucesso.")
            self.accept()
        else:
            self.status.setText(r.get('erro', 'Falha ao trocar senha.'))

# ------------------------------------------------------------------
# JANELA PRINCIPAL
# ------------------------------------------------------------------
class MainWindow(QMainWindow):
    # Sinal emitido de uma thread de rede -> tratado na thread da UI (conexao em fila).
    batida_pronta = pyqtSignal(str, object, str)  # tipo, resultado, mensagem_sucesso
    atualizacao_disp = pyqtSignal(object)         # manifesto de nova versao
    sessao_pronta = pyqtSignal(object)            # resposta de GET /sessao/atual
    extra_status = pyqtSignal(object)             # resposta de GET /hora-extra/status

    def __init__(self, api: APIClient):
        super().__init__()
        self.api = api
        self.escala = api.escala() or {}
        self.config_srv = api.config() or {}
        self.estado = 'fora'   # fora | trabalhando | intervalo | bloqueado
        self.ocioso_desde = None
        # Tempo trabalhado ancorado nas batidas do servidor (NAO no tempo de app aberto):
        #  - _trab_base_seg: segundos ja fechados (segmentos entrada->saida/intervalo)
        #  - _trab_seg_inicio: inicio do segmento de trabalho em aberto (ultima entrada/retorno)
        # O total ao vivo = _trab_base_seg + (agora - _trab_seg_inicio) enquanto trabalhando.
        # Assim, fechar e reabrir o agente reconstroi o tempo certo a partir da 1a batida.
        self._trab_base_seg = 0.0
        self._trab_seg_inicio = None
        self.minutos_intervalo_prev = 0     # intervalos ja concluidos (min)
        self.intervalo_inicio = None        # inicio do intervalo em andamento
        self.minutos_extra_solicitados = 0
        self.minutos_extra_aprovados = 0
        self.id_extra_pendente = None
        self._consultando_extra = False     # ha consulta de hora extra em voo?
        self._ultimo_lembrete_int = None    # HH:MM do ultimo lembrete de intervalo
        self.ocupado = False                # ha uma batida em andamento?
        self._pendentes = len(fila_ler())   # batidas offline aguardando envio
        self._ultimo_heartbeat = 0.0        # time.monotonic() do ultimo heartbeat
        self._encerrando = False            # saida disparada pelo fim de expediente?
        self._pulso_on = False              # estado do piscar do botao extra
        self._banner_timer = None
        self.setWindowTitle(f"DOT-ON - {api.user['nome']}")
        self.setFixedSize(460, 660)
        self._build_ui()
        self.batida_pronta.connect(self._on_batida_pronta)
        self.atualizacao_disp.connect(self._on_atualizacao_disp)
        self.sessao_pronta.connect(self._on_sessao_pronta)
        self.extra_status.connect(self._on_extra_status)
        self._restaurar_sessao()
        self._iniciar_timers()
        # Primeiro ciclo logo apos abrir: reenvia a fila offline sem travar o boot.
        QTimer.singleShot(2000, self._safe(self._sincronizar_sessao_async))
        # Checa atualizacao alguns segundos apos abrir (nao trava o boot).
        QTimer.singleShot(4000, self._checar_update_async)

    # ---------------- CONSTRUCAO DA UI ----------------
    def _build_ui(self):
        central = QWidget()
        raiz = QVBoxLayout()
        raiz.setContentsMargins(16, 14, 16, 14)
        raiz.setSpacing(12)

        # ---- Cabecalho: logo + usuario ----
        topo = QHBoxLayout()
        logo = QLabel("DOT-ON")
        logo.setStyleSheet(f"font-size:20px;font-weight:800;color:{COR['primaria']};letter-spacing:1px")
        topo.addWidget(logo)
        topo.addStretch()
        self.lbl_user = QLabel(f"{self.api.user['nome']}  ·  Mat. {self.api.user['matricula']}")
        self.lbl_user.setStyleSheet(f"font-size:12px;color:{COR['texto_fraco']}")
        topo.addWidget(self.lbl_user)
        raiz.addLayout(topo)

        # ---- Banner de feedback (aparece so quando ha mensagem) ----
        self.banner = QLabel("")
        self.banner.setWordWrap(True)
        self.banner.setAlignment(Qt.AlignCenter)
        self.banner.setVisible(False)
        raiz.addWidget(self.banner)

        # ---- Cartao de estado (badge + cronometro + progresso) ----
        card_estado = cartao()
        ce = QVBoxLayout(card_estado)
        ce.setContentsMargins(18, 16, 18, 16); ce.setSpacing(8)

        self.lbl_estado = QLabel("FORA DO EXPEDIENTE")
        self.lbl_estado.setAlignment(Qt.AlignCenter)
        self.lbl_estado.setStyleSheet(self._badge_css(COR['cinza']))
        linha_badge = QHBoxLayout(); linha_badge.addStretch()
        linha_badge.addWidget(self.lbl_estado); linha_badge.addStretch()
        ce.addLayout(linha_badge)

        self.lbl_cron = QLabel("00:00:00")
        self.lbl_cron.setAlignment(Qt.AlignCenter)
        self.lbl_cron.setStyleSheet(f"font-size:44px;font-weight:800;color:{COR['cinza']}")
        ce.addWidget(self.lbl_cron)

        # Progresso da carga diaria
        self.bar_dia = QProgressBar()
        self.bar_dia.setTextVisible(False)
        self.bar_dia.setRange(0, 100)
        self.bar_dia.setValue(0)
        ce.addWidget(self.bar_dia)
        self.lbl_carga = QLabel("")
        self.lbl_carga.setAlignment(Qt.AlignCenter)
        self.lbl_carga.setStyleSheet(f"font-size:12px;color:{COR['texto_fraco']}")
        ce.addWidget(self.lbl_carga)
        raiz.addWidget(card_estado)

        # ---- Cartao de jornada + almoco ----
        card_info = cartao()
        ci = QHBoxLayout(card_info)
        ci.setContentsMargins(16, 12, 16, 12); ci.setSpacing(8)
        self.lbl_jornada = QLabel("")
        self.lbl_jornada.setStyleSheet("font-size:12px")
        ci.addWidget(self.lbl_jornada)
        ci.addStretch()
        self.lbl_almoco = QLabel("")
        self.lbl_almoco.setAlignment(Qt.AlignRight | Qt.AlignVCenter)
        self.lbl_almoco.setStyleSheet("font-size:12px")
        ci.addWidget(self.lbl_almoco)
        raiz.addWidget(card_info)
        self._preencher_jornada()

        # ---- Botoes principais ----
        btn_layout = QHBoxLayout(); btn_layout.setSpacing(10)
        self.btn_entrada = QPushButton("Iniciar expediente")
        self.btn_entrada.setStyleSheet(btn_css(COR['sucesso']))
        self.btn_entrada.setCursor(Qt.PointingHandCursor)
        self.btn_entrada.clicked.connect(self.bater_entrada)
        btn_layout.addWidget(self.btn_entrada)

        self.btn_saida = QPushButton("Encerrar expediente")
        self.btn_saida.setStyleSheet(btn_css(COR['perigo']))
        self.btn_saida.setCursor(Qt.PointingHandCursor)
        self.btn_saida.clicked.connect(self.bater_saida)
        btn_layout.addWidget(self.btn_saida)
        raiz.addLayout(btn_layout)

        btn_layout2 = QHBoxLayout(); btn_layout2.setSpacing(10)
        self.btn_int = QPushButton("Iniciar intervalo")
        self.btn_int.setStyleSheet(btn_css(COR['alerta']))
        self.btn_int.setCursor(Qt.PointingHandCursor)
        self.btn_int.clicked.connect(self.bater_intervalo)
        btn_layout2.addWidget(self.btn_int)

        self.btn_ret = QPushButton("Retornar do intervalo")
        self.btn_ret.setStyleSheet(btn_css(COR['primaria']))
        self.btn_ret.setCursor(Qt.PointingHandCursor)
        self.btn_ret.clicked.connect(self.bater_retorno)
        btn_layout2.addWidget(self.btn_ret)
        raiz.addLayout(btn_layout2)

        # ---- Hora extra ----
        self.btn_extra = QPushButton("Solicitar hora extra")
        self.btn_extra.setStyleSheet(btn_css(COR['roxo']))
        self.btn_extra.setCursor(Qt.PointingHandCursor)
        self.btn_extra.clicked.connect(self.solicitar_extra_dialog)
        raiz.addWidget(self.btn_extra)

        # ---- Rodape tecnico (discreto) ----
        self.lbl_info = QLabel("")
        self.lbl_info.setStyleSheet(f"font-size:10px;color:#94a3b8")
        self.lbl_info.setWordWrap(True)
        self.lbl_info.setAlignment(Qt.AlignCenter)
        raiz.addWidget(self.lbl_info)

        raiz.addStretch()
        central.setLayout(raiz)
        self.setCentralWidget(central)
        self._atualizar_botoes()

    def _badge_css(self, color):
        bg = _shade(color, 1.7)
        return (f"background:{bg};color:{color};font-size:13px;font-weight:700;"
                f"padding:6px 16px;border-radius:20px;letter-spacing:0.5px")

    def _preencher_jornada(self):
        def _hm(v, d='--:--'):
            v = '' if v is None else str(v)
            return v[:5] if v else d
        if not self.escala:
            self.lbl_jornada.setText("Sem escala configurada")
            self.lbl_almoco.setText("")
            return
        if not self._trabalha_hoje():
            # Folga: sem jornada prevista (e sem encerramento automatico).
            self.lbl_jornada.setText(
                f"<span style='color:{COR['texto_fraco']}'>Hoje</span><br>"
                f"<b style='font-size:14px;color:{COR['primaria']}'>Folga</b>")
            return
        ent = _hm(self.escala.get('entrada'))
        sai = _hm(self.escala.get('saida'))
        self.lbl_jornada.setText(
            f"<span style='color:{COR['texto_fraco']}'>Jornada</span><br>"
            f"<b style='font-size:14px'>{ent} - {sai}</b>")

    # ---------------- SESSAO ----------------
    def _restaurar_sessao(self):
        """Carga inicial: le a sessao do dia na API antes de mostrar qualquer hora.
        A fila offline entra no calculo aqui e e reenviada no 1o ciclo assincrono
        (reenviar agora travaria a janela por 15s por batida se a rede estiver fora)."""
        self._on_sessao_pronta({'sessao': self.api.sessao_atual()}, inicial=True)

    def _sincronizar_sessao_async(self):
        """Re-le sessao, escala e config na API (em thread, para nao travar a UI).

        Roda periodicamente: assim o agente segue a fonte da verdade do servidor
        — inclusive batidas feitas pelo painel web e mudancas de escala — em vez
        de confiar no que ficou em memoria desde que a janela foi aberta."""
        if self.ocupado:
            return
        def work():
            recusadas = self._enviar_pendentes()
            self.sessao_pronta.emit({
                'recusadas': recusadas,
                'sessao': self.api.sessao_atual(),
                'escala': self.api.escala(),
                'config': self.api.config(),
            })
        threading.Thread(target=work, daemon=True).start()

    def _enviar_pendentes(self):
        """Reenvia as batidas guardadas offline (roda na thread de sincronizacao).

        Retorna a lista de batidas RECUSADAS pelo servidor, para avisar o
        funcionario. O client_uid garante que uma batida que chegou ao servidor
        mas cuja resposta se perdeu nao vire duplicata."""
        recusadas = []
        with _fila_lock:
            fila = fila_ler()
            if not fila:
                return recusadas
            restantes = []
            for item in fila:
                r = self.api.batida(item.get('tipo'), item.get('momento'), item.get('client_uid'))
                if r.get('ok'):
                    ja = ' (ja registrada)' if r.get('duplicada') else ''
                    log(f"Fila: '{item.get('tipo')}' de {item.get('momento')} enviada{ja}.")
                elif r.get('offline'):
                    restantes.append(item)      # ainda sem rede: tenta no proximo ciclo
                else:
                    # Recusa do servidor (regra de negocio): nao adianta insistir.
                    log(f"Fila: '{item.get('tipo')}' de {item.get('momento')} recusada: {r.get('erro')}")
                    recusadas.append(f"{item.get('tipo')} de "
                                     f"{str(item.get('momento'))[11:16]} ({r.get('erro')})")
            fila_gravar(restantes)
        return recusadas

    def _on_sessao_pronta(self, dados, inicial=False):
        """Tratado na thread da UI: aplica o que veio da API."""
        # Escala e parametros da empresa tambem vem do servidor a cada ciclo.
        esc = dados.get('escala')
        if esc and esc != self.escala:
            self.escala = esc
            self._preencher_jornada()
            log("Escala atualizada pelo servidor.")
        cfg = dados.get('config')
        if isinstance(cfg, dict) and cfg:
            self.config_srv = cfg

        # Batida da fila que o servidor recusou: o funcionario precisa saber
        # (vai ter que registrar de novo ou pedir ajuste ao gestor).
        recusadas = dados.get('recusadas') or []
        if recusadas:
            msg = "O servidor recusou: " + "; ".join(recusadas[:3])
            self._banner(msg + ". Fale com seu gestor.", 'erro')
            self._notificar(msg)

        s = dados.get('sessao') or {}
        if not s.get('ok'):
            if inicial:
                log("Sessao nao carregada (API indisponivel); aguardando sincronizacao.")
            return
        if self.ocupado:
            return  # ha batida em voo; o proximo ciclo sincroniza
        estado_ant = self.estado
        # Batidas do servidor + as que ainda estao na fila offline: para o
        # funcionario elas ja aconteceram, entao entram no calculo na ordem certa.
        # So as batidas DE HOJE entram na conta: uma entrada de ontem presa na
        # fila abriria um segmento de 24h+ no cronometro de hoje.
        batidas = list(s.get('batidas') or [])
        pendentes = [b for b in fila_ler() if b.get('tipo') and b.get('momento')]
        self._pendentes = len(pendentes)
        hoje = self.api.agora().strftime('%Y-%m-%d')
        pendentes_hoje = [b for b in pendentes if str(b.get('momento', '')).startswith(hoje)]
        if pendentes_hoje:
            batidas = sorted(batidas + pendentes_hoje, key=lambda b: b.get('momento') or '')
        self._aplicar_batidas(batidas)
        # 'bloqueado' (fim de expediente) e um estado so do cliente: o servidor
        # devolve 'fora' apos a saida. Preserva para nao destravar sozinho.
        if estado_ant == 'bloqueado' and self.estado == 'fora':
            self.estado = 'bloqueado'
        if inicial or estado_ant != self.estado:
            log(f"Sessao sincronizada: estado={self.estado}, "
                f"trab={int(self._segundos_trabalhados())//60}min, intervalo={self.minutos_intervalo_prev}min")
        self._atualizar_botoes()

    def _aplicar_batidas(self, batidas):
        """Reconstroi o tempo trabalhado a partir das batidas do servidor.

        Percorre as batidas pareando entrada->saida/intervalo (mesma logica do
        servidor) para somar os segmentos ja fechados e, principalmente, ancorar
        o segmento AINDA EM ABERTO na 1a batida real — nao no momento em que o
        app foi aberto. Assim, fechar e reabrir o agente nao perde tempo."""
        trab_seg = 0.0
        int_seg = 0.0
        ts_in = None       # entrada de trabalho em aberto
        ts_int_in = None   # intervalo em aberto
        for b in batidas:
            try:
                ts = datetime.strptime(b['momento'], '%Y-%m-%d %H:%M:%S')
            except Exception:
                continue
            tipo = b.get('tipo')
            if tipo == 'entrada':
                ts_in = ts
            elif tipo == 'saida_intervalo':
                if ts_in: trab_seg += (ts - ts_in).total_seconds(); ts_in = None
                ts_int_in = ts
            elif tipo == 'retorno_intervalo':
                if ts_int_in: int_seg += (ts - ts_int_in).total_seconds(); ts_int_in = None
                ts_in = ts
            elif tipo == 'saida':
                if ts_in: trab_seg += (ts - ts_in).total_seconds(); ts_in = None
        self._trab_base_seg = trab_seg
        self._trab_seg_inicio = ts_in              # None se nao esta trabalhando
        self.minutos_intervalo_prev = int(round(int_seg / 60))
        self.intervalo_inicio = ts_int_in          # None se nao esta em intervalo
        # Estado derivado do que ficou em aberto na ultima batida.
        if ts_int_in is not None:
            self.estado = 'intervalo'
        elif ts_in is not None:
            self.estado = 'trabalhando'
        else:
            self.estado = 'fora'

    def _safe(self, fn):
        """Envolve um slot de timer para que uma excecao nao derrube o processo."""
        def wrapper(*a, **k):
            try:
                return fn(*a, **k)
            except Exception as e:
                import traceback
                log(f"ERRO em {getattr(fn,'__name__','slot')}: {e}\n{traceback.format_exc()}")
        return wrapper

    def _iniciar_timers(self):
        self.timer_cron = QTimer(self); self.timer_cron.timeout.connect(self._safe(self._tick_cronometro))
        self.timer_cron.start(1000)
        self.timer_check = QTimer(self); self.timer_check.timeout.connect(self._safe(self._check_estado))
        self.timer_check.start(int(CFG.get('verificar_intervalo_segundos', 30)) * 1000)
        # Re-sincroniza estado/tempo com a API (fonte da verdade das batidas).
        self.timer_sync = QTimer(self); self.timer_sync.timeout.connect(self._safe(self._sincronizar_sessao_async))
        self.timer_sync.start(max(15, int(CFG.get('sincronizar_sessao_segundos', 60))) * 1000)
        # Piscar do botao de hora extra
        self.timer_pulso = QTimer(self); self.timer_pulso.timeout.connect(self._safe(self._pulsar_extra))
        self.timer_pulso.start(600)

    # ---------------- BATIDAS (assincronas) ----------------
    def _bater_async(self, tipo, msg_sucesso):
        """Executa a batida em uma thread para nao congelar a interface."""
        if self.ocupado:
            return
        self._set_ocupado(True)
        # Fecha um periodo de ociosidade em aberto ANTES de mudar de estado:
        # senao ele se perde quando o funcionario sai ou entra em intervalo.
        ocioso = self.ocioso_desde
        self.ocioso_desde = None
        def work():
            if ocioso:
                fim = self.api.agora()
                self.api.ociosidade(ocioso.strftime('%Y-%m-%d %H:%M:%S'),
                                    fim.strftime('%Y-%m-%d %H:%M:%S'), 'inatividade')
            r = self.api.batida(tipo)
            if r.get('offline') and r.get('batida'):
                fila_add(r['batida'])   # sem rede: guarda e reenvia depois
            self.batida_pronta.emit(tipo, r, msg_sucesso)
        threading.Thread(target=work, daemon=True).start()

    def _on_batida_pronta(self, tipo, r, msg_sucesso):
        """Tratado na thread da UI: aplica o novo estado apos a resposta do servidor."""
        self._set_ocupado(False)
        offline = bool(r and r.get('offline'))
        if not r or not (r.get('ok') or offline):
            self._banner(r.get('erro', 'Falha ao registrar a batida.') if r else 'Falha de conexao.', 'erro')
            return
        # Ancora no momento que o servidor REALMENTE gravou (ele pode corrigir o
        # que enviamos). Offline, vale o momento guardado na fila.
        agora = self.api.agora()
        try:
            momento = r.get('momento') or (r.get('batida') or {}).get('momento')
            if momento:
                agora = datetime.strptime(momento, '%Y-%m-%d %H:%M:%S')
        except Exception:
            pass
        if tipo == 'entrada':
            self.estado = 'trabalhando'
            self._trab_seg_inicio = agora
        elif tipo == 'saida':
            # Fecha o segmento de trabalho em aberto no total acumulado.
            if self._trab_seg_inicio:
                self._trab_base_seg += (agora - self._trab_seg_inicio).total_seconds()
                self._trab_seg_inicio = None
            # Se a saida veio do fim de expediente, mantem o estado "bloqueado"
            # (EXPEDIENTE ENCERRADO); caso contrario e uma saida normal.
            self.estado = 'bloqueado' if self._encerrando else 'fora'
            self._encerrando = False
        elif tipo == 'saida_intervalo':
            self.estado = 'intervalo'
            # Fecha o segmento de trabalho e abre a contagem do intervalo.
            if self._trab_seg_inicio:
                self._trab_base_seg += (agora - self._trab_seg_inicio).total_seconds()
                self._trab_seg_inicio = None
            self.intervalo_inicio = agora
        elif tipo == 'retorno_intervalo':
            self.estado = 'trabalhando'
            self._trab_seg_inicio = agora
            if self.intervalo_inicio:
                usados = (agora - self.intervalo_inicio).total_seconds() / 60
                self.minutos_intervalo_prev += int(round(usados))
                self.intervalo_inicio = None
        self._atualizar_botoes()
        if offline:
            self._pendentes = len(fila_ler())
            self._banner(f"{msg_sucesso} (sem conexao — sera enviada ao servidor "
                         f"assim que a internet voltar).", 'alerta')
            self._notificar(f"{msg_sucesso} — pendente de envio ao servidor.")
            return
        nsr = r.get('nsr')
        sufixo = f"  ·  NSR {nsr:09d}" if isinstance(nsr, int) else ""
        self._banner(msg_sucesso + sufixo, 'sucesso')
        self._notificar(msg_sucesso)

    def bater_entrada(self):
        self._bater_async('entrada', "Entrada registrada")

    def bater_saida(self):
        # Aviso de saida antecipada (nao bloqueia).
        if self.estado == 'trabalhando' and self.escala and self._dentro_expediente():
            resp = QMessageBox.question(
                self, "Saida antecipada",
                "Voce esta saindo antes do fim do expediente.\nDeseja realmente encerrar agora?",
                QMessageBox.Yes | QMessageBox.No, QMessageBox.No)
            if resp != QMessageBox.Yes:
                return
        self._bater_async('saida', "Saida registrada")

    def bater_intervalo(self):
        self._bater_async('saida_intervalo', "Intervalo iniciado. Bom descanso!")

    def bater_retorno(self):
        self._bater_async('retorno_intervalo', "Retorno registrado. Bom trabalho!")

    def _set_ocupado(self, ocupado):
        self.ocupado = ocupado
        for b in (self.btn_entrada, self.btn_saida, self.btn_int, self.btn_ret):
            if ocupado:
                b.setText("Registrando...")
        if not ocupado:
            self.btn_entrada.setText("Iniciar expediente")
            self.btn_saida.setText("Encerrar expediente")
            self.btn_int.setText("Iniciar intervalo")
            self.btn_ret.setText("Retornar do intervalo")
        self._atualizar_botoes()

    # ---------------- FEEDBACK VISUAL ----------------
    def _banner(self, msg, tipo='info'):
        """Mostra uma faixa de feedback no topo (some sozinha)."""
        cores = {
            'sucesso': (COR['sucesso'], _shade(COR['sucesso'], 1.75)),
            'erro':    (COR['perigo'],  _shade(COR['perigo'], 1.75)),
            'alerta':  (COR['alerta'],  _shade(COR['alerta'], 1.7)),
            'info':    (COR['primaria'],_shade(COR['primaria'], 1.75)),
        }
        cor, bg = cores.get(tipo, cores['info'])
        self.banner.setText(msg)
        self.banner.setStyleSheet(
            f"background:{bg};color:{cor};font-weight:600;font-size:12px;"
            f"padding:9px 12px;border-radius:10px")
        self.banner.setVisible(True)
        # alertas persistentes (carga atingida) nao somem sozinhos
        if self._banner_timer:
            self._banner_timer.stop()
        if tipo != 'alerta':
            self._banner_timer = QTimer(self); self._banner_timer.setSingleShot(True)
            self._banner_timer.timeout.connect(lambda: self.banner.setVisible(False))
            self._banner_timer.start(5000)

    # ---------------- HORA EXTRA ----------------
    def solicitar_extra_dialog(self):
        dlg = QDialog(self); dlg.setWindowTitle("Solicitar hora extra"); dlg.setFixedSize(420, 320)
        l = QVBoxLayout(); l.setContentsMargins(24, 20, 24, 20); l.setSpacing(10)
        t = QLabel("Solicitar hora extra")
        t.setStyleSheet(f"font-size:17px;font-weight:700;color:{COR['roxo']}")
        l.addWidget(t)
        l.addWidget(QLabel("Quantos minutos extras voce precisa?"))
        sp = QSpinBox(); sp.setRange(15, 240); sp.setValue(60); sp.setSuffix(" min")
        sp.setSingleStep(15)
        l.addWidget(sp)
        l.addWidget(QLabel("Justificativa (obrigatoria):"))
        txt = QTextEdit(); txt.setMaximumHeight(90)
        txt.setPlaceholderText("Descreva o motivo da hora extra...")
        l.addWidget(txt)
        btns = QHBoxLayout(); btns.setSpacing(10)
        cancel = QPushButton("Cancelar"); cancel.setStyleSheet(btn_css(COR['cinza'], alto=False))
        ok = QPushButton("Enviar solicitacao"); ok.setStyleSheet(btn_css(COR['roxo'], alto=False))
        ok.setCursor(Qt.PointingHandCursor); cancel.setCursor(Qt.PointingHandCursor)
        btns.addWidget(cancel); btns.addWidget(ok); l.addLayout(btns)
        dlg.setLayout(l)
        ok.clicked.connect(dlg.accept); cancel.clicked.connect(dlg.reject)
        if dlg.exec_() == QDialog.Accepted:
            j = txt.toPlainText().strip()
            if not j:
                QMessageBox.warning(self, "Erro", "Justificativa e obrigatoria.")
                return
            r = self.api.solicitar_extra(sp.value(), j)
            if r.get('ok'):
                self.id_extra_pendente = r['id']
                self.minutos_extra_solicitados = sp.value()
                self._banner(f"Solicitacao de hora extra enviada ao gestor (#{r['id']}).", 'info')
                self._notificar("Solicitacao de hora extra enviada. Aguarde a aprovacao.")
            else:
                self._banner(r.get('erro', 'Falha ao enviar solicitacao.'), 'erro')

    # ---------------- CARGA / ALMOCO ----------------
    def _carga_diaria_min(self):
        """Minutos de carga diaria: usa o valor da escala ou calcula por entrada/saida - almoco."""
        c = self.escala.get('carga_diaria_minutos')
        if c:
            return int(c)
        try:
            ent = datetime.strptime((self.escala.get('entrada') or '08:00:00')[:8], '%H:%M:%S')
            sai = datetime.strptime((self.escala.get('saida') or '17:00:00')[:8], '%H:%M:%S')
            total = int((sai - ent).total_seconds() // 60) - int(self.escala.get('almoco_minutos') or 0)
            return total if total > 0 else 480
        except Exception:
            return 480

    def _segundos_trabalhados(self):
        """Segundos trabalhados hoje = segmentos fechados + segmento em aberto ao vivo.
        Ancorado nas batidas, entao independe de o app ter sido fechado/reaberto."""
        base = self._trab_base_seg
        if self.estado == 'trabalhando' and self._trab_seg_inicio:
            base += (self.api.agora() - self._trab_seg_inicio).total_seconds()
        return max(0.0, base)

    # ---------------- LOOP DE VERIFICACAO ----------------
    def _check_estado(self):
        # 1) Ociosidade
        if self.estado == 'trabalhando':
            idle = get_idle_seconds()
            limite = int(self.config_srv.get('ociosidade_segundos_limite') or CFG.get('ociosidade_segundos', 300))
            if idle > limite:
                if not self.ocioso_desde:
                    self.ocioso_desde = self.api.agora() - timedelta(seconds=idle)
                    log(f"Ociosidade iniciada as {self.ocioso_desde}")
            else:
                if self.ocioso_desde:
                    inicio, fim = self.ocioso_desde, self.api.agora()
                    self.ocioso_desde = None
                    log(f"Ociosidade reportada: {int((fim - inicio).total_seconds())}s")
                    self._em_thread(lambda: self.api.ociosidade(
                        inicio.strftime('%Y-%m-%d %H:%M:%S'),
                        fim.strftime('%Y-%m-%d %H:%M:%S'), 'inatividade'))

        # 2) Hora do intervalo (lembrete)
        if self.estado == 'trabalhando' and self.escala:
            agora = self.api.agora().strftime('%H:%M')
            inicio_int = (self.escala.get('intervalo_inicio') or '')[:5]
            if inicio_int and agora == inicio_int and self._ultimo_lembrete_int != agora:
                self._ultimo_lembrete_int = agora   # evita repetir no tick seguinte
                self._notificar("Esta na hora do seu intervalo de almoco!")

        # 3) Fim de expediente (so no dia de trabalho e ja contando a hora extra aprovada)
        if self.estado == 'trabalhando' and self.escala and self._trabalha_hoje():
            if self.api.agora() >= self._fim_previsto():
                if self.id_extra_pendente:
                    self._consultar_extra_async()   # decide quando a resposta chegar
                else:
                    self._fim_expediente(self._bloqueia_tela())

        # 4) Heartbeat (sinaliza ao servidor que o agente esta vivo)
        hb = max(30, int(CFG.get('heartbeat_segundos', 60)))
        if time.monotonic() - self._ultimo_heartbeat >= hb:
            self._ultimo_heartbeat = time.monotonic()
            self._em_thread(self._heartbeat)

    def _em_thread(self, fn):
        """Dispara uma chamada de rede fora da thread da UI (nao congela a janela)."""
        def wrapper():
            try:
                fn()
            except Exception as e:
                log(f"Erro em chamada assincrona: {e}")
        threading.Thread(target=wrapper, daemon=True).start()

    def _heartbeat(self):
        requests.post(f"{self.api.base}/heartbeat", headers=self.api._headers(), timeout=5)

    def _bloqueia_tela(self):
        return str(self.config_srv.get('bloqueio_tela_apos_expediente', '1')) == '1'

    def _trabalha_hoje(self):
        """False quando a escala marca folga hoje (servidor manda trabalha_hoje=0)."""
        if not self.escala:
            return False
        return int(self.escala.get('trabalha_hoje', 1) or 0) == 1

    def _fim_previsto(self):
        """Momento em que o expediente deve encerrar: saida da escala + hora extra
        aprovada. Sem isso a extra aprovada era ignorada e a saida caia na hora."""
        agora = self.api.agora()
        try:
            saida_t = datetime.strptime((self.escala.get('saida') or '17:00:00')[:8], '%H:%M:%S').time()
        except Exception:
            saida_t = datetime.strptime('17:00:00', '%H:%M:%S').time()
        return (datetime.combine(agora.date(), saida_t)
                + timedelta(minutes=max(0, self.minutos_extra_aprovados)))

    def _consultar_extra_async(self):
        """Consulta o pedido de hora extra sem travar a UI."""
        if self._consultando_extra or not self.id_extra_pendente:
            return
        self._consultando_extra = True
        pedido_id = self.id_extra_pendente
        def work():
            self.extra_status.emit(self.api.status_extra(pedido_id))
        threading.Thread(target=work, daemon=True).start()

    def _on_extra_status(self, s):
        """Tratado na thread da UI: aplica a decisao do gestor sobre a hora extra."""
        self._consultando_extra = False
        if not s or not s.get('ok'):
            return
        ped = s.get('pedido') or {}
        if ped.get('status') == 'aprovada':
            self.minutos_extra_aprovados = int(ped.get('minutos_aprovados') or 0)
            self.id_extra_pendente = None
            self._banner(f"Hora extra aprovada: {self.minutos_extra_aprovados} min.", 'sucesso')
            self._notificar(f"Hora extra aprovada: {self.minutos_extra_aprovados} min")
        elif ped.get('status') == 'rejeitada':
            self.id_extra_pendente = None
            self._banner("Sua solicitacao de hora extra foi rejeitada.", 'erro')
            self._notificar("Sua solicitacao de hora extra foi rejeitada.")
            self._fim_expediente(self._bloqueia_tela())

    def _fim_expediente(self, bloquear):
        if self.estado in ('fora', 'bloqueado'): return
        self._encerrando = True
        self._bater_async('saida', "Fim do expediente. Saida registrada.")
        self.estado = 'bloqueado'   # trava reentrada enquanto a saida assincrona confirma
        self._notificar("Fim do expediente! Encerrando...")
        if bloquear:
            QTimer.singleShot(3000, lock_workstation)

    def _dentro_expediente(self):
        if not self.escala: return False
        agora = self.api.agora().time()
        ini = datetime.strptime((self.escala.get('entrada') or '08:00:00')[:8], '%H:%M:%S').time()
        fim = datetime.strptime((self.escala.get('saida')   or '17:00:00')[:8], '%H:%M:%S').time()
        return ini <= agora <= fim

    # ---------------- TICK (1s) ----------------
    def _tick_cronometro(self):
        # Sempre mostra o total das batidas — parado em intervalo/fora do
        # expediente, correndo enquanto trabalha. Nao e um contador proprio.
        seg = int(self._segundos_trabalhados())
        self.lbl_cron.setText(f"{seg//3600:02d}:{(seg%3600)//60:02d}:{seg%60:02d}")

        self._atualizar_progresso()
        self._atualizar_almoco()

        # Rodape tecnico discreto
        idle = int(get_idle_seconds())
        info = f"v{AGENT_VERSION}  ·  {self.api.base}  ·  ocioso {idle}s  ·  {socket.gethostname()}"
        if self.id_extra_pendente:
            info += f"  ·  extra #{self.id_extra_pendente} ({self.minutos_extra_solicitados} min)"
        if self._pendentes:
            info += f"  ·  {self._pendentes} batida(s) aguardando envio"
        self.lbl_info.setText(info)

    def _atualizar_progresso(self):
        carga = self._carga_diaria_min()
        trab_min = self._segundos_trabalhados() / 60
        limite = carga + self.minutos_extra_aprovados
        pct = int(min(100, (trab_min / limite) * 100)) if limite > 0 else 0
        self.bar_dia.setValue(pct)

        atingiu = trab_min >= carga and self.estado in ('trabalhando', 'intervalo')
        # cor da barra conforme progresso
        if atingiu and trab_min >= limite:
            chunk = COR['perigo']
        elif atingiu:
            chunk = COR['alerta']
        else:
            chunk = COR['sucesso']
        self.bar_dia.setStyleSheet(
            f"QProgressBar{{border:none;border-radius:7px;background:{COR['borda']};height:14px;}}"
            f"QProgressBar::chunk{{border-radius:7px;background:{chunk};}}")

        falta = carga - trab_min
        h = int(carga // 60); mn = int(carga % 60)
        if falta > 0:
            fh = int(falta // 60); fm = int(falta % 60)
            self.lbl_carga.setText(f"Carga diaria {h}h{mn:02d}  ·  faltam {fh}h{fm:02d}")
            self.lbl_carga.setStyleSheet(f"font-size:12px;color:{COR['texto_fraco']}")
        else:
            extra = int(-falta)
            self.lbl_carga.setText(f"Carga diaria {h}h{mn:02d} cumprida  ·  +{extra//60}h{extra%60:02d} alem")
            self.lbl_carga.setStyleSheet(f"font-size:12px;color:{COR['perigo']};font-weight:600")

        # Regra de negocio: passou da carga sem extra aprovado -> exige acao.
        self._extra_obrigatoria = (
            atingiu and self.minutos_extra_aprovados <= 0 and not self.id_extra_pendente)
        if self._extra_obrigatoria:
            if not (self.banner.isVisible() and 'carga diaria' in self.banner.text().lower()):
                self._banner("Voce atingiu sua carga diaria. Solicite hora extra ou encerre o expediente.", 'alerta')

    def _atualizar_almoco(self):
        almoco = int(self.escala.get('almoco_minutos') or 0) if self.escala else 0
        if almoco <= 0:
            self.lbl_almoco.setText(f"<span style='color:{COR['texto_fraco']}'>Sem intervalo</span>")
            return
        usado = self.minutos_intervalo_prev
        if self.estado == 'intervalo' and self.intervalo_inicio:
            usado_seg_atual = max(0.0, (self.api.agora() - self.intervalo_inicio).total_seconds())
        else:
            usado_seg_atual = 0
        restante_seg = almoco * 60 - (usado * 60 + usado_seg_atual)

        if self.estado == 'intervalo':
            # contagem regressiva ao vivo
            if restante_seg > 0:
                mm = int(restante_seg // 60); ss = int(restante_seg % 60)
                cor = COR['alerta'] if restante_seg < 300 else COR['sucesso']
                self.lbl_almoco.setText(
                    f"<span style='color:{COR['texto_fraco']}'>Almoco restante</span><br>"
                    f"<b style='font-size:15px;color:{cor}'>{mm:02d}:{ss:02d}</b>")
            else:
                excedente = int(-restante_seg // 60)
                self.lbl_almoco.setText(
                    f"<span style='color:{COR['texto_fraco']}'>Almoco</span><br>"
                    f"<b style='font-size:14px;color:{COR['perigo']}'>+{excedente} min alem</b>")
        else:
            rest_min = max(0, int(round(restante_seg / 60)))
            self.lbl_almoco.setText(
                f"<span style='color:{COR['texto_fraco']}'>Almoco ({almoco} min)</span><br>"
                f"<b style='font-size:14px'>{rest_min} min disponivel</b>")

    def _pulsar_extra(self):
        """Faz o botao de hora extra piscar quando a carga foi atingida sem extra aprovada."""
        if getattr(self, '_extra_obrigatoria', False) and self.btn_extra.isEnabled():
            self._pulso_on = not self._pulso_on
            cor = COR['roxo'] if self._pulso_on else _shade(COR['roxo'], 1.35)
            self.btn_extra.setText("⚠  Solicitar hora extra" if self._pulso_on else "Solicitar hora extra")
            self.btn_extra.setStyleSheet(btn_css(cor))
        else:
            if self._pulso_on:  # garante que volte ao normal uma unica vez
                self._pulso_on = False
                self.btn_extra.setText("Solicitar hora extra")
                self.btn_extra.setStyleSheet(btn_css(COR['roxo']))

    # ---------------- ESTADO DOS BOTOES ----------------
    def _atualizar_botoes(self):
        cores = {'fora': COR['cinza'], 'trabalhando': COR['sucesso'],
                 'intervalo': COR['alerta'], 'bloqueado': COR['perigo']}
        textos = {'fora': 'FORA DO EXPEDIENTE', 'trabalhando': 'TRABALHANDO',
                  'intervalo': 'EM INTERVALO', 'bloqueado': 'EXPEDIENTE ENCERRADO'}
        cor = cores.get(self.estado, COR['cinza'])
        self.lbl_estado.setText(textos.get(self.estado, self.estado.upper()))
        self.lbl_estado.setStyleSheet(self._badge_css(cor))
        self.lbl_cron.setStyleSheet(f"font-size:44px;font-weight:800;color:{cor}")
        livre = not self.ocupado
        self.btn_entrada.setEnabled(livre and self.estado == 'fora')
        self.btn_saida.setEnabled(livre and self.estado in ('trabalhando', 'intervalo'))
        self.btn_int.setEnabled(livre and self.estado == 'trabalhando')
        self.btn_ret.setEnabled(livre and self.estado == 'intervalo')
        self.btn_extra.setEnabled(livre and self.estado in ('trabalhando', 'intervalo'))

    def _notificar(self, msg):
        log(f"NOTIF: {msg}")
        try:
            self.tray.showMessage("DOT-ON", msg, QSystemTrayIcon.Information, 4000)
        except Exception:
            pass

    # ---------------- AUTO-UPDATE ----------------
    def _checar_update_async(self):
        # So faz sentido no executavel congelado; em modo script nao ha .exe para trocar.
        if not getattr(sys, 'frozen', False):
            log("Update: modo desenvolvimento, checagem ignorada.")
            return
        def work():
            m = verificar_atualizacao(self.api.base)
            if m:
                self.atualizacao_disp.emit(m)
        threading.Thread(target=work, daemon=True).start()

    def _on_atualizacao_disp(self, m):
        ver = m.get('versao', '?')
        notas = (m.get('notas') or '').strip()
        obrig = bool(m.get('obrigatoria'))
        corpo = f"Versao {ver} disponivel."
        if notas:
            corpo += f"\n\n{notas}"
        if obrig:
            QMessageBox.information(self, "Atualizacao obrigatoria",
                corpo + "\n\nEsta atualizacao e obrigatoria e sera instalada agora. O app vai reiniciar.")
            self._aplicar_update(m)
        else:
            resp = QMessageBox.question(self, "Atualizacao disponivel",
                corpo + "\n\nAtualizar agora? O DOT-ON vai fechar e reabrir automaticamente.",
                QMessageBox.Yes | QMessageBox.No, QMessageBox.Yes)
            if resp == QMessageBox.Yes:
                self._aplicar_update(m)

    def _aplicar_update(self, m):
        self._banner(f"Baixando atualizacao {m.get('versao')}...", 'info')
        QApplication.processEvents()
        ok, err = baixar_e_instalar(m, self.api.base)
        if not ok:
            self._banner(f"Falha na atualizacao: {err}", 'erro')
            return
        self._notificar("Atualizando o DOT-ON. O app vai reabrir em instantes...")
        QMessageBox.information(self, "DOT-ON",
            "Atualizacao baixada. O app vai fechar e reabrir automaticamente na nova versao.")
        QApplication.instance().quit()

    def closeEvent(self, e):
        e.ignore(); self.hide()
        self._notificar("DOT-ON esta rodando em segundo plano.")

# ------------------------------------------------------------------
# ICONE DA BANDEJA
# ------------------------------------------------------------------
def app_icon():
    """Ícone do app: usa o icon.ico embutido; se faltar, desenha um de reserva."""
    ico = recurso('icon.ico')
    if os.path.exists(ico):
        ic = QIcon(ico)
        if not ic.isNull():
            return ic
    return make_icon()

def make_icon():
    pm = QPixmap(64, 64); pm.fill(Qt.transparent)
    p = QPainter(pm)
    p.setRenderHint(QPainter.Antialiasing)
    p.setBrush(QColor('#2563eb')); p.setPen(Qt.NoPen)
    p.drawEllipse(4, 4, 56, 56)
    p.setPen(QColor('#fff')); p.setFont(QFont('Arial', 26, QFont.Bold))
    p.drawText(pm.rect(), Qt.AlignCenter, "D")
    p.end()
    return QIcon(pm)

# ------------------------------------------------------------------
# MAIN
# ------------------------------------------------------------------
def instancia_unica():
    """Impede um segundo agente na mesma sessao do Windows.

    Dois processos rodando duplicam batidas e disputam o estado. O mutex e
    'Local\\', entao cada usuario logado na maquina tem o seu."""
    try:
        ERROR_ALREADY_EXISTS = 183
        # O handle fica vivo enquanto o processo existir (nao fechar de proposito).
        ctypes.windll.kernel32.CreateMutexW(None, False, 'Local\\DOT-ON-Agent')
        return ctypes.windll.kernel32.GetLastError() != ERROR_ALREADY_EXISTS
    except Exception:
        return True   # sem mutex disponivel, nao impede o app de abrir

def main():
    # Loga qualquer excecao nao tratada em vez de fechar sem deixar rastro.
    def _excepthook(exc_type, exc, tb):
        import traceback
        log("ERRO NAO TRATADO:\n" + "".join(traceback.format_exception(exc_type, exc, tb)))
    sys.excepthook = _excepthook
    _rotacionar_log()

    app = QApplication(sys.argv); app.setQuitOnLastWindowClosed(False)
    app.setStyleSheet(APP_QSS)
    app.setWindowIcon(app_icon())   # ícone da janela/barra de tarefas

    if not instancia_unica():
        log("Ja existe um DOT-ON Agent aberto nesta sessao; encerrando este.")
        QMessageBox.information(None, "DOT-ON",
            "O DOT-ON Agent ja esta aberto.\nProcure o icone azul na bandeja, ao lado do relogio.")
        sys.exit(0)

    api = APIClient(CFG['api_url'])

    if not api.token or not api.user:
        dlg = LoginDialog(api)
        if dlg.exec_() != QDialog.Accepted:
            sys.exit(0)

    icon = app_icon()
    win = MainWindow(api)
    win.setWindowIcon(icon)
    win.show()

    tray = QSystemTrayIcon(icon); tray.setToolTip("DOT-ON - Registro de Ponto")
    menu = QMenu()
    act_show = QAction("Abrir DOT-ON"); act_show.triggered.connect(win.show); menu.addAction(act_show)
    menu.addSeparator()
    act_logout = QAction("Sair da conta")
    def fazer_logout():
        api.logout(); QMessageBox.information(None, "DOT-ON", "Voce foi desconectado."); app.quit()
    act_logout.triggered.connect(fazer_logout); menu.addAction(act_logout)
    act_quit = QAction("Encerrar agente"); act_quit.triggered.connect(app.quit); menu.addAction(act_quit)
    tray.setContextMenu(menu); tray.show()
    win.tray = tray

    log("DOT-ON Agent iniciado.")
    sys.exit(app.exec_())

if __name__ == '__main__':
    main()
