<?php
require_once __DIR__ . '/../classes/RequestManager.php';
require_once __DIR__ . '/../components/request_card.php';

$role = $_SESSION['role'] ?? 'solicitante';
$user_id = $_SESSION['id'] ?? 0;

$adminRoles = ['admin', 'adm', 'coord'];
$gestorRoles = ['gestor', 'ti', 'xerox', 'service', 'shop', 'mkt', 'marketing'];

$isAdmin = in_array($role, $adminRoles);
$isAdmSub = ($role === 'adm_sub');
$isGestor = in_array($role, $gestorRoles);
$canManage = $isAdmin || $isGestor || $isAdmSub;

if (!$canManage) {
    echo '<div class="panel"><div class="empty-big"><i class="fa-solid fa-lock"></i><h2>Sem acesso</h2><p>Esta tela é dos setores e da coordenação.</p><a class="btn" href="myRequests">Ir para Minhas requisições</a></div></div>';
    return;
}

$managingId = ($isAdmin || $isAdmSub) ? null : $user_id;
$subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;

// 1. Buscar requisições Aprovadas (Y)
$data = RequestManager::getRequests($pdo, null, $managingId, 'Y', null, null, 'DESC', [], null, null, $subdivisionFilter);
$requests = $data['requests'] ?? [];

// 2. Buscar requisições Repassadas PENDENTES ('pending')
require_once __DIR__ . '/../classes/RequestForwardService.php';

if ($isAdmin || $isAdmSub) {
    // Admin: Buscar todos os repasses pendentes e mapeá-los para o setor de destino
    $allForwards = RequestForwardService::getForwardsByDestination($pdo, 'all', null, null, $subdivisionFilter, 'pending');
    $requests = array_merge($requests, $allForwards);
} else {
    // Gestor: Buscar repasses pendentes direcionados a ele
    $pendingForwards = RequestForwardService::getPendingForwards($pdo, $user_id, 'pending');
    if ($pendingForwards['success'] && !empty($pendingForwards['data'])) {
        foreach ($pendingForwards['data'] as $fwd) {
            $rd = $fwd['request_data'] ?? [];
            if (empty($rd)) continue;
            
            $destSlug = strtolower($fwd['to_area_name'] ?? '');
            $requests[] = [
                'id' => $rd['id'],
                'title' => $rd['title'] ?? 'Sem título',
                'date' => $rd['date'] ?? '—',
                'created_at' => $rd['created_at'] ?? date('Y-m-d H:i:s'),
                'urgent' => $rd['urgent'] ?? 0,
                'priority' => $rd['priority'] ?? 2,
                'status' => 'F',
                'st_raw' => 'F',
                'table' => $fwd['request_table'],
                'sector' => $fwd['request_table'],
                'responsible_sector' => $destSlug,
                'solicitor_name' => $rd['solicitor_name'] ?? '—',
                'solicitor_name_formatted' => $rd['solicitor_name'] ?? '—',
                'subdivision_name' => $rd['subdivision_name'] ?? '',
                'subdivision_slug' => $rd['subdivision_slug'] ?? '',
                'is_forwarded_to_me' => true,
                'forward_from' => $fwd['from_area_label'] ?? '',
                'forward_id' => $fwd['id']
            ];
        }
    }
}

// =========================================
// DEDUPLICAÇÃO DE REQUISIÇÕES (Evitar A->B->A duplicado)
// =========================================
$uniqueRequests = [];
foreach ($requests as $req) {
    // Normalizar o nome da tabela (remover ctd_ e _frm) para garantir que as chaves batam sempre
    $cleanTable = str_replace(['ctd_', '_frm'], '', strtolower(trim($req['table'] ?? '')));
    $key = $cleanTable . '_' . trim($req['id'] ?? '');
    
    if (isset($uniqueRequests[$key])) {
        // Se a existente NÃO é repasse e a atual É repasse, sobrescrevemos
        // Isso garante que veremos a etiqueta "DE: SETOR" ao invés da versão original limpa
        if (!isset($uniqueRequests[$key]['is_forwarded_to_me']) && isset($req['is_forwarded_to_me'])) {
            $uniqueRequests[$key] = $req;
        }
    } else {
        $uniqueRequests[$key] = $req;
    }
}
$requests = array_values($uniqueRequests);

$totalAprovadas = count($requests);

$map = [
    'mkt' => 'gray',
    'xerox' => 'blue',
    'shop' => 'green',
    'service' => 'yellow',
    'ti' => 'red',
];

$sectorNames = [
    'mkt' => 'MKT', 'xerox' => 'Reprografia', 'shop' => 'Compras',
    'service' => 'Manutenção', 'ti' => 'TI',
];

$allowedSectors = [];
if (!$isAdmin && !$isAdmSub && $user_id) {
    $stmtSectors = $pdo->prepare("SELECT a.title FROM ctd_area a JOIN cfg_user_area cua ON a.id = cua.id_area WHERE cua.id_user = ?");
    $stmtSectors->execute([$user_id]);
    $allowedSectors = array_values(array_filter(array_map('areaTitleToSector', $stmtSectors->fetchAll(PDO::FETCH_COLUMN))));
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
    'title' => $isGestor ? 'Para atender' : 'Aprovadas',
    'subtitle' => $totalAprovadas === 0 ? 'Nenhum pedido aguardando atendimento.' : ($totalAprovadas === 1 ? '1 pedido aguardando atendimento.' : $totalAprovadas . ' pedidos aguardando atendimento.'),
    'empty' => 'Nenhum pedido aprovado esperando atendimento.',
    'panelHint' => $isGestor ? 'Clique em um pedido para ver os detalhes e começar o atendimento.' : 'Clique em um pedido para ver os detalhes.',
    'sectors' => $queueSectors,
    'forwardFilter' => $hasForwardRows,
    'actions' => $isGestor ? '<button type="submit" name="action_value" value="start_progress" class="btn btn--primary"><i class="fa-solid fa-play" aria-hidden="true"></i> Começar atendimento</button>' : '',
    'forwardActions' => $isGestor,
];

include __DIR__ . '/../components/queue_view.php';
