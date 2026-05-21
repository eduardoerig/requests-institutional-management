<?php
/**
 * API de Integração WhatsApp → SisReq
 * 
 * Autenticação: Header  X-Bot-Key: <chave_configurada_em_conn.local.php>
 * Método: POST
 * Content-Type: application/json
 * 
 * Identificação do usuário: phone (E.164 sem +, ex: 5511999998888)
 * Fallback: phone + name (nome parcial)
 */

require_once dirname(__DIR__) . '/config/conn.php';

header('Content-Type: application/json; charset=utf-8');

// =========================================
// 1. Verificar API Key
// =========================================
$localConfig = require dirname(__DIR__) . '/config/conn.local.php';
$validKey    = $localConfig['bot_key'] ?? '';

$receivedKey = $_SERVER['HTTP_X_BOT_KEY'] ?? '';

if (empty($validKey) || !hash_equals($validKey, $receivedKey)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'code' => 'UNAUTHORIZED', 'message' => 'Chave de autenticação inválida ou ausente.']);
    exit;
}

// =========================================
// 2. Ler corpo JSON
// =========================================
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (empty($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'INVALID_BODY', 'message' => 'Corpo da requisição inválido ou vazio.']);
    exit;
}

$phone   = preg_replace('/\D/', '', trim($body['phone'] ?? ''));
$name    = trim($body['name'] ?? '');
$type    = strtolower(trim($body['type'] ?? ''));
$title   = trim($body['title'] ?? '');
$details = trim($body['details'] ?? '');
$end     = trim($body['end'] ?? '');
$urgent  = strtolower(trim($body['urgent'] ?? 'nao')) === 'sim' ? 'sim' : '';
$obs     = trim($body['obs'] ?? '');
$priority = max(1, min(4, (int)($body['priority'] ?? 2)));

// =========================================
// 3. Validações básicas
// =========================================
if (empty($phone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'MISSING_PHONE', 'message' => 'O campo "phone" é obrigatório.']);
    exit;
}

$allowedTypes = ['ti', 'shop', 'service', 'xerox', 'mkt'];
if (!in_array($type, $allowedTypes, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'code'    => 'INVALID_TYPE',
        'message' => 'Tipo de requisição inválido. Use: ' . implode(', ', $allowedTypes)
    ]);
    exit;
}

if (empty($title) || empty($details) || empty($end)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'MISSING_FIELDS', 'message' => 'Os campos "title", "details" e "end" são obrigatórios.']);
    exit;
}

// Validar formato da data
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'code' => 'INVALID_DATE', 'message' => 'O campo "end" deve estar no formato YYYY-MM-DD.']);
    exit;
}

// =========================================
// 4. Identificar usuário por telefone
// =========================================
$userStmt = $pdo->prepare("SELECT id, name, subdivision_id, role FROM ctd_users WHERE phone = ? AND status = 1 LIMIT 1");
$userStmt->execute([$phone]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // Fallback: telefone + nome parcial (busca tolerante)
    if (!empty($name)) {
        $likeStmt = $pdo->prepare("SELECT id, name, subdivision_id, role FROM ctd_users WHERE phone = ? AND status = 1 LIMIT 1");
        $likeStmt->execute([$phone]);
        $user = $likeStmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'code'    => 'USER_NOT_FOUND',
            'message' => 'Nenhuma conta ativa encontrada com este número de telefone. Cadastre o número na tela de contas do sistema.'
        ]);
        exit;
    }
}

$userId        = (int)$user['id'];
$subdivisionId = $user['subdivision_id'] ? (int)$user['subdivision_id'] : null;

// =========================================
// 5. Inserir requisição na tabela correta
// =========================================
try {
    $requestId = null;

    switch ($type) {
        case 'ti':
            $stmt = $pdo->prepare(
                "INSERT INTO ctd_ti_frm (created_by, created_at, title, `date`, descp, urgent, obs, priority, subdivision_id)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $title, $end, $details, $urgent, $obs, $priority, $subdivisionId]);
            $requestId = $pdo->lastInsertId();
            break;

        case 'shop':
            $stmt = $pdo->prepare(
                "INSERT INTO ctd_shop_frm (created_by, created_at, title, `date`, descp, obs, priority, subdivision_id)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $title, $end, $details, $obs, $priority, $subdivisionId]);
            $requestId = $pdo->lastInsertId();
            break;

        case 'service':
            $stmt = $pdo->prepare(
                "INSERT INTO ctd_service_frm (created_by, created_at, title, `date`, descp, urgent, obs, priority, subdivision_id)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $title, $end, $details, $urgent, $obs, $priority, $subdivisionId]);
            $requestId = $pdo->lastInsertId();
            break;

        case 'xerox':
            $qtd  = (int)($body['qtd'] ?? 1);
            $model = trim($body['model'] ?? 'colorida');
            $file  = trim($body['file'] ?? '');
            $stmt = $pdo->prepare(
                "INSERT INTO ctd_xerox_frm (created_by, created_at, title, `date`, arquive, qtd, `type`, obs, priority, subdivision_id)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $title, $end, $file, $qtd, $model, $obs, $priority, $subdivisionId]);
            $requestId = $pdo->lastInsertId();
            break;

        case 'mkt':
            $model = trim($body['model'] ?? $body['outros_texto'] ?? '');
            $stmt = $pdo->prepare(
                "INSERT INTO ctd_mkt_frm (created_by, created_at, title, `date`, descp, `type`, obs, priority, subdivision_id)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $title, $end, $details, $model, $obs, $priority, $subdivisionId]);
            $requestId = $pdo->lastInsertId();
            break;
    }

    // Mapeamento de nomes amigáveis para resposta
    $typeLabels = [
        'ti'      => 'TI',
        'shop'    => 'Compras',
        'service' => 'Manutenção',
        'xerox'   => 'Reprografia',
        'mkt'     => 'Marketing',
    ];

    $label = $typeLabels[$type] ?? strtoupper($type);

    echo json_encode([
        'success'    => true,
        'request_id' => (int)$requestId,
        'message'    => "Requisição {$label} #{$requestId} criada com sucesso para {$user['name']}!",
        'user'       => [
            'id'   => $userId,
            'name' => $user['name'],
        ],
    ]);

} catch (Exception $e) {
    error_log('whatsapp_bot.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'code' => 'SERVER_ERROR', 'message' => 'Erro interno ao criar a requisição.']);
}
