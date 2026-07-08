# DOT-ON · Faturamento SaaS (Asaas) — guia de ativação

Integração de cobrança recorrente via **Asaas** para operar o DOT-ON como SaaS,
com **plano único de valor fixo** e mensalidade automática (Pix, boleto ou cartão).

> Branch: `dev`. Nada em `main` foi alterado.

## Modelo comercial

- **Plano único**, valor fixo mensal, configurável (padrão **R$ 49,90/mês**).
- **Trial** de 30 dias (configurável) no cadastro — sem cartão.
- Sem cobrança por colaborador: funcionários ilimitados no mesmo preço.

### Por que R$ 49,90 (posicionamento de mercado 2025/2026)

| Concorrente        | Entrada / preço            |
|--------------------|----------------------------|
| PontoSimples       | a partir de R$ 49,90/mês   |
| Pontomais          | a partir de R$ 59,90/mês   |
| Tangerino / Sólides| a partir de R$ 69,90/mês ou R$ 4,50/colaborador |
| Genyo              | ~R$ 3,75/colaborador       |
| PontoBarato        | R$ 20 (5 func) / R$ 50 (20 func) |

O plano único de **R$ 49,90** fica **levemente abaixo** da entrada dos líderes
(Pontomais/Tangerino) e, por não cobrar por cabeça, fica muito mais competitivo
conforme a empresa cresce. O valor é ajustável em **SuperAdmin → Faturamento**.

## Passo a passo para ativar

1. **Criar conta no Asaas** (https://asaas.com) — comece pelo **Sandbox** para testes.
2. Gerar a **API Key** (Configurações → Integrações → API) — `access_token`.
3. No painel **SuperAdmin → 💰 Faturamento**:
   - Ambiente: `Sandbox` (testes) ou `Produção`.
   - Colar a **chave da API**.
   - Definir **valor do plano** e **dias de trial**.
   - Clicar **Testar conexão** para validar a credencial.
   - Clicar **Gerar novo token** de webhook e copiá-lo.
4. No **Asaas → Integrações → Webhooks**, cadastrar:
   - URL: `https://dot-on.com.br/app/api/asaas_webhook.php`
   - Token de autenticação: o token gerado no passo 3.
   - Eventos: pagamentos (`PAYMENT_CONFIRMED`, `PAYMENT_RECEIVED`, `PAYMENT_OVERDUE`, etc.).
5. Pronto. A empresa assina em **Painel do gestor → Assinatura**.

## Schema

As tabelas são criadas automaticamente (`billing_ensure_schema()`) na primeira
visita ao painel de Faturamento. Para aplicar manualmente:

```sql
SOURCE app/config/migrations/2026_07_billing_asaas.sql;
```

Tabelas: `dot_billing_config`, `dot_assinaturas`, `dot_pagamentos`
Coluna nova: `dot_empresas.assinatura_status` (`trial|active|overdue|canceled`).

## Fluxo

```
Empresa (gestor)  →  assinatura.php  →  billing_assinar_empresa()
                                          ├─ Asaas: cria customer
                                          └─ Asaas: cria subscription (MONTHLY, valor fixo)
                                                        │
Asaas gera cobrança  →  webhook  →  asaas_webhook.php  →  billing_upsert_pagamento()
   PAYMENT_CONFIRMED/RECEIVED → empresa.assinatura_status = active
   PAYMENT_OVERDUE            → empresa.assinatura_status = overdue (5 dias de carência)
```

## Gating de acesso

`billing_acesso_liberado($empresa)` centraliza a regra (trial válido / ativa /
atraso com carência). O painel do gestor exibe avisos de trial expirando e de
atraso automaticamente (`app/admin/_layout.php`). O **bloqueio rígido** de acesso
por inadimplência ainda **não** está ligado — é a decisão comercial a tomar antes
de ir para produção (hoje só avisa, não bloqueia).

## Arquivos

- `app/includes/asaas.php` — cliente HTTP da API Asaas
- `app/includes/billing.php` — regra de negócio + schema + gating
- `app/api/asaas_webhook.php` — receptor de eventos
- `app/sysadmin/faturamento.php` — configuração + MRR/inadimplência
- `app/admin/assinatura.php` — página do gestor (assinar/pagar/histórico)
