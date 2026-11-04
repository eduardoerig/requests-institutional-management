<?php
/**
 * Encaminhamento de pedidos entre setores.
 *
 * Dois modos:
 *  - transfer: o outro setor passa a ser o responsável (depois de aceitar).
 *  - help:     o outro setor faz uma parte e devolve; o responsável não muda.
 *
 * Estados de um encaminhamento:
 *  pending → accepted → (help) returned
 *  pending → refused | cancelled
 *  Um encaminhamento "em aberto" (pending, ou ajuda aceita) impede outro ao mesmo tempo.
 *
 * O responsável atual de um pedido é o destino da transferência aceita mais recente;
 * sem transferência aceita, é o setor original (o setor do formulário).
 */

require_once __DIR__ . '/RequestManager.php';
require_once __DIR__ . '/../config/security.php';

class RequestForwardService
{
    public const MIN_MESSAGE = 10;

    // ------------------------------------------------------------------
    // Setores
    // ------------------------------------------------------------------

    public static function areaLabel(PDO $pdo, ?int $areaId): string
    {
        if (!$areaId) {
            return 'Setor';
        }
        $title = areaTitles($pdo)[$areaId] ?? '';
        $slug = areaTitleToSector($title);
        return $slug ? self::sectorName($slug) : ($title !== '' ? ucfirst($title) : 'Setor');
    }

    public static function sectorName(string $slug): string
    {
        return sectorLabel($slug); // config/sectors.php
    }

    public static function areaSlug(PDO $pdo, ?int $areaId): ?string
    {
        if (!$areaId) {
            return null;
        }
        return areaTitleToSector(areaTitles($pdo)[$areaId] ?? '');
    }

    public static function originAreaId(PDO $pdo, string $sector): ?int
    {
        return getAreaIdBySector($pdo, $sector);
    }

