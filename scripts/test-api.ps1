
# Credencial do usuário de teste: vem do ambiente, nunca fica no código.
# Defina FINLIFE_TEST_PASSWORD antes de rodar.
if (-not $env:FINLIFE_TEST_PASSWORD) {
    Write-Error "Defina FINLIFE_TEST_PASSWORD antes de rodar este script."
    exit 1
}
$PASS = $env:FINLIFE_TEST_PASSWORD

# FinLife — Teste de API end-to-end (PowerShell)
$ErrorActionPreference = 'Stop'
$BASE = 'http://127.0.0.1:8090'
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$stamp = Get-Date -Format 'yyyyMMddHHmmss'
$EMAIL = "apitest$stamp@teste.com"
$PASS = $env:FINLIFE_TEST_PASSWORD
$script:failures = @()
$script:steps = 0

function Check($name, $cond, $detail) {
    $script:steps++
    if ($cond) { Write-Host "OK   $name $detail" }
    else { Write-Host "FALHA $name $detail" -ForegroundColor Red; $script:failures += $name }
}

function Get-Csrf {
    $r = Invoke-WebRequest -Uri "$BASE/api/auth.php?action=csrf" -WebSession $session -UseBasicParsing
    return ($r.Content | ConvertFrom-Json).csrf
}

function Api($method, $uri, $body, $sess = $null) {
    if ($null -eq $sess) { $sess = $session }
    $csrf = Invoke-WebRequest -Uri "$BASE/api/auth.php?action=csrf" -WebSession $sess -UseBasicParsing | ForEach-Object { ($_.Content | ConvertFrom-Json).csrf }
    $headers = @{ 'X-CSRF-Token' = $csrf; 'Content-Type' = 'application/json' }
    $json = if ($body) { $body | ConvertTo-Json -Depth 5 } else { $null }
    try {
        if ($null -ne $json) {
            return Invoke-WebRequest -Uri $uri -Method $method -Headers $headers -Body $json -WebSession $sess -UseBasicParsing
        }
        return Invoke-WebRequest -Uri $uri -Method $method -Headers $headers -WebSession $sess -UseBasicParsing
    } catch {
        # respostas 4xx/5xx chegam como exceção no PowerShell 5.1 — extrai o corpo
        $resp = $_.Exception.Response
        if ($resp) {
            $stream = $resp.GetResponseStream()
            if ($stream) {
                $reader = New-Object System.IO.StreamReader($stream)
                $content = $reader.ReadToEnd()
                $obj = New-Object PSObject -Property @{ Content = $content; BaseResponse = $resp }
                return $obj
            }
        }
        throw
    }
}

# ---------- 1) Proteção de páginas sem sessão ----------
try { $r = Invoke-WebRequest -Uri "$BASE/dashboard" -UseBasicParsing -MaximumRedirection 5 } catch { $r = $_.Exception.Response }
$dashContent = Invoke-WebRequest -Uri "$BASE/dashboard" -WebSession (New-Object Microsoft.PowerShell.Commands.WebRequestSession) -UseBasicParsing -MaximumRedirection 5
Check 'Pagina /dashboard sem login redireciona para login' ($dashContent.BaseResponse.ResponseUri.AbsoluteUri -match '/login') "($($dashContent.BaseResponse.ResponseUri.AbsoluteUri))"

# ---------- 2) Cadastro (precisa de CSRF, como o app exige) ----------
$r = Api POST "$BASE/api/auth.php?action=register" @{ name = 'Usuario API'; email = $EMAIL; password = $PASS; password_confirm = $PASS }
$j = $r.Content | ConvertFrom-Json
Check 'Cadastro via API' ($j.ok -eq $true -and $j.redirect -eq 'dashboard') "user_id=$($j.user_id)"
$CSRF = $j.csrf

# ---------- 3) /me ----------
$r = Invoke-WebRequest -Uri "$BASE/api/auth.php?action=me" -WebSession $session -UseBasicParsing
$j = $r.Content | ConvertFrom-Json
Check 'GET me retorna usuario' ($j.ok -eq $true -and $j.user.email -eq $EMAIL) ""

