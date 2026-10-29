<?php
if (!defined('FINLIFE')) { http_response_code(403); exit; }
$icoSend = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>';
?>
<section class="chat-shell" aria-label="Conversa com o FinLife AI">
    <header class="chat-head">
        <span class="ai-avatar" aria-hidden="true">₣</span>
        <div class="chat-head-info">
            <h2>FinLife AI</h2>
            <span class="chip" id="ai-provider-chip">verificando…</span>
        </div>
    </header>

    <div class="chat-scroll" id="chat-scroll">
        <div class="chat-messages" id="chat-messages">
            <div class="msg assistant">
                <div class="msg-bubble">
                    <p>Olá! Sou o <strong>FinLife AI</strong>, seu assistente financeiro.</p>
                    <p>Eu registro lançamentos, crio metas, analiso seus dados reais, respondo dúvidas sobre investimentos e acompanho o mercado — sem inventar nada. Experimente:</p>
                    <ul>
                        <li>"Qual melhor investimento?"</li>
                        <li>"Qual minha saúde financeira?"</li>
                        <li>"Vou estourar o orçamento?"</li>
                        <li>"Gastei R$ 45 no almoço"</li>
                        <li>"Aportei R$ 500 na reserva"</li>
                        <li>"Avise quando PETR4 chegar a R$ 40"</li>
                        <li>"O que é CDI?"</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="chat-suggest" id="chat-suggest">
        <button class="chip btn-chip" data-msg="qual melhor investimento?">Qual melhor investimento?</button>
        <button class="chip btn-chip" data-msg="Qual minha saúde financeira?">Minha saúde financeira</button>
        <button class="chip btn-chip" data-msg="Onde estou gastando mais dinheiro?">Onde estou gastando mais?</button>
        <button class="chip btn-chip" data-msg="Vou estourar o orçamento?">Vou estourar o orçamento?</button>
        <button class="chip btn-chip" data-msg="Como está o mercado hoje?">Como está o mercado?</button>
        <button class="chip btn-chip" data-msg="o que é CDI?">O que é CDI?</button>
    </div>

    <form class="chat-composer" id="chat-form">
        <div class="composer-field">
            <textarea id="chat-text" rows="1" placeholder="Ex.: Gastei 35 reais no Uber" maxlength="1000" aria-label="Sua mensagem"></textarea>
        </div>
        <button type="submit" class="chat-send" id="chat-send" aria-label="Enviar mensagem" title="Enviar"><?= $icoSend ?></button>
    </form>

    <div class="chat-foot">
        <button class="chip btn-chip subtle" id="chat-clear">Limpar conversa</button>
        <span class="muted tiny">Ações são sempre confirmadas antes de tocar nos seus dados.</span>
    </div>
</section>
