<?php
/**
 * FinLife — Model de conversas com o FinLife AI.
 * No MVP cada usuário tem uma conversa contínua; a tabela
 * suporta múltiplas conversas no futuro.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class AiChat
{
    /** Retorna a conversa ativa do usuário (cria se não existir). */
    public static function conversationId(int $userId): int
    {
        $row = Database::run(
            'SELECT id FROM ai_conversations WHERE user_id = :uid ORDER BY updated_at DESC, id DESC LIMIT 1',
            ['uid' => $userId]
        )->fetch();
        if ($row) {
            return (int) $row['id'];
        }
        Database::run(
            'INSERT INTO ai_conversations (user_id, title) VALUES (:uid, "Assistente FinLife")',
            ['uid' => $userId]
        );
        return Database::lastId();
    }

    public static function addMessage(int $userId, int $conversationId, string $role, string $content, ?array $meta = null): void
    {
        Database::run(
            'INSERT INTO ai_messages (conversation_id, user_id, role, content, meta)
             VALUES (:cid, :uid, :role, :content, :meta)',
            [
                'cid'   => $conversationId,
                'uid'   => $userId,
                'role'  => Validator::in($role, ['user', 'assistant']) ?? 'assistant',
                'content' => Validator::text($content, 8000),
                'meta'  => ($meta === null) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]
        );
        Database::run('UPDATE ai_conversations SET updated_at = NOW() WHERE id = :cid', ['cid' => $conversationId]);
    }

    /** Histórico (mais recentes por último). */
    public static function history(int $userId, int $conversationId, int $limit = 30): array
    {
        $rows = Database::run(
            'SELECT role, content, created_at FROM ai_messages
             WHERE conversation_id = :cid AND user_id = :uid
             ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)),
            ['cid' => $conversationId, 'uid' => $userId]
        )->fetchAll();
        return array_reverse($rows);
    }

    /** Apaga toda a conversa do usuário. */
    public static function clear(int $userId): void
    {
        Database::run('DELETE FROM ai_conversations WHERE user_id = :uid', ['uid' => $userId]);
    }
}