    /** Áreas (setores) às quais o usuário está ligado */
    public static function userAreaIds(PDO $pdo, int $userId): array
    {
        static $cache = [];
        if (!isset($cache[$userId])) {
            $stmt = $pdo->prepare('SELECT id_area FROM cfg_user_area WHERE id_user = ?');
            $stmt->execute([$userId]);
            $cache[$userId] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        return $cache[$userId];
    }

    public static function userInArea(PDO $pdo, int $userId, ?int $areaId): bool
    {
        return $areaId !== null && in_array((int)$areaId, self::userAreaIds($pdo, $userId), true);
    }

    /** Setores de atendimento para onde se pode encaminhar (exclui o responsável atual) */
    public static function availableAreas(PDO $pdo, ?int $excludeAreaId = null): array
    {
        $areas = [];
        $titles = areaTitles($pdo);
        $descriptions = areaDescriptions($pdo);
        foreach ($titles as $id => $title) {
            $slug = areaTitleToSector($title);
            if ($slug === null || $id === (int)$excludeAreaId) {
                continue;
            }
            $areas[] = ['id' => $id, 'slug' => $slug, 'label' => self::sectorName($slug), 'description' => $descriptions[$id] ?? ''];
        }
        // Em ordem do nome que a pessoa lê (o título no banco pode ser "shop", "mkt"...)
        usort($areas, fn($a, $b) => strcoll(mb_strtolower($a['label']), mb_strtolower($b['label'])));
        return $areas;
    }

    // ------------------------------------------------------------------
    // Leitura
    // ------------------------------------------------------------------

    public static function getById(PDO $pdo, int $forwardId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM request_forwards WHERE id = ?');
        $stmt->execute([$forwardId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Todos os encaminhamentos do pedido, do mais antigo ao mais novo, com nomes */
    public static function forRequest(PDO $pdo, int $reqId, string $sector): array
    {
        $stmt = $pdo->prepare("
            SELECT rf.*, uf.name AS forwarded_by_name, ur.name AS responded_by_name
            FROM request_forwards rf
            LEFT JOIN ctd_users uf ON uf.id = rf.forwarded_by
            LEFT JOIN ctd_users ur ON ur.id = rf.responded_by
            WHERE rf.request_id = ? AND rf.request_table = ?
            ORDER BY rf.id ASC
        ");
        $stmt->execute([$reqId, $sector]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['from_label'] = self::areaLabel($pdo, (int)$row['from_area_id']);
            $row['to_label'] = self::areaLabel($pdo, (int)$row['to_area_id']);
        }
        return $rows;
    }

    /** Encaminhamento em aberto (pendente, ou ajuda aceita) */
    public static function openForward(PDO $pdo, int $reqId, string $sector): ?array
    {
        $stmt = $pdo->prepare("
            SELECT rf.*, uf.name AS forwarded_by_name FROM request_forwards rf
            LEFT JOIN ctd_users uf ON uf.id = rf.forwarded_by
            WHERE rf.request_id = ? AND rf.request_table = ?
              AND (rf.status = 'pending' OR (rf.mode = 'help' AND rf.status = 'accepted'))
            ORDER BY rf.id DESC LIMIT 1
        ");
        $stmt->execute([$reqId, $sector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['from_label'] = self::areaLabel($pdo, (int)$row['from_area_id']);
        $row['to_label'] = self::areaLabel($pdo, (int)$row['to_area_id']);
        return $row;
    }

    /** Setor responsável agora: destino da última transferência aceita, senão o setor original */
    public static function ownerAreaId(PDO $pdo, int $reqId, string $sector): ?int
    {
        $stmt = $pdo->prepare("
            SELECT to_area_id FROM request_forwards
            WHERE request_id = ? AND request_table = ? AND mode = 'transfer' AND status IN ('accepted', 'completed')
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$reqId, $sector]);
        $areaId = $stmt->fetchColumn();
        return $areaId ? (int)$areaId : self::originAreaId($pdo, $sector);
    }

    // ------------------------------------------------------------------
    // Ações
    // ------------------------------------------------------------------

    /** Encaminha o pedido. Quem pode: gestor do setor responsável ou administrador geral. */
    public static function forward(PDO $pdo, string $sector, int $reqId, int $toAreaId, string $mode, string $message, int $userId, string $userName, string $role): array
    {
        $sector = normalizeRequestTable($sector) ?? '';
        $req = $sector ? getRequestRow($pdo, $sector, $reqId) : null;
        if (!$req) {
            return self::fail('Pedido não encontrado.');
        }
        if (!in_array($mode, ['transfer', 'help'], true)) {
            return self::fail('Escolha se vai transferir ou pedir ajuda.');
        }
        $message = trim($message);
        if (mb_strlen($message) < self::MIN_MESSAGE) {
            return self::fail('Escreva para o outro setor o que ele precisa saber ou fazer (pelo menos ' . self::MIN_MESSAGE . ' letras).');
        }
        $status = strtoupper((string)($req['status'] ?? 'P'));
        if (!in_array($status, ['Y', 'W'], true)) {
            return self::fail('Só dá para encaminhar pedidos aprovados ou em atendimento. Situação atual: ' . statusLabel($status) . '.');
        }

        $ownerArea = self::ownerAreaId($pdo, $reqId, $sector);
        if (!isGlobalAdminRole($role) && !self::userInArea($pdo, $userId, $ownerArea)) {
            return self::fail('Só o setor responsável pelo pedido pode encaminhá-lo.');
        }
        if ((int)$toAreaId === (int)$ownerArea) {
            return self::fail('Escolha um setor diferente do que já cuida do pedido.');
        }
        $targetSlug = self::areaSlug($pdo, $toAreaId);
        if ($targetSlug === null) {
            return self::fail('Setor de destino inválido.');
        }
        if ($open = self::openForward($pdo, $reqId, $sector)) {
            return self::fail('Este pedido já tem um encaminhamento em aberto com ' . $open['to_label'] . '. Aguarde a resposta ou cancele o envio.');
        }

        $toLabel = self::sectorName($targetSlug);
        $fromLabel = self::areaLabel($pdo, $ownerArea);

        try {
            $pdo->beginTransaction();
            // Trava o pedido e confere de novo: dois cliques ao mesmo tempo não abrem dois encaminhamentos
            $pdo->prepare("SELECT id FROM `ctd_{$sector}_frm` WHERE id = ? FOR UPDATE")->execute([$reqId]);
            if ($open = self::openForward($pdo, $reqId, $sector)) {
                $pdo->rollBack();
                return self::fail('Este pedido já tem um encaminhamento em aberto com ' . $open['to_label'] . '. Aguarde a resposta ou cancele o envio.');
            }
            $pdo->prepare("
                INSERT INTO request_forwards (request_id, request_table, from_area_id, forwarded_by, to_area_id, mode, observation, status, previous_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)
            ")->execute([$reqId, $sector, $ownerArea, $userId, $toAreaId, $mode, mb_substr($message, 0, 3000), $status]);
            $forwardId = (int)$pdo->lastInsertId();

            if ($mode === 'transfer') {
                self::setRequestStatus($pdo, $sector, $reqId, $status, 'F');
            }
            RequestManager::addHistory($pdo, $reqId, $sector, $userId, $userName,
                $mode === 'transfer' ? 'Encaminhou para o setor ' . $toLabel : 'Pediu ajuda ao setor ' . $toLabel,
                $fromLabel, $toLabel);
            $pdo->commit();
        } catch (Throwable $e) {
            return self::rollback($pdo, $e, 'forward');
        }

        $link = self::link($reqId, $sector);
        $title = $req['title'] ?? 'Pedido';
        self::notifyArea($pdo, $toAreaId, $userId,
            $mode === 'transfer' ? "Pedido nº $reqId chegou de $fromLabel" : "$fromLabel pediu ajuda (nº $reqId)",
            ($mode === 'transfer' ? "$fromLabel quer passar \"$title\" para vocês: " : "$fromLabel pede ajuda em \"$title\": ") . self::preview($message),
            $link);
        if ($mode === 'transfer' && !empty($req['created_by'])) {
            RequestManager::notify($pdo, (int)$req['created_by'], "Pedido nº $reqId encaminhado",
                "Seu pedido \"$title\" foi encaminhado ao setor de $toLabel.", $link);
        }

        return ['success' => true, 'message' => $mode === 'transfer'
            ? "Pedido encaminhado. $toLabel precisa aceitar para assumir."
            : "Pedido de ajuda enviado para $toLabel.", 'forward_id' => $forwardId];
    }

    /** Aceitar (destino ou admin) */
    public static function accept(PDO $pdo, int $forwardId, int $userId, string $userName, string $role): array
    {
        $fwd = self::getById($pdo, $forwardId);
        if (!$fwd || $fwd['status'] !== 'pending') {
            return self::fail('Este encaminhamento já foi respondido.');
        }
        if (!isGlobalAdminRole($role) && !self::userInArea($pdo, $userId, (int)$fwd['to_area_id'])) {
            return self::fail('Só o setor que recebeu pode aceitar.');
        }
        $sector = $fwd['request_table'];
        $reqId = (int)$fwd['request_id'];
        $toLabel = self::areaLabel($pdo, (int)$fwd['to_area_id']);

        try {
            $pdo->beginTransaction();
            self::changeForward($pdo, $forwardId, 'pending', 'accepted', $userId, null);
            if ($fwd['mode'] === 'transfer') {
                // Volta à situação de antes (aprovado ou em atendimento), agora com o novo setor
                $back = in_array($fwd['previous_status'], ['Y', 'W'], true) ? $fwd['previous_status'] : 'Y';
                self::setRequestStatus($pdo, $sector, $reqId, 'F', $back);
            }
            RequestManager::addHistory($pdo, $reqId, $sector, $userId, $userName,
                $fwd['mode'] === 'transfer' ? "Assumiu o pedido ($toLabel)" : "Aceitou ajudar ($toLabel)",
                null, $toLabel);
            $pdo->commit();
        } catch (Throwable $e) {
            return self::rollback($pdo, $e, 'accept');
        }

        $req = getRequestRow($pdo, $sector, $reqId) ?? [];
        $link = self::link($reqId, $sector);
        self::notifyArea($pdo, (int)$fwd['from_area_id'], $userId,
            $fwd['mode'] === 'transfer' ? "$toLabel assumiu o pedido nº $reqId" : "$toLabel aceitou ajudar (nº $reqId)",
            "$userName aceitou o encaminhamento.", $link);
        if ($fwd['mode'] === 'transfer' && !empty($req['created_by'])) {
            RequestManager::notify($pdo, (int)$req['created_by'], "Pedido nº $reqId com novo setor",
                "Agora o setor de $toLabel cuida do seu pedido.", $link);
        }
        return ['success' => true, 'message' => $fwd['mode'] === 'transfer'
            ? 'Pronto: o pedido agora é do seu setor.'
            : 'Ajuda aceita. Quando terminar, devolva contando o que foi feito.'];
    }

    /** Recusar com motivo (destino ou admin) */
    public static function refuse(PDO $pdo, int $forwardId, string $reason, int $userId, string $userName, string $role): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            return self::fail('Explique o motivo da recusa para o outro setor entender.');
        }
        $fwd = self::getById($pdo, $forwardId);
        if (!$fwd || $fwd['status'] !== 'pending') {
            return self::fail('Este encaminhamento já foi respondido.');
        }
        if (!isGlobalAdminRole($role) && !self::userInArea($pdo, $userId, (int)$fwd['to_area_id'])) {
            return self::fail('Só o setor que recebeu pode recusar.');
        }
        $sector = $fwd['request_table'];
        $reqId = (int)$fwd['request_id'];
        $toLabel = self::areaLabel($pdo, (int)$fwd['to_area_id']);

        try {
            $pdo->beginTransaction();
            self::changeForward($pdo, $forwardId, 'pending', 'refused', $userId, $reason);
            if ($fwd['mode'] === 'transfer') {
                $back = in_array($fwd['previous_status'], ['Y', 'W'], true) ? $fwd['previous_status'] : 'Y';
                self::setRequestStatus($pdo, $sector, $reqId, 'F', $back);
            }
            RequestManager::addHistory($pdo, $reqId, $sector, $userId, $userName, "Recusou o encaminhamento ($toLabel)", null, null);
            $pdo->commit();
        } catch (Throwable $e) {
            return self::rollback($pdo, $e, 'refuse');
        }

        self::notifyArea($pdo, (int)$fwd['from_area_id'], $userId, "$toLabel recusou o pedido nº $reqId",
            'Motivo: ' . self::preview($reason), self::link($reqId, $sector));
        return ['success' => true, 'message' => 'Encaminhamento recusado. O setor que enviou recebeu o motivo.'];
    }

    /** Cancelar um envio pendente (quem enviou, setor de origem ou admin) */
    public static function cancel(PDO $pdo, int $forwardId, int $userId, string $userName, string $role): array
    {
        $fwd = self::getById($pdo, $forwardId);
        if (!$fwd || $fwd['status'] !== 'pending') {
            return self::fail('Só dá para cancelar enquanto o outro setor não respondeu.');
        }
        $allowed = isGlobalAdminRole($role) || (int)$fwd['forwarded_by'] === $userId || self::userInArea($pdo, $userId, (int)$fwd['from_area_id']);
        if (!$allowed) {
            return self::fail('Só quem enviou pode cancelar.');
        }
        $sector = $fwd['request_table'];
        $reqId = (int)$fwd['request_id'];
        $toLabel = self::areaLabel($pdo, (int)$fwd['to_area_id']);

        try {
            $pdo->beginTransaction();
            self::changeForward($pdo, $forwardId, 'pending', 'cancelled', $userId, null);
            if ($fwd['mode'] === 'transfer') {
                $back = in_array($fwd['previous_status'], ['Y', 'W'], true) ? $fwd['previous_status'] : 'Y';
                self::setRequestStatus($pdo, $sector, $reqId, 'F', $back);
            }
            RequestManager::addHistory($pdo, $reqId, $sector, $userId, $userName, "Cancelou o encaminhamento para $toLabel", null, null);
            $pdo->commit();
        } catch (Throwable $e) {
            return self::rollback($pdo, $e, 'cancel');
        }

        self::notifyArea($pdo, (int)$fwd['to_area_id'], $userId, "Encaminhamento cancelado (nº $reqId)",
            "$userName cancelou o envio do pedido para vocês.", self::link($reqId, $sector));
        return ['success' => true, 'message' => 'Envio cancelado.'];
    }

    /** Devolver uma ajuda com o que foi feito (setor que ajudou ou admin) */
    public static function returnHelp(PDO $pdo, int $forwardId, string $note, int $userId, string $userName, string $role): array
    {
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            return self::fail('Conte em poucas palavras o que foi feito.');
        }
        $fwd = self::getById($pdo, $forwardId);
        if (!$fwd || $fwd['mode'] !== 'help' || !in_array($fwd['status'], ['pending', 'accepted'], true)) {
            return self::fail('Esta ajuda não está em aberto.');
        }
        if (!isGlobalAdminRole($role) && !self::userInArea($pdo, $userId, (int)$fwd['to_area_id'])) {
            return self::fail('Só o setor que está ajudando pode devolver.');
        }
        $sector = $fwd['request_table'];
        $reqId = (int)$fwd['request_id'];
        $toLabel = self::areaLabel($pdo, (int)$fwd['to_area_id']);

        try {
            $pdo->beginTransaction();
            self::changeForward($pdo, $forwardId, $fwd['status'], 'returned', $userId, $note);
            RequestManager::addHistory($pdo, $reqId, $sector, $userId, $userName, "Devolveu a ajuda ($toLabel)", null, null);
            $pdo->commit();
        } catch (Throwable $e) {
            return self::rollback($pdo, $e, 'return');
        }

        self::notifyArea($pdo, (int)$fwd['from_area_id'], $userId, "$toLabel devolveu a ajuda (nº $reqId)",
            self::preview($note), self::link($reqId, $sector));
        return ['success' => true, 'message' => 'Ajuda devolvida ao setor responsável.'];
    }

    // ------------------------------------------------------------------
    // Apoio
    // ------------------------------------------------------------------

    private static function changeForward(PDO $pdo, int $id, string $from, string $to, int $userId, ?string $note): void
    {
        $stmt = $pdo->prepare("
            UPDATE request_forwards
            SET status = ?, responded_by = ?, responded_at = NOW(),
                response_note = COALESCE(?, response_note),
                received_by = CASE WHEN ? = 'accepted' THEN ? ELSE received_by END
            WHERE id = ? AND status = ?
        ");
        $stmt->execute([$to, $userId, $note, $to, $userId, $id, $from]);
        if ($stmt->rowCount() === 0) {
            throw new UserMessage('Outra pessoa respondeu este encaminhamento agora há pouco. Atualize a tela.');
        }
    }

    private static function setRequestStatus(PDO $pdo, string $sector, int $reqId, string $from, string $to): void
    {
        $sector = normalizeRequestTable($sector);
        $stmt = $pdo->prepare("UPDATE `ctd_{$sector}_frm` SET status = ? WHERE id = ? AND status = ?");
        $stmt->execute([$to, $reqId, $from]);
        if ($stmt->rowCount() === 0) {
            throw new UserMessage('O pedido mudou de situação agora há pouco. Atualize a tela.');
        }
    }

    /** Avisa todos os responsáveis ativos de um setor (menos quem fez a ação) */
    private static function notifyArea(PDO $pdo, int $areaId, int $exceptUserId, string $title, string $message, string $link): void
    {
        // Roda depois do commit: falha aqui vai para o log e não vira erro na tela
        try {
            $stmt = $pdo->prepare("
                SELECT DISTINCT u.id FROM ctd_users u
                JOIN cfg_user_area cua ON cua.id_user = u.id
                WHERE cua.id_area = ? AND u.status = 1
            ");
            $stmt->execute([$areaId]);
            RequestManager::notifyMany($pdo, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), $title, $message, $link, $exceptUserId);
        } catch (Throwable $e) {
            error_log("RequestForwardService::notifyArea area=$areaId \"$title\": " . $e->getMessage());
        }
    }

    private static function link(int $reqId, string $sector): string
    {
        return "request_detail?id=$reqId&table=$sector";
    }

    private static function preview(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        return mb_strlen($text) > 140 ? mb_substr($text, 0, 137) . '...' : $text;
    }

    private static function fail(string $message): array
    {
        return ['success' => false, 'message' => $message];
    }

    private static function rollback(PDO $pdo, Throwable $e, string $where): array
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof UserMessage) {
            return self::fail($e->getMessage());
        }
        error_log("RequestForwardService::$where: " . $e->getMessage());
        return self::fail('Não foi possível concluir agora. Tente de novo.');
    }
}
