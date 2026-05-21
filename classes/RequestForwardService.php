<?php
/**
 * RequestForwardService — Serviço de Repasse de Requisições entre Setores
 *
 * Gerencia todo o ciclo de vida de um repasse:
 * criar → aceitar/recusar → concluir
 *
 * Integrado com RequestManager para histórico e notificações.
 */

require_once __DIR__ . '/RequestManager.php';
require_once __DIR__ . '/../config/security.php';

class RequestForwardService {

    /**
     * Labels dos setores para mensagens amigáveis
     */
    private static $sectorLabels = [
        'mkt' => 'Marketing', 'xerox' => 'Reprografia', 'shop' => 'Compras',
        'service' => 'Manutenção', 'ti' => 'TI',
    ];

    /**
     * Repassa uma requisição para outro setor.
     *
     * @param PDO    $pdo
     * @param int    $reqId        ID da requisição
     * @param string $reqTable     Setor da requisição (ex: 'ti', 'shop')
     * @param int    $toAreaId     ID do setor de destino (ctd_area.id)
     * @param string $observation  Observação do gestor sobre o repasse
     * @param int    $userId       ID do gestor que está repassando
     * @param string $userName     Nome do gestor
     * @return array ['success' => bool, 'message' => string, 'data' => mixed]
     */
    public static function forwardRequest($pdo, $reqId, $reqTable, $toAreaId, $observation, $userId, $userName) {
        try {
            $reqTable = normalizeRequestTable((string)$reqTable);
            if ($reqTable === null) {
                return ['success' => false, 'message' => 'Tabela de requisição inválida.'];
            }

            // 1. Validar se a requisição existe
            $fullTable = "ctd_{$reqTable}_frm";
            $stmt = $pdo->prepare("SELECT * FROM `$fullTable` WHERE id = ?");
            $stmt->execute([$reqId]);
            $reqData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reqData) {
                return ['success' => false, 'message' => "Requisição #{$reqId} não encontrada."];
            }

            // 2. Validar status — pode repassar se estiver Aprovada (Y) ou Em Andamento (W) ou Repassada (F)
            $currentStatus = trim(strtoupper($reqData['status'] ?? 'P'));
            if ($currentStatus === '') $currentStatus = 'P';

            if (!in_array($currentStatus, ['Y', 'W', 'F'], true)) {
                $statusNames = ['P' => 'Pendente', 'Y' => 'Aprovada', 'N' => 'Recusada', 'C' => 'Concluída', 'F' => 'Repassada'];
                $statusName = $statusNames[$currentStatus] ?? $currentStatus;
                return ['success' => false, 'message' => "Não é possível repassar uma requisição com status \"{$statusName}\". A requisição deve estar Aprovada ou Em Andamento."];
            }

            // 3. Validar se o gestor tem permissão sobre o setor de ORIGEM
            $fromAreaId = self::getAreaIdBySector($pdo, $reqTable);
            if (!$fromAreaId) {
                return ['success' => false, 'message' => "Setor de origem \"{$reqTable}\" não encontrado na base de dados."];
            }

            $activeForward = null;
            if (!self::userHasAreaPermission($pdo, $userId, $fromAreaId)) {
                $activeForward = getActiveForwardForUser($pdo, $userId, $reqId, $reqTable, ['accepted']);
                if (!$activeForward) {
                    return ['success' => false, 'message' => 'Você não tem permissão para repassar requisições deste setor. É necessário aceitar formalmente o repasse antes de encaminhá-lo.'];
                }
                $fromAreaId = (int)$activeForward['to_area_id'];
            }

            // 4. Validar se o setor de destino existe
            $toArea = self::getAreaById($pdo, $toAreaId);
            if (!$toArea) {
                return ['success' => false, 'message' => 'O setor de destino selecionado não existe.'];
            }

            // 5. Não permitir repassar para o próprio setor
            if ((int)$fromAreaId === (int)$toAreaId) {
                return ['success' => false, 'message' => 'Não é possível repassar para o mesmo setor de origem.'];
            }

            // 6. Removido o bloqueio de repasse circular. Devolver para a origem (A->B->A) é um fluxo legítimo de trabalho.

            // 7. Criar o registro de repasse
            $pdo->beginTransaction();

            // Fechar qualquer repasse pendente ou ativo anterior desta mesma requisição (evita múltiplos repasses soltos)
            $existingActive = self::getActiveForward($pdo, $reqId, $reqTable);
            if ($existingActive) {
                $stmt = $pdo->prepare("UPDATE request_forwards SET status = 'completed', received_by = COALESCE(received_by, ?) WHERE id = ?");
                $stmt->execute([$userId, (int)$existingActive['id']]);
                
                // Se o fromAreaId original estava nulo ou vamos forçar rastreabilidade, podemos ajustar,
                // mas a validação de origem já garantiu que quem está repassando pode fazê-lo.
            }

            $stmt = $pdo->prepare("
                INSERT INTO request_forwards
                (request_id, request_table, from_area_id, forwarded_by, to_area_id, observation, status, previous_status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
            ");
            $stmt->execute([$reqId, $reqTable, $fromAreaId, $userId, $toAreaId, $observation, $currentStatus]);
            $forwardId = $pdo->lastInsertId();

            // 8. Atualizar o status da requisição para 'F' (Repassada)
            $stmt = $pdo->prepare("UPDATE `$fullTable` SET status = 'F' WHERE id = ?");
            $stmt->execute([$reqId]);

            // 9. Gravar no histórico
            $toAreaLabel = self::$sectorLabels[strtolower($toArea['title'])] ?? $toArea['title'];
            $fromAreaLabel = self::$sectorLabels[$reqTable] ?? strtoupper($reqTable);

            RequestManager::addHistory(
                $pdo, $reqId, $reqTable, $userId, $userName,
                "Requisição repassada para {$toAreaLabel}",
                $fromAreaLabel,
                $toAreaLabel
            );

            // 10. Notificar gestores do setor de destino
            $toSectorName = strtolower($toArea['title']);
            $managers = RequestManager::getManagersForSector($pdo, $toSectorName);
            $link = "request_detail?id={$reqId}&table={$reqTable}";
            $reqTitle = $reqData['title'] ?? "Requisição #{$reqId}";

            foreach ($managers as $manager) {
                if ($manager['id'] == $userId) continue;
                RequestManager::notify(
                    $pdo, $manager['id'],
                    "📨 Repasse Recebido",
                    "A requisição #{$reqId} ({$fromAreaLabel}) — \"{$reqTitle}\" — foi repassada para {$toAreaLabel} por {$userName}." .
                    ($observation ? " Obs: {$observation}" : ''),
                    $link
                );
            }

            // 11. Notificar o solicitante
            $createdBy = (int)($reqData['created_by'] ?? 0);
            if ($createdBy && $createdBy !== $userId) {
                RequestManager::notify(
                    $pdo, $createdBy,
                    "Requisição Encaminhada",
                    "Sua requisição #{$reqId} — \"{$reqTitle}\" — foi encaminhada do setor de {$fromAreaLabel} para {$toAreaLabel} para melhor atendimento.",
                    $link
                );
            }

            $pdo->commit();

            return [
                'success' => true,
                'message' => "Requisição repassada com sucesso para {$toAreaLabel}!",
                'data' => ['forward_id' => $forwardId]
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('RequestForwardService::forwardRequest: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erro ao repassar requisição.'];
        }
    }

    /**
     * Gestor do setor de destino aceita o repasse.
     */
    public static function acceptForward($pdo, $forwardId, $userId, $userName) {
        try {
            // Buscar dados do repasse
            $forward = self::getForwardById($pdo, $forwardId);
            if (!$forward) {
                return ['success' => false, 'message' => 'Repasse não encontrado.'];
            }

            if ($forward['status'] !== 'pending') {
                return ['success' => false, 'message' => 'Este repasse já foi processado.'];
            }

            // Verificar se o gestor tem permissão sobre o setor de destino
            if (!self::userHasAreaPermission($pdo, $userId, $forward['to_area_id'])) {
                return ['success' => false, 'message' => 'Você não tem permissão para aceitar repasses deste setor.'];
            }

            // Atualizar o repasse
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE request_forwards SET status = 'accepted', received_by = ? WHERE id = ?");
            $stmt->execute([$userId, $forwardId]);

            // Ao aceitar o repasse, colocar a requisição Em Andamento (W) no setor de destino
            $fullTable = "ctd_{$forward['request_table']}_frm";
            $stmt = $pdo->prepare("UPDATE `$fullTable` SET status = 'W' WHERE id = ?");
            $stmt->execute([$forward['request_id']]);

            // Gravar no histórico
            $toArea = self::getAreaById($pdo, $forward['to_area_id']);
            $toAreaLabel = self::$sectorLabels[strtolower($toArea['title'] ?? '')] ?? ($toArea['title'] ?? 'Setor');

            RequestManager::addHistory(
                $pdo, $forward['request_id'], $forward['request_table'], $userId, $userName,
                "Repasse aceito por {$userName} ({$toAreaLabel}) — Em Andamento",
                'Repassada',
                'Em Andamento'
            );

            // Notificar quem repassou
            $fromArea = self::getAreaById($pdo, $forward['from_area_id']);
            $fromAreaLabel = self::$sectorLabels[strtolower($fromArea['title'] ?? '')] ?? ($fromArea['title'] ?? 'Setor');
            $link = "request_detail?id={$forward['request_id']}&table={$forward['request_table']}";

            RequestManager::notify(
                $pdo, $forward['forwarded_by'],
                "✅ Repasse Aceito",
                "O repasse da requisição #{$forward['request_id']} foi aceito por {$userName} no setor {$toAreaLabel}.",
                $link
            );

            $pdo->commit();
            return ['success' => true, 'message' => "Repasse aceito! Agora você é responsável pelo atendimento."];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('RequestForwardService::acceptForward: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erro ao aceitar repasse.'];
        }
    }

    /**
     * Gestor do setor de destino recusa o repasse.
     * A requisição volta ao status anterior (Em Andamento) no setor de origem.
     */
    public static function refuseForward($pdo, $forwardId, $userId, $userName) {
        try {
            $forward = self::getForwardById($pdo, $forwardId);
            if (!$forward) {
                return ['success' => false, 'message' => 'Repasse não encontrado.'];
            }

            if ($forward['status'] !== 'pending') {
                return ['success' => false, 'message' => 'Este repasse já foi processado.'];
            }

            // Verificar permissão sobre o setor de destino
            if (!self::userHasAreaPermission($pdo, $userId, $forward['to_area_id'])) {
                return ['success' => false, 'message' => 'Você não tem permissão para recusar repasses deste setor.'];
            }

            // Atualizar o repasse para recusado
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE request_forwards SET status = 'refused', received_by = ? WHERE id = ?");
            $stmt->execute([$userId, $forwardId]);

            // Voltar o status da requisição para o status anterior (previous_status)
            $prevStatus = $forward['previous_status'] ?? 'Y';
            $fullTable = "ctd_{$forward['request_table']}_frm";
            $stmt = $pdo->prepare("UPDATE `$fullTable` SET status = ? WHERE id = ?");
            $stmt->execute([$prevStatus, $forward['request_id']]);

            // Gravar no histórico
            $toArea = self::getAreaById($pdo, $forward['to_area_id']);
            $toAreaLabel = self::$sectorLabels[strtolower($toArea['title'] ?? '')] ?? ($toArea['title'] ?? 'Setor');
            $prevLabel = ($prevStatus === 'W') ? 'Em Andamento' : 'Aprovada';

            RequestManager::addHistory(
                $pdo, $forward['request_id'], $forward['request_table'], $userId, $userName,
                "Repasse recusado por {$userName} ({$toAreaLabel}) — Retornou para $prevLabel",
                'Repassada',
                $prevLabel
            );

            $link = "request_detail?id={$forward['request_id']}&table={$forward['request_table']}";
            RequestManager::notify(
                $pdo, $forward['forwarded_by'],
                "❌ Repasse Recusado",
                "O repasse da requisição #{$forward['request_id']} foi recusado por {$userName} ({$toAreaLabel}). A requisição voltou para o status $prevLabel no seu setor.",
                $link
            );

            $pdo->commit();
            return ['success' => true, 'message' => "Repasse recusado. A requisição voltou ao status $prevLabel no setor de origem."];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('RequestForwardService::refuseForward: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erro ao recusar repasse.'];
        }
    }

    /**
     * Marca o repasse como concluído pelo setor de destino.
     * A requisição original é marcada como Concluída (C).
     */
    public static function completeForward($pdo, $forwardId, $userId, $userName) {
        try {
            $forward = self::getForwardById($pdo, $forwardId);
            if (!$forward) {
                return ['success' => false, 'message' => 'Repasse não encontrado.'];
            }

            if ($forward['status'] !== 'accepted') {
                return ['success' => false, 'message' => 'Apenas repasses aceitos podem ser concluídos.'];
            }

            // Verificar permissão
            if (!self::userHasAreaPermission($pdo, $userId, $forward['to_area_id'])) {
                return ['success' => false, 'message' => 'Você não tem permissão para concluir repasses deste setor.'];
            }

            // Atualizar o repasse
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE request_forwards SET status = 'completed' WHERE id = ?");
            $stmt->execute([$forwardId]);

            // Concluir a requisição original
            $fullTable = "ctd_{$forward['request_table']}_frm";
            $stmt = $pdo->prepare("UPDATE `$fullTable` SET status = 'C' WHERE id = ?");
            $stmt->execute([$forward['request_id']]);

            // Gravar no histórico
            $toArea = self::getAreaById($pdo, $forward['to_area_id']);
            $toAreaLabel = self::$sectorLabels[strtolower($toArea['title'] ?? '')] ?? ($toArea['title'] ?? 'Setor');

            RequestManager::addHistory(
                $pdo, $forward['request_id'], $forward['request_table'], $userId, $userName,
                "Requisição concluída via repasse ({$toAreaLabel})",
                'Repassada',
                'Concluída'
            );

            // Notificar todos os stakeholders
            $reqStmt = $pdo->prepare("SELECT * FROM `$fullTable` WHERE id = ?");
            $reqStmt->execute([$forward['request_id']]);
            $reqData = $reqStmt->fetch(PDO::FETCH_ASSOC);

            if ($reqData) {
                $reqTitle = $reqData['title'] ?? "Requisição #{$forward['request_id']}";
                $link = "request_detail?id={$forward['request_id']}&table={$forward['request_table']}";

                RequestManager::notifyStakeholders(
                    $pdo, $reqData, $forward['request_table'],
                    "Requisição Concluída",
                    "✅ Requisição #{$forward['request_id']} — \"{$reqTitle}\" — foi concluída pelo setor {$toAreaLabel} (via repasse).",
                    $link, $userId
                );
            }

            $pdo->commit();
            return ['success' => true, 'message' => "Requisição concluída com sucesso via repasse!"];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('RequestForwardService::completeForward: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erro ao concluir repasse.'];
        }
    }

    /**
     * Retorna toda a cadeia de repasses de uma requisição.
     * Ordenado cronologicamente para exibir no histórico.
     */
    public static function getForwardChain($pdo, $reqId, $reqTable) {
        try {
            $reqTable = normalizeRequestTable((string)$reqTable);
            if ($reqTable === null) {
                return ['success' => false, 'message' => 'Tabela de requisição inválida.', 'data' => []];
            }

            $stmt = $pdo->prepare("
                SELECT
                    rf.*,
                    uf.name AS forwarded_by_name,
                    ur.name AS received_by_name,
                    af.title AS from_area_name,
                    at2.title AS to_area_name
                FROM request_forwards rf
                LEFT JOIN ctd_users uf ON rf.forwarded_by = uf.id
                LEFT JOIN ctd_users ur ON rf.received_by = ur.id
                LEFT JOIN ctd_area af ON rf.from_area_id = af.id
                LEFT JOIN ctd_area at2 ON rf.to_area_id = at2.id
                WHERE rf.request_id = ? AND rf.request_table = ?
                ORDER BY rf.created_at ASC
            ");
            $stmt->execute([$reqId, $reqTable]);
            $chain = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Mapear labels amigáveis
            foreach ($chain as &$item) {
                $item['from_area_label'] = self::$sectorLabels[strtolower($item['from_area_name'] ?? '')] ?? $item['from_area_name'];
                $item['to_area_label'] = self::$sectorLabels[strtolower($item['to_area_name'] ?? '')] ?? $item['to_area_name'];
                $item['status_label'] = self::getStatusLabel($item['status']);
                $item['status_color'] = self::getStatusColor($item['status']);
            }

            return ['success' => true, 'data' => $chain];

        } catch (Exception $e) {
            error_log('RequestForwardService::getForwardChain: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erro ao carregar repasses.', 'data' => []];
        }
    }

    /**
     * Lista repasses pendentes para um gestor específico.
     * Busca por repasses onde o setor de destino está nos setores vinculados ao gestor.
     */
    public static function getPendingForwards($pdo, $userId, $forwardStatus = null) {
        try {
            // Buscar os setores do gestor
            $stmt = $pdo->prepare("
                SELECT a.id AS area_id, LOWER(a.title) AS sector
                FROM ctd_area a
                JOIN cfg_user_area cua ON a.id = cua.id_area
                WHERE cua.id_user = ?
            ");
            $stmt->execute([$userId]);
            $userAreas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($userAreas)) {
                return ['success' => true, 'data' => []];
            }

            $areaIds = array_column($userAreas, 'area_id');
            $placeholders = implode(',', array_fill(0, count($areaIds), '?'));

            // Buscar repasses pendentes para esses setores
            $query = "
                SELECT
                    rf.*,
                    uf.name AS forwarded_by_name,
                    af.title AS from_area_name,
                    at2.title AS to_area_name
                FROM request_forwards rf
                LEFT JOIN ctd_users uf ON rf.forwarded_by = uf.id
                LEFT JOIN ctd_area af ON rf.from_area_id = af.id
                LEFT JOIN ctd_area at2 ON rf.to_area_id = at2.id
                WHERE rf.to_area_id IN ($placeholders)
            ";
            $params = $areaIds;

            if ($forwardStatus) {
                $query .= " AND rf.status = ?";
                $params[] = $forwardStatus;
            } else {
                $query .= " AND rf.status IN ('pending', 'accepted')";
            }

            $query .= " ORDER BY rf.created_at DESC";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $forwards = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Para cada repasse, buscar dados da requisição original
            foreach ($forwards as &$fwd) {
                $sector = $fwd['request_table'];
                $fullTable = "ctd_{$sector}_frm";
                try {
                    $reqStmt = $pdo->prepare("
                        SELECT r.id, r.title, r.created_at, r.date, r.urgent, r.priority,
                               u.name AS solicitor_name
                        FROM `$fullTable` r
                        LEFT JOIN ctd_users u ON r.created_by = u.id
                        WHERE r.id = ?
                    ");
                    $reqStmt->execute([$fwd['request_id']]);
                    $fwd['request_data'] = $reqStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                } catch (Exception $e) {
                    $fwd['request_data'] = [];
                }

                $fwd['from_area_label'] = self::$sectorLabels[strtolower($fwd['from_area_name'] ?? '')] ?? $fwd['from_area_name'];
                $fwd['to_area_label'] = self::$sectorLabels[strtolower($fwd['to_area_name'] ?? '')] ?? $fwd['to_area_name'];
            }

            return ['success' => true, 'data' => $forwards];

        } catch (Exception $e) {
            error_log('RequestForwardService::getPendingForwards: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Erro ao carregar repasses pendentes.', 'data' => []];
        }
    }

    /**
     * Busca o repasse ativo (pending ou accepted) de uma requisição, se existir.
     * Utilizado para identificar visualmente requisições repassadas na listagem.
     */
    public static function getActiveForward($pdo, $reqId, $reqTable) {
        $stmt = $pdo->prepare("
            SELECT rf.*,
                   af.title AS from_area_name,
                   at2.title AS to_area_name,
                   uf.name AS forwarded_by_name
            FROM request_forwards rf
            LEFT JOIN ctd_area af ON rf.from_area_id = af.id
            LEFT JOIN ctd_area at2 ON rf.to_area_id = at2.id
            LEFT JOIN ctd_users uf ON rf.forwarded_by = uf.id
            WHERE rf.request_id = ? AND rf.request_table = ?
            AND rf.status IN ('pending', 'accepted')
            ORDER BY rf.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$reqId, $reqTable]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Busca repasses filtrados pelo setor de DESTINO (slug).
     * Útil para filtros dinâmicos onde queremos ver o que um setor recebeu.
     */
    public static function getForwardsByDestination($pdo, $sectorSlug, $searchText = null, $searchDate = null, $subdivisionId = null, $forwardStatus = null) {
        try {
            $query = "
                SELECT
                    rf.*,
                    uf.name AS forwarded_by_name,
                    af.title AS from_area_name,
                    at2.title AS to_area_name
                FROM request_forwards rf
                LEFT JOIN ctd_users uf ON rf.forwarded_by = uf.id
                LEFT JOIN ctd_area af ON rf.from_area_id = af.id
                LEFT JOIN ctd_area at2 ON rf.to_area_id = at2.id
                WHERE 1=1
            ";
            $params = [];

            if ($forwardStatus) {
                $query .= " AND rf.status = ?";
                $params[] = $forwardStatus;
            } else {
                $query .= " AND rf.status IN ('pending', 'accepted')";
            }

            if ($sectorSlug && $sectorSlug !== 'all') {
                $aliases = sectorAreaAliases((string)$sectorSlug);
                $placeholders = implode(',', array_fill(0, count($aliases), '?'));
                $query .= " AND LOWER(at2.title) IN ($placeholders)";
                $params = array_merge($params, $aliases);
            }

            $query .= " ORDER BY rf.created_at DESC";

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $forwards = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $filteredForwards = [];
            foreach ($forwards as $fwd) {
                $sector = $fwd['request_table'];
                $fullTable = "ctd_{$sector}_frm";

                $reqQuery = "
                    SELECT r.id, r.title, r.created_at, r.date, r.urgent, r.priority,
                           u.name AS solicitor_name,
                           sub.name AS subdivision_name, sub.slug AS subdivision_slug
                    FROM `$fullTable` r
                    LEFT JOIN ctd_users u ON r.created_by = u.id
                    LEFT JOIN ctd_subdivision sub ON r.subdivision_id = sub.id
                    WHERE r.id = ?
                ";
                $reqParams = [$fwd['request_id']];

                if ($searchText) {
                    $reqQuery .= " AND r.title LIKE ?";
                    $reqParams[] = "%$searchText%";
                }
                if ($searchDate) {
                    $reqQuery .= " AND r.date = ?";
                    $reqParams[] = $searchDate;
                }
                if ($subdivisionId !== null) {
                    if (is_array($subdivisionId)) {
                        if (empty($subdivisionId)) {
                            continue;
                        }
                        $placeholders = implode(',', array_fill(0, count($subdivisionId), '?'));
                        $subdivisionIds = array_map('intval', $subdivisionId);
                        $reqQuery .= " AND (
                            r.subdivision_id IN ($placeholders)
                            OR EXISTS (
                                SELECT 1
                                FROM cfg_user_subdivision cus
                                WHERE cus.id_user = r.created_by
                                  AND cus.id_subdivision IN ($placeholders)
                            )
                        )";
                        $reqParams = array_merge($reqParams, $subdivisionIds, $subdivisionIds);
                    } else {
                        $reqQuery .= " AND (
                            r.subdivision_id = ?
                            OR EXISTS (
                                SELECT 1
                                FROM cfg_user_subdivision cus
                                WHERE cus.id_user = r.created_by
                                  AND cus.id_subdivision = ?
                            )
                        )";
                        $reqParams[] = (int)$subdivisionId;
                        $reqParams[] = (int)$subdivisionId;
                    }
                }

                $reqStmt = $pdo->prepare($reqQuery);
                $reqStmt->execute($reqParams);
                $reqData = $reqStmt->fetch(PDO::FETCH_ASSOC);

                if ($reqData) {
                    $fwd['request_data'] = $reqData;
                    $fwd['from_area_label'] = self::$sectorLabels[strtolower($fwd['from_area_name'] ?? '')] ?? $fwd['from_area_name'];
                    $fwd['to_area_label'] = self::$sectorLabels[strtolower($fwd['to_area_name'] ?? '')] ?? $fwd['to_area_name'];

                    // Mapear para o formato esperado pelo front
                    $row = $reqData;
                    $row['status'] = 'F';
                    $row['st_raw'] = 'F';
                    $row['table'] = $fwd['request_table'];
                    $row['sector'] = $fwd['request_table'];
                    $row['responsible_sector'] = strtolower($fwd['to_area_name'] ?? '');
                    $row['is_forwarded_to_me'] = true;
                    $row['forward_from'] = $fwd['from_area_label'];

                    $filteredForwards[] = $row;
                }
            }

            return $filteredForwards;
        } catch (Exception $e) {
            return [];
        }
    }

    // ========================
    // MÉTODOS AUXILIARES PRIVADOS
    // ========================

    /**
     * Verifica se existe repasse circular na cadeia ativa.
     * Impede que A→B→A aconteça.
     */
    private static function hasCircularForward($pdo, $reqId, $reqTable, $fromAreaId, $toAreaId) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM request_forwards
            WHERE request_id = ? AND request_table = ?
            AND from_area_id = ? AND to_area_id = ?
            AND status IN ('pending', 'accepted')
        ");
        $stmt->execute([$reqId, $reqTable, $toAreaId, $fromAreaId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Busca o ID do setor (ctd_area) pelo nome do setor (ex: 'ti' → id).
     */
    private static function getAreaIdBySector($pdo, $sectorName) {
        $aliases = sectorAreaAliases((string)$sectorName);
        $placeholders = implode(',', array_fill(0, count($aliases), '?'));
        $stmt = $pdo->prepare("SELECT id FROM ctd_area WHERE LOWER(title) IN ($placeholders) ORDER BY id LIMIT 1");
        $stmt->execute($aliases);
        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Busca dados de um setor pelo ID.
     */
    private static function getAreaById($pdo, $areaId) {
        $stmt = $pdo->prepare("SELECT * FROM ctd_area WHERE id = ?");
        $stmt->execute([$areaId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Busca um repasse pelo ID.
     */
    private static function getForwardById($pdo, $forwardId) {
        $stmt = $pdo->prepare("SELECT * FROM request_forwards WHERE id = ?");
        $stmt->execute([$forwardId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Verifica se o usuário tem permissão sobre um setor (via cfg_user_area).
     * Admins têm acesso global.
     */
    private static function userHasAreaPermission($pdo, $userId, $areaId) {
        // Verificar se é admin (acesso global)
        $stmt = $pdo->prepare("SELECT role FROM ctd_users WHERE id = ?");
        $stmt->execute([$userId]);
        $userRole = $stmt->fetchColumn();

        if (in_array($userRole, ['admin', 'adm', 'coord'])) {
            return true;
        }

        // Verificar vínculo direto
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM cfg_user_area WHERE id_user = ? AND id_area = ?");
        $stmt->execute([$userId, $areaId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Retorna label amigável do status do repasse.
     */
    private static function getStatusLabel($status) {
        $labels = [
            'pending'   => 'Aguardando Aceitação',
            'accepted'  => 'Aceito',
            'refused'   => 'Recusado',
            'completed' => 'Concluído',
        ];
        return $labels[$status] ?? ucfirst($status);
    }

    /**
     * Retorna cor CSS do status do repasse.
     */
    private static function getStatusColor($status) {
        $colors = [
            'pending'   => '#f59e0b',
            'accepted'  => '#3b82f6',
            'refused'   => '#ef4444',
            'completed' => '#10b981',
        ];
        return $colors[$status] ?? '#6b7280';
    }

    /**
     * Lista todos os setores disponíveis (para o dropdown de repasse).
     */
    public static function getAvailableAreas($pdo) {
        $stmt = $pdo->query("SELECT id, title FROM ctd_area ORDER BY title ASC");
        $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($areas as &$area) {
            $area['label'] = self::$sectorLabels[strtolower($area['title'])] ?? $area['title'];
        }

        return $areas;
    }
}
