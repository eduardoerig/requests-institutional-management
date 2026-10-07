<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/security.php';
requireLoginJson($pdo);
require_once __DIR__ . '/../classes/RequestManager.php';

if (isset($_POST['action']) && $_POST['action'] === 'post') {
    $table = $_POST['table'] ?? 'all';
    $text = $_POST['text'] ?? null;
    $order = $_POST['value'] ?? 'DESC';
    $path = $_POST['path'] ?? 'home';
    $searchDate = (!empty($_POST['date'])) ? $_POST['date'] : null;

    $status_post = $_POST['status_filter'] ?? 'all';
    $status = null;
    $userId = null;

    // Se o status vindo do POST for específico, usamos ele. 
    // Caso contrário, usamos o padrão da página.
    if ($status_post !== 'all') {
        $status = $status_post;
    } else {
        if (strpos($path, 'approve') !== false && strpos($path, 'approved') === false) {
            $status = 'P';
        } elseif (strpos($path, 'approved') !== false) {
            $status = 'Y';
        } elseif (strpos($path, 'inWork') !== false) {
            $status = 'W'; // W e F serão buscados abaixo
        } elseif (strpos($path, 'myRequests') !== false) {
            $status = null;
        }
    }

    if (strpos($path, 'myRequests') !== false) {
        $userId = $_SESSION['id'];
    }

    $role = $_SESSION['role'] ?? 'solicitante';
    $current_user_id = $_SESSION['id'] ?? 0;
    $isAdmin = isGlobalAdminRole($role);
    $isAdmSub = ($role === 'adm_sub');
    
    $subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;
    $managingId = ($isAdmin || $isAdmSub) ? null : $current_user_id;

    // "Minhas Requisições" deve filtrar apenas pelo criador da solicitação.
    // Se mantivermos o escopo de gestor aqui, usuários sem setor vinculado
    // perdem visibilidade das próprias requisições ao aplicar filtros.
    if (strpos($path, 'myRequests') !== false) {
        $managingId = null;
    }

    // Lógica especial para inWork para trazer W e F simultaneamente se o status for o padrão
    if (strpos($path, 'inWork') !== false && ($status === 'W' || $status_post === 'all')) {
        require_once __DIR__ . '/../classes/RequestForwardService.php';
        $requestsW = [];
        $requestsF = [];

        if ($table === 'forwarded') {
            // 1. Mostrar APENAS requisições repassadas
            if ($isAdmin || $isAdmSub) {
                $requestsF = RequestForwardService::getForwardsByDestination($pdo, 'all', $text, $searchDate, $subdivisionFilter);
            } else {
                $pendingForwards = RequestForwardService::getPendingForwards($pdo, $current_user_id);
                if ($pendingForwards['success'] && !empty($pendingForwards['data'])) {
                    foreach ($pendingForwards['data'] as $fwd) {
                        $rd = $fwd['request_data'] ?? [];
                        if (empty($rd) || ($text && stripos($rd['title'], $text) === false) || ($searchDate && $rd['date'] !== $searchDate)) continue;
                        $requestsF[] = [
                            'id' => $rd['id'], 'title' => $rd['title'], 'date' => $rd['date'], 'created_at' => $rd['created_at'], 'urgent' => $rd['urgent'], 'priority' => $rd['priority'],
                            'status' => 'F', 'st_raw' => 'F', 
                            'table' => $fwd['request_table'], 
                            'sector' => $fwd['request_table'],
                            'responsible_sector' => strtolower($fwd['to_area_name'] ?? ''),
                            'solicitor_name' => $rd['solicitor_name'], 'is_forwarded_to_me' => true, 'forward_from' => $fwd['from_area_label'],
                        ];
                    }
                }
            }
        } elseif ($table !== 'all') {
            // 2. Filtro por um setor específico (Apenas W do setor, sem os repasses)
            $dataW = RequestManager::getRequests($pdo, $userId, $managingId, 'W', $table, $text, $order, [], $searchDate, $searchDate, $subdivisionFilter);
            $requestsW = $dataW['requests'] ?? [];
            // requestsF permanece vazio aqui
        } else {
            // 3. Filtro 'all' - Tudo (W + F)
            $dataW = RequestManager::getRequests($pdo, $userId, $managingId, 'W', 'all', $text, $order, [], $searchDate, $searchDate, $subdivisionFilter);
            $requestsW = $dataW['requests'] ?? [];
            
            if ($isAdmin || $isAdmSub) {
                $requestsF = RequestForwardService::getForwardsByDestination($pdo, 'all', $text, $searchDate, $subdivisionFilter);
            } else {
                $pendingForwards = RequestForwardService::getPendingForwards($pdo, $current_user_id);
                if ($pendingForwards['success'] && !empty($pendingForwards['data'])) {
                    foreach ($pendingForwards['data'] as $fwd) {
                        $rd = $fwd['request_data'] ?? [];
                        if (empty($rd) || ($text && stripos($rd['title'], $text) === false) || ($searchDate && $rd['date'] !== $searchDate)) continue;
                        $requestsF[] = [
                            'id' => $rd['id'], 'title' => $rd['title'], 'date' => $rd['date'], 'created_at' => $rd['created_at'], 'urgent' => $rd['urgent'], 'priority' => $rd['priority'],
                            'status' => 'F', 'st_raw' => 'F', 
                            'table' => $fwd['request_table'], 
                            'sector' => $fwd['request_table'],
                            'responsible_sector' => strtolower($fwd['to_area_name'] ?? ''),
                            'solicitor_name' => $rd['solicitor_name'], 'is_forwarded_to_me' => true, 'forward_from' => $fwd['from_area_label'],
                        ];
                    }
                }
            }
        }
        
        $requests = array_merge($requestsW, $requestsF);
        
        // =========================================
        // DEDUPLICAÇÃO DE REQUISIÇÕES (Evitar A->B->A duplicado)
        // =========================================
        $uniqueRequests = [];
        foreach ($requests as $req) {
            $cleanTable = str_replace(['ctd_', '_frm'], '', strtolower(trim($req['table'] ?? '')));
            $key = $cleanTable . '_' . trim($req['id'] ?? '');
            
            if (isset($uniqueRequests[$key])) {
                if (!isset($uniqueRequests[$key]['is_forwarded_to_me']) && isset($req['is_forwarded_to_me'])) {
                    $uniqueRequests[$key] = $req;
                }
            } else {
                $uniqueRequests[$key] = $req;
            }
        }
        $requests = array_values($uniqueRequests);
        // Re-ordenar após merge
        // Re-ordenar após merge conforme solicitação de data de prazo
        usort($requests, function($a, $b) use ($order) {
            $rawA = $a['date'] ?? '';
            $rawB = $b['date'] ?? '';
            
            $hasA = (!empty($rawA) && $rawA !== '0000-00-00' && $rawA !== '0000-00-00 00:00:00');
            $hasB = (!empty($rawB) && $rawB !== '0000-00-00' && $rawB !== '0000-00-00 00:00:00');
            
            if ($hasA && !$hasB) return -1;
            if (!$hasA && $hasB) return 1;
            if (!$hasA && !$hasB) {
                return strtotime($b['created_at'] ?? 0) - strtotime($a['created_at'] ?? 0);
            }
            
            $tsA = strtotime($rawA);
            $tsB = strtotime($rawB);
            
            if ($tsA === $tsB) {
                return strtotime($b['created_at'] ?? 0) - strtotime($a['created_at'] ?? 0);
            }
            
            if ($order === 'ASC') {
                return ($tsA < $tsB) ? -1 : 1;
            } else {
                return ($tsA > $tsB) ? -1 : 1;
            }
        });
    } else {
        $data = RequestManager::getRequests($pdo, $userId, $managingId, $status, $table, $text, $order, [], $searchDate, $searchDate, $subdivisionFilter);
        $requests = $data['requests'] ?? [];
        
        // Add forwards for approved page
        if (strpos($path, 'approved') !== false) {
            require_once __DIR__ . '/../classes/RequestForwardService.php';
            $requestsF = [];
            
            if ($table === 'forwarded' || $table === 'all') {
                if ($isAdmin || $isAdmSub) {
                    $requestsF = RequestForwardService::getForwardsByDestination($pdo, 'all', $text, $searchDate, $subdivisionFilter, 'completed');
                } else {
                    $completedForwards = RequestForwardService::getForwardsByDestination($pdo, $current_user_id, $text, $searchDate, $subdivisionFilter, 'completed');
                    $requestsF = $completedForwards;
                }
                
                // Mapeia para o mesmo formato
                foreach ($requestsF as &$fwd) {
                    if (!isset($fwd['is_forwarded_to_me'])) {
                        $fwd['is_forwarded_to_me'] = true;
                    }
                }
                
                $requests = array_merge($requests, $requestsF);
            }
        }
        
        // Deduplicação para o else block também
        $uniqueRequests = [];
        foreach ($requests as $req) {
            $cleanTable = str_replace(['ctd_', '_frm'], '', strtolower(trim($req['table'] ?? '')));
            $key = $cleanTable . '_' . trim($req['id'] ?? '');
            
            if (isset($uniqueRequests[$key])) {
                if (!isset($uniqueRequests[$key]['is_forwarded_to_me']) && isset($req['is_forwarded_to_me'])) {
                    $uniqueRequests[$key] = $req;
                }
            } else {
                $uniqueRequests[$key] = $req;
            }
        }
        $requests = array_values($uniqueRequests);
        
        // Re-ordenar
        // Re-ordenar conforme solicitação de data de prazo
        usort($requests, function($a, $b) use ($order) {
            $rawA = $a['date'] ?? '';
            $rawB = $b['date'] ?? '';
            
            $hasA = (!empty($rawA) && $rawA !== '0000-00-00' && $rawA !== '0000-00-00 00:00:00');
            $hasB = (!empty($rawB) && $rawB !== '0000-00-00' && $rawB !== '0000-00-00 00:00:00');
            
            if ($hasA && !$hasB) return -1;
            if (!$hasA && $hasB) return 1;
            if (!$hasA && !$hasB) {
                return strtotime($b['created_at'] ?? 0) - strtotime($a['created_at'] ?? 0);
            }
            
            $tsA = strtotime($rawA);
            $tsB = strtotime($rawB);
            
            if ($tsA === $tsB) {
                return strtotime($b['created_at'] ?? 0) - strtotime($a['created_at'] ?? 0);
            }
            
            if ($order === 'ASC') {
                return ($tsA < $tsB) ? -1 : 1;
            } else {
                return ($tsA > $tsB) ? -1 : 1;
            }
        });
    }

    $map = [
        'mkt' => 'gray',
        'xerox' => 'blue',
        'shop' => 'green',
        'service' => 'yellow',
        'ti' => 'red',
    ];

    $sectorNames = [
        'mkt' => 'MKT', 'marketing' => 'Marketing', 'xerox' => 'Reprografia', 
        'shop' => 'Compras', 'service' => 'Manutenção', 'ti' => 'TI',
    ];

    // Mesma ficha usada nas páginas (components/request_card.php)
    require_once __DIR__ . '/../components/request_card.php';
    $context = strpos($path, 'myRequests') !== false ? 'mine' : 'queue';
    $html = '';
    foreach ($requests as $row) {
        $html .= renderRequestCard($row, $context);
    }
    if ($html === '') {
        $html = '<div class="empty-state"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><p>Nenhum pedido encontrado com esses filtros.</p></div>';
    }

    echo json_encode(['success' => true, 'message' => 'Filtro aplicado!', 'data' => $html]);
    exit();
} else {
    echo json_encode(['success' => false, 'message' => 'Requisição inválida!']);
    exit();
}