# ---------- 4) Dashboard com dados reais ----------
$r = Invoke-WebRequest -Uri "$BASE/api/dashboard.php" -WebSession $session -UseBasicParsing
$j = $r.Content | ConvertFrom-Json
Check 'Dashboard retorna snapshot' ($j.ok -eq $true -and $null -ne $j.cards.balance) "saldo=$($j.cards.balance) receitas=$($j.cards.income) despesas=$($j.cards.expense)"

# ---------- 5) Transacoes: criar (despesa) ----------
$r = Api POST "$BASE/api/transactions.php" @{ type = 'expense'; amount = 35.50; description = 'Uber'; date = (Get-Date -Format 'yyyy-MM-dd') }
$j = $r.Content | ConvertFrom-Json
Check 'Criar despesa' ($j.ok -eq $true) "id=$($j.id)"
$txId = $j.id

# criar (receita)
$r = Api POST "$BASE/api/transactions.php" @{ type = 'income'; amount = 3000; description = 'Salario'; date = (Get-Date -Format 'yyyy-MM-dd') }
$j = $r.Content | ConvertFrom-Json
Check 'Criar receita' ($j.ok -eq $true) "id=$($j.id)"
$txIncomeId = $j.id

# criar com valor invalido
$r = Api POST "$BASE/api/transactions.php" @{ type = 'expense'; amount = -5; description = 'invalido'; date = (Get-Date -Format 'yyyy-MM-dd') }
$j = $r.Content | ConvertFrom-Json
Check 'Valor negativo rejeitado' ($j.ok -eq $false) "($($j.error))"

# criar sem CSRF (deve falhar)
try {
    $r = Invoke-WebRequest -Uri "$BASE/api/transactions.php" -Method POST -Headers @{ 'Content-Type' = 'application/json' } -Body (@{ type = 'expense'; amount = 1; description = 'sem csrf'; date = (Get-Date -Format 'yyyy-MM-dd') } | ConvertTo-Json) -WebSession $session -UseBasicParsing
    $j = $r.Content | ConvertFrom-Json
    Check 'POST sem CSRF rejeitado' ($j.ok -eq $false) "($($j.error))"
} catch { Check 'POST sem CSRF rejeitado' ($_.Exception.Response.StatusCode.value__ -eq 403) "(http $($_.Exception.Response.StatusCode.value__))" }

# ---------- 6) Transacoes: listar e filtrar ----------
$r = Invoke-WebRequest -Uri "$BASE/api/transactions.php?month=$(Get-Date -Format 'yyyy-MM')" -WebSession $session -UseBasicParsing
$j = $r.Content | ConvertFrom-Json
Check 'Listar transacoes do mes' ($j.ok -eq $true -and $j.transactions.Count -ge 2) "count=$($j.transactions.Count) income=$($j.totals.income) expense=$($j.totals.expense)"

$r = Invoke-WebRequest -Uri "$BASE/api/transactions.php?type=expense" -WebSession $session -UseBasicParsing
$j = $r.Content | ConvertFrom-Json
$allExpense = ($j.transactions | Where-Object { $_.type -ne 'expense' }).Count
Check 'Filtro type=expense' ($j.ok -eq $true -and $allExpense -eq 0) "count=$($j.transactions.Count)"

$r = Invoke-WebRequest -Uri "$BASE/api/transactions.php?q=uber" -WebSession $session -UseBasicParsing
$j = $r.Content | ConvertFrom-Json
Check 'Filtro busca textual q=uber' ($j.ok -eq $true -and $j.transactions.Count -ge 1) "count=$($j.transactions.Count)"

# ---------- 7) Transacoes: editar ----------
$r = Api PUT "$BASE/api/transactions.php" @{ id = $txId; amount = 40; description = 'Uber viagem' }
$j = $r.Content | ConvertFrom-Json
Check 'Editar transacao' ($j.ok -eq $true) "id=$($j.id)"

