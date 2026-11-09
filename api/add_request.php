<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../classes/RequestManager.php';
require_once __DIR__ . '/../classes/RequestForwardService.php';
requireLoginJson($pdo);
requirePostJson();

// A área do pedido define quem aprova. Quem responde por mais de uma escolhe no formulário.
$userAreas = userSubdivisionIds();
$subdivision_id = $userAreas[0] ?? null;
$role = $_SESSION['role'] ?? 'solicitante';
if (in_array($role, ['solicitante', 'adm_sub'], true) && empty($subdivision_id)) {
    jsonResponse(['success' => false, 'message' => 'Seu usuário ainda não está ligado a uma subdivisão. Fale com o administrador.']);
}
if (count($userAreas) > 1) {
    $subdivision_id = (int)($_POST['subdivision_id'] ?? 0);
    if (!in_array($subdivision_id, $userAreas, true)) {
        jsonResponse(['success' => false, 'message' => 'Escolha de qual área é este pedido.']);
    }
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

/**
 * Texto longo (descrição, observações): passou do limite, recusa com o tamanho em vez de cortar
 * calado o fim do que a pessoa escreveu.
 */
function postLongText(string $key, int $maxLength, string $what): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    $len = mb_strlen($value);
    if ($len > $maxLength) {
        fail(sprintf('%s pode ter até %s letras (hoje tem %s). Encurte o texto e envie de novo.',
            $what, number_format($maxLength, 0, ',', '.'), number_format($len, 0, ',', '.')));
    }
    return $value;
}

// Tipo do formulário => setor (tabela) e rótulo amigável (config/sectors.php)
$types = [];
foreach (sectorRegistry() as $slug => $s) {
    $types[$s['form_type']] = ['table' => $slug, 'label' => $s['label']];
}

$typeKey = (string)($_POST['type'] ?? '');
if (!isset($types[$typeKey])) {
    fail('Tipo de pedido inválido. Volte e escolha o setor novamente.');
}
$sector = $types[$typeKey]['table'];
$sectorLabel = $types[$typeKey]['label'];

// ---- Campos comuns ----
$title = postText('title', 255);
$end = postText('end', 10);
$obs = postLongText('obs', 2000, 'O campo de observações');
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
    if ($urgentReason === '') {
        fail('Conte por que o pedido é urgente.');
    }
    if ($urgentReason !== '') {
        $obs = 'Urgente: ' . $urgentReason . ($obs !== '' ? "\n" . $obs : '');
        // Reprografia guarda as observações em varchar(2000); nas outras tabelas a coluna é text
        if ($sector === 'xerox' && mb_strlen($obs) > 2000) {
            fail('O motivo da urgência e as observações juntos passam de 2.000 letras. Encurte as observações.');
        }
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

// Sugestão de setores parceiros (opcional): a coordenação confirma, troca ou tira ao aprovar
$partnerAreaIds = array_values(array_filter([(int)($_POST['partner_area_id'] ?? 0), (int)($_POST['partner2_area_id'] ?? 0)]));
foreach ($partnerAreaIds as $partnerAreaId) {
    if ($partnerAreaId < 0 || !RequestForwardService::canSuggestPartner($pdo, $sector, $partnerAreaId)) {
        fail('Escolha como parceiro um setor diferente do que vai atender o pedido.');
    }
}
if (count($partnerAreaIds) !== count(array_unique($partnerAreaIds))) {
    fail('Escolha dois setores parceiros diferentes.');
}

// ---- Campos específicos por setor ----
switch ($sector) {
    case 'service':
    case 'ti':
    case 'shop':
        $details = postLongText('details', 5000, 'A descrição');
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
        if ($qtdRaw === '') {
            fail('Informe quantas cópias você precisa.');
        }
        if (!ctype_digit($qtdRaw)) {
            fail('Use um número inteiro de cópias, sem vírgula nem ponto (ex.: 30).');
        }
        if ((int)$qtdRaw < 1) {
            fail('O número de cópias precisa ser maior que zero.');
        }
        if ((int)$qtdRaw > 100000) {
            fail('O máximo é 100.000 cópias por pedido.');
        }
        if ($model === '') {
            fail('Escolha o formato da impressão.');
        }
        $sql = 'INSERT INTO ctd_xerox_frm (created_by, created_at, title, `date`, arquive, qtd, `type`, obs, priority, urgent, subdivision_id) VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $params = [$_SESSION['id'], $title, $end, $file, (int)$qtdRaw, $model, $obs, $priority, $urgent, $subdivision_id];
        break;

    case 'mkt':
        // Aceita 'details' (padrão) e 'descp' (nome antigo do campo)
        $details = postLongText('details', 5000, 'A descrição') ?: postLongText('descp', 5000, 'A descrição');
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
    RequestManager::addHistory($pdo, $newId, $sector, $userId, $userName, 'Requisição criada', null, 'P', 'created');
    foreach ($partnerAreaIds as $partnerAreaId) {
        RequestForwardService::saveSuggestion($pdo, $sector, $newId, $partnerAreaId, $userId, $userName);
    }
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
