# 📦 DEPLOY ONYX VIOLET — FinLife para spssolar.com.br

Pacote validado localmente (343 checks verdes). Siga a ordem exata.

---

## O que este pacote contém

20 arquivos com a MESMA estrutura do projeto (arraste respeitando as pastas) + o SQL da migration.
**Arquivos iguais aos que já estão no servidor NÃO estão no pacote** (transactions.js, accounts.js, cards.js, categories.js, goals.js, ai.js — comparados por hash: idênticos).

---

## PASSO 1 — Backup do banco (obrigatório)

phpMyAdmin → selecione `spssolar_finlife` → **Export** → Quick → SQL → Salvar.
Guarde o arquivo. Só depois siga.

## PASSO 2 — Migration (tabelas do módulo de Compromissos)

phpMyAdmin → aba **SQL** → cole todo o conteúdo de **`MIGRATION-commitments.sql`** → Execute.
Verificação: as 3 tabelas aparecem na lateral (commitments, commitment_occurrences, commitment_payments).

> O redesign visual NÃO depende deste passo — mas a página /compromissos sim.

## PASSO 3 — Enviar os arquivos (File Manager)

**public_html/** (raiz) — envie e substitua:
- `index.php`
- `controllers/PageController.php`
- `models/Commitment.php` (novo)
- `services/LocalNlu.php`, `services/AiAssistant.php`, `services/ContextBuilder.php`
- `includes/helpers.php`
- `api/commitments.php` (novo)
- `scripts/migrate-commitments.php` (opcional — o SQL do passo 2 já cobre)
- `views/layouts/app.php`, `views/layouts/auth.php`
- `views/ai/chat.php`
- `views/commitments/index.php` (novo — crie a pasta `views/commitments/`)

**public_html/assets/** — envie e substitua:
- `css/app.css`
- `js/app.js`, `js/charts.js`, `js/market-charts.js`
- `js/pages/dashboard.js`, `js/pages/markets.js`
- `js/pages/commitments.js` (novo)

**NÃO envie**: `.env` (o seu já está correto no servidor) · `transactions.js`, `accounts.js`, `cards.js`, `categories.js`, `goals.js`, `ai.js` (já são idênticos por hash) · `database.sql` (usa o SQL do passo 2) · `logs/` (permanece).

## PASSO 4 — Permissões e cache

- Pastas novas criadas no File Manager herdam 755 ✓ (conferir se algo ficou 700).
- **Ctrl+F5** no navegador (o CSS antigo está em cache). No celular: recarregar 2× ou limpar dados do site.

## PASSO 5 — Validação no domínio (checklist)

| # | Verificação | Esperado |
|---|---|---|
| 1 | `https://finlife.spssolar.com.br/login` | fundo quase-preto (dark default), pill roxa no formulário |
| 2 | Ver código-fonte (Ctrl+U) | `data-theme="dark"` no `<html>` |
| 3 | Login → dashboard | hero roxo com brilho, cards com borda sutil, fundo Onyx |
| 4 | Menu lateral | item **Compromissos** (grupo Planejamento) |
| 5 | `/compromissos` | página carrega com 6 KPIs e estado vazio |
| 6 | Criar um compromisso + registrar pagamento | saldo devedor reduz, aparece em Transações |
| 7 | Dashboard | card "Compromissos próximos" após o orçamento |
| 8 | Chat IA → "Quanto ainda devo ao meu pai?" | resposta com dados reais e cards visuais |
| 9 | Mercados | tiles + heatmap sem piscar; WebSocket "Mercado conectado" |
| 10 | Console (F12) | sem erros vermelhos |
| 11 | Celular | bottom nav flutuante, hero compacto, sem scroll horizontal |

## Sintoma → solução rápida

| Sintoma | Ação |
|---|---|
| Visual antigo persiste | Ctrl+F5 / limpar cache — o app.css fica em cache |
| 404 em /compromissos | `public/index.php` ou `PageController.php` não substituídos |
| Erro de tabela no /compromissos | Passo 2 (SQL) não executado |
| Tela branca | `APP_DEBUG=true` temporariamente no `.env` para ver o erro |
| CSS pela metade | reenviar `css/app.css` (upload interrompido) |
