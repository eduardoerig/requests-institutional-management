<?php
require_once __DIR__ . '/../classes/RequestManager.php';
require_once __DIR__ . '/../components/request_card.php';

$role = $_SESSION['role'] ?? 'solicitante';
$user_id = $_SESSION['id'] ?? 0;

$isAdmin = isGlobalAdminRole($role);
$isAdmSub = ($role === 'adm_sub');
$isGestor = isGestorRole($role);
$canApprove = $isAdmin || $isAdmSub; // Admin geral aprova tudo; coordenação aprova a própria subdivisão

if (!$canApprove) {
    echo '<div class="panel"><div class="empty-big"><i class="fa-solid fa-lock"></i><h2>Sem acesso</h2><p>Somente a coordenação e os administradores aprovam pedidos.</p><a class="btn" href="home"><i class="fa-solid fa-arrow-left"></i> Voltar ao início</a></div></div>';
    return;
}

$managingId = null; // Admin vê tudo para aprovação
$subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;

$data = RequestManager::getRequests($pdo, null, $managingId, 'P', null, null, 'DESC', [], null, null, $subdivisionFilter);
$requests = $data['requests'] ?? [];
$totalPendentes = count($requests);

$map = [
    'mkt' => 'gray',
    'xerox' => 'blue',
    'shop' => 'green',
    'service' => 'yellow',
    'ti' => 'red',
];

$sectorNames = [
    'mkt' => 'MKT',
    'xerox' => 'Reprografia',
    'shop' => 'Compras',
    'service' => 'Manutenção',
    'ti' => 'TI',
];

// Pegar setores permitidos para os botões de rádio
$allowedSectors = [];
if ($user_id) {
    $stmtSectors = $pdo->prepare("SELECT LOWER(a.title) FROM ctd_area a JOIN cfg_user_area cua ON a.id = cua.id_area WHERE cua.id_user = ?");
    $stmtSectors->execute([$user_id]);
    $allowedSectors = $stmtSectors->fetchAll(PDO::FETCH_COLUMN);
}
if (!is_array($allowedSectors)) $allowedSectors = [];

// Filtro de setor: só setores que aparecem na fila (e, para setores, só os que a pessoa atende)
$queueSectors = [];
$hasForwardRows = false;
foreach ($requests as $r) {
    $slug = str_replace(['ctd_', '_frm'], '', strtolower((string)($r['table'] ?? '')));
    if (strtoupper((string)($r['st_raw'] ?? $r['status'] ?? '')) === 'F' || !empty($r['is_forwarded_to_me'])) {
        $hasForwardRows = true;
    }
    if ($slug !== '' && (empty($allowedSectors) || $isAdmin || $isAdmSub || in_array($slug, $allowedSectors, true))) {
        $queueSectors[$slug] = requestSectorName($slug);
    }
}
ksort($queueSectors);

$queue = [
    'title' => 'Aprovar pedidos',
    'subtitle' => $totalPendentes === 0 ? 'Nenhum pedido esperando sua decisão.' : ($totalPendentes === 1 ? '1 pedido esperando sua decisão.' : $totalPendentes . ' pedidos esperando sua decisão.'),
    'empty' => 'Tudo em dia: nenhum pedido aguardando aprovação.',
    'panelHint' => 'Clique em um pedido para ver os detalhes e aprovar ou recusar.',
    'sectors' => $queueSectors,
    'forwardFilter' => false,
    'actions' => '<button type="submit" class="btn btn--success" value="Y"><i class="fa-solid fa-check" aria-hidden="true"></i> Aprovar</button>'
               . '<button type="submit" class="btn btn--danger" value="N"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Recusar</button>',
    'forwardActions' => false,
];

include __DIR__ . '/../components/queue_view.php';
