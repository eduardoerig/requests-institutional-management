<?php
session_start();
require_once 'conn.php';
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
    $isAdmin = in_array($role, ['admin', 'adm', 'coord']);
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

    $html = '';
    if (count($requests) > 0) {
        foreach ($requests as $row) {
            $st = trim(strtoupper($row['status'] ?? 'P'));
            $cardClass = isset($map[$row['table']]) ? $map[$row['table']] : 'gray';
            
            // Priority Badges
            $pri = $row['priority'] ?? 2;
            $priBadge = '';
            if ($pri == 4) $priBadge = '<span class="card-priority critical"><i class="fa-solid fa-fire-extinguisher"></i></span>';
            elseif ($pri == 3) $priBadge = '<span class="card-priority high"><i class="fa-solid fa-angles-up"></i></span>';

            $sectorBadge = '<span class="card-sector-tag">' . ($sectorNames[$row['table']] ?? ucfirst($row['table'])) . '</span>';
            
            // Subdivision Badge
            $subBadge = '';
            if (!empty($row['subdivision_name'])) {
                $subBadge = '<span class="badge-subdivision badge-sub-' . ($row['subdivision_slug'] ?? 'default') . '">' . htmlspecialchars($row['subdivision_name']) . '</span>';
            }

            // Timer visual para "Em Andamento" (W) ou "Repassada" (F)
            $timerHtml = '';
            if ($st === 'W' || $st === 'F') {
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
            if ($st === 'Y' || $st === 'A') {
                $statusIcon = '<span class="req_confirmed"><i class="fa-solid fa-check-circle"></i></span>';
            }

            // Badge de Repasse
            $forwardedBadge = '';
            if ($st === 'F') {
                $forwardedBadge = '<span class="badge-forwarded"><i class="fa-solid fa-share-from-square"></i> Repassada</span>';
            }
            
            // Nome da requisição (Padronizado como approve.php para consistência)
            $reqNome = 'Requisição ' . strtoupper($sectorNames[$row['table']] ?? $row['table'] ?? '') . ' #' . $row['id'];
            if (strpos($path, 'approve') !== false && strpos($path, 'approved') === false) {
                // Em approve.php o estilo é um pouco diferente
            }

            $rd = $row['date'] ?? '';
            $prazoBadge = getPrazoBadge($rd, $st);

            $html .= '
                <div class="card_req ' . $cardClass . ($st === 'F' ? ' forwarded' : '') . '" data-id="' . $row['id'] . '" data-table="' . $row['table'] . '">
                    <div class="card_header">
                        <span class="req_nome">' . $reqNome . ($st === 'F' ? ' <i class="fa-solid fa-share" style="font-size: 0.8rem; color: #f59e0b;"></i>' : '') . '</span>
                        <div style="display:flex; gap:6px; align-items:center;">' . $timerHtml . $statusIcon . $priBadge . '</div>
                    </div>
                    <div class="card_title">' . htmlspecialchars($row['title']) . '</div>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px; flex-wrap:wrap; gap:6px;">
                        <div class="card_date" style="display:flex; align-items:center;">' . $prazoBadge . '</div>
                        <div style="display:flex; gap:6px; align-items:center;">' . $forwardedBadge . $sectorBadge . '</div>
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
