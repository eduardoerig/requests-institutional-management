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
    public static function getRequests($pdo, $userId = null, $managingUserId = null, $status = null, $specificSector = null, $searchText = null, $order = 'DESC', $excludeStatuses = [], $dateStart = null, $dateEnd = null, $subdivisionId = null) {
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
            if ($normalizedSector === null) {
                return [
                    'requests' => [],
                    'stats' => [
                        'total' => 0, 'pendentes' => 0, 'aprovadas' => 0, 'emAndamento' => 0, 'concluidas' => 0, 'recusadas' => 0, 'repassadas' => 0, 'atrasadas' => 0, 'urgentes' => 0, 'criticas' => 0,
                        'statusCounts' => ['P' => 0, 'Y' => 0, 'N' => 0, 'W' => 0, 'C' => 0, 'R' => 0, 'F' => 0],
                        'categoryCounts' => []
                    ]
                ];
            }
            $tables[] = "ctd_" . $normalizedSector . "_frm";
        } else {
            $stmtTables = $pdo->query("SHOW TABLES LIKE 'ctd_%_frm'");
            while($row = $stmtTables->fetch(PDO::FETCH_NUM)) {
                $tableName = $row[0];
                $sectorName = str_replace(['ctd_', '_frm'], '', $tableName);
                $sectorName = strtolower($sectorName);
                
                // Se estiver filtrando por gestor, ignora tabelas que não são da sua área
                if ($managingUserId && !in_array($sectorName, $allowedSectors)) {
                    continue;
                }
                
                $tables[] = $tableName;
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
                $query = "SELECT r.*, u.name as solicitor_name, sub.name as subdivision_name, sub.slug as subdivision_slug,
                    (SELECT GROUP_CONCAT(s2.name ORDER BY s2.id SEPARATOR ', ')
                     FROM cfg_user_subdivision cus2
                     JOIN ctd_subdivision s2 ON cus2.id_subdivision = s2.id
                     WHERE cus2.id_user = r.created_by) as all_subdivision_names,
                    (SELECT GROUP_CONCAT(s2.slug ORDER BY s2.id SEPARATOR ',')
                     FROM cfg_user_subdivision cus2
                     JOIN ctd_subdivision s2 ON cus2.id_subdivision = s2.id
                     WHERE cus2.id_user = r.created_by) as all_subdivision_slugs
                FROM $t r LEFT JOIN ctd_users u ON r.created_by = u.id LEFT JOIN ctd_subdivision sub ON r.subdivision_id = sub.id WHERE 1=1";
                $params = [];
                
                if ($userId !== null) {
                    $query .= " AND created_by = ?";
                    $params[] = $userId;
                }

                if ($status === 'P') {
                    $query .= " AND (TRIM(UPPER(r.status)) = 'P' OR r.status IS NULL OR r.status = '')";
                } elseif ($status !== null) {
                    $query .= " AND TRIM(UPPER(r.status)) = UPPER(?)";
                    $params[] = $status;
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
                    $query .= " AND title LIKE ?";
                    $params[] = "%$searchText%";
                }

                if ($dateStart) {
                    $query .= " AND created_at >= ?";
                    $params[] = $dateStart . " 00:00:00";
                }

                if ($dateEnd) {
                    $query .= " AND created_at <= ?";
                    $params[] = $dateEnd . " 23:59:59";
                }

                // Filtro por subdivisão
                if ($subdivisionId !== null) {
                    if (is_array($subdivisionId)) {
                        if (!empty($subdivisionId)) {
                            $placeholders = implode(',', array_fill(0, count($subdivisionId), '?'));
                            $query .= " AND (
                                r.subdivision_id IN ($placeholders)
                                OR EXISTS (
                                    SELECT 1
                                    FROM cfg_user_subdivision cus
                                    WHERE cus.id_user = r.created_by
                                      AND cus.id_subdivision IN ($placeholders)
                                )
                            )";
                            $params = array_merge($params, $subdivisionId, $subdivisionId);
                        }
                    } else {
                        $query .= " AND (
                            r.subdivision_id = ?
                            OR EXISTS (
                                SELECT 1
                                FROM cfg_user_subdivision cus
                                WHERE cus.id_user = r.created_by
                                  AND cus.id_subdivision = ?
                            )
                        )";
                        $params[] = $subdivisionId;
                        $params[] = $subdivisionId;
                    }
                }
                
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $sectorName = str_replace(['ctd_', '_frm'], '', $t);
                
                foreach ($res as $r) {
                    $st = trim(strtoupper($r['status'] ?? 'P'));
                    $createdAtStr = $r['created_at'] ?? '0';
                    $createdAt = strtotime($createdAtStr);
                    $isAtrasada = ($st !== 'C' && $st !== 'N' && $st !== 'R' && (time() - $createdAt) > (3 * 24 * 3600)); 
                    
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
                    if (!empty($r['urgent'])) $stats['urgentes']++;
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

        // Ordenar as requisições por data de prazo (date) conforme solicitação do usuário
        usort($all_requests, function($a, $b) use ($order) {
            $rawA = $a['date'] ?? '';
            $rawB = $b['date'] ?? '';
            
            $hasA = (!empty($rawA) && $rawA !== '0000-00-00' && $rawA !== '0000-00-00 00:00:00');
            $hasB = (!empty($rawB) && $rawB !== '0000-00-00' && $rawB !== '0000-00-00 00:00:00');
            
            // Se um deles não tem prazo, colocamos no final
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
                // ASC (Seta para CIMA): Mais atrasadas (datas antigas) -> Mais de boas (datas futuras)
                return ($tsA < $tsB) ? -1 : 1;
            } else {
                // DESC (Seta para BAIXO): Mais de boas (datas futuras) -> Mais atrasadas (datas antigas)
                return ($tsA > $tsB) ? -1 : 1;
            }
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
        } catch (Exception $e) {
            // Silently fail
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
    public static function addHistory($pdo, $reqId, $reqTable, $userId, $userName, $action, $oldValue = null, $newValue = null) {
        try {
            $stmt = $pdo->prepare("INSERT INTO request_history (request_id, request_table, user_id, user_name, action, old_value, new_value) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$reqId, $reqTable, $userId, $userName, $action, $oldValue, $newValue]);
        } catch (Exception $e) {
            // Silently fail
        }
    }

    /**
     * Notifica todos os interessados em uma requisição (Solicitante, Gestores do Setor e Admins).
     */
    public static function notifyStakeholders($pdo, $reqData, $sector, $title, $message, $link, $excludeUserId = null, $notifyAdmins = true) {
        $createdBy = (int)($reqData['created_by'] ?? 0);
        $subdivisionId = $reqData['subdivision_id'] ?? null;
        $notified = $excludeUserId ? [$excludeUserId] : [];

        // 1. Notificar o solicitante
        if ($createdBy && !in_array($createdBy, $notified)) {
            self::notify($pdo, $createdBy, $title, $message, $link);
            $notified[] = $createdBy;
        }

        // 2. Notificar adm_sub da subdivisão principal e das subdivisões vinculadas ao solicitante.
        $subAdmins = [];
        if ($subdivisionId) {
            $subAdmins = self::getSubdivisionAdmins($pdo, $subdivisionId);
        }
        if ($createdBy) {
            $subAdmins = array_merge($subAdmins, self::getSubdivisionAdminsForUser($pdo, $createdBy));
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
