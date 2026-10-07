<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../classes/RequestManager.php';
requireLoginJson($pdo);
requirePostJson();

$subdivision_id = $_SESSION['subdivision_id'] ?? null;
$role = $_SESSION['role'] ?? 'solicitante';
if (in_array($role, ['solicitante', 'adm_sub'], true) && empty($subdivision_id)) {
    jsonResponse(['success' => false, 'message' => 'Seu usuário ainda não está ligado a uma subdivisão. Fale com o administrador.']);
}

/**
 * Lê um campo de texto do POST, aparando espaços e limitando o tamanho.
 */
function postText(string $key, int $maxLength = 5000): string
{
    return mb_substr(trim((string)($_POST[$key] ?? '')), 0, $maxLength);
}

function fail(string $message): void
{
    jsonResponse(['success' => false, 'message' => $message]);
}

// Tipo do formulário => setor (tabela) e rótulo amigável
$types = [
    'service'    => ['table' => 'service', 'label' => 'Manutenção'],
    'shopping'   => ['table' => 'shop',    'label' => 'Compras'],
    'colorPrint' => ['table' => 'xerox',   'label' => 'Reprografia'],
    'mkt'        => ['table' => 'mkt',     'label' => 'Marketing'],
    'ti'         => ['table' => 'ti',      'label' => 'TI'],
];

$typeKey = (string)($_POST['type'] ?? '');
if (!isset($types[$typeKey])) {
    fail('Tipo de requisição inválido. Volte e escolha o setor novamente.');
}
$sector = $types[$typeKey]['table'];
$sectorLabel = $types[$typeKey]['label'];

// ---- Campos comuns ----
$title = postText('title', 255);
$end = postText('end', 10);
$obs = postText('obs', 2000);
$priority = (int)($_POST['priority'] ?? 2);

if ($title === '') {
    fail('Escreva um título curto para a sua requisição.');
}
if (!in_array($priority, [1, 2, 3, 4], true)) {
    $priority = 2;
}
// Marcação legada de "urgente" vira prioridade alta
if (!empty($_POST['urgent']) && $priority < 3) {
    $priority = 3;
}
$urgent = $priority >= 3 ? 1 : 0;

$endDate = DateTime::createFromFormat('!Y-m-d', $end);
if ($end === '' || !$endDate || $endDate->format('Y-m-d') !== $end) {
    fail('Informe a data em que você precisa receber.');
}
if ($endDate < new DateTime('today')) {
    fail('A data de entrega não pode ser no passado.');
}

// ---- Campos específicos por setor ----
switch ($sector) {
    case 'service':
    case 'ti':
    case 'shop':
        $details = postText('details');
        if ($details === '') {
            fail('Descreva o que você precisa.');
        }
        if ($sector === 'shop') {
            $sql = 'INSERT INTO ctd_shop_frm (created_by, created_at, title, `date`, descp, obs, priority, urgent, subdivision_id) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)';
        } else {
            $sql = "INSERT INTO ctd_{$sector}_frm (created_by, created_at, title, `date`, descp, obs, priority, urgent, subdivision_id) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)";
        }
        $params = [$_SESSION['id'], $title, $end, $details, $obs, $priority, $urgent, $subdivision_id];
        break;

    case 'xerox':
        $file = postText('file', 255);
        $qtdRaw = trim((string)($_POST['qtd'] ?? ''));
        $model = postText('model', 50);
        if ($qtdRaw === '' || !ctype_digit($qtdRaw) || (int)$qtdRaw < 1 || (int)$qtdRaw > 100000) {
            fail('Informe quantas cópias você precisa (número maior que zero).');
        }
        if ($model === '') {
            fail('Escolha o formato da impressão.');
        }
        $sql = 'INSERT INTO ctd_xerox_frm (created_by, created_at, title, `date`, arquive, qtd, `type`, obs, priority, urgent, subdivision_id) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $params = [$_SESSION['id'], $title, $end, $file, (int)$qtdRaw, $model, $obs, $priority, $urgent, $subdivision_id];
        break;

    case 'mkt':
        // Aceita 'details' (padrão) e 'descp' (nome antigo do campo)
        $details = postText('details') ?: postText('descp');
        $model = postText('model', 255);
        if ($model === '' || $model === 'outros') {
            $model = postText('outros_texto', 255);
        }
        if ($model === '') {
            fail('Escolha onde a peça será usada (ou descreva em "Outros").');
        }
        $sql = 'INSERT INTO ctd_mkt_frm (created_by, created_at, title, `date`, descp, `type`, obs, priority, urgent, subdivision_id) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)';
        $params = [$_SESSION['id'], $title, $end, $details, $model, $obs, $priority, $urgent, $subdivision_id];
        break;

    default:
        fail('Tipo de requisição inválido.');
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $newId = (int)$pdo->lastInsertId();

    $userId = (int)$_SESSION['id'];
    $userName = $_SESSION['name'] ?? 'Usuário';
    RequestManager::addHistory($pdo, $newId, $sector, $userId, $userName, 'Requisição criada', null, 'Pendente');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('addRequest.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Não foi possível enviar agora. Tente de novo em instantes.'], 500);
}

// ---- Avisar quem aprova: administradores gerais + administradores da subdivisão ----
$link = "request_detail?id=$newId&table=$sector";
$approverIds = array_column(RequestManager::getAdmins($pdo), 'id');
if ($subdivision_id) {
    $approverIds = array_merge($approverIds, array_column(RequestManager::getSubdivisionAdmins($pdo, (int)$subdivision_id), 'id'));
}
RequestManager::notifyMany(
    $pdo,
    array_values(array_unique(array_map('intval', $approverIds))),
    'Nova requisição para aprovar',
    "📥 $userName abriu a requisição #$newId ($sectorLabel) — \"$title\".",
    $link,
    $userId
);

jsonResponse([
    'success' => true,
    'message' => "Requisição #$newId enviada! Agora ela aguarda aprovação.",
    'id' => $newId,
    'table' => $sector,
    'link' => $link,
]);
