/* ==============================================================
   FinLife — Página FinLife AI
   Chat com o assistente: envia mensagens ao backend, renderiza
   respostas e pede confirmação antes de executar ações.
   A chave da IA nunca aparece aqui — tudo passa pelo endpoint PHP.
   ============================================================== */
(function () {
    'use strict';

    var FL = window.FL;
    if (!FL) { return; }

    var messages = document.getElementById('chat-messages');
    var scroll = document.getElementById('chat-scroll');
    var form = document.getElementById('chat-form');
    var input = document.getElementById('chat-text');
    var sendBtn = document.getElementById('chat-send');
    var clearBtn = document.getElementById('chat-clear');
    var busy = false;

    // ------------------------------------------------------------
    // Renderização
    // ------------------------------------------------------------
    function scrollToBottom() {
        if (scroll) { scroll.scrollTop = scroll.scrollHeight; }
    }

    function timeLabel(iso) {
        if (!iso) { return ''; }
        var m = String(iso).match(/(\d{2}):(\d{2})/);
        return m ? m[1] + ':' + m[2] : '';
    }

    /** Renderização leve de markdown: **negrito**, listas com •/-/1., parágrafos. */
    function renderRich(text) {
        var escaped = FL.esc(String(text == null ? '' : text));
        escaped = escaped.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
        var html = '';
        var listOpen = false;
        escaped.split(/\n/).forEach(function (line) {
            var m = line.match(/^\s*(?:[•\-*]|\d+[.)])\s+(.*)$/);
            if (m) {
                if (!listOpen) { html += '<ul>'; listOpen = true; }
                html += '<li>' + m[1] + '</li>';
            } else {
                if (listOpen) { html += '</ul>'; listOpen = false; }
                if (line.trim() !== '') { html += '<p>' + line + '</p>'; }
            }
        });
        if (listOpen) { html += '</ul>'; }
        return html || '<p></p>';
    }

    // ------------------------------------------------------------
    // Rich cards: blocos estruturados vindos do backend (dados reais)
    // ------------------------------------------------------------
    function renderRichBlock(block) {
        if (!block || !block.type) { return ''; }
        var html = '';
        if (block.type === 'stats' && Array.isArray(block.items)) {
            html += '<div class="chat-stats">';
            block.items.forEach(function (it) {
                var tone = it.tone ? ' tone-' + FL.esc(it.tone) : '';
                html += '<div class="chat-stat' + tone + '"><small>' + FL.esc(it.label || '') + '</small><strong>' + FL.esc(it.value || '') + '</strong></div>';
            });
            html += '</div>';
        } else if (block.type === 'tx-list' && Array.isArray(block.items)) {
            html += '<div class="chat-txlist">';
            if (block.title) { html += '<div class="chat-txlist-title">' + FL.esc(block.title) + '</div>'; }
            block.items.forEach(function (it) {
                html += '<div class="chat-txrow">'
                    + '<span class="chat-txico">' + FL.esc(it.icon || '💸') + '</span>'
                    + '<span class="chat-txinfo"><strong>' + FL.esc(it.desc || '') + '</strong>'
                    + (it.sub ? '<small>' + FL.esc(it.sub) + '</small>' : '')
                    + '</span>'
                    + '<span class="chat-txval">' + FL.esc(it.amount || '') + '</span>'
                    + '</div>';
            });
            html += '</div>';
        } else if (block.type === 'progress' && block.label) {
            var pct = Math.max(0, Math.min(100, Number(block.pct) || 0));
            html += '<div class="chat-prog"><div class="chat-prog-top"><span>' + FL.esc(block.label) + '</span><span>' + FL.esc(pct.toLocaleString('pt-BR')) + '%</span></div>'
                + '<div class="bar"><div class="bar-fill' + (pct >= 90 ? ' danger' : pct >= 70 ? '' : ' success') + '" style="width:' + pct + '%"></div></div>'
                + '<div class="chat-prog-sub">' + FL.esc(block.value != null ? (typeof block.value === 'number' ? block.value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) : block.value) : '') + ' de ' + FL.esc(block.target != null ? (typeof block.target === 'number' ? block.target.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) : block.target) : '') + '</div></div>';
        } else if (block.type === 'progress-group' && Array.isArray(block.items)) {
            block.items.forEach(function (p) {
                html += renderRichBlock({ type: 'progress', label: p.label, value: p.value, target: p.target, pct: p.pct });
            });
        } else if (block.type === 'alert') {
            var level = block.level === 'good' ? 'success' : (block.level === 'bad' ? 'error' : 'warn');
            html += '<div class="chat-alert chat-alert-' + FL.esc(block.level || 'warn') + '">' + FL.esc(block.text || '') + '</div>';
        }
        return html;
    }

    /** Quick replies dinâmicas: chips clicáveis abaixo da resposta. */
    function addQuickReplies(bubbleEl, quickList) {
        if (!Array.isArray(quickList) || !quickList.length) { return; }
        var wrap = document.createElement('div');
        wrap.className = 'chat-quick';
        quickList.slice(0, 4).forEach(function (q) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'chip btn-chip chat-quick-btn';
            b.textContent = q;
            b.addEventListener('click', function () {
                wrap.remove();
                sendMessage(q);
            });
            wrap.appendChild(b);
        });
        bubbleEl.appendChild(wrap);
        scrollToBottom();
    }

    function bubble(role, text, withTime, timeIso, richBlocks) {
        var el = document.createElement('div');
        el.className = 'msg ' + role;

        var avatar = document.createElement('span');
        avatar.className = 'msg-avatar';
        avatar.setAttribute('aria-hidden', 'true');
        avatar.textContent = role === 'assistant' ? '₣' : 'Eu';

        var b = document.createElement('div');
        b.className = 'msg-bubble';
        b.innerHTML = renderRich(text);
        // rich cards do backend (sempre dados reais)
        if (Array.isArray(richBlocks)) {
            richBlocks.forEach(function (block) {
                var html = renderRichBlock(block);
                if (html) {
                    var wrap = document.createElement('div');
                    wrap.className = 'msg-richtext';
                    wrap.innerHTML = html;
                    b.appendChild(wrap);
                }
            });
        }
        if (withTime) {
            var t = document.createElement('span');
            t.className = 'msg-time';
            t.textContent = timeLabel(timeIso);
            b.appendChild(t);
        }

        el.appendChild(avatar);
        el.appendChild(b);
        if (messages) { messages.appendChild(el); }
        scrollToBottom();
        return { msg: el, bubble: b };
    }

    function typingBubble() {
        var el = document.createElement('div');
        el.className = 'msg assistant';
        el.innerHTML = '<span class="msg-avatar">₣</span><div class="msg-bubble"><span class="typing"><span></span><span></span><span></span></span></div>';
        if (messages) { messages.appendChild(el); }
        scrollToBottom();
        return el;
    }

    /** Adiciona botões de confirmação/cancelamento embaixo da resposta. */
    function addConfirmButtons(bubbleEl) {
        var actions = document.createElement('div');
        actions.className = 'chat-actions';

        var yes = document.createElement('button');
        yes.type = 'button';
        yes.className = 'btn btn-primary btn-sm';
        yes.textContent = 'Confirmar';
        var no = document.createElement('button');
        no.type = 'button';
        no.className = 'btn btn-ghost btn-sm';
        no.textContent = 'Cancelar';

        yes.addEventListener('click', function () {
            actions.remove();
            sendConfirm(true);
        });
        no.addEventListener('click', function () {
            actions.remove();
            sendConfirm(false);
        });

        actions.appendChild(yes);
        actions.appendChild(no);
        bubbleEl.appendChild(actions);
        scrollToBottom();
    }

    // ------------------------------------------------------------
    // Comunicação
    // ------------------------------------------------------------
    function handleResponse(data) {
        if (!data || !data.ok) {
            bubble('assistant', (data && data.error) ? data.error : 'Erro inesperado. Tente novamente.', true);
            return;
        }
        var b = bubble('assistant', data.reply, true, null, data.rich || null);
        if (data.quick_replies && !data.pending_action) {
            addQuickReplies(b.bubble, data.quick_replies);
        }
        if (data.pending_action) {
            addConfirmButtons(b.bubble);
        }
    }

    function sendMessage(text) {
        if (busy) { return; }
        text = String(text || '').trim();
        if (!text) { return; }
        busy = true;
        sendBtn.disabled = true;
        bubble('user', text, false);
        var t = typingBubble();

        FL.api('/api/ai.php', { method: 'POST', body: { action: 'message', text: text } })
            .then(function (r) {
                t.remove();
                busy = false;
                sendBtn.disabled = false;
                input.focus();
                handleResponse(r.data);
            });
    }

    function sendConfirm(accept) {
        if (busy) { return; }
        busy = true;
        var t = typingBubble();
        FL.api('/api/ai.php', { method: 'POST', body: { action: 'confirm', accept: accept } })
            .then(function (r) {
                t.remove();
                busy = false;
                input.focus();
                handleResponse(r.data);
            });
    }

    // ------------------------------------------------------------
    // Eventos
    // ------------------------------------------------------------
    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var text = input ? input.value : '';
            if (input) { input.value = ''; }
            sendMessage(text);
        });
    }

    if (input) {
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                var text = input.value;
                input.value = '';
                sendMessage(text);
            }
        });
        // autogrow
        input.addEventListener('input', function () {
            input.style.height = 'auto';
            input.style.height = Math.min(140, input.scrollHeight) + 'px';
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            FL.confirm('Limpar conversa', 'Apagar toda a conversa com o assistente? Esta ação não pode ser desfeita.', true)
                .then(function (ok) {
                    if (!ok) { return; }
                    FL.api('/api/ai.php', { method: 'POST', body: { action: 'clear' } })
                        .then(function (r) {
                            if (r.data && r.data.ok) {
                                if (messages) { messages.innerHTML = ''; }
                                bubble('assistant', (r.data.reply) || 'Conversa apagada. Como posso ajudar?', true);
                                FL.toastSuccess('Conversa apagada.');
                            } else {
                                FL.toastError((r.data && r.data.error) || 'Não foi possível limpar a conversa.');
                            }
                        });
                });
        });
    }

    // Sugestões
    FL.$$('.btn-chip[data-msg]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            sendMessage(btn.getAttribute('data-msg'));
        });
    });

    // ------------------------------------------------------------
    // Init: status do provedor + histórico
    // ------------------------------------------------------------
    FL.api('/api/ai.php?action=status', { silent: true }).then(function (r) {
        var chip = document.getElementById('ai-provider-chip');
        if (chip && r.data && r.data.ok) {
            if (r.data.configured) {
                chip.textContent = 'IA externa: ' + (r.data.model || 'ativa');
                chip.className = 'chip ai';
            } else {
                chip.textContent = 'modo local (dados reais)';
                chip.className = 'chip';
            }
        }
    });

    FL.api('/api/ai.php?action=history', { silent: true }).then(function (r) {
        if (!r.data || !r.data.ok) { return; }
        var msgs = r.data.messages || [];
        if (!msgs.length || !messages) { return; }
        // substitui a mensagem de boas-vindas estática pelo histórico real
        messages.innerHTML = '';
        msgs.forEach(function (m) {
            bubble(m.role, m.content, true, m.created_at);
        });
    });
})();
