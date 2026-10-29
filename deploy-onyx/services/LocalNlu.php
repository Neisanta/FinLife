<?php
/**
 * FinLife — NLU local (modo offline).
 *
 * Motor de regras que interpreta linguagem natural em português
 * quando não há provedor de IA externo configurado (ou ele falhou).
 * TODAS as respostas usam dados reais consultados no banco —
 * nada é inventado. Ações geram o mesmo protocolo JSON da IA
 * externa e também exigem confirmação.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class LocalNlu
{
    /** Palavras-chave → categorias padrão. */
    private const CATEGORY_HINTS = [
        'alimentação' => ['almoco', 'almoço', 'jantar', 'comida', 'lanche', 'mercado', 'supermercado', 'padaria', 'restaurante', 'ifood', 'pizza', 'café', 'cafe', 'sorvete', 'açougue', 'acougue', 'hortifruti', 'barbearia'],
        'transporte'  => ['uber', '99', 'taxi', 'táxi', 'onibus', 'ônibus', 'metro', 'metrô', 'combustivel', 'combustível', 'gasolina', 'estacionamento', 'pedágio', 'pedagio', 'carro', 'moto', 'uberx'],
        'moradia'      => ['aluguel', 'condominio', 'condomínio', 'luz', 'energia', 'agua', 'água', 'internet', 'gas', 'gás', 'iptu', 'obra', 'reforma', 'casa', 'apartamento'],
        'lazer'        => ['cinema', 'filme', 'show', 'bar', 'balada', 'viagem', 'hotel', 'passeio', 'game', 'jogo', 'parque', 'festa', 'ingresso', 'show'],
        'saúde'        => ['saúde', 'saude', 'farmácia', 'farmacia', 'remédio', 'remedio', 'medico', 'médico', 'dentista', 'plano de saúde', 'exame', 'academia', 'terapia'],
        'educação'     => ['educação', 'educacao', 'escola', 'faculdade', 'curso', 'livro', 'material', 'mensalidade', 'creche', 'professor'],
        'assinaturas'  => ['assinatura', 'netflix', 'spotify', 'disney', 'prime', 'hbo', 'paramount', 'youtube premium', 'crunchyroll', 'chatgpt'],
        'compras'      => ['compras', 'comprei', 'compra', 'roupa', 'roupas', 'tenis', 'tênis', 'celular', 'notebook', 'eletrônico', 'eletronico', 'amazon', 'shopee', 'mercado livre', 'shopping', 'presente', 'brinquedo'],
        'contas'       => ['conta', 'contas', 'fatura', 'boleto', 'tarifa', 'anuidade', 'imposto', 'banco'],
    ];

    /** Responde a uma mensagem do usuário usando apenas dados reais. */
    public static function handle(int $userId, string $raw, array $snap): array
    {
        $text = normalize($raw);

        // 1) Criação de meta (mesmo com "?" — ex.: "criar uma meta de 10 mil?")
        if ($goal = self::goalIntent($userId, $text, $raw)) {
            return $goal;
        }

        // 2) Perguntas (análises) têm prioridade sobre criação:
        //    "quanto gastei?" não é um registro, é uma consulta.
        if (self::isQuestion($text)) {
            $r = self::answer($userId, $text, $snap);
            // answer pode retornar string ou array ['reply'=>, 'rich'=>, 'quick'=>]
            if (is_array($r)) {
                return array_merge(['action' => null], $r);
            }
            return ['action' => null, 'reply' => $r];
        }

        // 2b) Alerta de preço ("avise quando PETR4 chegar a 40")
        if ($alert = self::marketAlertIntent($userId, $text, $raw)) {
            return $alert;
        }

        // 2c) Aporte em meta ("aportei R$ 500 na reserva")
        if ($deposit = self::goalDepositIntent($userId, $text, $raw)) {
            return $deposit;
        }

        // 3) Compra em cartão de crédito (com menção a cartão ou parcelas)
        if ($card = self::cardPurchaseIntent($userId, $text, $raw)) {
            return $card;
        }

        // 4) Criação de transação
        if ($tx = self::transactionIntent($userId, $text, $raw)) {
            return $tx;
        }

        // 5) Outras perguntas e análises (sempre com dados reais)
        $r = self::answer($userId, $text, $snap);
        if (is_array($r)) {
            return array_merge(['action' => null], $r);
        }
        return ['action' => null, 'reply' => $r];
    }

    // ------------------------------------------------------------------
    // Contexto conversacional (memória de entidades citadas)
    // ------------------------------------------------------------------
    private static function ctxGet(string $key)
    {
        return $_SESSION['ai_ctx'][$key] ?? null;
    }

    private static function ctxSet(string $key, $value): void
    {
        $_SESSION['ai_ctx'][$key] = $value;
    }

    /** "2026-10" → "out/2026" (para respostas de fluxo). */
    private static function monthShort(string $ym): string
    {
        return ContextBuilder::monthLabel($ym);
    }

    /** Resumo da memória conversacional para injetar no prompt da IA externa. */
    public static function memoryText(): string
    {
        $ctx = $_SESSION['ai_ctx'] ?? [];
        if ($ctx === []) {
            return '';
        }
        $labels = [
            'category' => 'última categoria citada',
            'month' => 'último mês citado',
            'symbol' => 'último ativo citado',
            'goal' => 'última meta citada',
        ];
        $parts = [];
        foreach ($labels as $key => $label) {
            if (!empty($ctx[$key])) {
                $parts[] = $label . ': ' . $ctx[$key];
            }
        }
        return $parts === [] ? '' : "\n\nMEMÓRIA DA CONVERSA (o usuário já citou): " . implode(' · ', $parts)
            . '. Referências como "e no mês passado?" ou "esse ativo" seguem este contexto.';
    }

    // ------------------------------------------------------------------
    // Alerta de preço ("avise quando PETR4 chegar a R$ 40")
    // ------------------------------------------------------------------
    private static function marketAlertIntent(int $userId, string $text, string $raw): ?array
    {
        if (preg_match('~\b(avise|avisa|alerte|alerta|me avise|me alerte|notifique|quero saber quando)\b~', $text) !== 1) {
            return null;
        }
        if (preg_match('~\b([A-Z]{4,5}\d{1,2}|BTC|BITCOIN|ETHEREUM|ETH|SOLANA|XRP|DOGE)\b~', mb_strtoupper($raw), $m) !== 1) {
            return [
                'action' => null,
                'reply' => 'Posso criar alertas de preço para você! Diga, por exemplo: "Avise quando PETR4 chegar a R$ 40" ou "Me avise se Bitcoin cair para 300 mil".',
            ];
        }
        $symbol = MarketUniverse::normalize($m[1]);
        $amount = self::amount($raw);
        if ($amount === null) {
            return [
                'action' => null,
                'reply' => 'Entendi o alerta para ' . MarketUniverse::display($symbol) . ', mas preciso do valor-alvo. Ex.: "Avise quando chegar a R$ 40".',
            ];
        }
        $type = (preg_match('~\b(cair|abaixo|menos de|menor|despencar|cair para)\b~', $text) === 1) ? 'below' : 'above';
        $meta = MarketUniverse::meta($symbol);
        $label = $meta['label'] ?? MarketUniverse::display($symbol);
        self::ctxSet('symbol', $symbol);
        return [
            'action' => [
                'intent' => 'create_market_alert',
                'symbol' => $symbol,
                'type' => $type,
                'target' => $amount,
                'note' => 'alerta criado via assistente',
                'requires_confirmation' => true,
            ],
            'reply' => 'Vou criar um alerta para ' . $label . ' ' . ($type === 'below' ? 'cair para' : 'chegar a') . ' R$ '
                . number_format($amount, 2, ',', '.') . '. Você verá a notificação aqui no sistema quando acontecer. Confirmar?',
        ];
    }

    // ------------------------------------------------------------------
    // Aporte em meta ("aportei R$ 500 na reserva")
    // ------------------------------------------------------------------
    private static function goalDepositIntent(int $userId, string $text, string $raw): ?array
    {
        if (preg_match('~\b(aportei|aportar|aporte|depositei|depositar|deposite|investi|adicionei|coloquei)\b~', $text) !== 1) {
            return null;
        }
        $amount = self::amount($raw);
        if ($amount === null) {
            return [
                'action' => null,
                'reply' => 'Entendi que você quer registrar um aporte. Diga o valor — por exemplo: "Aportei R$ 500 na reserva de emergência".',
            ];
        }
        $goals = Goal::list($userId);
        if ($goals === []) {
            return [
                'action' => null,
                'reply' => 'Você ainda não tem metas cadastradas — então não há onde aportar. Que tal criar uma? Diga: "Crie uma meta de reserva de emergência de 10 mil".',
            ];
        }
        $goal = null;
        foreach ($goals as $g) {
            $needle = normalize((string) $g['name']);
            if ($needle !== '' && mb_stripos($text, $needle) !== false) {
                $goal = $g;
                break;
            }
        }
        if ($goal === null && count($goals) === 1) {
            $goal = $goals[0];
        }
        if ($goal === null) {
            $names = implode(' ou ', array_map(static fn ($g) => '"' . $g['name'] . '"', array_slice($goals, 0, 3)));
            return [
                'action' => null,
                'reply' => 'Em qual meta você aportou ' . money($amount) . '? Você tem: ' . $names . '.',
            ];
        }
        self::ctxSet('goal', $goal['name']);
        return [
            'action' => [
                'intent' => 'create_goal_deposit',
                'goal_id' => (int) $goal['id'],
                'goal_name' => $goal['name'],
                'amount' => $amount,
                'date' => date('Y-m-d'),
                'note' => 'aporte via assistente',
                'requires_confirmation' => true,
            ],
            'reply' => 'Vou registrar um aporte de ' . money($amount) . ' na meta "' . $goal['name'] . '" para hoje. Confirmar?',
        ];
    }

    /** O texto é uma pergunta/análise (e não um pedido de registro)? */
    private static function isQuestion(string $text): bool
    {
        if (str_contains($text, '?')) {
            return true;
        }
        return preg_match('~^(quanto|onde|qual|quais|como|quem|quando|por que|pq)\b~', $text) === 1
            || preg_match('~\b(onde estou gastando|quanto gastei|maior gasto|meus gastos|resumo do mes|resumo deste mes)\b~', $text) === 1;
    }

    // ------------------------------------------------------------------
    // Compra em cartão de crédito (à vista ou parcelada)
    // ------------------------------------------------------------------
    private static function cardPurchaseIntent(int $userId, string $text, string $raw): ?array
    {
        // "paguei a fatura" não é compra no cartão
        if (preg_match('~\b(fatura)\b~', $text) === 1 && preg_match('~\b(paguei|pagar|pague|quitar|quitei)\b~', $text) === 1) {
            return null;
        }
        $isBuy = preg_match('~\b(comprei|compra|comprar|paguei|gastei|adquiri|peguei|custou)\b~', $text) === 1;
        if (!$isBuy) {
            return null;
        }

        // Parcelas: "10x", "10 vezes", "em 12 parcelas"
        $installments = 1;
        if (preg_match('~\b(\d{1,2})\s*(?:x|vezes|parcelas?)\b~', $text, $m)) {
            $installments = max(1, min(48, (int) $m[1]));
        }

        // Cartão mencionado?
        $card = null;
        foreach (Card::activeNames($userId) as $c) {
            foreach (['name', 'nickname'] as $field) {
                $candidate = normalize((string) $c[$field]);
                if ($candidate !== '' && preg_match('~\b' . preg_quote($candidate, '~') . '\b~u', $text) === 1) {
                    $card = $c;
                    break 2;
                }
            }
        }
        if ($card === null && $installments === 1) {
            return null; // sem cartão e sem parcelas → transação comum
        }
        if ($card === null) {
            // parcelado sem cartão identificado — precisa do cartão
            return [
                'action' => null,
                'reply' => 'Entendi uma compra parcelada em ' . $installments . 'x. Diga em qual cartão registrar — por exemplo: "Comprei uma TV de 2.400 em 10x no Nubank".',
            ];
        }
        if (!in_array($card['type'], ['credit', 'multiple'], true)) {
            return null; // cartão de débito/benefício → transação normal
        }

        $amount = self::amount($raw);
        if ($amount === null) {
            return [
                'action' => null,
                'reply' => 'Entendi a compra no cartão ' . $card['name'] . ', mas não identifiquei o valor. Tente: "Comprei uma TV de 2.400 em 10x no ' . $card['name'] . '".',
            ];
        }

        $category = self::guessCategory($userId, $text, 'expense');
        $description = self::buildDescription($raw, $text);
        $date = str_contains($text, 'ontem') ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
        $catName = $category['name'] ?? 'Outras';

        $parts = ($installments > 1)
            ? ' em ' . $installments . ' parcelas de ' . money(round($amount / $installments, 2))
            : ' à vista';

        return [
            'action' => [
                'intent' => 'create_card_purchase',
                'card_id' => (int) $card['id'],
                'card' => $card['name'],
                'amount' => $amount,
                'installments' => $installments,
                'category' => $catName,
                'description' => $description,
                'date' => $date,
                'requires_confirmation' => true,
            ],
            'reply' => 'Identifiquei uma compra de ' . money($amount) . $parts
                . ' no cartão ' . $card['name'] . ' (' . $description . ' — ' . $catName . '), data '
                . ContextBuilder::brDate($date) . '. Deseja que eu registre?',
        ];
    }

    /** Score de saúde financeira 0-100 com componentes reais. */
    private static function healthScore(int $userId, array $s): array
    {
        $score = 0;
        $parts = [];
        $advice = [];

        // 1) Taxa de poupança (até 40 pts)
        $sr = $s['savings_rate'];
        if ($sr !== null) {
            if ($sr >= 20) { $score += 40; $parts[] = 'poupança ' . number_format($sr, 1) . "\u{a0}%"; }
            elseif ($sr >= 10) { $score += 28; $parts[] = 'poupança ' . number_format($sr, 1) . "\u{a0}%"; }
            elseif ($sr >= 0) { $score += 14; $parts[] = 'poupança baixa'; $advice[] = 'Aumentar a taxa de poupança acima de 10% da renda cria margem de segurança.'; }
            else { $parts[] = 'gastando mais do que ganha'; $advice[] = 'As despesas superaram as receitas — revisar os maiores gastos é urgente.'; }
        } else {
            $parts[] = 'sem receitas registradas';
            $advice[] = 'Registre suas receitas para eu calcular sua taxa de poupança.';
        }

        // 2) Uso do limite de cartões (até 25 pts)
        $cards = $s['cards']['credit'] ?? [];
        $totalLimit = 0.0; $totalUsed = 0.0;
        foreach ($cards as $c) {
            $totalLimit += $c['limit'];
            $totalUsed += $c['used'];
        }
        if ($totalLimit > 0) {
            $usage = $totalUsed / $totalLimit * 100;
            if ($usage < 50) { $score += 25; $parts[] = number_format($usage, 0) . "\u{a0}% do limite de cartão"; }
            elseif ($usage < 80) { $score += 15; $parts[] = number_format($usage, 0) . "\u{a0}% do limite de cartão"; $advice[] = 'Manter o uso de limite abaixo de 50% é mais saudável.'; }
            else { $parts[] = number_format($usage, 0) . "\u{a0}% do limite de cartão (alto)"; $advice[] = 'O limite dos cartões está muito comprometido — priorize quitar faturas.'; }
        } else {
            $score += 15; // sem cartões de crédito = sem dívida rotativa
            $parts[] = 'sem limite de crédito em uso';
        }

        // 3) Aderência ao orçamento (até 25 pts)
        if ($s['budget'] !== null) {
            $used = (float) ($s['budget_used_pct'] ?? 0);
            $dayPct = (int) date('j') / (int) date('t') * 100;
            if ($used <= $dayPct + 5) { $score += 25; $parts[] = 'no ritmo do orçamento'; }
            elseif ($used <= 100) { $score += 12; $parts[] = 'orçamento ' . number_format($used, 0) . "\u{a0}% usado"; $advice[] = 'O ritmo de gastos está à frente do mês — vale segurar um pouco.'; }
            else { $parts[] = 'orçamento estourado'; $advice[] = 'O orçamento do mês já foi ultrapassado — o foco agora é proteger a sobra.'; }
        } else {
            $score += 10;
            $advice[] = 'Definir um orçamento mensal no Dashboard me ajuda a avaliar seu ritmo com precisão.';
        }

        // 4) Evolução vs mês anterior (até 10 pts)
        $prev = $s['prev_month'];
        if ($prev['expense'] > 0) {
            $delta = $s['expense'] - $prev['expense'];
            if ($delta <= 0) { $score += 10; $parts[] = 'gastos caindo vs mês anterior'; }
            elseif ($delta / $prev['expense'] <= 0.1) { $score += 5; $parts[] = 'gastos estáveis'; }
            else { $parts[] = 'gastos subindo ' . number_format($delta / $prev['expense'] * 100, 0) . "\u{a0}%"; $advice[] = 'As despesas subiram em relação ao mês passado — veja os maiores gastos para entender o motivo.'; }
        }

        if ($advice === []) {
            $advice[] = 'Continue registrando tudo — dados completos = análises melhores.';
        }
        return [min(100, max(0, $score)), $parts, implode(' ', $advice)];
    }

    /** Dicas práticas baseadas em dados reais do mês. */
    private static function smartTips(int $userId, array $s): array
    {
        $tips = [];
        $cats = $s['top_categories'] ?? [];
        if (!empty($cats)) {
            $top = $cats[0];
            $share = (float) $s['expense'] > 0 ? ($top['total'] / $s['expense']) * 100 : 0;
            if ($share >= 25 && $s['income'] > 0) {
                $tips[] = '• ' . $top['name'] . ' responde por ' . number_format($share, 0) . "\u{a0}% das despesas (" . money((float) $top['total'])
                    . '). Um corte de 15% aí libera ' . money((float) $top['total'] * 0.15) . ' por mês.';
            }
        }
        $subs = $s['cards']['subscriptions']['monthly_total'] ?? 0;
        if ($subs > 0) {
            $count = count($s['cards']['subscriptions']['items'] ?? []);
            $tips[] = '• Você tem ' . $count . ' assinatura(s) somando ' . money($subs) . '/mês (' . money($subs * 12) . '/ano) — vale revisar as que menos usa.';
        }
        $openInvoices = $s['cards'] ?? null;
        if (is_array($openInvoices)) {
            foreach (($openInvoices['credit'] ?? []) as $c) {
                if (!empty($c['next_due'])) {
                    $tips[] = '• A fatura do ' . $c['name'] . ' (' . money($c['next_due']['total']) . ') vence em ' . ContextBuilder::brDate($c['next_due']['due_date']) . ' — pague no dia para não rodar juros.';
                    break;
                }
            }
        }
        if ($s['savings_rate'] !== null && $s['savings_rate'] >= 20) {
            $tips[] = '• Você está poupando ' . number_format($s['savings_rate'], 0) . "\u{a0}% da renda — considerar automatizar o aporte em uma meta protege esse excedente.";
        } elseif ($s['net'] > 0) {
            $tips[] = '• A sobra de ' . money($s['net']) . ' deste mês ainda está "solta" — aloque parte em uma meta antes de ser consumida.';
        }
        return array_slice($tips, 0, 3);
    }

    // ------------------------------------------------------------------
    // Criação de transação
    // ------------------------------------------------------------------
    private static function transactionIntent(int $userId, string $text, string $raw): ?array
    {
        $isExpense = preg_match('~\b(gastei|gaste|paguei|pagar|comprei|despesa|gasto|custou|gastando)\b~', $text) === 1;
        $isIncome  = preg_match('~\b(recebi|receita|salario|salário|ganhei|freelance|comissao|comissão|rendimento|vendi|depositaram)\b~', $text) === 1;
        if (!$isExpense && !$isIncome) {
            return null;
        }

        $amount = self::amount($raw);
        if ($amount === null) {
            return [
                'action' => null,
                'reply'  => 'Entendi que você quer registrar algo, mas não consegui identificar o valor. '
                         . 'Tente algo como: "Gastei R$ 45 no almoço" ou "Recebi meu salário de 2.300".',
            ];
        }

        $type = $isIncome ? 'income' : 'expense';
        $category = self::guessCategory($userId, $text, $type);
        $description = self::buildDescription($raw, $text);
        $date = str_contains($text, 'ontem') ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');

        $catName = $category['name'] ?? ($type === 'income' ? 'Outras receitas' : 'Outras');
        $typeLabel = $type === 'income' ? 'receita' : 'despesa';

        return [
            'action' => [
                'intent' => 'create_transaction',
                'type' => $type,
                'amount' => $amount,
                'category' => $catName,
                'description' => $description,
                'date' => $date,
                'requires_confirmation' => true,
            ],
            'reply' => 'Identifiquei uma ' . $typeLabel . ' de ' . money($amount) . ' na categoria '
                     . $catName . ' (' . $description . ') para ' . ContextBuilder::brDate($date) . '. '
                     . 'Deseja que eu registre?',
        ];
    }

    // ------------------------------------------------------------------
    // Criação de meta
    // ------------------------------------------------------------------
    private static function goalIntent(int $userId, string $text, string $raw): ?array
    {
        if (preg_match('~\b(crie|criar|cria|quero|definir|adiciona)[^.]*(meta|objetivo)\b~', $text) !== 1) {
            return null;
        }
        $amount = self::amount($raw);
        if ($amount === null) {
            return [
                'action' => null,
                'reply' => 'Entendi que você quer criar uma meta. Informe o valor — por exemplo: '
                        . '"Crie uma meta para comprar uma moto de 15 mil".',
            ];
        }

        // nome da meta: "meta para X", "meta de X", "objetivo de X"
        $name = 'Minha meta';
        if (preg_match('~(?:meta|objetivo)\s+(?:para|de)\s+(?:comprar\s+|juntar\s+|guardar\s+)?(.+?)(?:\s+(?:de|com|no valor de)\s*(?:r\$)?\s*[\d.,]+.*)?$~iu', $raw, $m)) {
            $candidate = Validator::text($m[1] ?? '', 100);
            if ($candidate !== '' && strlen($candidate) > 2) {
                $name = mb_convert_case($candidate, MB_CASE_TITLE, 'UTF-8');
            }
        }

        return [
            'action' => [
                'intent' => 'create_goal',
                'name' => $name,
                'target_amount' => $amount,
                'deadline' => null,
                'requires_confirmation' => true,
            ],
            'reply' => 'Vamos criar a meta "' . $name . '" com o alvo de ' . money($amount) . '. '
                     . 'Você pode definir um prazo depois, na página de Metas. Confirmar a criação?',
        ];
    }

    // ------------------------------------------------------------------
    // Perguntas e análises
    // ------------------------------------------------------------------
    /** @return string|array Resposta em texto, ou array ['reply'=>, 'rich'=>, 'quick'=>] para respostas visuais. */
    private static function answer(int $userId, string $text, array $s)
    {
        // Cumprimento/ajuda
        if (preg_match('~^(oi|ola|olá|bom dia|boa tarde|boa noite|hey|hi|eai|e aí|ajuda|help|o que voce faz|o que você faz|como funciona)~', $text) === 1) {
            return "Olá! Eu sou o FinLife AI, seu assistente financeiro. Posso:\n"
                 . "• Registrar lançamentos: \"Gastei R$ 45 no almoço\", \"Recebi meu salário de 2.300\"\n"
                 . "• Registrar compras no cartão: \"Comprei uma TV de 2.400 em 10x no Nubank\"\n"
                 . "• Criar metas: \"Crie uma meta para comprar uma moto de 15 mil\"\n"
                 . "• Analisar seus dados reais: \"Onde estou gastando mais?\", \"Quanto economizei esse mês?\"\n"
                 . "• Acompanhar cartões: \"Quanto ainda tenho de limite?\", \"Quais parcelas eu tenho?\", \"Quanto gasto em assinaturas?\"\n"
                 . 'O que você gostaria de fazer?';
        }

        // Agradecimento
        if (preg_match('~\b(obrigado|obrigada|valeu|vlw)\b~', $text) === 1) {
            return 'Por nada! Estou aqui para ajudar você a organizar sua vida financeira. 💚';
        }

        $cards = $s['cards'] ?? [];
        $hasCards = is_array($cards) && !empty($cards['credit']);

        // Limite de cartão
        if ($hasCards && preg_match('~\b(limite|quanto ainda tenho de limite|quanto posso gastar no cartao|limite disponivel)\b~', $text) === 1) {
            $lines = [];
            foreach ($cards['credit'] as $c) {
                $lines[] = '• ' . $c['name'] . ': ' . money($c['available']) . ' disponíveis de ' . money($c['limit'])
                    . ' (' . number_format($c['pct'], 1, ',', '.') . '% usados)';
            }
            return 'Seu limite total é ' . money($cards['total_limit']) . ', com ' . money($cards['total_available'])
                . " disponíveis:\n" . implode("\n", $lines);
        }

        // Faturas: quanto vou pagar / próxima / maior / está alta?
        if ($hasCards && preg_match('~\b(fatura|vou pagar de cartao|quanto vou pagar)\b~', $text) === 1) {
            // maior fatura / fatura alta
            if (preg_match('~(maior fatura|fatura (esta|está|ta|tá) (muito )?(alta|grande)|fatura alta)~', $text) === 1) {
                $biggest = null;
                foreach ($cards['credit'] as $c) {
                    $inv = $c['invoice']['total'] ?? ($c['next_due']['total'] ?? 0);
                    if ($biggest === null || $inv > $biggest['total']) {
                        $biggest = ['id' => $c['id'], 'name' => $c['name'], 'total' => $inv, 'due' => $c['invoice']['due_date'] ?? ($c['next_due']['due_date'] ?? null)];
                    }
                }
                if ($biggest === null || $biggest['total'] <= 0) {
                    return 'Você não tem faturas em aberto no momento. 🎉';
                }
                $avg = CardInvoice::closedAverage($userId, (int) $biggest['id'], 3);
                $extra = '';
                if ($avg > 0 && $biggest['total'] > $avg * 1.2) {
                    $extra = ' Isso está ' . number_format((($biggest['total'] / $avg) - 1) * 100, 0, ',', '.')
                        . '% acima da média das suas últimas faturas (' . money($avg) . ').';
                } elseif ($avg > 0) {
                    $extra = ' A média das suas últimas faturas é ' . money($avg) . '.';
                }
                return 'Sua maior fatura em aberto é a do ' . $biggest['name'] . ': ' . money($biggest['total'])
                    . ($biggest['due'] ? ', vencendo em ' . ContextBuilder::brDate($biggest['due']) : '') . '.' . $extra;
            }
            $lines = [];
            foreach ($cards['credit'] as $c) {
                if (!empty($c['next_due'])) {
                    $lines[] = '• ' . $c['name'] . ': ' . money($c['next_due']['total']) . ' — vence ' . ContextBuilder::brDate($c['next_due']['due_date']);
                }
            }
            if ($lines === []) {
                return 'Você não tem faturas em aberto nos seus cartões. 🎉';
            }
            $out = 'Faturas em aberto:' . "\n" . implode("\n", $lines);
            if (!empty($cards['future_installments'])) {
                $out .= "\n\nAlém disso, você já tem parcelas futuras comprometidas.";
            }
            return $out;
        }

        // Parcelas / comprometido
        if ($hasCards && preg_match('~\b(parcelas|parcelamento|comprometido|parcelas pendentes|quais parcelas)\b~', $text) === 1) {
            $groups = array_slice($cards['future_installments'], 0, 6);
            if (empty($groups)) {
                return 'Você não tem parcelas pendentes nos cartões. Compras parceladas aparecem aqui automaticamente.';
            }
            $total = 0.0;
            $lines = [];
            foreach ($groups as $g) {
                $total += $g['total'];
                $lines[] = '• ' . ContextBuilder::monthLabel($g['month']) . ': ' . money($g['total']);
            }
            return 'Você tem ' . money($total) . ' comprometidos em parcelas nos próximos meses:' . "\n" . implode("\n", $lines);
        }

        // Assinaturas
        if ($hasCards && preg_match('~\b(assinatura|assinaturas)\b~', $text) === 1) {
            $items = $cards['subscriptions']['items'] ?? [];
            if (empty($items)) {
                return 'Você não tem assinaturas cadastradas. Registre Netflix, academia e outros serviços fixos na página de Cartões, aba Assinaturas.';
            }
            $lines = [];
            foreach ($items as $x) {
                $lines[] = '• ' . $x['name'] . ': ' . money($x['amount']) . ' (' . $x['card'] . ')';
            }
            return 'Suas assinaturas somam ' . money($cards['subscriptions']['monthly_total']) . ' por mês:' . "\n" . implode("\n", $lines);
        }

        // ==================== COMPROMISSOS E CONTAS FIXAS ====================

        // "Quanto ainda devo ao meu pai?" — saldo devedor por pessoa/compromisso
        if (preg_match('~(quanto (ainda )?devo|resta pagar|saldo devedor|quanto falta pagar)~', $text) === 1) {
            $rows = Database::run(
                "SELECT c.name, c.payee, c.total_amount,
                        COALESCE((SELECT SUM(p.amount) FROM commitment_payments p WHERE p.commitment_id = c.id), 0) AS paid,
                        c.next_due
                 FROM commitments c
                 WHERE c.user_id = :uid AND c.kind = 'debt' AND c.status <> 'settled' AND c.total_amount IS NOT NULL
                 ORDER BY c.next_due ASC LIMIT 10",
                ['uid' => $userId]
            )->fetchAll();
            $rows = array_values(array_filter($rows, static function ($r) {
                return ((float) $r['total_amount'] - (float) $r['paid']) > 0.009;
            }));
            if ($rows === []) {
                return 'Você não tem dívidas em aberto nos compromissos. 🎉 Se registrá-las em "Compromissos e Contas Fixas", eu acompanho o saldo devedor de cada uma.';
            }
            $total = 0.0;
            $lines = [];
            $items = [];
            foreach ($rows as $r) {
                $balance = round((float) $r['total_amount'] - (float) $r['paid'], 2);
                $total += $balance;
                $lines[] = '• ' . $r['name'] . ' (' . $r['payee'] . '): ' . money($balance)
                    . ' restantes' . ($r['next_due'] ? ', próximo vencimento ' . ContextBuilder::brDate((string) $r['next_due']) : '');
                $items[] = [
                    'icon' => '🤝', 'desc' => $r['name'], 'sub' => $r['payee'], 'amount' => money($balance),
                ];
            }
            $rich = [
                ['type' => 'tx-list', 'title' => 'Dívidas em aberto', 'items' => $items],
                ['type' => 'stats', 'items' => [['label' => 'Total devido', 'value' => money($total), 'tone' => 'warn']]],
            ];
            return ['reply' => "Suas dívidas em compromissos:\n" . implode("\n", $lines) . "\n\n**Total em aberto: " . money($total) . "**.", 'rich' => $rich];
        }

        // "Quais contas preciso pagar este mês?" — agenda do mês
        if (preg_match('~(quais contas|contas (do mes|que preciso|a pagar)|o que (preciso|tenho) (que )?pagar|agenda de pagamentos|vencimentos (do mes|deste mes)|compromissos (do mes|deste mes))~', $text) === 1) {
            $pending = Commitment::monthPending($userId);
            if ($pending === []) {
                return 'Nenhuma pendência de compromissos para ' . $s['month_label'] . '. Tudo em ordem! ✅';
            }
            $total = 0.0;
            $items = [];
            $lines = [];
            foreach ($pending as $p) {
                $total += (float) $p['pending_amount'];
                $lines[] = '• ' . $p['name'] . ' (' . $p['payee'] . '): ' . money((float) $p['pending_amount'])
                    . ' — vence ' . ContextBuilder::brDate((string) $p['due_date']);
                $items[] = [
                    'icon' => '📅', 'desc' => $p['name'], 'sub' => $p['payee'] . ' · vence ' . ContextBuilder::brDate((string) $p['due_date']),
                    'amount' => money((float) $p['pending_amount']),
                ];
            }
            $rich = [
                ['type' => 'tx-list', 'title' => 'Pendências de ' . $s['month_label'], 'items' => $items],
                ['type' => 'stats', 'items' => [['label' => 'Total pendente do mês', 'value' => money($total), 'tone' => 'warn']]],
            ];
            return ['reply' => "Compromissos pendentes de " . $s['month_label'] . ":\n" . implode("\n", $lines) . "\n\n**Total pendente: " . money($total) . "**.", 'rich' => $rich];
        }

        // "Quanto dos meus próximos salários já está comprometido?" — fluxo futuro
        if (preg_match('~(comprometido|fluxo de caixa|proximo(s)? (salario|meses)|previsao (do|de) fluxo|quanto ja esta comprometido|renda comprometida)~', $text) === 1) {
            $sum = Commitment::summary($userId);
            $next2 = Database::run(
                "SELECT o.due_date, SUM(o.amount - o.paid_amount) AS pending
                 FROM commitment_occurrences o JOIN commitments c ON c.id = o.commitment_id
                 WHERE o.user_id = :uid AND c.status = 'active' AND o.status IN ('pending','partial')
                   AND o.due_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                 GROUP BY o.due_date ORDER BY o.due_date ASC LIMIT 12",
                ['uid' => $userId]
            )->fetchAll();
            $total60 = 0.0;
            $byMonth = [];
            foreach ($next2 as $r) {
                $v = (float) $r['pending'];
                $total60 += $v;
                $ym = substr((string) $r['due_date'], 0, 7);
                $byMonth[$ym] = ($byMonth[$ym] ?? 0) + $v;
            }
            $monthParts = [];
            foreach ($byMonth as $ym => $v) {
                $monthParts[] = self::monthShort($ym) . ': ' . money($v);
            }
            $rich = [
                ['type' => 'stats', 'items' => [
                    ['label' => 'Comprometido nos próximos 60 dias', 'value' => money($total60), 'tone' => 'warn'],
                    ['label' => 'Pendente deste mês', 'value' => money($sum['month_pending'])],
                    ['label' => 'Dívidas em aberto', 'value' => money($sum['debts_open'])],
                ]],
            ];
            return ['reply' => "Nos próximos 60 dias você tem **" . money($total60) . "** já comprometidos com pagamentos agendados"
                . ($monthParts ? " (" . implode(' · ', $monthParts) . ")" : '')
                . ". Isso não inclui faturas de cartão — veja-as no módulo de Cartões. Os pagamentos confirmados entram no seu fluxo de caixa como despesas.", 'rich' => $rich];
        }

        // "Quais pagamentos estão atrasados?"
        if (preg_match('~(atrasad|vencid|em atraso|pagamento(s)? atrasado)~', $text) === 1) {
            $rows = Database::run(
                "SELECT c.name, c.payee, MIN(o.due_date) AS due, SUM(o.amount - o.paid_amount) AS total
                 FROM commitment_occurrences o JOIN commitments c ON c.id = o.commitment_id
                 WHERE o.user_id = :uid AND c.status = 'active' AND o.status IN ('pending','partial') AND o.due_date < CURDATE()
                 GROUP BY c.id ORDER BY due ASC LIMIT 10",
                ['uid' => $userId]
            )->fetchAll();
            if ($rows === []) {
                return 'Nenhum compromisso em atraso — tudo em dia! ✅';
            }
            $total = 0.0;
            $items = [];
            $lines = [];
            foreach ($rows as $r) {
                $total += (float) $r['total'];
                $lines[] = '• ' . $r['name'] . ' (' . $r['payee'] . '): vencia em ' . ContextBuilder::brDate((string) $r['due'])
                    . ' — ' . money((float) $r['total']) . ' em aberto';
                $items[] = ['icon' => '⚠️', 'desc' => $r['name'], 'sub' => $r['payee'] . ' · venceu ' . ContextBuilder::brDate((string) $r['due']), 'amount' => money((float) $r['total'])];
            }
            $rich = [
                ['type' => 'tx-list', 'title' => 'Pagamentos atrasados', 'items' => $items],
                ['type' => 'alert', 'level' => 'bad', 'text' => 'Total em atraso: ' . money($total) . ' — regularize para evitar juros ou constrangimento.'],
            ];
            return ['reply' => "Compromissos em atraso:\n" . implode("\n", $lines) . "\n\n**Total em atraso: " . money($total) . "**.", 'rich' => $rich];
        }

        // "Quanto gasto mensalmente com contas fixas?"
        if (preg_match('~(contas fixas|gasto (mensal|fixo)|mensalidade(s)?|contas recorrentes|custo fixo)~', $text) === 1) {
            $rows = Database::run(
                "SELECT c.name, c.payee, c.installment_amount, c.periodicity
                 FROM commitments c
                 WHERE c.user_id = :uid AND c.status = 'active' AND c.installment_amount IS NOT NULL AND c.installment_amount > 0",
                ['uid' => $userId]
            )->fetchAll();
            if ($rows === []) {
                return 'Você ainda não tem compromissos com valor fixo cadastrados. Cadastre em "Compromissos e Contas Fixas" e eu te mostro o custo mensal.';
            }
            $monthly = 0.0;
            $items = [];
            $lines = [];
            foreach ($rows as $r) {
                $v = (float) $r['installment_amount'];
                $monthly += $v;
                $lines[] = '• ' . $r['name'] . ' (' . $r['payee'] . '): ' . money($v) . '/' . mb_strtolower(Commitment::PERIODICITY_LABELS[$r['periodicity']] ?? 'mês');
                $items[] = ['icon' => '📌', 'desc' => $r['name'], 'sub' => $r['payee'], 'amount' => money($v)];
            }
            $yearly = $monthly * 12;
            $rich = [
                ['type' => 'tx-list', 'title' => 'Contas fixas ativas', 'items' => $items],
                ['type' => 'stats', 'items' => [
                    ['label' => 'Total mensal', 'value' => money($monthly), 'tone' => 'warn'],
                    ['label' => 'Projeção anual', 'value' => money($yearly)],
                ]],
            ];
            return ['reply' => "Seus compromissos com valor fixo somam **" . money($monthly) . " por mês** (" . money($yearly) . " por ano):\n" . implode("\n", $lines), 'rich' => $rich];
        }

        // ==================== FIM DOS COMPROMISSOS ====================

        // Perguntas consultivas sobre investimentos (educacional, ancorada em dados reais)
        if (preg_match('~(melhor investimento|onde investir|como investir|vale a pena investir|o que investir|onde aplicar|como aplicar|comecar a investir|investimento (melhor|bom|seguro))~', $text) === 1) {
            $sobrou = (float) $s['net'];
            $savings = $s['savings_rate'];
            $lines = [];

            if ($savings !== null && $savings < 10) {
                $lines[] = '• **Primeiro, quitar o caro:** sua taxa de poupança é ' . number_format($savings, 1, ',', '.') . "% — antes de investir, vale priorizar quitar dívidas rotativas e cartão (os juros delas superam qualquer retorno).";
            }
            if ($sobrou > 0) {
                $lines[] = '• **Sua sobra real deste mês é ' . money($sobrou) . '** — um valor com esse perfil se encaixa bem em:';
            } else {
                $lines[] = '• Neste mês a sobra está em ' . money($sobrou) . ' — o foco agora é organizar as despesas para gerar excedente antes de investir.';
            }
            $lines[] = '• **Tesouro Selic / CDB liquidez diária:** para reserva de emergência (6 meses de gastos), segurança e resgate rápido.';
            $lines[] = '• **Tesouro IPCA+:** proteção contra inflação para prazos de 5+ anos.';
            $lines[] = '• **Fundos imobiliários (FIIs):** renda passiva mensal, mas com risco de mercado — só depois da reserva formada.';

            $advice = "\n\nPara um retrato mais completo da sua situação financeira, pergunte: **\"qual minha saúde financeira?\"**.";
            return [
                'reply' => 'Não existe um "melhor investimento" universal — o ideal depende do seu momento. Com base nos seus dados reais:' . "\n\n" . implode("\n", $lines) . $advice
                    . "\n\n*Isto não é recomendação de investimento — é orientação educacional baseada nos seus dados reais.*",
                'quick' => ['Qual minha saúde financeira?', 'Dicas para economizar', 'Vou estourar o orçamento?'],
            ];
        }

        // Perguntas educacionais gerais sobre finanças (CDI, CDB, Tesouro, renda fixa...)
        if (preg_match('~(o que e|oque e|o que significa|como funciona|explica|defina)\s+(cdi|cdb|tesouro|ipca|selic|renda fixa|fii|fundo imobiliario|etf|bdr|acoes?|dividendos?|juros compostos|inflacao|renda passiva)~', $text) === 1) {
            return [
                'reply' => 'Ótima pergunta! Esta é uma dúvida educacional — posso te dar uma visão geral baseada em princípios de finanças pessoais, mas para uma resposta mais personalizada ao seu contexto, experimente perguntar sobre sua situação específica (ex.: "qual minha saúde financeira?", "onde estou gastando mais?").' . "\n\nPara conceitos gerais sobre esse tema, a consulta a um educador financeiro ou conteúdo especializado complementa o que posso oferecer com seus dados reais aqui.",
                'quick' => ['Qual minha saúde financeira?', 'Onde estou gastando mais?'],
            ];
        }

        // "e no mês passado?" — follow-up contextual (usa a memória da conversa)
        if (preg_match('~^(e |e o |)no mes (passado|anterior)|(e )?mes (passado|anterior)|e antes disso~', $text) === 1) {
            $cat = self::ctxGet('category');
            $prev = $s['prev_month'];
            if ($cat !== null) {
                $c = Category::matchByName($userId, (string) $cat, 'expense');
                if ($c !== null) {
                    $total = Transaction::categoryTotal($userId, (int) $c['id'], $prev['month'], 'expense');
                    self::ctxSet('month', $prev['month']);
                    return 'No mês anterior (' . ContextBuilder::monthLabel($prev['month']) . '), você gastou ' . money($total) . ' com ' . $c['name'] . '.';
                }
            }
            return 'Em ' . ContextBuilder::monthLabel($prev['month']) . ': receitas ' . money($prev['income']) . ', despesas ' . money($prev['expense']) . ', sobra de ' . money($prev['income'] - $prev['expense']) . '. Me pergunte sobre um tema específico — como "quanto gastei com alimentação?" — para eu comparar os dois meses.';
        }

        // "quanto gastei hoje / ontem"
        if (preg_match('~(gastei|gastos|despesas).*(hoje|ontem)|quanto (gastei|gast|gasto) (hoje|ontem)~', $text) === 1) {
            $when = (str_contains($text, 'ontem')) ? '-1 day' : 'now';
            $day = date('Y-m-d', strtotime($when));
            $total = (float) (Database::run(
                "SELECT COALESCE(SUM(amount), 0) AS t FROM transactions WHERE user_id = :uid AND type = 'expense' AND date = :d",
                ['uid' => $userId, 'd' => $day]
            )->fetch()['t'] ?? 0);
            $label = str_contains($text, 'ontem') ? 'Ontem' : 'Hoje';
            if ($total <= 0) {
                return $label . ' você não registrou despesas. Um dia limpo! 🎉';
            }
            $rich = [
                ['type' => 'stats', 'items' => [
                    ['label' => $label . ' você gastou', 'value' => money($total), 'tone' => 'danger'],
                ]],
            ];
            return ['reply' => $label . ' você gastou ' . money($total) . ' no total. Quer ver com o que? Pergunte "onde estou gastando mais?".', 'rich' => $rich];
        }

        // "vou estourar o orçamento?" / "quanto resta do orçamento" — projeção
        if ($s['budget'] !== null && preg_match('~(vou estourar|estourar o orcamento|vao estourar|quanto resta|sobrou do orcamento|termina o mes|acaba o orcamento|aguenta o ritmo|aguenta ate o fim)~', $text) === 1) {
            $expense = (float) $s['expense'];
            $budget = (float) $s['budget'];
            $dayOfMonth = (int) date('j');
            $daysInMonth = (int) date('t');
            $daysLeft = max(1, $daysInMonth - $dayOfMonth);
            $dailyRate = $expense / max(1, $dayOfMonth);
            $projected = $dailyRate * $daysInMonth;
            $remaining = $budget - $expense;
            $risk = $projected > $budget ? 'danger' : ($projected > $budget * 0.92 ? 'warn' : 'good');
            $verdict = $projected > $budget
                ? 'Sim — nesse ritmo você ultrapassa o orçamento em ~' . money($projected - $budget) . '.'
                : 'Não — nesse ritmo você termina o mês em ' . money(max(0, $budget - $projected)) . ' de folga.';
            $advice = $risk === 'danger'
                ? 'Para caber, o teto dos próximos dias é ~' . money(max(0, $remaining) / $daysLeft) . '/dia.'
                : 'Ritmo saudável — continue assim!';
            $rich = [
                ['type' => 'progress', 'label' => 'Orçamento do mês', 'value' => $expense, 'target' => $budget, 'pct' => min(100, ($expense / max(0.01, $budget)) * 100)],
                ['type' => 'stats', 'items' => [
                    ['label' => 'Resta hoje', 'value' => money(max(0, $remaining))],
                    ['label' => 'Projeção do mês', 'value' => money($projected), 'tone' => $risk],
                    ['label' => 'Ritmo diário', 'value' => money(round($dailyRate, 2))],
                ]],
            ];
            return ['reply' => $verdict . ' ' . $advice, 'rich' => $rich, 'quick' => ['Onde estou gastando mais?', 'Meus maiores gastos', 'Dicas para economizar']];
        }

        // "qual minha saúde financeira" — score com componentes reais
        if (preg_match('~(saude financeira|saude das minhas financas|como esta minha saude|score financeiro|como estou de saude|nota financeira|meu score)~', $text) === 1) {
            [$score, $parts, $advice] = self::healthScore($userId, $s);
            $tone = $score >= 70 ? 'good' : ($score >= 45 ? 'warn' : 'bad');
            $verdict = $score >= 70 ? 'Sua saúde financeira está forte 👏' : ($score >= 45 ? 'Sua saúde financeira está razoável — dá para melhorar.' : 'Atenção: sua saúde financeira precisa de cuidados.');
            $verdict = 'Seu score financeiro é **' . $score . '/100**. ' . $verdict;
            $rich = [
                ['type' => 'stats', 'items' => [
                    ['label' => 'Score', 'value' => $score . '/100', 'tone' => $tone],
                    ['label' => 'Taxa de poupança', 'value' => ($s['savings_rate'] !== null ? number_format($s['savings_rate'], 1, ',', '.') . '%' : '—')],
                    ['label' => 'Sobra do mês', 'value' => money($s['net'])],
                ]],
                ['type' => 'alert', 'level' => $tone, 'text' => implode(' · ', $parts)],
            ];
            return ['reply' => $verdict . "\n\n" . $advice, 'rich' => $rich, 'quick' => ['Quanto economizei esse mês?', 'Dicas para economizar', 'Vou estourar o orçamento?']];
        }

        // "meus maiores gastos" — top transações do mês com lista visual
        if (preg_match('~(maiores gastos|top gastos|maiores despesas|maior gasto do mes|gastos mais altos|o que mais gastei)~', $text) === 1) {
            $rows = Database::run(
                "SELECT t.description, t.amount, c.name AS cat, c.icon FROM transactions t
                 LEFT JOIN categories c ON c.id = t.category_id
                 WHERE t.user_id = :uid AND t.type = 'expense' AND t.date LIKE :ym
                 ORDER BY t.amount DESC LIMIT 5",
                ['uid' => $userId, 'ym' => $s['month'] . '%']
            )->fetchAll();
            if ($rows === []) {
                return 'Você ainda não registrou despesas em ' . $s['month_label'] . '.';
            }
            $items = [];
            $total = 0.0;
            foreach ($rows as $r) {
                $items[] = [
                    'icon' => $r['icon'] ?: '💸',
                    'desc' => $r['description'],
                    'sub' => $r['cat'] ?? '',
                    'amount' => money((float) $r['amount']),
                ];
                $total += (float) $r['amount'];
            }
            $rich = [
                ['type' => 'tx-list', 'title' => 'Maiores gastos de ' . $s['month_label'], 'items' => $items],
                ['type' => 'stats', 'items' => [
                    ['label' => 'Somados, os 5 maiores', 'value' => money($total)],
                    ['label' => 'Do total de despesas', 'value' => ($s['expense'] > 0 ? number_format($total / $s['expense'] * 100, 0) : 0) . '%'],
                ]],
            ];
            return ['reply' => 'Estes são seus 5 maiores gastos de ' . $s['month_label'] . ' — juntos somam ' . money($total) . '.', 'rich' => $rich, 'quick' => ['Onde estou gastando mais?', 'Dicas para economizar']];
        }

        // "dicas para economizar" — sempre baseadas em dados reais
        if (preg_match('~(dica|dicas|como economizar|como gastar menos|onde cortar|como poupar|economizar dinheiro)~', $text) === 1) {
            $tips = self::smartTips($userId, $s);
            if ($tips === []) {
                return 'Com seus dados atuais, o melhor próximo passo é registrar mais transações — quanto mais completo o histórico, melhores as minhas dicas.';
            }
            return ['reply' => "Baseado nos seus dados reais deste mês:\n\n" . implode("\n", $tips), 'quick' => ['Meus maiores gastos', 'Vou estourar o orçamento?', 'Como estão minhas metas?']];
        }

        // ==================== FIM DAS NOVAS ANÁLISES ====================

        // Cartão mais usado
        if ($hasCards && preg_match('~(qual cartao (estou )?(usando|uso) mais|cartao mais usado|mais utilizad)~', $text) === 1) {
            $most = null;
            foreach ($cards['credit'] as $c) {
                if ($most === null || $c['pct'] > $most['pct']) { $most = $c; }
            }
            if ($most === null) { return 'Não encontrei cartões de crédito cadastrados.'; }
            return 'O cartão que você está usando mais é o ' . $most['name'] . ': '
                . number_format($most['pct'], 1, ',', '.') . '% do limite (' . money($most['used']) . ' de ' . money($most['limit']) . ').';
        }

        // ==================== MERCADOS ====================
        $mkt = $s['market'] ?? null;
        $mktQuotes = (is_array($mkt) && isset($mkt['quotes'])) ? $mkt['quotes'] : [];

        $findQuote = static function (string $needle) use ($mktQuotes): ?array {
            // 1) símbolo exato (evita "USD" casar com "BTCUSDT")
            foreach ($mktQuotes as $q) {
                if ($q['symbol'] === $needle) { return $q; }
            }
            // 2) label começa com o termo
            foreach ($mktQuotes as $q) {
                if (mb_stripos($q['label'], $needle) === 0) { return $q; }
            }
            // 3) contém
            foreach ($mktQuotes as $q) {
                if (mb_stripos($q['label'] . ' ' . $q['symbol'], $needle) !== false) { return $q; }
            }
            return null;
        };
        $quoteTimeTxt = static fn (array $q): string => ' (cotação de ' . $q['time'] . ', fonte: ' . $q['provider']
            . ($q['live'] ? ', em tempo real' : ', com atraso de até 15 min') . ')';

        // "quanto subiu o ibovespa?" / "como está o ibovespa?" / "dólar?"
        if (preg_match('~(ibovespa|ibov|bovespa|s&p|nasdaq|dolar|dólar)~', $text) === 1) {
            $needle = preg_match('~(s&p)~', $text) === 1 ? 'S&P'
                : ((preg_match('~nasdaq~', $text) === 1) ? 'Nasdaq' : ((preg_match('~(dolar|dólar)~', $text) === 1) ? 'USDBRL' : 'Ibovespa'));
            $q = $findQuote($needle);
            if ($q === null) {
                return 'Não consegui buscar a cotação de ' . $needle . ' agora. Tente novamente em alguns instantes.';
            }
            $dir = ($q['pct'] ?? 0) >= 0 ? 'subindo' : 'caindo';
            return $q['label'] . ' está em ' . money($q['price']) . ', ' . $dir . ' '
                . number_format(abs($q['pct']), 2, ',', '.') . '% hoje'
                . $quoteTimeTxt($q) . '.';
        }

        // bitcoin / cripto
        if (preg_match('~(bitcoin|btc|ethereum|cripto|criptomoeda)~', $text) === 1) {
            $needle = preg_match('~(ethereum|eth)~', $text) === 1 ? 'ETHUSDT' : 'BTCUSDT';
            $q = $findQuote($needle);
            if ($q === null) {
                return 'Não consegui buscar a cotação agora. Tente novamente em instantes.';
            }
            $name = $q['label'] ?? $needle;
            return $name . ' está em ' . money($q['price']) . ($q['pct'] !== null ? ' (' . number_format($q['pct'], 2, ',', '.') . '% nas últimas 24h)' : '')
                . $quoteTimeTxt($q) . '.';
        }

        // "como está o mercado hoje?" — resumo geral
        if (preg_match('~(como (esta|está) o mercado|mercado (hoje|agora)|panorama do mercado|resumo do mercado)~', $text) === 1) {
            if ($mktQuotes === []) {
                return 'Não consegui carregar as cotações agora. Tente novamente em instantes.';
            }
            $lines = [];
            foreach ($mktQuotes as $q) {
                $lines[] = '• ' . $q['label'] . ': ' . money($q['price'])
                    . ($q['pct'] !== null ? ' (' . number_format($q['pct'], 2, ',', '.') . '%)' : '');
            }
            $first = $mktQuotes[0];
            return "Panorama do mercado agora:\n" . implode("\n", $lines)
                . "\n\nCotações de " . $first['time'] . ' (fontes públicas' . ($first['live'] ? '; cripto em tempo real' : '; com atraso de bolsa') . '). Dados detalhados na página Mercados!';
        }

        // carteira de investimentos
        if (preg_match('~(minha carteira|carteira de investimentos|carteira valorizou|quanto (estou ganhando|valho)|melhor posicao|posicoes)~', $text) === 1) {
            $pf = is_array($mkt) ? ($mkt['portfolio'] ?? null) : null;
            if ($pf === null) {
                return 'Você ainda não tem operações registradas na carteira. Registre sua primeira compra na página Mercados (aba "Minha carteira") — preço médio, lucro e rentabilidade são calculados com cotações reais.';
            }
            if (preg_match('~(melhor posicao|melhor ativo)~', $text) === 1) {
                $best = null;
                foreach ($pf['positions'] as $p) {
                    if ($best === null || $p['profit_pct'] > $best['profit_pct']) { $best = $p; }
                }
                if ($best === null) { return 'Sem posições registradas ainda.'; }
                return 'Sua melhor posição é ' . $best['symbol'] . ': ' . number_format($best['profit_pct'], 2, ',', '.') . '% ('
                    . money($best['profit']) . ' de resultado)' . ($best['time'] ? ' — cotação de ' . $best['time'] : '') . '.';
            }
            $dir = $pf['profit'] >= 0 ? 'lucro' : 'prejuízo';
            $out = 'Sua carteira de investimentos: valor atual ' . money($pf['total_value']) . ', com ' . $dir
                . ' de ' . money(abs($pf['profit']))
                . ($pf['profit_pct'] !== null ? ' (' . number_format($pf['profit_pct'], 2, ',', '.') . '%)' : '')
                . ' sobre o custo de ' . money($pf['total_cost']) . '.'
                . "\nDividendos: " . money($pf['dividends_month']) . ' no mês, ' . money($pf['dividends_year']) . ' no ano.';
            $out .= "\nQuer ver posição por posição? Abra a aba Minha carteira na página Mercados.";
            return $out;
        }

        // dividendos
        if (preg_match('~(dividendos|proventos|rendimento dos meus ativos)~', $text) === 1) {
            $pf = is_array($mkt) ? ($mkt['portfolio'] ?? null) : null;
            if ($pf === null) {
                return 'Você ainda não tem proventos registrados. Registre dividendos e rendimentos de FIIs na página Mercados (aba "Minha carteira") para acompanhar mês a mês.';
            }
            return 'Você recebeu ' . money($pf['dividends_month']) . ' em dividendos/proventos neste mês e '
                . money($pf['dividends_year']) . ' no ano. Registre cada provento na página Mercados (aba Minha carteira) para manter o histórico completo.';
        }

        // watchlist: qual ativo mais subiu
        if (preg_match('~(watchlist|mais subiu|acompanhamento)~', $text) === 1) {
            $wl = is_array($mkt) ? ($mkt['watchlist'] ?? []) : [];
            if ($wl === []) {
                return 'Sua watchlist está vazia. Adicione ativos na página Mercados (aba Watchlist) — ex.: PETR4, Bitcoin, HGLG11 — e eu consigo te dizer quem mais subiu!';
            }
            $best = null;
            foreach ($wl as $w) {
                if ($w['pct'] === null) { continue; }
                if ($best === null || $w['pct'] > $best['pct']) { $best = $w; }
            }
            if ($best === null) { return 'Sua watchlist não tem cotações disponíveis agora.'; }
            return 'O ativo que mais subiu na sua watchlist é ' . $best['label'] . ': '
                . number_format($best['pct'], 2, ',', '.') . '% hoje' . ($best['time'] ? ' (cotação de ' . $best['time'] . ')' : '') . '.';
        }

        // Saldo / quanto tenho
        if (preg_match('~\b(saldo|quanto tenho|dinheiro na conta|minhas contas|quanto tenho de saldo)\b~', $text) === 1) {
            $parts = array_map(static fn ($a) => '• ' . $a['name'] . ': ' . money($a['balance']), $s['accounts']);
            $list = ($parts === []) ? 'Você ainda não cadastrou contas.' : implode("\n", $parts);
            $stats = [['label' => 'Saldo total', 'value' => money($s['balance']), 'tone' => 'good']];
            foreach (array_slice($s['accounts'], 0, 2) as $a) {
                $stats[] = ['label' => $a['name'], 'value' => money($a['balance'])];
            }
            return [
                'reply' => "Seu saldo total consolidado é " . money($s['balance']) . ".\n\n" . $list,
                'rich' => [['type' => 'stats', 'items' => $stats]],
                'quick' => ['Como está meu orçamento?', 'Quanto economizei esse mês?', 'Qual minha saúde financeira?'],
            ];
        }

        // Onde estou gastando mais / maior categoria
        if (preg_match('~(onde (estou )?gastando mais|maior gasto|mais dinheiro|gastos principais|por onde vai meu dinheiro)~', $text) === 1) {
            if (empty($s['top_categories'])) {
                return 'Ainda não há despesas registradas em ' . $s['month_label'] . '. Registre seus gastos e eu te mostro para onde o dinheiro está indo.';
            }
            $total = 0.0;
            $lines = [];
            $txItems = [];
            foreach ($s['top_categories'] as $i => $c) {
                $total += (float) $c['total'];
                $lines[] = ($i + 1) . '. ' . $c['name'] . ' — ' . money((float) $c['total'])
                    . ' (' . number_format((float) $c['pct'], 1, ',', '.') . '%)';
                $txItems[] = [
                    'icon' => '💸',
                    'desc' => $c['name'],
                    'sub' => number_format((float) $c['pct'], 1, ',', '.') . '% das despesas',
                    'amount' => money((float) $c['total']),
                ];
            }
            self::ctxSet('month', $s['month']);
            return [
                'reply' => "Em " . $s['month_label'] . ", seus maiores gastos foram:\n" . implode("\n", $lines)
                    . "\n\nTotal de despesas do mês: " . money($s['expense']) . '.',
                'rich' => [['type' => 'tx-list', 'title' => 'Categorias de ' . $s['month_label'], 'items' => $txItems]],
                'quick' => ['Meus maiores gastos', 'Dicas para economizar', 'E no mês passado?'],
            ];
        }

        // Quanto gastei (com X / no mês)
        if (preg_match('~(quanto gastei|gastei com|gastos com|quanto gasto|total de gastos?)~', $text) === 1) {
            $keyword = self::extractCategoryKeyword($text);
            if ($keyword !== null) {
                $cat = Category::matchByName($userId, $keyword, 'expense');
                if ($cat !== null) {
                    $total = Transaction::categoryTotal($userId, (int) $cat['id'], $s['month'], 'expense');
                    self::ctxSet('category', $cat['name']);
                    self::ctxSet('month', $s['month']);
                    if ($total > 0) {
                        return 'Você gastou ' . money($total) . ' com ' . $cat['name'] . ' em ' . $s['month_label'] . '. '
                            . 'Quer comparar? Pergunte "e no mês passado?".';
                    }
                    return 'Não encontrei gastos com ' . $cat['name'] . ' em ' . $s['month_label'] . '.';
                }
                return 'Não encontrei uma categoria chamada "' . $keyword . '" nas suas categorias de despesa.';
            }
            return 'Em ' . $s['month_label'] . ' você gastou ' . money($s['expense']) . '. '
                . 'Se quiser detalhar por categoria, pergunte: "Quanto gastei com alimentação esse mês?".';
        }

        // Gastos dos últimos N meses com algo
        if (preg_match('~\b(ultimos|últimos)\s+(\d+)\s*mes(es)?\b~', $text, $m) && preg_match('~\b(gasto|gastei|gastos)\b~', $text) === 1) {
            $n = min(12, max(1, (int) $m[2]));
            $keyword = self::extractCategoryKeyword($text);
            if ($keyword !== null) {
                $cat = Category::matchByName($userId, $keyword, 'expense');
                if ($cat !== null) {
                    $total = Transaction::categoryTotalMonths($userId, (int) $cat['id'], $n);
                    return 'Nos últimos ' . $n . ' meses, você gastou ' . money($total) . ' com ' . $cat['name']
                        . ' (média de ' . money(round($total / $n, 2)) . ' por mês).';
                }
            }
        }

        // Economizei / sobra / poupança do mês
        if (preg_match('~(economizei|poupei|sobra|sobra do mes|quanto sobrou|quanto poup(ei|o))~', $text) === 1) {
            if ($s['net'] > 0) {
                return 'Em ' . $s['month_label'] . ' você teve uma sobra de ' . money($s['net'])
                    . ' (' . number_format($s['savings_rate'] ?? 0, 1, ',', '.') . '% das suas receitas). '
                    . 'Sugestão: considere usar esse valor para adiantar suas metas ou fortalecer sua reserva de emergência.';
            }
            if ($s['net'] < 0) {
                return 'Em ' . $s['month_label'] . ' as despesas (' . money($s['expense'])
                    . ') superaram as receitas (' . money($s['income']) . ') em ' . money(abs($s['net']))
                    . '. Vale revisar as categorias com maior gasto e cortar o que não é essencial.';
            }
            return 'Este mês está com saldo zerado até agora.';
        }

        // Investimentos
        if (preg_match('~\b(investido|investimentos|investimento|investi)\b~', $text) === 1) {
            return 'O controle de investimentos completo chega na próxima fase do FinLife. '
                . 'Por enquanto, recomendo registrar os valores aplicados como metas (ex.: "Reserva de emergência") '
                . 'para acompanhar a evolução. Suas metas: '
                . self::goalsSummary($s);
        }

        // Estou gastando demais?
        if (preg_match('~(gastando demais|gastando muito|gasto demais|estou gastando demais|minhas contas estao apertadas)~', $text) === 1) {
            if ($s['income'] <= 0) {
                return 'Ainda não há receitas registradas em ' . $s['month_label'] . '. Registre suas receitas para eu avaliar seu ritmo de gastos.';
            }
            $rate = round(($s['expense'] / $s['income']) * 100, 1);
            if ($rate > 90) {
                $msg = 'Sim, atenção: suas despesas consumiram ' . number_format($rate, 1, ',', '.') . '% das receitas de ' . $s['month_label'] . ' (' . money($s['expense']) . ' de ' . money($s['income']) . ').';
            } elseif ($rate > 70) {
                $msg = 'Seu ritmo está no limite: ' . number_format($rate, 1, ',', '.') . '% da sua renda de ' . $s['month_label'] . ' foi usada em despesas. Uma margem maior que 30% é mais confortável.';
            } else {
                $msg = 'Pelo contrário, bom controle: suas despesas ficaram em ' . number_format($rate, 1, ',', '.') . '% das suas receitas de ' . $s['month_label'] . '.';
            }
            if ($s['budget'] !== null && $s['budget_used_pct'] !== null && $s['budget_used_pct'] > 100) {
                $msg .= ' Além disso, você ultrapassou seu orçamento mensal (' . money($s['budget']) . ').';
            }
            return $msg;
        }

        // Posso comprar X de valor Y?
        if (preg_match('~(posso comprar|consigo comprar|da para comprar|dá para comprar|vale a pena comprar)~', $text) === 1) {
            $amount = self::amountFromQuestion($text);
            if ($amount === null) {
                return 'Para avaliar uma compra, informe o valor — por exemplo: "Posso comprar um celular de R$ 3.000?".';
            }
            $sobrou = $s['net'];
            $saldoTotal = $s['balance'];
            if ($saldoTotal >= $amount && $sobrou > 0) {
                return 'Analisei seus dados de ' . $s['month_label'] . ': seu saldo total é ' . money($saldoTotal)
                    . ' e a sobra do mês foi de ' . money($sobrou) . '. Comprar algo de ' . money($amount)
                    . ' é viável, mas representaria ' . number_format(($amount / max(0.01, $saldoTotal)) * 100, 1, ',', '.')
                    . '% do seu saldo total. Se não for urgente, poupar ' . money(round($amount / 3, 2))
                    . ' por mês durante 3 meses evita comprometer sua reserva. A decisão é sua! 💪';
            }
            if ($saldoTotal >= $amount) {
                return 'Você tem saldo suficiente (' . money($saldoTotal) . ') para uma compra de ' . money($amount)
                    . ', mas a sobra do mês (' . money($sobrou) . ') não cobre esse valor — ou seja, a compra usaria'
                    . ' dinheiro de outros meses. Se possível, espere acumular mais.';
            }
            return 'Com saldo total de ' . money($saldoTotal) . ', uma compra de ' . money($amount)
                . ' não cabe no seu orçamento atual. Que tal criar uma meta para esse objetivo? Diga: "Crie uma meta para comprar um celular de 3 mil".';
        }

        // Quanto preciso investir/aportar para chegar em X?
        if (preg_match('~(quanto preciso|quanto tenho que|quanto devo)( investir| guardar| aportar| poupar| guardar por mes| investir por mes)? .*?para (chegar|juntar)~', $text) === 1
            || preg_match('~chegar em? .*?(mil|\d)~', $text) === 1) {
            $amount = self::amountFromQuestion($text);
            if ($amount === null) {
                return 'Para fazer essa projeção, informe o valor desejado — por exemplo: "Quanto preciso investir por mês para chegar em 100 mil?".';
            }
            $i = 0.006; // ~0,6% ao mês (aproximação educativa, não garantia)
            $lines = [];
            foreach ([12, 24, 36, 60] as $n) {
                $aporte = ($i > 0) ? ($amount * $i) / (pow(1 + $i, $n) - 1) : $amount / $n;
                $lines[] = '• ' . $n . ' meses: ' . money(round($aporte, 2)) . '/mês';
            }
            return 'Para atingir ' . money($amount) . ', aportes mensais aproximados (com juros de ~0,6% ao mês, apenas referência educativa):' . "\n"
                . implode("\n", $lines)
                . "\n\nQuer que eu crie uma meta com esse objetivo?";
        }

        // Situação melhorou?
        if (preg_match('~(melhorou|piorou|minha situacao financeira|situacao financeira|evolucao financeira|como estou financeiramente)~', $text) === 1) {
            $prev = $s['prev_month'];
            $delta = $s['expense'] - $prev['expense'];
            if ($prev['expense'] > 0 && abs($delta) >= 0.01) {
                $dir = ($delta < 0) ? 'uma queda' : 'um aumento';
                $msg = 'Comparando ' . $s['month_label'] . ' com ' . ContextBuilder::monthLabel($prev['month']) . ': '
                    . 'suas despesas tiveram ' . $dir . ' de ' . money(abs($delta)) . ' ('
                    . number_format(abs($delta / max(0.01, $prev['expense'])) * 100, 1, ',', '.') . '%). ';
            } else {
                $msg = 'Não há dados suficientes de ' . ContextBuilder::monthLabel($prev['month']) . ' para comparar. ';
            }
            $msg .= 'Em ' . $s['month_label'] . ': receitas ' . money($s['income']) . ', despesas ' . money($s['expense'])
                . ', sobra ' . money($s['net']) . '.';
            return $msg;
        }

        // Mostre meus gastos / resumo do mês
        if (preg_match('~(mostre meus gastos|meus gastos|resumo do mes|resumo deste mes|resumo financeiro|como estao meus gastos|como estão meus gastos)~', $text) === 1) {
            $catLines = [];
            foreach (array_slice($s['top_categories'], 0, 5) as $c) {
                $catLines[] = '• ' . $c['name'] . ': ' . money((float) $c['total']);
            }
            $out = 'Resumo de ' . $s['month_label'] . ":\n"
                 . '• Receitas: ' . money($s['income']) . "\n"
                 . '• Despesas: ' . money($s['expense']) . "\n"
                 . '• Sobra: ' . money($s['net']);
            if ($catLines !== []) {
                $out .= "\n\nPrincipais categorias:\n" . implode("\n", $catLines);
            }
            return $out;
        }

        // Metas
        if (preg_match('~\b(metas|minhas metas|como vao as metas|progresso das metas|objetivos)\b~', $text) === 1) {
            return self::goalsSummary($s);
        }

        // Orçamento
        if (preg_match('~\b(orcamento|orçamento|budget)\b~', $text) === 1) {
            if ($s['budget'] === null) {
                return 'Você ainda não definiu um orçamento mensal. Dica: use o card "Orçamento" no Dashboard para definir um limite e eu te aviso quando ele estiver sob pressão.';
            }
            return 'Seu orçamento de ' . money($s['budget']) . ' está ' . number_format($s['budget_used_pct'] ?? 0, 1, ',', '.')
                . '% usado (' . money($s['expense']) . ' de despesas em ' . $s['month_label'] . ').';
        }

        return "Posso ajudar com isso e mais! Experimente:\n"
             . "• \"Gastei R$ 35 no Uber\"\n"
             . "• \"Comprei uma TV de 2.400 em 10x no Nubank\"\n"
             . "• \"Onde estou gastando mais dinheiro?\"\n"
             . "• \"Quanto ainda tenho de limite?\"\n"
             . "• \"Quais parcelas ainda tenho?\"\n"
             . "• \"Quanto gasto por mês em assinaturas?\"\n"
             . "• \"Crie uma meta para comprar uma moto de 15 mil\"";
    }

    // ------------------------------------------------------------------
    // Auxiliares
    // ------------------------------------------------------------------
    private static function goalsSummary(array $s)
    {
        if (empty($s['goals'])) {
            return 'Você ainda não tem metas cadastradas. Que tal criar a primeira? Diga por exemplo: "Crie uma meta de reserva de emergência de 10 mil".';
        }
        $lines = [];
        $progress = [];
        foreach ($s['goals'] as $g) {
            $l = '• ' . $g['name'] . ': ' . money($g['current']) . ' de ' . money($g['target'])
                . ' (' . number_format($g['progress'], 0, ',', '.') . '%)';
            if ($g['deadline'] !== null) {
                $l .= ', prazo ' . ContextBuilder::brDate($g['deadline']);
            }
            if ($g['monthly_needed'] !== null && $g['status'] !== 'completed') {
                $l .= ', aporte recomendado: ' . money($g['monthly_needed']) . '/mês';
            }
            $lines[] = $l;
            $progress[] = [
                'label' => $g['name'],
                'value' => (float) $g['current'],
                'target' => (float) $g['target'],
                'pct' => (float) $g['progress'],
            ];
        }
        if (!empty($progress)) {
            self::ctxSet('goal', $progress[0]['label']);
        }
        return [
            'reply' => "Suas metas:\n" . implode("\n", $lines),
            'rich' => [['type' => 'progress-group', 'items' => $progress]],
            'quick' => ['Aportei R$ 500 na ' . mb_strtolower($progress[0]['label'] ?? 'meta'), 'Quanto economizei esse mês?'],
        ];
    }

    /** Chute da categoria a partir de palavras-chave, resolvido contra as categorias reais do usuário. */
    private static function guessCategory(int $userId, string $text, string $type): ?array
    {
        // receitas: salário tem match direto
        if ($type === 'income') {
            if (preg_match('~\b(salario|salário|ordenado)\b~', $text) === 1) {
                return Category::matchByName($userId, 'salário', 'income');
            }
            if (preg_match('~\b(freelance|bico|projeto)\b~', $text) === 1) {
                return Category::matchByName($userId, 'freelance', 'income');
            }
            if (preg_match('~\b(comissao|comissão|bonus|bônus)\b~', $text) === 1) {
                return Category::matchByName($userId, 'comissões', 'income');
            }
            return Category::fallbackFor($userId, 'income');
        }

        foreach (self::CATEGORY_HINTS as $catName => $words) {
            foreach ($words as $w) {
                if (self::hintMatches($text, $w)) {
                    $cat = Category::matchByName($userId, $catName, 'expense');
                    if ($cat !== null) {
                        return $cat;
                    }
                }
            }
        }
        return Category::fallbackFor($userId, 'expense');
    }

    /** Extrai a categoria citada na pergunta ("gastei com alimentação", "lazer" etc.). */
    private static function extractCategoryKeyword(string $text): ?string
    {
        if (preg_match('~\b(?:com|de|no|na|em|para)\s+([a-zçãáéíóúêõô ]{3,25})\b~u', $text, $m)) {
            $kw = trim($m[1]);
            if (!preg_match('~^(mes|esse mes|este mes|esse|este|hoje|ontem|total|tudo|ano|ano passado|frente|meses)$~', $kw)) {
                return $kw;
            }
        }
        foreach (self::CATEGORY_HINTS as $cat => $words) {
            foreach ($words as $w) {
                if (self::hintMatches($text, $w)) {
                    return $cat;
                }
            }
        }
        return null;
    }

    /** Palavra-chave do hint presente no texto como palavra inteira (sem casar substrings). */
    private static function hintMatches(string $text, string $word): bool
    {
        $quoted = preg_quote($word, '~');
        return preg_match('~\b' . $quoted . '\b~u', $text) === 1;
    }

    /** Monta uma descrição curta a partir da frase original. */
    private static function buildDescription(string $raw, string $text): string
    {
        $d = mb_strtolower($raw, 'UTF-8');
        $d = preg_replace('~(r\$\s*)?[\d]+([\d.,]*)\s*(mil|k)?\s*(reais)?~iu', ' ', $d) ?? $d;
        $d = preg_replace('~\b(gastei|gaste|paguei|pagar|comprei|despesa|gasto|custou|recebi|receita|salario|salário|ganhei|freelance|comissao|comissão|rendimento|vendi|meu|minha|de|do|da|no|na|em|um|uma|uns|umas|com|para|por|hoje|ontem|reais|real)\b~iu', ' ', $d) ?? $d;
        $d = preg_replace('~\s+~', ' ', $d);
        $d = trim($d, " \t.,;:-");
        $d = mb_substr(Validator::text($d, 60), 0, 60);
        return ($d === '') ? 'Lançamento via assistente' : ucfirst($d);
    }

    /** Extrai valor monetário da frase ("R$ 45", "1.500,50", "15 mil"). */
    public static function amount(string $raw): ?float
    {
        $text = mb_strtolower($raw, 'UTF-8');

        // "X mil" / "Xk"
        if (preg_match('~(\d+(?:[.,]\d{1,2})?)\s*(mil|k)\b~u', $text, $m)) {
            $t = self::tofloat($m[1]);
            return ($t === null) ? null : $t * 1000;
        }
        // "R$ X" ou "X reais"
        if (preg_match('~(?:r\$|rs)\s*([\d.,]+)~u', $text, $m) || preg_match('~([\d][\d.,]*)\s*(?:reais|real)\b~u', $text, $m)) {
            return self::tofloat($m[1]);
        }
        // número isolado
        if (preg_match('~\b(\d{1,3}(?:\.\d{3})*(?:,\d{1,2})?|\d+(?:,\d{1,2})?)\b~', $text, $m)) {
            return self::tofloat($m[1]);
        }
        return null;
    }

    private static function amountFromQuestion(string $text): ?float
    {
        return self::amount($text);
    }

    private static function tofloat(string $s): ?float
    {
        if (str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif (preg_match('~^\d{1,3}(\.\d{3})+$~', $s)) {
            $s = str_replace('.', '', $s); // 1.500 → 1500
        }
        if (!is_numeric($s)) {
            return null;
        }
        $v = (float) $s;
        return ($v > 0 && $v <= 99999999.99) ? round($v, 2) : null;
    }
}
