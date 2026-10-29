# 🚀 FinLife — Deploy em servidor externo (estilo XAMPP: cPanel, Hostinger, etc.)

Guia rápido para qualquer hospedagem com **Apache + MySQL + PHP 8.1+**.
O projeto já está pronto: `.htaccess` configurado, `database.sql` completo (31 tabelas) e `.env` externo.

---

## O que você precisa

| Item | Detalhe |
|---|---|
| Hospedagem com PHP **8.1+** (8.2 ideal) | cPanel, Plesk, Hostinger, KingHost etc. |
| MySQL 5.7+ ou MariaDB | Já vem em praticamente toda hospedagem |
| Extensões PHP | `pdo_mysql`, `curl`, `openssl`, `mbstring`, `session` (padrão em toda hospedagem) |
| Acesso | File Manager (ou FTP) + phpMyAdmin do painel |

---

## Passo 1 — Criar o banco de dados (cPanel)

1. Painel → **MySQL® Databases**
2. **Create New Database**: nome `finlife` (o painel vai prefixar: `seuusuario_finlife`)
3. **MySQL Users → Add New User**: usuário e senha fortes
4. **Add User To Database** → selecione os dois → **ALL PRIVILEGES** → ✅

> 📝 Anote: nome do banco, usuário, senha e o prefixo (ex.: `seuusuario_finlife`).

## Passo 2 — Importar o schema (phpMyAdmin)

1. Painel → **phpMyAdmin** → selecione o banco criado na lateral esquerda
2. Aba **Import** → **Choose File** → `database.sql` (da raiz do projeto)
3. **Import** → aguarde (segundos) → as 31 tabelas aparecem

> ✅ O `database.sql` já inclui cartões, mercados, portfólio e IA — **não precisa rodar migrations**.

## Passo 3 — Subir os arquivos

1. Painel → **File Manager** → abra a pasta `public_html`
2. Suba **todo o conteúdo do projeto** (pode compactar em `.zip` no PC, subir e extrair — mais rápido)
3. Confira na raiz de `public_html`: `.htaccess`, `index.php` ❌ **não** — o `index.php` fica dentro de `public/`; na raiz devem ficar `.htaccess`, `database.sql`, `includes/`, `services/`, `config/`, `controllers/`, `views/`, `api/`, `public/`, `logs/`

> A estrutura fica exatamente como no projeto. O `.htaccess` da raiz já envia tudo para `public/` e **bloqueia acesso** a `.env`, `.sql`, `logs/` e pastas internas.

## Passo 4 — Configurar o `.env`

1. File Manager → clique com o botão direito no `.env` → **Edit**
2. Ajuste apenas o bloco do banco:

```env
DB_HOST=localhost          ← cPanel usa "localhost" (não 127.0.0.1)
DB_PORT=3306
DB_NAME=seuusuario_finlife ← com o prefixo do painel
DB_USER=seuusuario_finlife ← com o prefixo do painel
DB_PASS=suaSenhaForte      ← a senha do Passo 1
```

3. **Produção**: troque `APP_DEBUG=true` → `APP_DEBUG=false`
4. As chaves de IA (`AI_API_KEY` etc.) são opcionais no servidor — sem elas, o assistente funciona no modo local

## Passo 5 — PHP 8.2 e permissões

1. Painel → **MultiPHP Manager** → selecione o domínio → versão **8.2**
2. Permissões (File Manager → botão direito → Change Permissions):
   - Pasta `logs/` → **755** (o app grava o log de erros aí)
   - Demais pastas → 755, arquivos → 644 (padrão)

## Passo 6 — HTTPS e teste final

1. Painel → **SSL/TLS Status** → **Run AutoSSL** (certificado gratuito Let's Encrypt)
2. Acesse `https://seudominio.com` → a tela de login aparece
3. **Cadastre o primeiro usuário** pelo próprio app (botão "Criar conta")
4. Teste: registrar uma transação, abrir Mercados (cotações reais), conversar com a IA

---

## Problemas comuns

| Sintoma | Causa e correção |
|---|---|
| Página em branco ou erro 500 | `APP_DEBUG=true` temporariamente para ver o erro; confira versão do PHP (8.1+) |
| "Could not connect to database" | `DB_HOST` deve ser `localhost` no cPanel; confira nome/usuário **com prefixo** |
| CSS/JS não carregam | O `.htaccess` raiz não foi subido (arquivos ocultos: ative "Show Hidden Files" no File Manager) |
| Erro 403 em tudo | Permissões erradas; pastas 755, arquivos 644 |
| Mercados sem cotações | A hospedagem bloqueia `curl` externo — raro; verifique com o suporte |
| Cotações demoram na 1ª carga | Normal: o backend busca e cacheia (900s) — depois fica instantâneo |

---

## Resumo de 30 segundos

```text
1. cPanel → MySQL → criar banco + usuário (ALL PRIVILEGES)
2. phpMyAdmin → Import → database.sql
3. File Manager → public_html → subir tudo (zip + extract)
4. Editar .env → localhost + prefixo + senha → APP_DEBUG=false
5. MultiPHP → 8.2 · AutoSSL
6. Acessar e criar sua conta 🎉
```
