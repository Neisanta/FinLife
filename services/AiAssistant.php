<?php
/**
 * FinLife — Orquestrador do assistente financeiro.
 *
 * Fluxo: usuário → PHP identifica a solicitação → banco de dados →
 * contexto real enviado à IA (ou motor local) → análise → resposta.
 *
 * Segurança:
 * - Toda resposta da IA externa é validada campo a campo (whitelist
 *   de intents, tipos, valores numéricos, datas e textos sanitizados)
 * - A IA NUNCA executa SQL: ações passam pelos models (regras de negócio)
 * - Ações só executam após confirmação explícita do usuário
 * - Rate limiting por usuário
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class AiAssistant
{
    private const ALLOWED_INTENTS = ['create_transaction', 'create_goal', 'create_card_purchase', 'create_goal_deposit', 'create_market_alert'];
    private const PENDING_TTL = 900; // 15 minutos

    /** Processa uma mensagem do usuário no chat. */
    public static function message(array $user, string $raw): array
    {
        $userId = (int) $user['id'];
        $text = trim($raw);
        if ($text === '' || mb_strlen($text) > 1000) {
            return self::out('Escreva uma mensagem de até 1.000 caracteres.', null, self::source());
        }

        // Rate limiting: 20 mensagens por minuto por usuário
        if (RateLimiter::hit('ai:user:' . $userId, 20, 60)) {
            return self::out('Você está enviando mensagens rápido demais. Aguarde alguns segundos e tente novamente.', null, self::source());
        }

        $convId = AiChat::conversationId($userId);

        // Confirmação pendente? ("sim", "confirmo", "não", "cancela")
        $pending = self::pendingAction();
        if ($pending !== null) {
            $answer = self::confirmationAnswer($text);
            if ($answer !== null) {
                AiChat::addMessage($userId, $convId, 'user', $text);
                $result = self::resolvePending($user, $convId, $answer);
                return $result;
            }
        }

        AiChat::addMessage($userId, $convId, 'user', $text);

        $ai = new AIService();
        $snap = ContextBuilder::snapshot($userId);

        // ------------------------------------------------------------------
        // 1) Motor local PRIMEIRO (determinístico, dados exatos do banco):
        //    - ações (transação, meta, aporte, alerta) → confirmação
        //    - consultas com rich cards (saldo, saúde, metas, top gastos,
        //      orçamento) → resposta visual com números exatos
        // ------------------------------------------------------------------
        $local = LocalNlu::handle($userId, $text, $snap);
        $localAction = self::validateAction($userId, $local['action'] ?? null);
        $localRich = $local['rich'] ?? null;
        $localQuick = $local['quick'] ?? null;
        $localReply = is_string($local['reply'] ?? null) ? $local['reply'] : (string) ($local['reply']['reply'] ?? '');

        if ($localAction !== null) {
            self::storePending($localAction);
            AiChat::addMessage($userId, $convId, 'assistant', self::confirmationQuestion($localAction), ['action' => $localAction]);
            return self::out(self::confirmationQuestion($localAction), $localAction, 'local');
        }

        if (!empty($localRich)) {
            AiChat::addMessage($userId, $convId, 'assistant', $localReply, ['action' => null]);
            $out = self::out($localReply, null, 'local');
            $out['rich'] = $localRich;
            if (!empty($localQuick)) { $out['quick_replies'] = $localQuick; }
            return $out;
        }

        // ------------------------------------------------------------------
        // 2) IA externa: perguntas educacionais/consultivas e conversa livre
        //    ("qual melhor investimento?", "o que é CDI?", conselhos gerais)
        // ------------------------------------------------------------------
        $reply = null;
        $action = null;
        $rich = null;
        $quick = null;
        $source = 'local';

        if ($ai->isConfigured()) {
            $memory = LocalNlu::memoryText();
            $messages = [['role' => 'system', 'content' => ContextBuilder::systemPrompt(ContextBuilder::snapshotText($snap), $memory)]];
            foreach (AiChat::history($userId, $convId, 10) as $h) {
                $messages[] = ['role' => $h['role'], 'content' => $h['content']];
            }
            $messages[] = ['role' => 'user', 'content' => $text];

            $res = $ai->chat($messages);
            if ($res['ok']) {
                $source = 'ai';
                $content = $res['content'];
                $json = AIService::extractJson($content);
                if ($json !== null && isset($json['intent'])) {
                    $validated = self::validateAction($userId, $json);
                    if ($validated !== null) {
                        $action = $validated;
                        $reply = self::confirmationQuestion($validated);
                    } else {
                        $reply = "Entendi o pedido, mas não consegui validar os dados para executá-lo com segurança. "
                            . 'Pode reformular? Por exemplo: "Gastei R$ 35 no Uber" ou "Crie uma meta de 15 mil".';
                    }
                } else {
                    $reply = self::sanitizeReply($content);
                    // Disclaimer determinístico: respostas sobre investimento/
                    // recomendação SEMPRE carregam a nota educacional (o LLM
                    // pode omitir por temperatura — aqui é garantido).
                    if (preg_match('~(investi|aplicar|recomend|melhor invest|acoes|bolsa|renda|cartao|divida)~i', $text) === 1
                        && stripos($reply, 'não é recomendação') === false) {
                        $reply .= "\n\n*Isto não é recomendação de investimento — é orientação educacional baseada nos seus dados reais.*";
                    }
                }
            } else {
                // fallback amigável: a resposta local já calculada acima
                log_error('IA externa indisponível — usando modo local', ['error' => $res['error'] ?? '']);
                $reply = $localReply;
                $quick = $localQuick;
            }
        } else {
            $reply = $localReply;
            $quick = $localQuick;
        }

        if ($action !== null) {
            self::storePending($action);
        }

        AiChat::addMessage($userId, $convId, 'assistant', is_array($reply) ? ($reply['reply'] ?? '') : $reply, ['action' => $action]);
        $out = self::out(is_array($reply) ? ($reply['reply'] ?? '') : $reply, $action, $source);
        if (is_array($reply)) {
            if (!empty($reply['rich'])) { $out['rich'] = $reply['rich']; }
            if (!empty($reply['quick'])) { $out['quick_replies'] = $reply['quick']; }
        }
        if ($quick !== null && empty($out['quick_replies'])) { $out['quick_replies'] = $quick; }
        return $out;
    }

    /** Confirma (ou recusa) a ação pendente. */
    public static function confirm(array $user, bool $accept): array
    {
        $userId = (int) $user['id'];
        $convId = AiChat::conversationId($userId);
        $pending = self::pendingAction();
        if ($pending === null) {
            return self::out('Não há nenhuma ação aguardando confirmação.', null, self::source());
        }
        $result = self::resolvePending($user, $convId, $accept);
        return $result;
    }

    /** Resumo financeiro do mês para o Dashboard. */
    public static function summary(array $user): array
    {
        $userId = (int) $user['id'];
        if (RateLimiter::hit('ai:summary:' . $userId, 5, 60)) {
            return self::out('Aguarde alguns segundos antes de gerar outro resumo.', null, self::source());
        }
        $snap = ContextBuilder::snapshot($userId);
        $ai = new AIService();
        $source = 'local';

        if ($ai->isConfigured()) {
            $prompt = 'Gere um resumo curto (máximo 5 frases) da situação financeira do usuário neste mês, '
                . 'com base SOMENTE no contexto real. Destaque pontos fortes, riscos e uma recomendação prática. '
                . 'Não invente valores.';
            $res = $ai->chat([
                ['role' => 'system', 'content' => ContextBuilder::systemPrompt(ContextBuilder::snapshotText($snap))],
                ['role' => 'user', 'content' => $prompt],
            ]);
            if ($res['ok']) {
                $source = 'ai';
                return self::out(self::sanitizeReply($res['content']), null, $source);
            }
            log_error('IA externa indisponível para resumo — modo local', ['error' => $res['error'] ?? '']);
        }

        return self::out(self::localSummary($snap), null, 'local');
    }

    // ------------------------------------------------------------------
    // Ações
    // ------------------------------------------------------------------

    /**
     * Valida rigorosamente o JSON de ação (da IA externa ou do motor local).
     * Retorna a ação normalizada ou null se inválida.
     */
    public static function validateAction(int $userId, ?array $json): ?array
    {
        if ($json === null || !is_array($json)) {
            return null;
        }
        $intent = Validator::in($json['intent'] ?? '', self::ALLOWED_INTENTS);
        if ($intent === null) {
            return null;
        }

        if ($intent === 'create_transaction') {
            $type = Validator::in($json['type'] ?? '', Transaction::TYPES);
            if ($type === null) {
                return null;
            }
            $amount = Validator::amount($json['amount'] ?? null);
            if ($amount === null) {
                return null;
            }
            $categoryName = Validator::text($json['category'] ?? '', 60);
            $category = ($categoryName !== '') ? Category::matchByName($userId, $categoryName, $type) : null;
            if ($category === null) {
                $category = Category::fallbackFor($userId, $type);
            }
            $date = Validator::date($json['date'] ?? null) ?? date('Y-m-d');
            $accountId = Account::firstId($userId);

            return [
                'intent' => 'create_transaction',
                'type' => $type,
                'amount' => $amount,
                'category' => $category['name'] ?? 'Outras',
                'category_id' => $category ? (int) $category['id'] : null,
                'description' => Validator::text($json['description'] ?? '', 120) ?: 'Lançamento via assistente',
                'date' => $date,
                'account_id' => $accountId,
                'requires_confirmation' => true,
            ];
        }

        // create_card_purchase
        if ($intent === 'create_card_purchase') {
            $amount = Validator::amount($json['amount'] ?? null);
            if ($amount === null) {
                return null;
            }
            $cardName = Validator::text($json['card'] ?? '', 80);
            $card = ($cardName !== '') ? Card::matchByName($userId, $cardName) : null;
            if ($card === null || !in_array($card['type'], ['credit', 'multiple'], true)) {
                return null;
            }
            $installments = filter_var($json['installments'] ?? 1, FILTER_VALIDATE_INT);
            $installments = ($installments !== false && $installments >= 1 && $installments <= 48) ? $installments : 1;

            $categoryName = Validator::text($json['category'] ?? '', 60);
            $category = ($categoryName !== '') ? Category::matchByName($userId, $categoryName, 'expense') : null;
            if ($category === null) {
                $category = Category::fallbackFor($userId, 'expense');
            }
            $date = Validator::date($json['date'] ?? null) ?? date('Y-m-d');

            return [
                'intent' => 'create_card_purchase',
                'card_id' => (int) $card['id'],
                'card' => $card['name'],
                'amount' => $amount,
                'installments' => $installments,
                'category' => $category['name'] ?? 'Outras',
                'category_id' => $category ? (int) $category['id'] : null,
                'description' => Validator::text($json['description'] ?? '', 120) ?: 'Compra via assistente',
                'date' => $date,
                'requires_confirmation' => true,
            ];
        }

        // create_goal_deposit
        if ($intent === 'create_goal_deposit') {
            $goalId = Validator::positiveInt($json['goal_id'] ?? null);
            $amount = Validator::amount($json['amount'] ?? null);
            if ($goalId === null || $amount === null) {
                return null;
            }
            $goal = Goal::get($userId, $goalId);
            if ($goal === null) {
                return null;
            }
            return [
                'intent' => 'create_goal_deposit',
                'goal_id' => $goalId,
                'goal_name' => $goal['name'],
                'amount' => $amount,
                'date' => Validator::date($json['date'] ?? null) ?? date('Y-m-d'),
                'note' => Validator::text($json['note'] ?? 'aporte via assistente', 255),
                'requires_confirmation' => true,
            ];
        }

        // create_market_alert
        if ($intent === 'create_market_alert') {
            $symbol = MarketUniverse::normalize((string) ($json['symbol'] ?? ''));
            if ($symbol === '') {
                return null;
            }
            $type = Validator::in($json['type'] ?? 'above', ['above', 'below', 'pct_up', 'pct_down']);
            $target = Validator::amount($json['target'] ?? null);
            if ($type === null || $target === null) {
                return null;
            }
            return [
                'intent' => 'create_market_alert',
                'symbol' => $symbol,
                'type' => $type,
                'target' => $target,
                'note' => Validator::text($json['note'] ?? 'alerta via assistente', 255) ?: null,
                'requires_confirmation' => true,
            ];
        }

        // create_goal
        $name = Validator::text($json['name'] ?? '', 120);
        $target = Validator::amount($json['target_amount'] ?? null);
        if ($name === '' || $target === null) {
            return null;
        }
        $deadline = Validator::date($json['deadline'] ?? null);

        return [
            'intent' => 'create_goal',
            'name' => $name,
            'target_amount' => $target,
            'deadline' => $deadline,
            'requires_confirmation' => true,
        ];
    }

    /** Executa a ação confirmada — SEMPRE pelas regras de negócio (models). */
    private static function execute(array $user, array $action): array
    {
        $userId = (int) $user['id'];
        if ($action['intent'] === 'create_transaction') {
            $result = Transaction::create($userId, [
                'type' => $action['type'],
                'amount' => $action['amount'],
                'category_id' => $action['category_id'],
                'account_id' => $action['account_id'],
                'description' => $action['description'],
                'date' => $action['date'],
            ], 'ai');

            if ($result['ok'] ?? false) {
                $label = $action['type'] === 'income' ? 'Receita' : 'Despesa';
                return self::out(
                    $label . ' registrada com sucesso ✅ ' . money($action['amount']) . ' em '
                    . $action['category'] . ' (' . $action['description'] . '), em '
                    . ContextBuilder::brDate($action['date']) . '.',
                    null,
                    self::source()
                );
            }
            return self::out('Não consegui registrar: ' . ($result['error'] ?? 'erro desconhecido'), null, self::source());
        }

        if ($action['intent'] === 'create_card_purchase') {
            $result = CardPurchase::create($userId, [
                'card_id'      => $action['card_id'],
                'description'  => $action['description'],
                'amount'       => $action['amount'],
                'date'         => $action['date'],
                'method'       => 'credit',
                'installments' => $action['installments'],
                'category_id'  => $action['category_id'],
            ], 'ai');

            if (($result['ok'] ?? false) === true) {
                $msg = 'Compra registrada com sucesso ✅ ' . money($action['amount'])
                    . ' (' . $action['description'] . ') no cartão ' . $action['card'];
                if (($result['installments'] ?? 0) > 1) {
                    $msg .= ' em ' . $result['installments'] . ' parcelas de '
                        . money(round($action['amount'] / $result['installments'], 2)) . ' — já alocadas nas faturas';
                }
                $msg .= ', em ' . ContextBuilder::brDate($action['date']) . '. Acompanhe na página de Cartões!';
                return self::out($msg, null, self::source());
            }
            return self::out('Não consegui registrar a compra: ' . ($result['error'] ?? 'erro desconhecido'), null, self::source());
        }

        if ($action['intent'] === 'create_goal') {
            $result = Goal::create($userId, [
                'name' => $action['name'],
                'target_amount' => $action['target_amount'],
                'deadline' => $action['deadline'],
            ]);
            if ($result['ok'] ?? false) {
                return self::out(
                    'Meta criada com sucesso ✅ "' . $action['name'] . '" com alvo de '
                    . money($action['target_amount']) . '. Acompanhe o progresso na página de Metas!',
                    null,
                    self::source()
                );
            }
            return self::out('Não consegui criar a meta: ' . ($result['error'] ?? 'erro desconhecido'), null, self::source());
        }

        if ($action['intent'] === 'create_goal_deposit') {
            $result = Goal::addDeposit($userId, (int) $action['goal_id'], [
                'amount' => $action['amount'],
                'date' => $action['date'],
                'note' => $action['note'] ?? null,
            ]);
            if ($result['ok'] ?? false) {
                // busca o progresso atualizado da meta
                $progress = '';
                foreach (Goal::list($userId) as $g) {
                    if ((int) $g['id'] === (int) $action['goal_id']) {
                        $progress = ' Novo saldo da meta: ' . money((float) $g['current_amount'])
                            . ' de ' . money((float) $g['target_amount']) . ' ('
                            . number_format((float) $g['progress'], 0, ',', '.') . '%).';
                        break;
                    }
                }
                return self::out(
                    'Aporte registrado com sucesso ✅ ' . money($action['amount']) . ' na meta "'
                    . $action['goal_name'] . '".' . $progress,
                    null,
                    self::source()
                );
            }
            return self::out('Não consegui registrar o aporte: ' . ($result['error'] ?? 'erro desconhecido'), null, self::source());
        }

        if ($action['intent'] === 'create_market_alert') {
            $result = MarketAlert::create($userId, [
                'symbol' => $action['symbol'],
                'type' => $action['type'],
                'target' => $action['target'],
                'note' => $action['note'] ?? null,
            ]);
            if (($result['ok'] ?? false) === true) {
                return self::out(
                    'Alerta criado com sucesso ✅ Vou te notificar aqui no sistema quando '
                    . MarketUniverse::display($action['symbol']) . ' '
                    . ($action['type'] === 'below' ? 'cair para' : 'chegar a') . ' R$ '
                    . number_format($action['target'], 2, ',', '.') . '. Você também pode ver todos os alertas na página Mercados.',
                    null,
                    self::source()
                );
            }
            return self::out('Não consegui criar o alerta: ' . ($result['error'] ?? 'erro desconhecido'), null, self::source());
        }

        return self::out('Ação não suportada.', null, self::source());
    }

    // ------------------------------------------------------------------
    // Confirmação pendente
    // ------------------------------------------------------------------

    private static function pendingAction(): ?array
    {
        $p = $_SESSION['ai_pending_action'] ?? null;
        if (!is_array($p) || empty($p['action'])) {
            return null;
        }
        if (time() - (int) ($p['created'] ?? 0) > self::PENDING_TTL) {
            unset($_SESSION['ai_pending_action']);
            return null;
        }
        return $p['action'];
    }

    private static function storePending(array $action): void
    {
        $_SESSION['ai_pending_action'] = ['action' => $action, 'created' => time()];
    }

    private static function clearPending(): void
    {
        unset($_SESSION['ai_pending_action']);
    }

    /** Detecta resposta de confirmação ("sim", "pode", "não"...). */
    private static function confirmationAnswer(string $text): ?bool
    {
        $t = normalize($text);
        if ($t === '') {
            return null;
        }
        if (preg_match('~^(sim|si|s|claro|confirmo|confirmar|pode|pode sim|ok|okay|beleza|isso|exato|com certeza|perfeito|manda|vai|aceito|aprovo|quero)([.!]|$)~', $t)) {
            return true;
        }
        if (preg_match('~^(nao|n|negativo|cancela|cancelar|cancelo|deixa|deixa pra la|melhor nao|rejeito|agora nao|nao quero)([.!]|$)~', $t)) {
            return false;
        }
        return null;
    }

    private static function resolvePending(array $user, int $convId, bool $accept): array
    {
        $action = self::pendingAction();
        self::clearPending();
        if ($action === null) {
            return self::out('Não há nenhuma ação aguardando confirmação.', null, self::source());
        }
        if ($accept) {
            $result = self::execute($user, $action);
        } else {
            $result = self::out('Ok, cancelei a operação. Não foi alterado nada nos seus dados.', null, self::source());
        }
        AiChat::addMessage((int) $user['id'], $convId, 'assistant', $result['reply']);
        return $result;
    }

    /** Pergunta de confirmação com o resumo do que será feito. */
    private static function confirmationQuestion(array $action): string
    {
        if ($action['intent'] === 'create_transaction') {
            $label = $action['type'] === 'income' ? 'receita' : 'despesa';
            return 'Identifiquei uma ' . $label . ' de ' . money($action['amount']) . ' na categoria '
                . $action['category'] . ' (' . $action['description'] . '), data '
                . ContextBuilder::brDate($action['date']) . '. Deseja que eu registre?';
        }
        if ($action['intent'] === 'create_card_purchase') {
            $parts = ($action['installments'] > 1)
                ? ' em ' . $action['installments'] . ' parcelas de ' . money(round($action['amount'] / $action['installments'], 2))
                : ' à vista';
            return 'Identifiquei uma compra de ' . money($action['amount']) . $parts
                . ' no cartão ' . $action['card'] . ' (' . $action['description'] . ' — '
                . $action['category'] . '), data ' . ContextBuilder::brDate($action['date'])
                . '. Deseja que eu registre?';
        }
        if ($action['intent'] === 'create_goal_deposit') {
            return 'Identifiquei um aporte de ' . money($action['amount']) . ' na meta "'
                . $action['goal_name'] . '" para ' . ContextBuilder::brDate($action['date'])
                . '. Deseja que eu registre?';
        }
        if ($action['intent'] === 'create_market_alert') {
            return 'Identifiquei um alerta para ' . MarketUniverse::display($action['symbol']) . ' '
                . ($action['type'] === 'below' ? 'abaixo de' : 'acima de') . ' R$ '
                . number_format($action['target'], 2, ',', '.') . '. Deseja que eu crie?';
        }
        $deadlineTxt = $action['deadline'] ? ', prazo ' . ContextBuilder::brDate($action['deadline']) : '';
        return 'Identifiquei a criação da meta "' . $action['name'] . '" com alvo de '
            . money($action['target_amount']) . $deadlineTxt . '. Deseja confirmar?';
    }

    // ------------------------------------------------------------------
    // Utilitários
    // ------------------------------------------------------------------

    private static function out(string $reply, ?array $action, string $source): array
    {
        return [
            'ok' => true,
            'reply' => $reply,
            'pending_action' => $action,
            'source' => $source,
        ];
    }

    private static function source(): string
    {
        return (new AIService())->isConfigured() ? 'ai' : 'local';
    }

    /** Limpa a resposta da IA para exibição (remove blocos de código e espaços). */
    private static function sanitizeReply(string $content): string
    {
        $c = trim($content);
        // remove cercas de código que alguns modelos adicionam indevidamente
        $c = preg_replace('~^```(?:[a-z]*)\s*~i', '', $c) ?? $c;
        $c = preg_replace('~```$~', '', $c) ?? $c;
        return trim(Validator::text($c, 4000));
    }

    /** Resumo local (modo offline) baseado apenas em dados reais. */
    private static function localSummary(array $s): string
    {
        if ($s['income'] <= 0 && $s['expense'] <= 0) {
            return 'Você ainda não registrou movimentações em ' . $s['month_label']
                . '. Comece registrando suas receitas e despesas — pelo chat mesmo: "Recebi meu salário de 2.300" ou "Gastei 25 no almoço".';
        }
        $top = $s['top_categories'][0] ?? null;
        $out = 'Resumo de ' . $s['month_label'] . ': você recebeu ' . money($s['income'])
            . ' e gastou ' . money($s['expense']) . ', com sobra de ' . money($s['net']) . '.';
        if ($top !== null) {
            $out .= ' Seu maior gasto foi ' . $top['name'] . ' (' . money((float) $top['total']) . ').';
        }
        if ($s['net'] < 0) {
            $out .= ' Atenção: as despesas superaram as receitas — vale revisar os gastos não essenciais.';
        } elseif ($s['savings_rate'] !== null && $s['savings_rate'] >= 20) {
            $out .= ' Ótimo: você está poupando mais de 20% da renda. Considere direcionar a sobra para suas metas.';
        } else {
            $out .= ' Sugestão: use a sobra para adiantar uma meta ou reforçar sua reserva de emergência.';
        }
        return $out;
    }
}
