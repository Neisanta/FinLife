<?php
/**
 * FinLife — API REST de categorias.
 *
 * GET    /api/categories.php
 * POST   {name, type, icon, color}
 * PUT    {id, name, icon, color}
 * DELETE ?id=
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
        $cats = Category::list($userId);
        foreach ($cats['income'] as &$c) { $c['usage_count'] = (int) $c['usage_count']; }
        foreach ($cats['expense'] as &$c) { $c['usage_count'] = (int) $c['usage_count']; }
        json_out(['ok' => true, 'categories' => $cats]);
    }

    if ($method === 'POST') {
        csrf_guard();
        $body = json_input();
        if ($body === null) {
            json_out(['ok' => false, 'error' => 'Corpo JSON inválido.'], 400);
        }
        if (Validator::text($body['name'] ?? '', 60) === '') {
            json_out(['ok' => false, 'error' => 'Informe o nome da categoria.'], 422);
        }
        try {
            $id = Category::create($userId, $body);
            json_out(['ok' => true, 'id' => $id], 201);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                json_out(['ok' => false, 'error' => 'Já existe uma categoria com esse nome para este tipo.'], 422);
            }
            throw $e;
        }
    }

    if ($method === 'PUT') {
        csrf_guard();
        $body = json_input();
        $id = Validator::positiveInt($body['id'] ?? null);
        if ($id === null) {
            json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
        }
        if (Category::update($userId, $id, $body ?? [])) {
            json_out(['ok' => true, 'id' => $id]);
        }
        json_out(['ok' => false, 'error' => 'Categoria não encontrada.'], 404);
    }

    if ($method === 'DELETE') {
        csrf_guard();
        $id = Validator::positiveInt($_GET['id'] ?? null);
        if ($id === null) {
            json_out(['ok' => false, 'error' => 'ID inválido.'], 400);
        }
        $result = Category::delete($userId, $id);
        if ($result === false) {
            json_out(['ok' => false, 'error' => 'Categoria não encontrada.'], 404);
        }
        if (is_array($result) && isset($result['error'])) {
            json_out(['ok' => false, 'error' => $result['error']], 422);
        }
        json_out(['ok' => true]);
    }

    json_out(['ok' => false, 'error' => 'Método não permitido.'], 405);
} catch (Throwable $e) {
    log_error('API categories: erro', ['msg' => $e->getMessage()]);
    json_out(['ok' => false, 'error' => 'Erro interno. Tente novamente.'], 500);
}
