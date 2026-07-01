"""
Gera o ícone do DOT-ON Agent (icon.ico multi-resolução).
Tema: cronômetro/ponto — fundo azul arredondado + relógio branco.
Para trocar por um logo próprio: substitua icon.ico (ou rode este script
com outra arte) e recompile o .exe.
"""
import math
from PIL import Image, ImageDraw

SS = 4               # supersampling p/ bordas suaves
BASE = 256
S = BASE * SS

AZUL_ESCURO = (12, 74, 110)   # #0c4a6e
AZUL        = (2, 132, 199)    # #0284c7
BRANCO      = (255, 255, 255)

img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
d = ImageDraw.Draw(img)

# --- Fundo arredondado com gradiente vertical azul ---
grad = Image.new("RGBA", (S, S), (0, 0, 0, 0))
gd = ImageDraw.Draw(grad)
for y in range(S):
    t = y / S
    r = int(AZUL_ESCURO[0] + (AZUL[0] - AZUL_ESCURO[0]) * t)
    g = int(AZUL_ESCURO[1] + (AZUL[1] - AZUL_ESCURO[1]) * t)
    b = int(AZUL_ESCURO[2] + (AZUL[2] - AZUL_ESCURO[2]) * t)
    gd.line([(0, y), (S, y)], fill=(r, g, b, 255))

mask = Image.new("L", (S, S), 0)
md = ImageDraw.Draw(mask)
raio = int(S * 0.22)
md.rounded_rectangle([0, 0, S - 1, S - 1], radius=raio, fill=255)
img.paste(grad, (0, 0), mask)
d = ImageDraw.Draw(img)

cx, cy = S // 2, int(S * 0.56)
rel_r = int(S * 0.30)

# --- Botão do cronômetro (topo) ---
bw, bh = int(S * 0.14), int(S * 0.10)
d.rounded_rectangle([cx - bw // 2, cy - rel_r - int(S * 0.11), cx + bw // 2, cy - rel_r + int(S * 0.02)],
                    radius=int(bh * 0.4), fill=BRANCO)

# --- Corpo do relógio (anel branco + face azul) ---
d.ellipse([cx - rel_r, cy - rel_r, cx + rel_r, cy + rel_r], fill=BRANCO)
anel = int(S * 0.035)
d.ellipse([cx - rel_r + anel, cy - rel_r + anel, cx + rel_r - anel, cy + rel_r - anel], fill=AZUL_ESCURO)

# --- Marcas das horas ---
for i in range(12):
    ang = math.radians(i * 30)
    r1 = rel_r - anel - int(S * 0.015)
    r2 = r1 - int(S * 0.03)
    x1 = cx + r1 * math.sin(ang); y1 = cy - r1 * math.cos(ang)
    x2 = cx + r2 * math.sin(ang); y2 = cy - r2 * math.cos(ang)
    d.line([(x1, y1), (x2, y2)], fill=BRANCO, width=max(2, int(S * 0.010)))

# --- Ponteiros ---
def ponteiro(ang_deg, comp, larg):
    ang = math.radians(ang_deg)
    x = cx + comp * math.sin(ang); y = cy - comp * math.cos(ang)
    d.line([(cx, cy), (x, y)], fill=BRANCO, width=larg)

ponteiro(300, int(rel_r * 0.55), max(4, int(S * 0.022)))   # ponteiro das horas (~10h)
ponteiro(60,  int(rel_r * 0.78), max(3, int(S * 0.016)))    # ponteiro dos minutos (~2)
d.ellipse([cx - int(S*0.02), cy - int(S*0.02), cx + int(S*0.02), cy + int(S*0.02)], fill=BRANCO)

# --- Downscale + exporta multi-resolução ---
img = img.resize((BASE, BASE), Image.LANCZOS)
img.save("icon.ico", sizes=[(16, 16), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)])
img.save("icon.png")
print("icon.ico e icon.png gerados.")
