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
 *
 * Setor parceiro: uma ajuda (is_partner = 1) incluída pela coordenação, com prazo próprio (due_date).
 * Até MAX_PARTNERS parceiros ao mesmo tempo. Quem pede só sugere (request_partner_suggestions);
 * o setor só vê o pedido depois da aprovação.
 * O principal segue trabalhando, não desfaz a inclusão e só conclui depois que o parceiro devolver.
 */

require_once __DIR__ . '/RequestManager.php';
require_once __DIR__ . '/../config/security.php';

class RequestForwardService
{
    public const MIN_MESSAGE = 10;

    /** Setores parceiros abertos ao mesmo tempo em um pedido, além do principal */
    public const MAX_PARTNERS = 2;

    // Situações de um encaminhamento (request_forwards.status)
    public const ST_PENDING = 'pending';
    public const ST_ACCEPTED = 'accepted';
    public const ST_REFUSED = 'refused';
    public const ST_CANCELLED = 'cancelled';
    public const ST_RETURNED = 'returned';
    public const ST_COMPLETED = 'completed';

    /** Transferência que valeu (aceita ou concluída): o destino virou o responsável. Em SQL e em PHP. */
    public const EFFECTIVE_TRANSFER_SQL = "mode = 'transfer' AND status IN ('accepted', 'completed')";

    /** Encaminhamento em aberto: pendente, ou ajuda aceita e ainda não devolvida. Em SQL (alias rf.) e em PHP. */
    public const OPEN_SQL = "(rf.status = 'pending' OR (rf.mode = 'help' AND rf.status = 'accepted'))";

    public static function isOpen(array $f): bool
    {
        return ($f['status'] ?? '') === self::ST_PENDING || (($f['mode'] ?? '') === 'help' && ($f['status'] ?? '') === self::ST_ACCEPTED);
    }

    public static function isEffectiveTransfer(array $f): bool
    {
        return ($f['mode'] ?? '') === 'transfer' && in_array($f['status'] ?? '', [self::ST_ACCEPTED, self::ST_COMPLETED], true);
    }

