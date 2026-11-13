<?php
require_once __DIR__ . '/../config/security.php';

class RequestManager {
    /**
     * Busca todas as requisições, podendo filtrar por usuário, status, setor, busca de texto ou ordenação.
     * Retorna um array com 'requests' (lista ordenada) e 'stats' (estatísticas para o dashboard).
     *
     * @param PDO $pdo
     * @param int|null $userId - Filtra por criador da requisição (para "Minhas Requisições")
     * @param int|null $managingUserId - Filtra pelas áreas do gestor
     * @param string|null $status - Filtra por status específico (P, Y, N, W, C)
     * @param string|null $specificSector - Filtra por setor específico
     * @param string|null $searchText - Busca por texto no título
     * @param string $order - Ordenação (ASC ou DESC)
     * @param array $excludeStatuses - Lista de status a excluir (ex: ['P', 'N'] para gestores)
     * @param int|null $subdivisionId - Filtra por subdivisão específica (para adm_sub)
     */
    /** Cache dentro da mesma requisição HTTP (menu lateral e página pedem os mesmos dados) */
    private static $requestCache = [];

    /** Mapa id_user => [nomes, slugs] das subdivisões, carregado uma vez */
    private static $userSubdivisionMap = null;

    private static function userSubdivisionMap($pdo) {
        if (self::$userSubdivisionMap === null) {
            self::$userSubdivisionMap = [];
            $rows = $pdo->query("
                SELECT cus.id_user,
                       GROUP_CONCAT(s.name ORDER BY s.id SEPARATOR ', ') AS names,
                       GROUP_CONCAT(s.slug ORDER BY s.id SEPARATOR ',') AS slugs
                FROM cfg_user_subdivision cus
                JOIN ctd_subdivision s ON s.id = cus.id_subdivision
                GROUP BY cus.id_user
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                self::$userSubdivisionMap[(int)$row['id_user']] = [$row['names'], $row['slugs']];
            }
        }
        return self::$userSubdivisionMap;
    }

    /**
     * Filtro de subdivisão (coordenação): pedidos da subdivisão; pedido sem subdivisão vale a de quem pediu.
     * Mesma regra de userCoordinatesRequest().
     * null = sem filtro (admin geral); lista vazia = coordenação sem subdivisão, não vê nada.
     * @return array{0: string, 1: array} trecho SQL (começa com " AND") e parâmetros
     */
    private static function subdivisionFilter($subdivisionId): array
    {
        if ($subdivisionId === null) {
            return ['', []];
        }
        if (is_array($subdivisionId) && empty($subdivisionId)) {
            return [' AND 1 = 0', []];
        }
        $ids = is_array($subdivisionId) ? array_values($subdivisionId) : [$subdivisionId];
        $in = implode(',', array_fill(0, count($ids), '?'));
        return [" AND (
            r.subdivision_id IN ($in)
            OR (COALESCE(r.subdivision_id, 0) = 0 AND EXISTS (
                SELECT 1
                FROM cfg_user_subdivision cus
                WHERE cus.id_user = r.created_by
                  AND cus.id_subdivision IN ($in)
            ))
        )", array_merge($ids, $ids)];
    }

