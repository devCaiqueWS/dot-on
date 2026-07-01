============================================================
 DOT-ON AGENT - Registro de Ponto Eletronico para Windows
============================================================

REQUISITOS:
 - Windows 10/11
 - Python 3.10+ (https://www.python.org/downloads/)
   (marque "Add Python to PATH" durante a instalacao)

INSTALACAO:
 1. Descompacte o ZIP em uma pasta (ex.: C:\DOT-ON\).
    O ZIP cria a pasta "dot-on-agent" com:
      - agent.py
      - config.json
      - requirements.txt
      - LEIA-ME.txt
 2. No PowerShell, dentro da pasta:
      pip install -r requirements.txt
 3. Confira o endereco do servidor em config.json:
      "api_url": "https://dot-on.com.br/app/api/"
 4. Execute:
      python agent.py

OBS: se voce baixou pelo LINK PERSONALIZADO da sua empresa
(/app/install/SEU-SLUG), o ZIP ja inclui um "dot-on.ini" com a
URL correta e o agente usa esse arquivo automaticamente.

PRIMEIRO ACESSO:
 - Faca login com o e-mail e a senha temporaria recebidos.
 - O sistema vai pedir para criar uma nova senha (min. 8 caracteres).

GERAR EXECUTAVEL (.exe) OPCIONAL:
 - pip install pyinstaller
 - pyinstaller --onefile --windowed --name DOT-ON-Agent agent.py
 - O executavel ficara em dist\DOT-ON-Agent.exe
   (envie para app/downloads/DOT-ON-Agent.exe para liberar no painel)

INICIAR COM O WINDOWS (opcional):
 - Win+R -> "shell:startup" -> crie um atalho para:
      pythonw "C:\DOT-ON\dot-on-agent\agent.py"

FUNCIONALIDADES:
 - Registro de entrada / saida / intervalo
 - Deteccao automatica de ociosidade (mouse/teclado parados)
 - Lembrete de cumprimento de intervalo (almoco)
 - Bloqueio de tela ao fim do expediente
 - Solicitacao de hora extra com aprovacao do gestor
 - Funciona em segundo plano (icone na bandeja)

SUPORTE: pierre@syscomai.com.br
============================================================
