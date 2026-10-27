<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
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
    fail('Tipo de pedido inválido. Volte e escolha o setor novamente.');
}
$sector = $types[$typeKey]['table'];
$sectorLabel = $types[$typeKey]['label'];

// ---- Campos comuns ----
$title = postText('title', 255);
$end = postText('end', 10);
$obs = postText('obs', 2000);
// Quem pede não escolhe o nível: nasce Normal (ou Alta se for urgente) e o setor ajusta depois
$priority = 2;
$urgentReason = '';

if ($title === '') {
    fail('Escreva um título curto para o seu pedido.');
}
// "É urgente" vira prioridade alta; o motivo vai no começo das observações
if (!empty($_POST['urgent'])) {
    $priority = 3;
    $urgentReason = postText('urgent_reason', 255);
    if ($urgentReason === '' && isset($_POST['urgent_reason'])) {
        fail('Conte por que o pedido é urgente.');
    }
    if ($urgentReason !== '') {
        $obs = mb_substr('Urgente: ' . $urgentReason . ($obs !== '' ? "\n" . $obs : ''), 0, 2000);
    }
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
        if ($file === '') {
            fail('Informe onde está o arquivo para imprimir.');
        }
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
        if ($details === '') {
            fail('Explique o que você precisa.');
        }
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
        fail('Tipo de pedido inválido.');
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
    error_log('add_request.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Não foi possível enviar agora. Tente de novo em instantes.'], 500);
}

// ---- Avisar quem aprova: administradores gerais + administradores da subdivisão ----
// O pedido já foi gravado: falha nos avisos vai para o log e não vira erro na tela (evita reenvio duplicado)
$link = "request_detail?id=$newId&table=$sector";
try {
    $approverIds = array_column(RequestManager::getAdmins($pdo), 'id');
    if ($subdivision_id) {
        $approverIds = array_merge($approverIds, array_column(RequestManager::getSubdivisionAdmins($pdo, (int)$subdivision_id), 'id'));
    }
    RequestManager::notifyMany(
        $pdo,
        array_values(array_unique(array_map('intval', $approverIds))),
        ($urgent ? 'Urgente: pedido' : 'Pedido') . " nº $newId para aprovar",
        "$userName pediu: \"$title\" ($sectorLabel)." . ($urgentReason !== '' ? " Motivo da urgência: $urgentReason" : ''),
        $link,
        $userId
    );
} catch (Throwable $e) {
    error_log("add_request.php: avisos do pedido $sector/$newId: " . $e->getMessage());
}

jsonResponse([
    'success' => true,
    'message' => "Pedido nº $newId enviado! Agora ele aguarda aprovação.",
    'id' => $newId,
    'table' => $sector,
    'link' => $link,
]);
