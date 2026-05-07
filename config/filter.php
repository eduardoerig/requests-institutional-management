<?php
session_start();
require_once 'conn.php';
require_once __DIR__ . '/../classes/RequestManager.php';

if (isset($_POST['action']) && $_POST['action'] === 'post') {
    $table = $_POST['table'] ?? 'all';
    $text = $_POST['text'] ?? null;
    $order = $_POST['value'] ?? 'DESC';
    $path = $_POST['path'] ?? 'home';

    $status = null;
    $userId = null;

    // Mapeamento de status baseado na página
    if (strpos($path, 'approve') !== false && strpos($path, 'approved') === false) {
        $status = 'P';
    } elseif (strpos($path, 'approved') !== false) {
        $status = 'Y';
    } elseif (strpos($path, 'inWork') !== false) {
        $status = 'W';
    } elseif (strpos($path, 'myRequests') !== false) {
        $userId = $_SESSION['id'];
    }

    $role = $_SESSION['role'] ?? 'solicitante';
    $current_user_id = $_SESSION['id'] ?? 0;
    $isAdmin = in_array($role, ['admin', 'adm', 'coord']);
    $isAdmSub = ($role === 'adm_sub');
    
    $subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;
    
    // Se for gestor, filtra pelos seus setores. Se for admin ou adm_sub, vê todos (null).
    $managingId = ($isAdmin || $isAdmSub) ? null : $current_user_id;

    $data = RequestManager::getRequests($pdo, $userId, $managingId, $status, $table, $text, $order, [], null, null, $subdivisionFilter);
    $requests = $data['requests'] ?? [];

    $map = [
        'mkt' => 'gray',
        'xerox' => 'blue',
        'shop' => 'green',
        'service' => 'yellow',
        'ti' => 'red',
    ];

    $sectorNames = [
        'mkt' => 'MKT', 'xerox' => 'Xerox', 'shop' => 'Compras',
        'service' => 'Manutenção', 'ti' => 'TI',
    ];

    $html = '';
    if (count($requests) > 0) {
        foreach ($requests as $row) {
            $urgentMark = !empty($row['urgent']) ? '<span class="req_alert">!</span>' : '';
            $cardClass = isset($map[$row['table']]) ? $map[$row['table']] : 'gray';
            $sectorBadge = '<span class="card-sector-tag">' . ($sectorNames[$row['table']] ?? '') . '</span>';
            
            // Timer visual para "Em Andamento"
            $timerHtml = '';
            if ($status === 'W') {
                $createdAt = strtotime($row['created_at'] ?? 'now');
                $elapsed = time() - $createdAt;
                $days = floor($elapsed / 86400);
                $hours = floor(($elapsed % 86400) / 3600);
                $timeLabel = $days > 0 ? $days . 'd ' . $hours . 'h' : $hours . 'h';
                $timeClass = ($elapsed > (3 * 86400)) ? 'timer-late' : 'timer-ok';
                $timerHtml = '<span class="card-timer ' . $timeClass . '"><i class="fa-solid fa-stopwatch"></i> ' . $timeLabel . '</span>';
            }

            // Indicador de status para aprovadas
            $statusIcon = '';
            if ($status === 'Y') {
                $statusIcon = '<span class="req_confirmed"><i class="fa-solid fa-check-circle"></i></span>';
            }
            
            $html .= '
                <div class="card_req ' . $cardClass . '" data-id="' . $row['id'] . '" data-table="' . $row['table'] . '">
                    <div class="card_header">
                        <span class="req_nome">Requisição #' . $row['id'] . '</span>
                        <div style="display:flex; gap:6px; align-items:center;">' . $timerHtml . $statusIcon . $urgentMark . '</div>
                    </div>
                    <div class="card_title">' . htmlspecialchars($row['title']) . '</div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px;">
                        <div class="card_date">Entrega: ' . ($row['date'] ?? '—') . '</div>
                        ' . $sectorBadge . '
                    </div>
                </div>
            ';
        }
    } else {
        $html = '<div class="empty-state"><i class="fa-solid fa-inbox"></i><p>Nenhuma requisição encontrada.</p></div>';
    }

    echo json_encode(['success' => true, 'message' => 'Filtro aplicado!', 'data' => $html]);
    exit();
} else {
    echo json_encode(['success' => false, 'message' => 'Requisição inválida!']);
    exit();
}