# editar de outro usuario (nao deve achar)
$r = Api PUT "$BASE/api/transactions.php" @{ id = 999999; amount = 1 }
$j = $r.Content | ConvertFrom-Json
Check 'Editar transacao inexistente 404/422' ($j.ok -eq $false) "($($j.error))"

# ---------- 8) Transacoes: excluir ----------
$r = Api DELETE "$BASE/api/transactions.php?id=$txIncomeId"
$j = $r.Content | ConvertFrom-Json
Check 'Excluir transacao' ($j.ok -eq $true) ""

# ---------- 9) Contas ----------
$r = Invoke-WebRequest -Uri "$BASE/api/accounts.php" -WebSession $session -UseBasicParsing
$j = $r.Content | ConvertFrom-Json
Check 'Listar contas' ($j.ok -eq $true -and $j.accounts.Count -ge 1) "count=$($j.accounts.Count) total=$($j.total_balance)"
$accId = $j.accounts[0].id

$r = Api POST "$BASE/api/accounts.php" @{ name = 'Banco Teste'; type = 'checking'; initial_balance = 500 }
$j = $r.Content | ConvertFrom-Json
Check 'Criar conta' ($j.ok -eq $true) "id=$($j.id)"
$acc2 = $j.id

$r = Api PUT "$BASE/api/accounts.php" @{ id = $acc2; name = 'Banco Renomeado'; initial_balance = 600 }
$j = $r.Content | ConvertFrom-Json
Check 'Editar conta' ($j.ok -eq $true) ""

$r = Api DELETE "$BASE/api/accounts.php?id=$acc2"
$j = $r.Content | ConvertFrom-Json
Check 'Excluir conta' ($j.ok -eq $true) ""

# ---------- 10) Categorias ----------
$r = Invoke-WebRequest -Uri "$BASE/api/categories.php" -WebSession $session -UseBasicParsing
$j = $r.Content | ConvertFrom-Json
Check 'Listar categorias' ($j.ok -eq $true -and $j.categories.income.Count -ge 1 -and $j.categories.expense.Count -ge 1) "income=$($j.categories.income.Count) expense=$($j.categories.expense.Count)"

$r = Api POST "$BASE/api/categories.php" @{ name = 'TesteCat'; type = 'expense'; color = '#ff0000' }
$j = $r.Content | ConvertFrom-Json
Check 'Criar categoria' ($j.ok -eq $true) "id=$($j.id)"
$catId = $j.id

$r = Api POST "$BASE/api/categories.php" @{ name = 'TesteCat'; type = 'expense' }
$j = $r.Content | ConvertFrom-Json
Check 'Categoria duplicada rejeitada' ($j.ok -eq $false) "($($j.error))"

# ---------- 11) Metas ----------
$r = Api POST "$BASE/api/goals.php" @{ name = 'Reserva de emergencia'; target_amount = 10000; deadline = (Get-Date).AddMonths(6).ToString('yyyy-MM-dd') }
$j = $r.Content | ConvertFrom-Json
Check 'Criar meta' ($j.ok -eq $true) "id=$($j.id)"
$goalId = $j.id

$r = Api POST "$BASE/api/goals.php?action=deposit" @{ goal_id = $goalId; amount = 250; date = (Get-Date -Format 'yyyy-MM-dd'); note = 'primeiro aporte' }
$j = $r.Content | ConvertFrom-Json
Check 'Aporte na meta' ($j.ok -eq $true) ""

$r = Invoke-WebRequest -Uri "$BASE/api/goals.php" -WebSession $session -UseBasicParsing
$j = $r.Content | ConvertFrom-Json
$g = $j.goals | Where-Object { $_.id -eq $goalId }
Check 'Meta com progresso' ($null -ne $g -and $g.current_amount -gt 0) "atual=$($g.current_amount) de $($g.target_amount)"