    /**
     * Responsável por transferência aceita, em lote: "tabela:id" => área de destino da última.
     * Pedido sem transferência aceita não aparece (o responsável é o setor do formulário).
     * $where/$params filtram os pedidos (ex.: "(request_table = ? AND request_id IN (...))").
     */
    public static function ownerAreaIdsFor(PDO $pdo, string $where = '1 = 1', array $params = []): array
    {
        $stmt = $pdo->prepare('SELECT request_table, request_id, to_area_id FROM request_forwards WHERE '
            . self::EFFECTIVE_TRANSFER_SQL . " AND ($where) ORDER BY id ASC");
        $stmt->execute($params);
        $owner = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $owner[$f['request_table'] . ':' . $f['request_id']] = (int)$f['to_area_id'];
        }
        return $owner;
    }

    /** Responsável a partir dos encaminhamentos já carregados do pedido (sem nova consulta) */
    public static function ownerFromForwards(PDO $pdo, array $forwards, string $sector): ?int
    {
        $owner = null;
        foreach ($forwards as $f) {
            if (self::isEffectiveTransfer($f)) {
                $owner = (int)$f['to_area_id'];
            }
        }
        return $owner ?? self::originAreaId($pdo, $sector);
    }

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
              AND " . self::OPEN_SQL . "
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

    /** Todos os encaminhamentos em aberto: mais de um só acontece com setores parceiros */
    public static function openForwards(PDO $pdo, int $reqId, string $sector): array
    {
        return array_values(array_filter(self::forRequest($pdo, $reqId, $sector), [self::class, 'isOpen']));
    }

    /** Setor responsável agora: destino da última transferência aceita, senão o setor original */
    public static function ownerAreaId(PDO $pdo, int $reqId, string $sector): ?int
    {
        $stmt = $pdo->prepare('SELECT to_area_id FROM request_forwards WHERE request_id = ? AND request_table = ? AND '
            . self::EFFECTIVE_TRANSFER_SQL . ' ORDER BY id DESC LIMIT 1');
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
                $fromLabel, $toLabel, $mode === 'transfer' ? 'forward_transfer' : 'forward_help');
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

    // ------------------------------------------------------------------
    // Setor parceiro
    // ------------------------------------------------------------------

    public static function isPartner(array $f): bool
    {
        return !empty($f['is_partner']);
    }

    /** A parte do parceiro passou do prazo? (só faz sentido com a parte em aberto) */
    public static function partnerIsLate(array $f): bool
    {
        $due = substr((string)($f['due_date'] ?? ''), 0, 10);
        return self::isPartner($f) && self::isOpen($f) && $due !== '' && $due < date('Y-m-d');
    }

    /** Nomes dos setores para as frases: "Compras" ou "Compras e Marketing" */
    public static function partnerNames(array $forwards): string
    {
        return implode(' e ', array_column($forwards, 'to_label'));
    }

    /** Prazo inicial da parte: o do pedido; se já passou ou não existe, hoje */
    public static function defaultPartnerDue(array $req): string
    {
        $date = substr((string)($req['date'] ?? ''), 0, 10);
        $today = date('Y-m-d');
        return $date !== '' && strpos($date, '0000') !== 0 && $date > $today ? $date : $today;
    }

    /** Setor que pode ser sugerido em um pedido deste setor: existe, atende pedidos e não é o próprio */
    public static function canSuggestPartner(PDO $pdo, string $sector, int $areaId): bool
    {
        $slug = self::areaSlug($pdo, $areaId);
        return $slug !== null && $slug !== $sector;
    }

    /** Guarda a sugestão de quem pediu (dentro da transação que cria o pedido) */
    public static function saveSuggestion(PDO $pdo, string $sector, int $reqId, int $areaId, int $userId, string $userName): void
    {
        $pdo->prepare('INSERT INTO request_partner_suggestions (request_id, request_table, area_id) VALUES (?, ?, ?)')
            ->execute([$reqId, $sector, $areaId]);
        RequestManager::addHistory($pdo, $reqId, $sector, $userId, $userName,
            'Sugeriu um setor parceiro', null, self::areaLabel($pdo, $areaId), 'partner_suggested');
    }

    /** Setores sugeridos por quem pediu, sem os que deixaram de valer (setor excluído ou que é o responsável) */
    public static function suggestedPartners(PDO $pdo, string $sector, int $reqId): array
    {
        $stmt = $pdo->prepare('SELECT area_id FROM request_partner_suggestions WHERE request_table = ? AND request_id = ? ORDER BY area_id');
        $stmt->execute([$sector, $reqId]);
        $ownerArea = (int)self::ownerAreaId($pdo, $reqId, $sector);
        return array_values(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)),
            fn($areaId) => $areaId !== $ownerArea && self::areaSlug($pdo, $areaId) !== null));
    }

    /** Sugestões em lote para a fila de aprovação: "tabela:id" => [áreas]. A tabela só guarda pedidos sem decisão. */
    public static function suggestionsFor(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query('SELECT request_table, request_id, area_id FROM request_partner_suggestions ORDER BY area_id')->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $out[$s['request_table'] . ':' . $s['request_id']][] = (int)$s['area_id'];
        }
        return $out;
    }

    public static function clearSuggestion(PDO $pdo, string $sector, int $reqId): void
    {
        $pdo->prepare('DELETE FROM request_partner_suggestions WHERE request_table = ? AND request_id = ?')->execute([$sector, $reqId]);
    }

    /**
     * Confere um parceiro escolhido pela coordenação. Sem setor (ou 0): sem parceiro.
     * Devolve null ou ['area', 'due', 'note']. Dado inválido: UserMessage.
     * @throws UserMessage
     */
    public static function resolvePartnerChoice(PDO $pdo, string $sector, array $req, array $input): ?array
    {
        $reqId = (int)($req['id'] ?? 0);
        $ownerArea = self::ownerAreaId($pdo, $reqId, $sector);
        $areaId = (int)($input['partner_area_id'] ?? 0);
        if ($areaId <= 0) {
            return null;
        }
        if (self::areaSlug($pdo, $areaId) === null) {
            throw new UserMessage('Setor parceiro inválido.');
        }
        if ($areaId === (int)$ownerArea) {
            throw new UserMessage('O setor parceiro precisa ser diferente do setor responsável.');
        }

        $due = trim((string)($input['partner_due'] ?? ''));
        if ($due === '') {
            $due = self::defaultPartnerDue($req);
        }
        $parsed = DateTime::createFromFormat('!Y-m-d', $due);
        if (!$parsed || $parsed->format('Y-m-d') !== $due) {
            throw new UserMessage('Informe uma data válida para o prazo da parte.');
        }
        if ($due < date('Y-m-d')) {
            throw new UserMessage('O prazo da parte não pode ser no passado.');
        }

        $note = trim((string)($input['partner_note'] ?? ''));
        if (mb_strlen($note) > 1000) {
            throw new UserMessage('O recado ao setor parceiro pode ter até 1.000 letras. Encurte o texto e envie de novo.');
        }
        return ['area' => $areaId, 'due' => $due, 'note' => $note];
    }

    /**
     * Escolha da aprovação: o primeiro parceiro (partner_*) e o segundo (partner2_*).
     * Sem partner_area_id no envio, valem as sugestões de quem pediu, com o prazo do pedido; 0 tira o primeiro.
     * @return array lista com até MAX_PARTNERS itens ['area', 'due', 'note']
     * @throws UserMessage
     */
    public static function resolvePartnerChoices(PDO $pdo, string $sector, array $req, array $input): array
    {
        $partners = [];
        if (!array_key_exists('partner_area_id', $input)) {
            foreach (self::suggestedPartners($pdo, $sector, (int)($req['id'] ?? 0)) as $areaId) {
                $partners[] = self::resolvePartnerChoice($pdo, $sector, $req, ['partner_area_id' => $areaId]);
            }
            return $partners;
        }
        $first = self::resolvePartnerChoice($pdo, $sector, $req, $input);
        if ($first) {
            $partners[] = $first;
        }
        if (!empty($input['partner2_area_id'])) {
            $second = self::resolvePartnerChoice($pdo, $sector, $req, [
                'partner_area_id' => $input['partner2_area_id'],
                'partner_due' => $input['partner2_due'] ?? '',
                'partner_note' => $input['partner2_note'] ?? '',
            ]);
            if ($first && $first['area'] === $second['area']) {
                throw new UserMessage('Escolha dois setores parceiros diferentes.');
            }
            $partners[] = $second;
        }
        return $partners;
    }

    /** Grava o parceiro. Roda dentro da transação de quem chama (aprovação ou addPartner). */
    public static function insertPartner(PDO $pdo, string $sector, int $reqId, array $partner, string $status, int $userId, string $userName): int
    {
        $ownerArea = self::ownerAreaId($pdo, $reqId, $sector);
        $toLabel = self::areaLabel($pdo, $partner['area']);
        $message = $partner['note'] !== '' ? $partner['note'] : "A coordenação incluiu $toLabel como setor parceiro deste pedido.";
        $pdo->prepare("
            INSERT INTO request_forwards (request_id, request_table, from_area_id, forwarded_by, to_area_id, mode, is_partner, due_date, observation, status, previous_status)
            VALUES (?, ?, ?, ?, ?, 'help', 1, ?, ?, 'pending', ?)
        ")->execute([$reqId, $sector, $ownerArea, $userId, $partner['area'], $partner['due'], $message, $status]);
        $forwardId = (int)$pdo->lastInsertId();
        RequestManager::addHistory($pdo, $reqId, $sector, $userId, $userName,
            'Incluiu o setor parceiro ' . $toLabel, self::areaLabel($pdo, $ownerArea), $toLabel, 'partner_added');
        return $forwardId;
    }

    /** Avisos da inclusão (depois do commit): parceiro, setor responsável e quem pediu */
    public static function notifyPartnerAdded(PDO $pdo, array $req, string $sector, array $partner, int $userId): void
    {
        $reqId = (int)$req['id'];
        $title = (string)($req['title'] ?? 'Pedido');
        $link = self::link($reqId, $sector);
        $toLabel = self::areaLabel($pdo, $partner['area']);
        $due = date('d/m', strtotime($partner['due']));
        self::notifyArea($pdo, $partner['area'], $userId, "Coordenação chamou vocês (nº $reqId)",
            "Vocês têm uma parte em \"$title\", com prazo até $due." . ($partner['note'] !== '' ? ' Recado: ' . self::preview($partner['note']) : ''),
            $link);
        self::notifyArea($pdo, (int)self::ownerAreaId($pdo, $reqId, $sector), $userId, "Coordenação incluiu $toLabel (nº $reqId)",
            "$toLabel tem uma parte em \"$title\", com prazo até $due.", $link);
        if (!empty($req['created_by']) && (int)$req['created_by'] !== $userId) {
            try {
                RequestManager::notify($pdo, (int)$req['created_by'], "Coordenação incluiu $toLabel (nº $reqId)",
                    "O setor de $toLabel também vai participar de \"$title\".", $link);
            } catch (Throwable $e) {
                error_log("RequestForwardService::notifyPartnerAdded $sector/$reqId: " . $e->getMessage());
            }
        }
    }

    /** Inclui o parceiro com o pedido em atendimento. Quem pode: coordenação da subdivisão ou admin geral. */
    public static function addPartner(PDO $pdo, string $sector, int $reqId, array $input, int $userId, string $userName, string $role): array
    {
        $sector = normalizeRequestTable($sector) ?? '';
        $req = $sector ? getRequestRow($pdo, $sector, $reqId) : null;
        if (!$req) {
            return self::fail('Pedido não encontrado.');
        }
        if (!userCanManageOriginalRequest($pdo, $req, $sector, $userId, $role)) {
            return self::fail('Só a coordenação do pedido pode incluir um setor parceiro.');
        }
        if (empty($input['partner_area_id'])) {
            return self::fail('Escolha o setor parceiro.');
        }
        try {
            $partner = self::resolvePartnerChoice($pdo, $sector, $req, $input);
        } catch (UserMessage $e) {
            return self::fail($e->getMessage());
        }

        try {
            $pdo->beginTransaction();
            // Trava o pedido e confere de novo: outro setor ou outra pessoa pode ter mudado o pedido agora
            $lock = $pdo->prepare("SELECT status FROM `ctd_{$sector}_frm` WHERE id = ? FOR UPDATE");
            $lock->execute([$reqId]);
            $status = strtoupper(trim((string)$lock->fetchColumn()));
            if (!in_array($status, ['Y', 'W'], true)) {
                $pdo->rollBack();
                return self::fail('Só dá para incluir um parceiro em pedidos aprovados ou em atendimento. Situação atual: ' . statusLabel($status) . '.');
            }
            $openList = self::openForwards($pdo, $reqId, $sector);
            foreach ($openList as $open) {
                if (!self::isPartner($open)) {
                    $pdo->rollBack();
                    return self::fail('Este pedido já tem um encaminhamento em aberto com ' . $open['to_label'] . '. Aguarde a resposta.');
                }
                if ((int)$open['to_area_id'] === $partner['area']) {
                    $pdo->rollBack();
                    return self::fail($open['to_label'] . ' já é setor parceiro deste pedido.');
                }
            }
            if (count($openList) >= self::MAX_PARTNERS) {
                $pdo->rollBack();
                return self::fail('Este pedido já tem ' . self::MAX_PARTNERS . ' setores parceiros: ' . self::partnerNames($openList) . '.');
            }
            self::insertPartner($pdo, $sector, $reqId, $partner, $status, $userId, $userName);
            $pdo->commit();
        } catch (Throwable $e) {
            return self::rollback($pdo, $e, 'addPartner');
        }

        self::notifyPartnerAdded($pdo, $req, $sector, $partner, $userId);
        return ['success' => true, 'message' => self::areaLabel($pdo, $partner['area']) . ' foi chamado como setor parceiro.'];
    }

    /** Avisa quem decide pelo pedido: administradores gerais e a coordenação da subdivisão */
    private static function notifyCoordination(PDO $pdo, array $req, int $exceptUserId, string $title, string $message, string $link): void
    {
        try {
            $people = RequestManager::getAdmins($pdo);
            if (!empty($req['subdivision_id'])) {
                $people = array_merge($people, RequestManager::getSubdivisionAdmins($pdo, (int)$req['subdivision_id']));
            }
            if (!empty($req['created_by'])) {
                $people = array_merge($people, RequestManager::getSubdivisionAdminsForUser($pdo, (int)$req['created_by']));
            }
            $ids = array_values(array_unique(array_map('intval', array_column($people, 'id'))));
            RequestManager::notifyMany($pdo, $ids, $title, $message, $link, $exceptUserId);
        } catch (Throwable $e) {
            error_log("RequestForwardService::notifyCoordination \"$title\": " . $e->getMessage());
        }
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
                null, $toLabel, $fwd['mode'] === 'transfer' ? 'accepted_transfer' : 'accepted_help');
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
            : (self::isPartner($fwd) ? 'Parte aceita.' : 'Ajuda aceita.') . ' Quando terminar, devolva contando o que foi feito.'];
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
            RequestManager::addHistory($pdo, $reqId, $sector, $userId, $userName,
                self::isPartner($fwd) ? "Recusou ser parceiro ($toLabel)" : "Recusou o encaminhamento ($toLabel)",
                null, $toLabel, self::isPartner($fwd) ? 'partner_refused' : 'forward_refused');
            $pdo->commit();
        } catch (Throwable $e) {
            return self::rollback($pdo, $e, 'refuse');
        }

        if (self::isPartner($fwd)) {
            // Quem incluiu foi a coordenação: é ela que decide o que fazer com a recusa
            $title = "$toLabel recusou ser parceiro (nº $reqId)";
            $message = 'Motivo: ' . self::preview($reason);
            $link = self::link($reqId, $sector);
            self::notifyArea($pdo, (int)$fwd['from_area_id'], $userId, $title, $message, $link);
            self::notifyCoordination($pdo, getRequestRow($pdo, $sector, $reqId) ?? [], $userId, $title, $message, $link);
            return ['success' => true, 'message' => 'Parte recusada. A coordenação e o setor responsável receberam o motivo.'];
        }

        self::notifyArea($pdo, (int)$fwd['from_area_id'], $userId, "$toLabel recusou o pedido nº $reqId",
            'Motivo: ' . self::preview($reason), self::link($reqId, $sector));
        return ['success' => true, 'message' => 'Encaminhamento recusado. O setor que enviou recebeu o motivo.'];
    }

    /** Cancelar um envio pendente (quem enviou, setor de origem ou admin). Parceiro: só a coordenação, mesmo depois de aceitar. */
    public static function cancel(PDO $pdo, int $forwardId, int $userId, string $userName, string $role): array
    {
        $fwd = self::getById($pdo, $forwardId);
        // Parceiro que aceitou e não devolve travaria a conclusão: a coordenação também pode tirá-lo
        if (!$fwd || ($fwd['status'] !== 'pending' && !(self::isPartner($fwd) && self::isOpen($fwd)))) {
            return self::fail('Só dá para cancelar enquanto o outro setor não respondeu.');
        }
        // O parceiro foi decisão da coordenação: o setor responsável não desfaz
        if (self::isPartner($fwd)) {
            $req = getRequestRow($pdo, (string)$fwd['request_table'], (int)$fwd['request_id']);
            if (!$req || !userCanManageOriginalRequest($pdo, $req, (string)$fwd['request_table'], $userId, $role)) {
                return self::fail('Só a coordenação do pedido pode tirar o setor parceiro.');
            }
        } elseif (!isGlobalAdminRole($role) && ($role === 'adm_sub' || ((int)$fwd['forwarded_by'] !== $userId && !self::userInArea($pdo, $userId, (int)$fwd['from_area_id'])))) {
            return self::fail('Só quem enviou pode cancelar.');
        }
        $sector = $fwd['request_table'];
        $reqId = (int)$fwd['request_id'];
        $toLabel = self::areaLabel($pdo, (int)$fwd['to_area_id']);

        try {
            $pdo->beginTransaction();
            self::changeForward($pdo, $forwardId, $fwd['status'], 'cancelled', $userId, null);
            if ($fwd['mode'] === 'transfer') {
                $back = in_array($fwd['previous_status'], ['Y', 'W'], true) ? $fwd['previous_status'] : 'Y';
                self::setRequestStatus($pdo, $sector, $reqId, 'F', $back);
            }
            RequestManager::addHistory($pdo, $reqId, $sector, $userId, $userName, "Cancelou o encaminhamento para $toLabel", null, $toLabel, 'forward_cancelled');
            $pdo->commit();
        } catch (Throwable $e) {
            return self::rollback($pdo, $e, 'cancel');
        }

        self::notifyArea($pdo, (int)$fwd['to_area_id'], $userId, "Encaminhamento cancelado (nº $reqId)",
            self::isPartner($fwd) ? "$userName tirou vocês como setor parceiro." : "$userName cancelou o envio do pedido para vocês.", self::link($reqId, $sector));
        if (self::isPartner($fwd)) {
            self::notifyArea($pdo, (int)$fwd['from_area_id'], $userId, "Encaminhamento cancelado (nº $reqId)",
                "$userName tirou $toLabel como setor parceiro.", self::link($reqId, $sector));
            return ['success' => true, 'message' => "$toLabel não é mais setor parceiro deste pedido."];
        }
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
            RequestManager::addHistory($pdo, $reqId, $sector, $userId, $userName, "Devolveu a ajuda ($toLabel)", null, $toLabel, 'help_returned');
            $pdo->commit();
        } catch (Throwable $e) {
            return self::rollback($pdo, $e, 'return');
        }

        self::notifyArea($pdo, (int)$fwd['from_area_id'], $userId, "$toLabel devolveu a ajuda (nº $reqId)",
            self::preview($note), self::link($reqId, $sector));
        return ['success' => true, 'message' => (self::isPartner($fwd) ? 'Parte devolvida' : 'Ajuda devolvida') . ' ao setor responsável.'];
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
