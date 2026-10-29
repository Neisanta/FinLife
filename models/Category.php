<?php
/**
 * FinLife — Model de categorias.
 * Cada usuário tem seu próprio conjunto (criado no cadastro),
 * garantindo isolamento total dos dados.
 */
declare(strict_types=1);

if (!defined('FINLIFE')) {
    http_response_code(403);
    exit('Acesso direto proibido.');
}

final class Category
{
    public const TYPES = ['income', 'expense'];

    /** Categorias padrão criadas no cadastro. */
    private const DEFAULTS = [
        ['income', 'Salário',          '💼', '#10b981'],
        ['income', 'Freelance',        '🧑‍💻', '#22d3ee'],
        ['income', 'Comissões',        '🤝', '#a78bfa'],
        ['income', 'Rendimentos',      '📈', '#f59e0b'],
        ['income', 'Benefícios',       '🎁', '#34d399'],
        ['income', 'Outras receitas',  '➕', '#94a3b8'],
        ['expense', 'Alimentação',     '🍔', '#f97316'],
        ['expense', 'Transporte',      '🚗', '#3b82f6'],
        ['expense', 'Moradia',         '🏠', '#6366f1'],
        ['expense', 'Educação',        '📚', '#8b5cf6'],
        ['expense', 'Lazer',           '🎮', '#ec4899'],
        ['expense', 'Saúde',           '💊', '#ef4444'],
        ['expense', 'Assinaturas',     '📺', '#14b8a6'],
        ['expense', 'Compras',         '🛍️', '#f43f5e'],
        ['expense', 'Contas',          '🧾', '#64748b'],
        ['expense', 'Outras',          '✨', '#94a3b8'],
    ];

    public static function seedDefaults(int $userId): void
    {
        $sql = 'INSERT INTO categories (user_id, name, type, icon, color, is_system)
                VALUES (:uid, :name, :type, :icon, :color, 1)
                ON DUPLICATE KEY UPDATE name = VALUES(name)';
        $stmt = Database::conn()->prepare($sql);
        foreach (self::DEFAULTS as [$type, $name, $icon, $color]) {
            $stmt->execute([':uid' => $userId, ':name' => $name, ':type' => $type, ':icon' => $icon, ':color' => $color]);
        }
    }

    public static function create(int $userId, array $d): int
    {
        $name = Validator::text($d['name'], 60);
        $type = Validator::in($d['type'], self::TYPES) ?? 'expense';
        Database::run(
            'INSERT INTO categories (user_id, name, type, icon, color) VALUES (:uid, :name, :type, :icon, :color)',
            [
                'uid'   => $userId,
                'name'  => $name,
                'type'  => $type,
                'icon'  => Validator::text($d['icon'] ?? '', 16) ?: '✨',
                'color' => Validator::color($d['color'] ?? null) ?? self::pickColor($type),
            ]
        );
        return Database::lastId();
    }

    public static function update(int $userId, int $id, array $d): bool
    {
        $cat = self::get($userId, $id);
        if ($cat === null) {
            return false;
        }
        Database::run(
            'UPDATE categories SET name = :name, icon = :icon, color = :color WHERE id = :id AND user_id = :uid',
            [
                'id'    => $id,
                'uid'   => $userId,
                'name'  => Validator::text($d['name'] ?? $cat['name'], 60),
                'icon'  => Validator::text($d['icon'] ?? $cat['icon'], 16) ?: '✨',
                'color' => Validator::color($d['color'] ?? $cat['color']) ?? $cat['color'],
            ]
        );
        return true;
    }

    /** Exclui apenas se não houver transações vinculadas. */
    public static function delete(int $userId, int $id): bool|array
    {
        $cat = self::get($userId, $id);
        if ($cat === null) {
            return false;
        }
        $used = Transaction::countByCategory($userId, $id);
        if ($used > 0) {
            return ['error' => 'Esta categoria possui ' . $used . ' transação(ões) vinculada(s) e não pode ser excluída.'];
        }
        Database::run('DELETE FROM categories WHERE id = :id AND user_id = :uid', ['id' => $id, 'uid' => $userId]);
        return true;
    }

    public static function get(int $userId, int $id): ?array
    {
        $row = Database::run(
            'SELECT * FROM categories WHERE id = :id AND user_id = :uid LIMIT 1',
            ['id' => $id, 'uid' => $userId]
        )->fetch();
        return $row ?: null;
    }

    /** Lista agrupada por tipo. */
    public static function list(int $userId): array
    {
        $rows = Database::run(
            'SELECT c.*, 
                    (SELECT COUNT(*) FROM transactions t WHERE t.category_id = c.id AND t.user_id = c.user_id) AS usage_count
             FROM categories c
             WHERE c.user_id = :uid
             ORDER BY FIELD(c.type, "expense", "income"), c.name',
            ['uid' => $userId]
        )->fetchAll();
        $out = ['income' => [], 'expense' => []];
        foreach ($rows as $r) {
            $out[$r['type']][] = $r;
        }
        return $out;
    }

    /** Resolve o nome de categoria vindos da IA para um ID real. */
    public static function matchByName(int $userId, string $name, string $type): ?array
    {
        $name = normalize($name);
        if ($name === '') {
            return null;
        }
        $rows = Database::run(
            'SELECT * FROM categories WHERE user_id = :uid AND type = :type',
            ['uid' => $userId, 'type' => $type]
        )->fetchAll();

        // 1) igualdade exata (normalizada)
        foreach ($rows as $r) {
            if (normalize($r['name']) === $name) {
                return $r;
            }
        }
        // 2) contém
        foreach ($rows as $r) {
            $n = normalize($r['name']);
            if ($n !== '' && (str_contains($n, $name) || str_contains($name, $n))) {
                return $r;
            }
        }
        return null;
    }

    /** Categoria "Outras"/"Outras receitas" do usuário. */
    public static function fallbackFor(int $userId, string $type): ?array
    {
        $name = ($type === 'income') ? 'Outras receitas' : 'Outras';
        $row = Database::run(
            'SELECT * FROM categories WHERE user_id = :uid AND type = :type AND name = :name LIMIT 1',
            ['uid' => $userId, 'type' => $type, 'name' => $name]
        )->fetch();
        return $row ?: null;
    }

    private static function pickColor(string $type): string
    {
        return ($type === 'income') ? '#10b981' : '#f97316';
    }
}