$r = Api PUT "$BASE/api/goals.php" @{ id = $goalId; name = 'Reserva'; target_amount = 12000 }
$j = $r.Content | ConvertFrom-Json
Check 'Editar meta' ($j.ok -eq $true) ""

# ---------- 12) IA (modo local, sem chave) ----------
$r = Invoke-WebRequest -Uri "$BASE/api/ai.php?action=status" -WebSession $session -UseBasicParsing
$j = $r.Content | ConvertFrom-Json
Check 'AI status' ($j.ok -eq $true) "configured=$($j.configured) provider=$($j.provider)"

$r = Api POST "$BASE/api/ai.php" @{ action = 'message'; text = 'Gastei R$ 35 no Uber' }
$j = $r.Content | ConvertFrom-Json
Check 'IA cria transacao (pede confirmacao)' ($j.ok -eq $true -and $null -ne $j.action) "intent=$($j.action.intent) valor=$($j.action.data.amount) font=$($j.source)"
$hasAction = ($null -ne $j.action)

if ($hasAction) {
    $r = Api POST "$BASE/api/ai.php" @{ action = 'confirm'; accept = $true }
    $j = $r.Content | ConvertFrom-Json
    Check 'IA confirma e registra' ($j.ok -eq $true) "resposta=$($j.reply.Substring(0, [Math]::Min(80, $j.reply.Length)))"
}

$r = Api POST "$BASE/api/ai.php" @{ action = 'message'; text = 'Quanto gastei esse mes?' }
$j = $r.Content | ConvertFrom-Json
Check 'IA responde pergunta de gastos' ($j.ok -eq $true -and $j.reply -ne '') "resposta=$($j.reply.Substring(0, [Math]::Min(60, $j.reply.Length)))"

$r = Api POST "$BASE/api/ai.php" @{ action = 'message'; text = 'Onde estou gastando mais?' }
$j = $r.Content | ConvertFrom-Json
Check 'IA responde onde gasta mais' ($j.ok -eq $true) ""

# ---------- 13) Dados isolados por usuario ----------
$session2 = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$EMAIL2 = "outro$stamp@teste.com"
$r = Api POST "$BASE/api/auth.php?action=register" @{ name = 'Outro Usuario'; email = $EMAIL2; password = $PASS; password_confirm = $PASS } $session2
$j = $r.Content | ConvertFrom-Json
Check 'Cadastro segundo usuario' ($j.ok -eq $true) ""

$r = Invoke-WebRequest -Uri "$BASE/api/transactions.php" -WebSession $session2 -UseBasicParsing
$j = $r.Content | ConvertFrom-Json
$leaked = ($j.transactions | Where-Object { $_.description -match 'Uber|Salario' }).Count
Check 'Segundo usuario NAO ve transacoes do primeiro' ($leaked -eq 0) "count=$($j.transactions.Count)"

# ---------- 14) .env e chave nao vazam ----------
try {
    $envTest = Invoke-WebRequest -Uri "$BASE/.env" -UseBasicParsing
    Check '.env bloqueado pelo servidor' ($envTest.StatusCode -eq 404) "(http $($envTest.StatusCode))"
} catch {
    $code = $_.Exception.Response.StatusCode.value__
    Check '.env bloqueado pelo servidor' ($code -eq 404) "(http $code)"
}
$r = Invoke-WebRequest -Uri "$BASE/api/ai.php?action=status" -WebSession $session -UseBasicParsing
$content = $r.Content
Check 'API de IA nao expoe chave' ($content -notmatch 'sk-') ""

# ---------- 15) Logout ----------
$r = Api POST "$BASE/api/auth.php?action=logout"
$j = $r.Content | ConvertFrom-Json
Check 'Logout' ($j.ok -eq $true) ""

Write-Host ""
if ($script:failures.Count -eq 0) { Write-Host "RESULTADO: $script:steps/$script:steps testes passaram." -ForegroundColor Green }
else { Write-Host "RESULTADO: $($script:failures.Count) falha(s) de $script:steps testes: $($script:failures -join ', ')" -ForegroundColor Red }
