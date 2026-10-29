# FinLife

Assistente financeiro pessoal em PHP + MySQL. Controle de receitas, despesas,
contas, metas e um assistente de IA que analisa seus dados reais — sem inventar nada.

## Requisitos

- XAMPP (PHP 8.1+ com `pdo_mysql`, `curl`, `mbstring`) — MySQL ativo na porta 3306
- Nenhuma dependência externa de frontend (vanilla JS + SVG)

## Instalação

1. Copie a pasta do projeto para `C:\xampp\htdocs\FinLife`.
2. Garanta o MySQL ativo no painel do XAMPP.
3. Crie a configuração local a partir do modelo:

   ```
   copy .env.example .env
   ```

   Preencha `DB_NAME`, `DB_USER` e `DB_PASS` com os dados do seu MySQL.
   O arquivo `.env` **nunca** é versionado — ele guarda a chave da IA e as
   credenciais do banco.

4. Crie o banco e as tabelas (idempotente):

   ```
   C:\xampp\php\php.exe scripts\install.php
   ```

   O schema também está em `database.sql` (só estrutura, sem dados).

5. (Opcional) Configure a IA em `.env` — `AI_API_KEY`, `AI_API_URL` e `AI_MODEL`
   (compatível com OpenAI, DeepSeek, Groq, OpenRouter...). Sem chave, o
   assistente opera em **modo local**: regras + dados reais do banco.

## Uso

- **Via XAMPP/Apache:** <http://localhost/FinLife/>
- **Via servidor embutido (desenvolvimento):**

  ```
  C:\xampp\php\php.exe -S 127.0.0.1:8090 -t public router.php
  ```

  e acesse <http://127.0.0.1:8090/>

Crie sua conta em **/cadastro** — o sistema já semeia 16 categorias padrão
e uma carteira de dinheiro. Depois use as páginas **Transações**, **Contas**,
**Categorias**, **Metas** e **FinLife AI**.

### Exemplos de comandos para a IA

- "Gastei R$ 35 no Uber."
- "Quanto gastei esse mês?"
- "Onde estou gastando mais?"
- "Quanto preciso guardar para atingir minha meta?"
- "Crie uma meta para comprar uma moto de 15 mil"

Ações da IA (criar transação/meta) são sempre **confirmadas** antes de
executar, passam pelas regras de negócio (models) e **nunca executam SQL**.

## Segurança

- Senhas com `password_hash()` (bcrypt) + rehash automático
- Sessões HttpOnly/SameSite, regeneração de ID no login, fingerprint de UA
- Proteção de páginas (redirect) e de API (401 JSON)
- CSRF em todas as operações que alteram estado
- Prepared statements em 100% das queries (sem SQL injection)
- Saída HTML escapada no servidor (`e()`) e no frontend (`FL.esc()`)
- Rate limiting (login, cadastro, IA, transações)
- Dados isolados por usuário em todas as tabelas (FK + `user_id`)
- Chave da IA apenas no `.env` (fora do Git, nunca exposta ao navegador)
- Diretórios de backend inacessíveis pelo browser (`.htaccess` + `router.php`)

## Estrutura

```
api/         Endpoints REST (auth, transactions, accounts, categories, goals, dashboard, ai)
config/      Configuração geral e da IA (lida do .env)
core/        Database (PDO), Auth, Validator, RateLimiter
controllers/ PageController (rotas server-side)
includes/    Bootstrap, Env (.env), helpers
models/      User, Account, Category, Transaction, Goal, AiChat
public/      Front controller + assets (css, js, img)
scripts/     install.php (banco), screenshots.js (testes visuais)
services/    AIService, AiAssistant, ContextBuilder, LocalNlu
views/       Views PHP (layouts, auth, dashboard, transações, contas, categorias, metas, ai)
```

## Scripts de verificação

Os scripts em `scripts/` e `tools/` automatizam auditorias visuais (screenshots,
testes de fluxo, comparação de assets). Eles **não** carregam credenciais:
a senha do usuário de teste vem do ambiente.

```powershell
$env:FINLIFE_TEST_PASSWORD = "<senha do usuário local de teste>"
node scripts\screenshots.js
```

Sem a variável, o script encerra com uma mensagem explicativa — nunca tenta
autenticar com um valor fixo.

## O que não entra no repositório

| Item | Motivo |
|------|--------|
| `.env` | Segredos de verdade: chave da IA e senha do banco |
| `logs/*.log` | Log de execução do ambiente local |
| `tools/ffmpeg/` | Binários de terceiros (ffmpeg/ffprobe, ~200 MB) |
| `video-pitch*/` | Arquivos de produção do vídeo (áudio, captura, render) |
| `.design-preview/` | Material de revisão visual |

O código-fonte, o schema do banco e a documentação **são** versionados.
