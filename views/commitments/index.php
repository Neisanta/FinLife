<?php
/** FinLife — Compromissos e Contas Fixas (dívidas pessoais + contas recorrentes). */
if (!defined('FINLIFE')) { http_response_code(403); exit; }
?>
<div class="page-head">
    <div>
        <h2 class="page-title">Compromissos & Contas Fixas</h2>
        <p class="muted">Dívidas pessoais, acordos e contas recorrentes — agenda de pagamentos com saldo devedor real.</p>
    </div>
    <div class="page-head-actions">
        <button type="button" class="btn btn-primary" id="cm-new"><span>＋</span> Novo compromisso</button>
    </div>
</div>

<!-- KPIs -->
<section class="stats-grid" id="cm-kpis">
    <div class="stat-card">
        <span class="stat-label">Ativos</span>
        <strong class="stat-value" id="kpi-active">—</strong>
        <span class="stat-badge muted" id="kpi-active-sub">—</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Previsto no mês</span>
        <strong class="stat-value" id="kpi-planned">—</strong>
        <span class="stat-badge muted">ocorrências do mês</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Pago no mês</span>
        <strong class="stat-value success" id="kpi-paid">—</strong>
        <span class="stat-badge muted">confirmados</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Pendente do mês</span>
        <strong class="stat-value" id="kpi-pending">—</strong>
        <span class="stat-badge muted" id="kpi-due-soon">—</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Dívidas em aberto</span>
        <strong class="stat-value warning" id="kpi-debts">—</strong>
        <span class="stat-badge muted">saldo devedor</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">Em atraso</span>
        <strong class="stat-value danger" id="kpi-overdue">—</strong>
        <span class="stat-badge danger" id="kpi-overdue-count" hidden>—</span>
    </div>
</section>

<!-- Vencendo nos próximos 7 dias -->
<section class="card cm-soon" id="cm-soon-card" hidden>
    <div class="card-head">
        <h3>Vencendo nos próximos 7 dias</h3>
    </div>
    <div class="cm-soon-list" id="cm-soon"></div>
</section>

<!-- Filtros -->
<section class="card">
    <div class="cm-filters">
        <input type="search" id="f-search" class="input" placeholder="Buscar por pessoa ou instituição…" style="flex:2;min-width:180px">
        <select id="f-category" class="input" style="min-width:160px">
            <option value="">Todas as categorias</option>
            <option value="divida_pessoal">Dívida pessoal</option>
            <option value="emprestimo_informal">Empréstimo informal</option>
            <option value="telefone">Telefone</option>
            <option value="internet">Internet</option>
            <option value="servico">Serviço</option>
            <option value="mensalidade">Mensalidade</option>
            <option value="assinatura">Assinatura</option>
            <option value="outro">Outro</option>
        </select>
        <select id="f-status" class="input" style="min-width:150px">
            <option value="">Todos os status</option>
            <option value="ontime">Em dia</option>
            <option value="overdue">Atrasados</option>
            <option value="paused">Pausados</option>
            <option value="settled">Quitados</option>
        </select>
        <select id="f-kind" class="input" style="min-width:140px">
            <option value="">Dívidas e contas</option>
            <option value="debt">Só dívidas</option>
            <option value="recurring">Só contas fixas</option>
        </select>
    </div>
</section>

<!-- Lista -->
<section id="cm-list" class="cm-grid"></section>
<div class="empty-state" id="cm-empty" hidden>
    <div class="brand-mark big">📅</div>
    <h3>Nenhum compromisso por aqui</h3>
    <p class="muted">Registre dívidas pessoais, acordos de pagamento e contas fixas — telefone, internet, mensalidades — e mantenha tudo sob controle.</p>
    <button type="button" class="btn btn-primary" data-new-cta>＋ Criar primeiro compromisso</button>
</div>

<!-- Modais (preenchidos via JS) -->
<div class="modal-overlay" id="cm-modal-overlay" hidden></div>
