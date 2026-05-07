<?php
/**
 * Migração: Criar tabela de ligação cfg_user_subdivision e migrar dados.
 */
require_once __DIR__ . '/../config/conn.php';

echo "=== MIGRAÇÃO: Múltiplas Subdivisões por Usuário ===\n\n";

try {
    // 1. Criar tabela cfg_user_subdivision
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cfg_user_subdivision (
            id_user INT UNSIGNED NOT NULL,
            id_subdivision INT UNSIGNED NOT NULL,
            PRIMARY KEY (id_user, id_subdivision),
            INDEX idx_user (id_user),
            INDEX idx_sub (id_subdivision)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Tabela cfg_user_subdivision criada.\n";

    // 2. Migrar dados existentes de ctd_users.subdivision_id
    $stmt = $pdo->query("SELECT id, subdivision_id FROM ctd_users WHERE subdivision_id IS NOT NULL");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $migrated = 0;
    foreach ($users as $u) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM cfg_user_subdivision WHERE id_user = ? AND id_subdivision = ?");
        $check->execute([$u['id'], $u['subdivision_id']]);
        if ($check->fetchColumn() == 0) {
            $ins = $pdo->prepare("INSERT INTO cfg_user_subdivision (id_user, id_subdivision) VALUES (?, ?)");
            $ins->execute([$u['id'], $u['subdivision_id']]);
            $migrated++;
        }
    }
    echo "✅ $migrated registros migrados para a nova tabela.\n";

    echo "\n=== MIGRAÇÃO CONCLUÍDA COM SUCESSO ===\n\n";

} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
}
