<?php
/**
 * FinLife — API do dashboard.
 *
 * GET  ?month=YYYY-MM → cards, categorias, evolução, metas, recentes
 * POST {action:'set_budget', amount} → define orçamento mensal (null limpa)
 */
declare(strict_types=1);

define('FINLIFE', true);
require __DIR__ . '/../includes/bootstrap.php';

header('Cache-Control: no-store');
$user = Auth::user();
if ($user === null) {
    json_out(['ok' => false, 'error' => 'Não autenticado.'], 401);
}
$userId = (int) $user['id'];

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $month = preg_match('~^\d{4}-\d{2}$~', $_GET['month'] ?? '') ? $_GET['month'] : month_now();
        $snap = ContextBuilder::snapshot($userId, $month);

        $byCategory = Transaction::byCategory($userId, $month, 'expense');
        $incomeByCategory = Transaction::byCategory($userId, $month, 'income');

        $goals = array_map(static function ($g) {
            $g['target_amount'] = (float) $g['target_amount'];
            return $g;
        }, Goal::list($userId));

        $recent = array_map(static function ($t) {
            $t['amount'] = (float) $t['amount'];
            return $t;
        }, Transaction::recent($userId, 8));

        $aiConfigured = (new AIService())->isConfigured();

        json_out([
            'ok' => true,
            'month' => $month,
            'month_label' => ContextBuilder::monthLabel($month),
            'cards' => [
                'balance'      => $snap['balance'],
                'income'       => $snap['income'],
                'expense'      => $snap['expense'],
                'net'          => $snap['net'],
                'savings_rate' => $snap['savings_rate'],
                'budget'       => $snap['budget'],
                'budget_used_pct' => $snap['budget_used_pct'],
                'prev_income'  => $snap['prev_month']['income'],
                'prev_expense' => $snap['prev_month']['expense'],
            ],
            'expenses_by_category' => array_map(static function ($c) {
                $c['total'] = (float) $c['total'];
                $c['pct'] = (float) $c['pct'];
                return $c;
            }, $byCategory),
            'income_by_category' => array_map(static function ($c) {
                $c['total'] = (float) $c['total'];
                $c['pct'] = (float) $c['pct'];
                return $c;
            }, $incomeByCategory),
            'evolution' => $snap['evolution'],
            'goals' => $goals,
            'commitments' => [
                'summary' => Commitment::summary($userId),
                'due_soon' => Commitment::dueSoon($userId, 7),
            ],
            'recent_transactions' => $recent,
            'accounts' => $snap['accounts'],
            'ai' => ['configured' => $aiConfigured],
        ]);
    }

    if ($method === 'POST') {
        csrf_guard();
        $body = json_input();
        $action = $body['action'] ?? '';

        if ($action === 'set_budget') {
            $amount = Validator::amount($body['amount'] ?? null);
            if (($body['amount'] ?? null) === null || (string) $body['amount'] === '') {
                $amount = null; // limpa o orçamento
            }
            User::setMonthlyBudget($userId, $amount);
            json_out(['ok' => true, 'budget' => $amount]);
        }

        json_out(['ok' => false, 'error' => 'Ação inválida.'], 400);
    }

    json_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
} catch (Throwable $e) {
    log_error('API dashboard: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro interno. Tente novamente.'], 500);
}