    /**
     * Quantos pedidos aguardam aprovação (contador do menu), numa consulta só e com o mesmo filtro de getRequests.
     * $viewerId > 0 tira os pedidos da própria pessoa: ninguém aprova o próprio pedido,
     * e a tela Aprovar (RequestInbox::approvals) já os esconde.
     */
    public static function countPending($pdo, $subdivisionId = null, int $viewerId = 0): int
    {
        $parts = [];
        $params = [];
        foreach (allowedRequestTables() as $sector) {
            [$subSql, $subParams] = self::subdivisionFilter($subdivisionId);
            $ownSql = $viewerId > 0 ? ' AND (r.created_by IS NULL OR r.created_by <> ?)' : '';
            $parts[] = "(SELECT COUNT(*) FROM `ctd_{$sector}_frm` r WHERE r.status = 'P'$subSql$ownSql)";
            $params = array_merge($params, $subParams, $viewerId > 0 ? [$viewerId] : []);
        }
        $stmt = $pdo->prepare('SELECT ' . implode(' + ', $parts));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public static function getRequests($pdo, $userId = null, $managingUserId = null, $status = null, $specificSector = null, $searchText = null, $order = 'DESC', $excludeStatuses = [], $dateStart = null, $dateEnd = null, $subdivisionId = null) {
        $cacheKey = md5(serialize(func_get_args() ? array_slice(func_get_args(), 1) : []));
        if (isset(self::$requestCache[$cacheKey])) {
            return self::$requestCache[$cacheKey];
        }
        $result = self::fetchRequests($pdo, $userId, $managingUserId, $status, $specificSector, $searchText, $order, $excludeStatuses, $dateStart, $dateEnd, $subdivisionId);
        self::$requestCache[$cacheKey] = $result;
        return $result;
    }

    private static function fetchRequests($pdo, $userId, $managingUserId, $status, $specificSector, $searchText, $order, $excludeStatuses, $dateStart, $dateEnd, $subdivisionId) {
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $allowedSectors = [];
        if ($managingUserId) {
            $stmt = $pdo->prepare("SELECT a.title FROM ctd_area a JOIN cfg_user_area cua ON a.id = cua.id_area WHERE cua.id_user = ?");
            $stmt->execute([$managingUserId]);
            $allowedSectors = array_values(array_filter(array_map('areaTitleToSector', $stmt->fetchAll(PDO::FETCH_COLUMN))));
        }

        $tables = [];
        if ($specificSector && $specificSector !== 'all') {
            $normalizedSector = normalizeRequestTable((string)$specificSector);
            // Gestor só filtra entre os próprios setores; outro setor no filtro não devolve nada
            if ($normalizedSector === null || ($managingUserId && !in_array($normalizedSector, $allowedSectors, true))) {
                return [
                    'requests' => [],
                    'stats' => [
                        'total' => 0, 'pendentes' => 0, 'aprovadas' => 0, 'emAndamento' => 0, 'concluidas' => 0, 'recusadas' => 0, 'repassadas' => 0, 'atrasadas' => 0, 'urgentes' => 0, 'criticas' => 0,
                        'statusCounts' => ['P' => 0, 'Y' => 0, 'N' => 0, 'W' => 0, 'C' => 0, 'R' => 0, 'F' => 0],
                        'categoryCounts' => []
                    ]
                ];
            }
            $tables[] = requestTableName($normalizedSector);
        } else {
            foreach (allowedRequestTables() as $sectorName) {
                // Se estiver filtrando por gestor, ignora tabelas que não são da sua área
                if ($managingUserId && !in_array($sectorName, $allowedSectors)) {
                    continue;
                }

                $tables[] = requestTableName($sectorName);
            }
        }

        $all_requests = [];
        
        $stats = [
            'total' => 0, 'pendentes' => 0, 'aprovadas' => 0, 'emAndamento' => 0, 'concluidas' => 0, 'recusadas' => 0, 'repassadas' => 0, 'atrasadas' => 0, 'urgentes' => 0, 'criticas' => 0,
            'statusCounts' => ['P' => 0, 'Y' => 0, 'N' => 0, 'W' => 0, 'C' => 0, 'R' => 0, 'F' => 0],
            'categoryCounts' => []
        ];

        foreach ($tables as $t) {
            try {
                $query = "SELECT r.*, u.name as solicitor_name, sub.name as subdivision_name, sub.slug as subdivision_slug
                FROM $t r LEFT JOIN ctd_users u ON r.created_by = u.id LEFT JOIN ctd_subdivision sub ON r.subdivision_id = sub.id WHERE 1=1";
                $params = [];
                
                if ($userId !== null) {
                    $query .= " AND r.created_by = ?";
                    $params[] = $userId;
                }

                if ($status === 'P') {
                    $query .= " AND r.status = 'P'";
                } elseif ($status !== null) {
                    $query .= " AND r.status = ?";
                    $params[] = strtoupper(trim((string)$status));
                }

                // Excluir status específicos (r.status)
                if (!empty($excludeStatuses)) {
                    $placeholders = implode(',', array_fill(0, count($excludeStatuses), '?'));
                    $query .= " AND r.status NOT IN ($placeholders)";
                    
                    // Se estiver excluindo 'P' (Pendentes), também deve excluir NULL e Vazio (que são considerados pendentes)
                    if (in_array('P', $excludeStatuses)) {
                        $query .= " AND r.status IS NOT NULL AND r.status <> ''";
                    }
                    
                    $params = array_merge($params, $excludeStatuses);
                }

                if ($searchText !== null && trim($searchText) !== '') {
                    $query .= " AND r.title LIKE ?";
                    $params[] = "%$searchText%";
                }

                if ($dateStart) {
                    $query .= " AND r.created_at >= ?";
                    $params[] = $dateStart . " 00:00:00";
                }

                if ($dateEnd) {
                    $query .= " AND r.created_at <= ?";
                    $params[] = $dateEnd . " 23:59:59";
                }

                // Filtro por subdivisão
                [$subSql, $subParams] = self::subdivisionFilter($subdivisionId);
                $query .= $subSql;
                $params = array_merge($params, $subParams);

                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $sectorName = requestSlug($t);
                
                $subMap = self::userSubdivisionMap($pdo);
                foreach ($res as $r) {
                    [$r['all_subdivision_names'], $r['all_subdivision_slugs']] = $subMap[(int)($r['created_by'] ?? 0)] ?? [null, null];
                    $st = trim(strtoupper($r['status'] ?? 'P'));
                    // Atrasada = prazo pedido já passou e a requisição ainda não foi encerrada
                    $deadline = $r['date'] ?? null;
                    $isAtrasada = !in_array($st, ['C', 'N'], true)
                        && !empty($deadline) && $deadline !== '0000-00-00'
                        && $deadline < date('Y-m-d');
                    
                    // Padronização
                    $r['sector'] = $sectorName;
                    $r['table'] = $sectorName; 
                    $r['st_raw'] = $st;
                    $r['is_atrasada'] = $isAtrasada;

                    // Formatar nome: Primeiro e Último
                    $fullName = trim($r['solicitor_name'] ?? '');
                    if ($fullName) {
                        $parts = explode(' ', $fullName);
                        if (count($parts) > 1) {
                            $r['solicitor_name_formatted'] = $parts[0] . ' ' . end($parts);
                        } else {
                            $r['solicitor_name_formatted'] = $fullName;
                        }
                    } else {
                        $r['solicitor_name_formatted'] = '—';
                    }
                    
                    $all_requests[] = $r;
                    
                    // Contagem de Estatísticas
                    if ($st === 'P' || $st === '') {
                        $stats['pendentes']++;
                    } elseif ($st === 'Y' || $st === 'A') {
                        $stats['aprovadas']++;
                    } elseif ($st === 'W') {
                        $stats['emAndamento']++;
                    } elseif ($st === 'C') {
                        $stats['concluidas']++;
                    } elseif ($st === 'N') {
                        $stats['recusadas']++;
                    } elseif ($st === 'F') {
                        $stats['repassadas']++;
                    }
                    $stats['total']++;
                    
                    if ($isAtrasada) $stats['atrasadas']++;
                    if (!empty($r['urgent']) || (int)($r['priority'] ?? 2) >= 3) $stats['urgentes']++;
                    if (isset($r['priority']) && $r['priority'] == 4) $stats['criticas']++;
                    
                    $stMapped = $st;
                    if ($st === '' || $st === 'P') $stMapped = 'P';
                    elseif ($st === 'A') $stMapped = 'Y';
                    
                    if (isset($stats['statusCounts'][$stMapped])) {
                        $stats['statusCounts'][$stMapped]++;
                    }
                    $stats['categoryCounts'][$sectorName] = ($stats['categoryCounts'][$sectorName] ?? 0) + 1;
                }
            } catch(Exception $e) {
                // Tabela pode não existir ou erro de query
                error_log("RequestManager::getRequests {$t}: " . $e->getMessage());
            }
        }

        // Ordenar por prazo (sem prazo vai para o fim; empate: mais recente primeiro).
        // Datas ISO (AAAA-MM-DD) comparam corretamente como texto — evita strtotime a cada comparação.
        $hasDeadline = fn($d) => !empty($d) && strpos((string)$d, '0000-00-00') !== 0;
        usort($all_requests, function($a, $b) use ($order, $hasDeadline) {
            $rawA = (string)($a['date'] ?? '');
            $rawB = (string)($b['date'] ?? '');
            $hasA = $hasDeadline($rawA);
            $hasB = $hasDeadline($rawB);

            if ($hasA !== $hasB) return $hasA ? -1 : 1;
            if (!$hasA || $rawA === $rawB) {
                return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
            }
            return $order === 'ASC' ? strcmp($rawA, $rawB) : strcmp($rawB, $rawA);
        });

        return [
            'requests' => $all_requests,
            'stats' => $stats
        ];
    }

    /**
     * Busca gestores vinculados a um setor específico.
     * Usado para enviar notificações quando uma requisição do setor é aprovada.
     */
    public static function getManagersForSector($pdo, $sectorName) {
        $aliases = sectorAreaAliases((string)$sectorName);
        $placeholders = implode(',', array_fill(0, count($aliases), '?'));
        $stmt = $pdo->prepare("
            SELECT u.id, u.name, u.role 
            FROM ctd_users u
            JOIN cfg_user_area cua ON u.id = cua.id_user
            JOIN ctd_area a ON a.id = cua.id_area
            WHERE LOWER(a.title) IN ($placeholders)
            AND u.role IN ('gestor', 'admin', 'adm', 'coord', 'ti', 'xerox', 'service', 'shop', 'mkt', 'marketing')
        ");
        $stmt->execute($aliases);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca administradores de uma subdivisão específica (role = adm_sub).
     */
    public static function getSubdivisionAdmins($pdo, $subdivisionId) {
        $stmt = $pdo->prepare("
            SELECT u.id, u.name 
            FROM ctd_users u 
            JOIN cfg_user_subdivision cus ON u.id = cus.id_user
            WHERE u.role = 'adm_sub' AND cus.id_subdivision = ?
        ");
        $stmt->execute([$subdivisionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca administradores das subdivisões vinculadas a um usuário.
     */
    public static function getSubdivisionAdminsForUser($pdo, $userId) {
        $subdivisionIds = getUserSubdivisionIds($pdo, (int)$userId);
        if (empty($subdivisionIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($subdivisionIds), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id, u.name
            FROM ctd_users u
            JOIN cfg_user_subdivision cus ON u.id = cus.id_user
            WHERE u.role = 'adm_sub'
              AND cus.id_subdivision IN ($placeholders)
        ");
        $stmt->execute($subdivisionIds);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Busca todos os admins/coordenadores para notificações globais.
     */
    public static function getAdmins($pdo) {
        $stmt = $pdo->query("SELECT id, name FROM ctd_users WHERE role IN ('admin', 'adm', 'coord')");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Envia notificação para um ou mais usuários.
     */
    public static function notify($pdo, $userId, $title, $message, $link = null) {
        try {
            $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, link) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $title, $message, $link]);
        } catch (Throwable $e) {
            // Aviso é acessório: não derruba a ação, mas fica no log
            error_log("RequestManager::notify user=$userId \"$title\": " . $e->getMessage());
        }
    }

    /**
     * Envia notificação para múltiplos usuários de uma vez.
     */
    public static function notifyMany($pdo, $userIds, $title, $message, $link = null, $excludeUserId = null) {
        foreach ($userIds as $uid) {
            if ($excludeUserId && $uid == $excludeUserId) continue;
            self::notify($pdo, $uid, $title, $message, $link);
        }
    }

    /**
     * Registra uma ação no histórico da requisição.
     */
    /**
     * Registra um evento do pedido. $eventCode diz o que aconteceu (created, approved, started...,
     * ver migração 008); $oldValue/$newValue vão em código: situação P/Y/W/C/N/F, prioridade 1-4 e,
     * nos encaminhamentos, o nome do setor. $action é o texto para leitura (impressão, registros antigos).
     */
    public static function addHistory($pdo, $reqId, $reqTable, $userId, $userName, $action, $oldValue = null, $newValue = null, ?string $eventCode = null) {
        try {
            $stmt = $pdo->prepare("INSERT INTO request_history (request_id, request_table, user_id, user_name, action, event_code, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$reqId, $reqTable, $userId, $userName, $action, $eventCode, $oldValue, $newValue]);
        } catch (Throwable $e) {
            error_log("RequestManager::addHistory $reqTable/$reqId \"$action\": " . $e->getMessage());
            // Dentro de uma transação o histórico faz parte da operação: sem ele, desfaz tudo
            if ($pdo->inTransaction()) {
                throw $e;
            }
        }
    }

    /**
     * Notifica todos os interessados em uma requisição (Solicitante, Gestores do Setor e Admins).
     * Roda depois da ação já gravada: falha aqui vai para o log e não vira erro na tela.
     */
    public static function notifyStakeholders($pdo, $reqData, $sector, $title, $message, $link, $excludeUserId = null, $notifyAdmins = true) {
        try {
            self::sendStakeholderNotifications($pdo, $reqData, $sector, $title, $message, $link, $excludeUserId, $notifyAdmins);
        } catch (Throwable $e) {
            error_log("RequestManager::notifyStakeholders $sector/" . ($reqData['id'] ?? '?') . " \"$title\": " . $e->getMessage());
        }
    }

    private static function sendStakeholderNotifications($pdo, $reqData, $sector, $title, $message, $link, $excludeUserId, $notifyAdmins) {
        $createdBy = (int)($reqData['created_by'] ?? 0);
        $subdivisionId = $reqData['subdivision_id'] ?? null;
        $notified = $excludeUserId ? [$excludeUserId] : [];

        // 1. Notificar o solicitante
        if ($createdBy && !in_array($createdBy, $notified)) {
            self::notify($pdo, $createdBy, $title, $message, $link);
            $notified[] = $createdBy;
        }

        // 2. Notificar adm_sub da subdivisão do pedido; pedido sem subdivisão vale a de quem pediu.
        $subAdmins = [];
        if ($subdivisionId) {
            $subAdmins = self::getSubdivisionAdmins($pdo, $subdivisionId);
        } elseif ($createdBy) {
            $subAdmins = self::getSubdivisionAdminsForUser($pdo, $createdBy);
        }
        foreach ($subAdmins as $sa) {
            if (!in_array($sa['id'], $notified)) {
                self::notify($pdo, $sa['id'], $title, $message, $link);
                $notified[] = $sa['id'];
            }
        }

        // 3. Notificar gestores do setor
        $managers = self::getManagersForSector($pdo, $sector);
        foreach ($managers as $m) {
            if (!$notifyAdmins && in_array($m['role'], ['admin', 'adm', 'coord'])) {
                continue;
            }
            if (!in_array($m['id'], $notified)) {
                self::notify($pdo, $m['id'], $title, $message, $link);
                $notified[] = $m['id'];
            }
        }

        // 4. Notificar admins globais
        if ($notifyAdmins) {
            $admins = self::getAdmins($pdo);
            foreach ($admins as $a) {
                if (!in_array($a['id'], $notified)) {
                    self::notify($pdo, $a['id'], $title, $message, $link);
                    $notified[] = $a['id'];
                }
            }
        }
    }
}
