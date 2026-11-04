<?php
/**
 * Criar setor pela tela está desligado: cada setor precisa de formulário, tabela (ctd_<setor>_frm),
 * perfil e caixa próprios no código. Um setor criado só no banco não aparecia no Encaminhar nem na
 * Nova requisição (decisão de 2026-11-04). Os 5 setores continuam editáveis em Usuários e setores.
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);
requirePostJson();

if (!isGlobalAdminRole($_SESSION['role'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Acesso negado.'], 403);
}

jsonResponse(['success' => false, 'message' => 'Setores novos precisam de formulário e caixa próprios. Peça ao desenvolvedor para criar o setor.']);
