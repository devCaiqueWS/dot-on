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

import sys, os, json, time, socket, threading, ctypes, configparser
from datetime import datetime, timedelta
from pathlib import Path

import requests
from PyQt5.QtCore import Qt, QTimer, pyqtSignal, QObject
from PyQt5.QtGui import QIcon, QPixmap, QPainter, QColor, QFont
from PyQt5.QtWidgets import (QApplication, QMainWindow, QSystemTrayIcon, QMenu,
    QAction, QWidget, QVBoxLayout, QHBoxLayout, QLabel, QLineEdit, QPushButton,
    QMessageBox, QDialog, QTextEdit, QSpinBox, QFormLayout)

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
INI_FILE = EXE_DIR / 'dot-on.ini'  # entregue pelo instalador ao lado do .exe

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

def log(msg):
    line = f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {msg}"
    print(line)
    try:
        with open(LOG_FILE, 'a', encoding='utf-8') as f:
            f.write(line + '\n')
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
# API CLIENT
# ------------------------------------------------------------------
class APIClient:
    def __init__(self, base_url):
        self.base = base_url.rstrip('/')
        self.token = None
        self.user = None
        self.precisa_trocar_senha = 0
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

    def batida(self, tipo, momento=None):
        momento = momento or datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        try:
            r = requests.post(f"{self.base}/batida", headers=self._headers(),
                json={'tipo': tipo, 'momento': momento, 'hostname': socket.gethostname()}, timeout=15)
            return r.json()
        except Exception as e:
            log(f"Erro batida: {e}"); return {'ok': False, 'erro': str(e)}

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
            r = requests.get(f"{self.base}/escala", headers=self._headers(), timeout=10)
            return r.json().get('escala')
        except Exception:
            return None

    def config(self):
        try:
            r = requests.get(f"{self.base}/config", headers=self._headers(), timeout=10)
            return r.json().get('config', {})
        except Exception:
            return {}

    def sessao_atual(self):
        try:
            r = requests.get(f"{self.base}/sessao/atual", headers=self._headers(), timeout=10)
            return r.json()
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
# LOGIN DIALOG
# ------------------------------------------------------------------
class LoginDialog(QDialog):
    def __init__(self, api: APIClient):
        super().__init__()
        self.api = api
        self.setWindowTitle("DOT-ON - Login")
        self.setFixedSize(380, 280)
        layout = QVBoxLayout()
        layout.addWidget(self._titulo())
        form = QFormLayout()
        self.email = QLineEdit(); self.email.setPlaceholderText("seu.email@empresa.com")
        self.senha = QLineEdit(); self.senha.setEchoMode(QLineEdit.Password)
        self.servidor = QLineEdit(CFG.get('api_url', ''))
        form.addRow("E-mail:", self.email)
        form.addRow("Senha:", self.senha)
        form.addRow("Servidor:", self.servidor)
        layout.addLayout(form)
        btn = QPushButton("Entrar")
        btn.setStyleSheet("background:#2563eb;color:white;padding:10px;font-weight:bold;border:none;border-radius:4px")
        btn.clicked.connect(self.fazer_login)
        layout.addWidget(btn)
        self.status = QLabel("")
        self.status.setStyleSheet("color:#dc2626")
        layout.addWidget(self.status)
        self.setLayout(layout)

    def _titulo(self):
        lbl = QLabel("DOT-ON")
        lbl.setStyleSheet("font-size:28px;font-weight:bold;color:#2563eb;text-align:center")
        lbl.setAlignment(Qt.AlignCenter)
        return lbl

    def fazer_login(self):
        global CFG
        api_url = self.servidor.text().strip()
        CFG['api_url'] = api_url
        try:
            CONFIG_FILE.write_text(json.dumps(CFG, indent=2), encoding='utf-8')
        except Exception:
            pass
        self.api.base = api_url.rstrip('/')
        ok, info = self.api.login(self.email.text().strip(), self.senha.text())
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
        self.setFixedSize(400, 280)
        l = QVBoxLayout()
        msg = QLabel("Por seguranca, defina uma nova senha para o seu primeiro acesso.")
        msg.setWordWrap(True)
        l.addWidget(msg)
        form = QFormLayout()
        self.nova = QLineEdit(); self.nova.setEchoMode(QLineEdit.Password)
        self.conf = QLineEdit(); self.conf.setEchoMode(QLineEdit.Password)
        form.addRow("Nova senha:", self.nova)
        form.addRow("Confirmar:", self.conf)
        l.addLayout(form)
        dica = QLabel("Minimo de 8 caracteres.")
        dica.setStyleSheet("color:#6b7280;font-size:11px")
        l.addWidget(dica)
        btn = QPushButton("Salvar nova senha")
        btn.setStyleSheet("background:#2563eb;color:white;padding:10px;font-weight:bold;border:none;border-radius:4px")
        btn.clicked.connect(self.salvar)
        l.addWidget(btn)
        self.status = QLabel("")
        self.status.setStyleSheet("color:#dc2626")
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
    def __init__(self, api: APIClient):
        super().__init__()
        self.api = api
        self.escala = api.escala() or {}
        self.config_srv = api.config()
        self.estado = 'fora'   # fora | trabalhando | intervalo | extra | bloqueado
        self.ocioso_desde = None
        self.minutos_trab_hoje = 0
        self.minutos_extra_solicitados = 0
        self.id_extra_pendente = None
        self.setWindowTitle(f"DOT-ON - {api.user['nome']}")
        self.setFixedSize(440, 520)
        self._build_ui()
        self._restaurar_sessao()
        self._iniciar_timers()

    def _build_ui(self):
        central = QWidget(); layout = QVBoxLayout()

        titulo = QLabel("DOT-ON")
        titulo.setStyleSheet("font-size:24px;font-weight:bold;color:#2563eb")
        titulo.setAlignment(Qt.AlignCenter)
        layout.addWidget(titulo)

        self.lbl_user = QLabel(f"<b>{self.api.user['nome']}</b><br>Matricula: {self.api.user['matricula']}")
        self.lbl_user.setAlignment(Qt.AlignCenter)
        layout.addWidget(self.lbl_user)

        # Cartao de escala (almoco por duracao; campos podem vir nulos/vazios)
        def _hm(v, d='--'):
            v = '' if v is None else str(v)
            return v[:5] if v else d
        esc_txt = "Sem escala configurada"
        if self.escala:
            almoco = self.escala.get('almoco_minutos')
            almoco_txt = f"{int(almoco)} min" if almoco not in (None, '', 0, '0') else "--"
            esc_txt = (f"Jornada: {_hm(self.escala.get('entrada'))} - {_hm(self.escala.get('saida'))}"
                       f"   |   Almoco: {almoco_txt}")
        lbl_esc = QLabel(esc_txt)
        lbl_esc.setStyleSheet("background:#f3f4f6;padding:10px;border-radius:6px;font-size:12px")
        lbl_esc.setAlignment(Qt.AlignCenter)
        layout.addWidget(lbl_esc)

        # Estado atual
        self.lbl_estado = QLabel("Estado: FORA DO EXPEDIENTE")
        self.lbl_estado.setStyleSheet("font-size:14px;color:#6b7280;padding:8px;text-align:center")
        self.lbl_estado.setAlignment(Qt.AlignCenter)
        layout.addWidget(self.lbl_estado)

        # Cronometro
        self.lbl_cron = QLabel("00:00:00")
        self.lbl_cron.setStyleSheet("font-size:36px;font-weight:bold;color:#16a34a")
        self.lbl_cron.setAlignment(Qt.AlignCenter)
        layout.addWidget(self.lbl_cron)

        # Botoes principais
        btn_layout = QHBoxLayout()
        self.btn_entrada = QPushButton("Iniciar expediente")
        self.btn_entrada.setStyleSheet(self._btn_css('#16a34a'))
        self.btn_entrada.clicked.connect(self.bater_entrada)
        btn_layout.addWidget(self.btn_entrada)

        self.btn_saida = QPushButton("Encerrar expediente")
        self.btn_saida.setStyleSheet(self._btn_css('#dc2626'))
        self.btn_saida.clicked.connect(self.bater_saida)
        btn_layout.addWidget(self.btn_saida)
        layout.addLayout(btn_layout)

        btn_layout2 = QHBoxLayout()
        self.btn_int = QPushButton("Iniciar intervalo")
        self.btn_int.setStyleSheet(self._btn_css('#f59e0b'))
        self.btn_int.clicked.connect(self.bater_intervalo)
        btn_layout2.addWidget(self.btn_int)

        self.btn_ret = QPushButton("Retornar do intervalo")
        self.btn_ret.setStyleSheet(self._btn_css('#2563eb'))
        self.btn_ret.clicked.connect(self.bater_retorno)
        btn_layout2.addWidget(self.btn_ret)
        layout.addLayout(btn_layout2)

        # Hora extra
        self.btn_extra = QPushButton("Solicitar hora extra")
        self.btn_extra.setStyleSheet(self._btn_css('#7c3aed'))
        self.btn_extra.clicked.connect(self.solicitar_extra_dialog)
        layout.addWidget(self.btn_extra)

        # Status info
        self.lbl_info = QLabel("")
        self.lbl_info.setStyleSheet("background:#f9fafb;padding:8px;border-radius:6px;font-size:11px;color:#6b7280")
        self.lbl_info.setWordWrap(True)
        layout.addWidget(self.lbl_info)

        central.setLayout(layout)
        self.setCentralWidget(central)
        self._atualizar_botoes()

    def _btn_css(self, color):
        return f"background:{color};color:white;padding:12px;font-weight:bold;border:none;border-radius:6px;font-size:13px"

    def _restaurar_sessao(self):
        s = self.api.sessao_atual()
        if s.get('ok') and s.get('sessao'):
            sessao = s['sessao']
            self.minutos_trab_hoje = int(sessao.get('minutos_trabalhados') or 0)
            batidas = s.get('batidas', [])
            if batidas:
                ultimo = batidas[-1]['tipo']
                if ultimo == 'entrada' or ultimo == 'retorno_intervalo':
                    self.estado = 'trabalhando'
                elif ultimo == 'saida_intervalo':
                    self.estado = 'intervalo'
                elif ultimo == 'saida':
                    self.estado = 'fora'
                log(f"Sessao restaurada: estado={self.estado}, batidas={len(batidas)}")
        self._atualizar_botoes()

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

    # -------- BATIDAS --------
    def bater_entrada(self):
        r = self.api.batida('entrada')
        if r.get('ok'):
            self.estado = 'trabalhando'
            self._atualizar_botoes()
            self._notificar(f"Entrada registrada - NSR {r['nsr']:09d}")
        else:
            QMessageBox.warning(self, "Erro", r.get('erro','Falha'))

    def bater_saida(self):
        # Verifica se esta antes do fim de expediente: pode exigir extra
        if self.escala and self._dentro_expediente():
            QMessageBox.information(self, "Saida antecipada", "Voce esta saindo antes do fim do expediente. Registre uma justificativa pelo painel web se necessario.")
        r = self.api.batida('saida')
        if r.get('ok'):
            self.estado = 'fora'; self._atualizar_botoes()
            self._notificar(f"Saida registrada - NSR {r['nsr']:09d}")
        else:
            QMessageBox.warning(self, "Erro", r.get('erro','Falha'))

    def bater_intervalo(self):
        r = self.api.batida('saida_intervalo')
        if r.get('ok'):
            self.estado = 'intervalo'; self._atualizar_botoes()
            self._notificar("Intervalo iniciado. Bom descanso!")
        else: QMessageBox.warning(self, "Erro", r.get('erro','Falha'))

    def bater_retorno(self):
        r = self.api.batida('retorno_intervalo')
        if r.get('ok'):
            self.estado = 'trabalhando'; self._atualizar_botoes()
            self._notificar("Retorno registrado. Bom trabalho!")
        else: QMessageBox.warning(self, "Erro", r.get('erro','Falha'))

    # -------- HORA EXTRA --------
    def solicitar_extra_dialog(self):
        dlg = QDialog(self); dlg.setWindowTitle("Solicitar Hora Extra"); dlg.setFixedSize(400, 280)
        l = QVBoxLayout()
        l.addWidget(QLabel("Quantos minutos extras voce precisa?"))
        sp = QSpinBox(); sp.setRange(15, 240); sp.setValue(60); sp.setSuffix(" min")
        l.addWidget(sp)
        l.addWidget(QLabel("Justificativa (obrigatoria):"))
        txt = QTextEdit(); txt.setMaximumHeight(100)
        l.addWidget(txt)
        btns = QHBoxLayout()
        ok = QPushButton("Enviar solicitacao"); ok.setStyleSheet(self._btn_css('#2563eb'))
        cancel = QPushButton("Cancelar")
        btns.addWidget(cancel); btns.addWidget(ok); l.addLayout(btns)
        dlg.setLayout(l)
        ok.clicked.connect(dlg.accept); cancel.clicked.connect(dlg.reject)
        if dlg.exec_() == QDialog.Accepted:
            j = txt.toPlainText().strip()
            if not j: QMessageBox.warning(self, "Erro", "Justificativa e obrigatoria."); return
            r = self.api.solicitar_extra(sp.value(), j)
            if r.get('ok'):
                self.id_extra_pendente = r['id']
                self.minutos_extra_solicitados = sp.value()
                QMessageBox.information(self, "Enviado",
                    f"Solicitacao enviada ao gestor (ID #{r['id']}).\nVoce recebera a resposta no app.")
            else:
                QMessageBox.warning(self, "Erro", r.get('erro','Falha'))

    # -------- LOOP DE VERIFICACAO --------
    def _check_estado(self):
        # 1) Ociosidade
        if self.estado == 'trabalhando':
            idle = get_idle_seconds()
            limite = int(self.config_srv.get('ociosidade_segundos_limite') or CFG.get('ociosidade_segundos', 300))
            if idle > limite:
                if not self.ocioso_desde:
                    self.ocioso_desde = datetime.now() - timedelta(seconds=idle)
                    log(f"Ociosidade iniciada as {self.ocioso_desde}")
            else:
                if self.ocioso_desde:
                    fim = datetime.now()
                    self.api.ociosidade(
                        self.ocioso_desde.strftime('%Y-%m-%d %H:%M:%S'),
                        fim.strftime('%Y-%m-%d %H:%M:%S'),
                        'inatividade'
                    )
                    log(f"Ociosidade reportada: {(fim - self.ocioso_desde).seconds}s")
                    self.ocioso_desde = None

        # 2) Hora do intervalo (lembrete)
        if self.estado == 'trabalhando' and self.escala:
            agora = datetime.now().strftime('%H:%M')
            inicio_int = (self.escala.get('intervalo_inicio') or '')[:5]
            if inicio_int and agora == inicio_int:
                self._notificar("Esta na hora do seu intervalo de almoco!")

        # 3) Fim de expediente
        if self.estado == 'trabalhando' and self.escala:
            agora_t = datetime.now().time()
            saida_t = datetime.strptime((self.escala.get('saida') or '17:00:00')[:8], '%H:%M:%S').time()
            if agora_t >= saida_t:
                bloquear = str(self.config_srv.get('bloqueio_tela_apos_expediente', '1')) == '1'
                if not self.id_extra_pendente:
                    self._fim_expediente(bloquear)
                else:
                    # checa status da extra
                    s = self.api.status_extra(self.id_extra_pendente)
                    if s.get('ok'):
                        ped = s['pedido']
                        if ped['status'] == 'aprovada':
                            self._notificar(f"Hora extra aprovada: {ped['minutos_aprovados']} min")
                            self.id_extra_pendente = None  # libera fim quando completar
                        elif ped['status'] == 'rejeitada':
                            self._notificar("Sua solicitacao de hora extra foi rejeitada.")
                            self.id_extra_pendente = None
                            self._fim_expediente(bloquear)

        # 4) Heartbeat (mantem token vivo)
        if int(time.time()) % 60 < 1:
            try: requests.post(f"{self.api.base}/heartbeat", headers=self.api._headers(), timeout=5)
            except: pass

    def _fim_expediente(self, bloquear):
        if self.estado in ('fora','bloqueado'): return
        self.bater_saida()
        self.estado = 'bloqueado'
        self._notificar("Fim do expediente! Encerrando...")
        if bloquear:
            QTimer.singleShot(3000, lock_workstation)

    def _dentro_expediente(self):
        if not self.escala: return False
        agora = datetime.now().time()
        ini = datetime.strptime((self.escala.get('entrada') or '08:00:00')[:8], '%H:%M:%S').time()
        fim = datetime.strptime((self.escala.get('saida')   or '17:00:00')[:8], '%H:%M:%S').time()
        return ini <= agora <= fim

    def _tick_cronometro(self):
        if self.estado == 'trabalhando':
            self.minutos_trab_hoje_seg = getattr(self, 'minutos_trab_hoje_seg', self.minutos_trab_hoje * 60) + 1
            h = self.minutos_trab_hoje_seg // 3600
            m = (self.minutos_trab_hoje_seg % 3600) // 60
            s = self.minutos_trab_hoje_seg % 60
            self.lbl_cron.setText(f"{h:02d}:{m:02d}:{s:02d}")
        # info
        idle = int(get_idle_seconds())
        info = f"Servidor: {self.api.base}\nOciosidade atual: {idle}s | Hostname: {socket.gethostname()}"
        if self.id_extra_pendente:
            info += f"\nExtra pendente: #{self.id_extra_pendente} ({self.minutos_extra_solicitados} min)"
        self.lbl_info.setText(info)

    def _atualizar_botoes(self):
        cores = {'fora':'#6b7280','trabalhando':'#16a34a','intervalo':'#f59e0b','bloqueado':'#dc2626'}
        textos = {'fora':'FORA DO EXPEDIENTE','trabalhando':'TRABALHANDO',
                  'intervalo':'EM INTERVALO','bloqueado':'EXPEDIENTE ENCERRADO'}
        cor = cores.get(self.estado, '#6b7280')
        self.lbl_estado.setText(f"Estado: {textos.get(self.estado, self.estado.upper())}")
        self.lbl_estado.setStyleSheet(f"font-size:14px;color:{cor};font-weight:bold;padding:8px")
        self.lbl_cron.setStyleSheet(f"font-size:36px;font-weight:bold;color:{cor}")
        self.btn_entrada.setEnabled(self.estado == 'fora')
        self.btn_saida.setEnabled(self.estado in ('trabalhando','intervalo'))
        self.btn_int.setEnabled(self.estado == 'trabalhando')
        self.btn_ret.setEnabled(self.estado == 'intervalo')
        self.btn_extra.setEnabled(self.estado in ('trabalhando','intervalo'))

    def _notificar(self, msg):
        log(f"NOTIF: {msg}")
        try:
            self.tray.showMessage("DOT-ON", msg, QSystemTrayIcon.Information, 4000)
        except Exception:
            pass

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
def main():
    # Loga qualquer excecao nao tratada em vez de fechar sem deixar rastro.
    def _excepthook(exc_type, exc, tb):
        import traceback
        log("ERRO NAO TRATADO:\n" + "".join(traceback.format_exception(exc_type, exc, tb)))
    sys.excepthook = _excepthook

    app = QApplication(sys.argv); app.setQuitOnLastWindowClosed(False)
    app.setWindowIcon(app_icon())   # ícone da janela/barra de tarefas
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
