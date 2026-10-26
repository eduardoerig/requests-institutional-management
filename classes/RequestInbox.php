<?php
/**
 * Filas de trabalho, no modelo dos sistemas de chamados: cada aba diz de quem é a vez.
 *
 *  fazer      o setor precisa agir (grupos: chegaram, novos, andamento, ajudando)
 *  esperando  pedidos do setor parados à espera de outro setor (transferência ou ajuda em aberto)
 *  historico  concluídos pelo setor e pedidos transferidos para outro setor (os 100 mais recentes)
 *
 * Também monta a fila da tela Aprovar pedidos (approvals). As telas de lista e o
 * Anterior/Próximo da página do pedido usam estas mesmas funções, na mesma ordem.
 */

require_once __DIR__ . '/RequestForwardService.php';
require_once __DIR__ . '/../config/security.php';

class RequestInbox
{
    public const TABS = [
        'fazer' => 'Para fazer',
        'esperando' => 'Esperando',
        'historico' => 'Histórico',
    ];

    /** Grupos de "Para fazer", na ordem do trabalho */
    public const GROUPS = [
        'chegaram' => 'Chegaram de outros setores',
        'novos' => 'Novos',
        'andamento' => 'Em atendimento',
        'ajudando' => 'Ajudando outro setor',
    ];

    /**
     * Faixas de "Para fazer" na tela (UI 2.0), na ordem do trabalho. São só de exibição:
     * 'group' continua sendo o grupo do fluxo (chegaram, novos, andamento, ajudando).
     *   urgentes  prioridade alta/crítica ou marcado como urgente, de qualquer grupo
     *   outros    outros setores pedindo ajuda ou transferindo (chegaram + ajudando)
     *   novos     aprovados pela coordenação, ainda sem atendimento
     *   andamento já começaram
     */
    public const LANES = [
        'urgentes' => ['Urgentes', 'resolva primeiro'],
        'outros' => ['Outros setores pedindo ajuda', 'vocês fazem uma parte e devolvem'],
        'novos' => ['Chegaram', 'aprovados pela coordenação'],
        'andamento' => ['Em andamento', 'já começaram'],
    ];

    /** Abas antigas (links salvos e avisos antigos) */
    public const LEGACY_TABS = [
        'novos' => 'fazer',
        'andamento' => 'fazer',
        'chegaram' => 'fazer',
        'ajudando' => 'fazer',
        'enviados' => 'esperando',
        'concluidos' => 'historico',
    ];

    private const HISTORY_LIMIT = 100;

    /** Recusa ou devolução de ajuda continua aparecendo na linha do pedido por estes dias */
    private const RECENT_REPLY_DAYS = 14;

    /** Caixas já montadas nesta requisição (o menu e a página usam a mesma) */
    private static array $built = [];

    public static function clearCache(): void
    {
        self::$built = [];
    }

    /**
     * @param int[] $areaIds setores considerados
     * @return array{tabs: array<string, array>, counts: array<string,int>}
     */
    public static function build(PDO $pdo, array $areaIds): array
    {
        $areaIds = array_values(array_unique(array_map('intval', $areaIds)));
        sort($areaIds);
        $memoKey = implode(',', $areaIds);
        return self::$built[$memoKey] ??= self::assemble($pdo, $areaIds);
    }

    private static function assemble(PDO $pdo, array $areaIds): array
    {
        $tabs = array_fill_keys(array_keys(self::TABS), []);
        if (!$areaIds) {
            return ['tabs' => $tabs, 'counts' => self::countRows($tabs)];
        }

        // 1) Encaminhamentos que envolvem estes setores + transferências que definem o responsável
        $in = implode(',', array_fill(0, count($areaIds), '?'));
        $stmt = $pdo->prepare("
            SELECT rf.*, uf.name AS forwarded_by_name, ur.name AS responded_by_name
            FROM request_forwards rf
            LEFT JOIN ctd_users uf ON uf.id = rf.forwarded_by
            LEFT JOIN ctd_users ur ON ur.id = rf.responded_by
            WHERE rf.to_area_id IN ($in) OR rf.from_area_id IN ($in)
            ORDER BY rf.id ASC
        ");
        $stmt->execute(array_merge($areaIds, $areaIds));
        $myForwards = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $owner = [];      // "tabela:id" => área responsável por transferência aceita
        foreach ($pdo->query("
            SELECT request_table, request_id, to_area_id FROM request_forwards
            WHERE mode = 'transfer' AND status IN ('accepted', 'completed') ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $owner[$t['request_table'] . ':' . $t['request_id']] = (int)$t['to_area_id'];
        }

        // 2) Pedidos candidatos: dos setores de origem + os citados em encaminhamentos
        $originSlugs = [];
        foreach ($areaIds as $areaId) {
            if ($slug = RequestForwardService::areaSlug($pdo, $areaId)) {
                $originSlugs[$slug] = $areaId;
            }
        }
        $keysBySector = [];
        foreach ($myForwards as $f) {
            $keysBySector[$f['request_table']][(int)$f['request_id']] = true;
        }
        foreach ($owner as $key => $areaId) {
            if (in_array($areaId, $areaIds, true)) {
                [$tbl, $id] = explode(':', $key);
                $keysBySector[$tbl][(int)$id] = true;
            }
        }

        $parts = [];
        $params = [];
        foreach (allowedRequestTables() as $sector) {
            $where = [];
            if (isset($originSlugs[$sector])) {
                $where[] = "r.status IN ('Y', 'W', 'F', 'C')";
            }
            if (!empty($keysBySector[$sector])) {
                $ids = array_keys($keysBySector[$sector]);
                $where[] = 'r.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $params = array_merge($params, $ids);
            }
            if ($where) {
                $parts[] = "SELECT '$sector' AS req_table, r.id, r.title, r.date, r.created_at, r.status, r.priority, r.urgent, r.obs, r.created_by, r.subdivision_id,
                        u.name AS solicitor_name, sub.name AS subdivision_name
                    FROM `ctd_{$sector}_frm` r
                    LEFT JOIN ctd_users u ON u.id = r.created_by
                    LEFT JOIN ctd_subdivision sub ON sub.id = r.subdivision_id
                    WHERE " . implode(' OR ', $where);
            }
        }
        $requests = [];
        if ($parts) {
            $q = $pdo->prepare(implode(' UNION ALL ', $parts)); // uma ida ao banco para todos os setores
            $q->execute($params);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $sector = $row['req_table'];
                $row['table'] = $sector;
                $row['st_raw'] = strtoupper((string)($row['status'] ?? 'P')) ?: 'P';
                $row['solicitor_name_formatted'] = self::shortName($row['solicitor_name'] ?? '');
                $key = $sector . ':' . $row['id'];
                $row['owner_area_id'] = $owner[$key] ?? RequestForwardService::originAreaId($pdo, $sector);
                $requests[$key] = $row;
            }
        }

        // 3) Encaminhamento em aberto e o último enviado por estes setores, por pedido
        $open = [];
        $lastSent = [];
        foreach ($myForwards as $f) {
            $key = $f['request_table'] . ':' . $f['request_id'];
            $f['from_label'] = RequestForwardService::areaLabel($pdo, (int)$f['from_area_id']);
            $f['to_label'] = RequestForwardService::areaLabel($pdo, (int)$f['to_area_id']);
            if ($f['status'] === 'pending' || ($f['mode'] === 'help' && $f['status'] === 'accepted')) {
                $open[$key] = $f;
            }
            if (in_array((int)$f['from_area_id'], $areaIds, true)) {
                $lastSent[$key] = $f; // fica o mais recente
            }
        }

        // 4) Quem começou o atendimento e quem concluiu (do histórico)
        $people = self::statusPeople($pdo, array_keys($requests));

        // 5) Cada pedido vai para uma aba só: a de quem precisa agir agora
        $recent = date('Y-m-d H:i:s', strtotime('-' . self::RECENT_REPLY_DAYS . ' days'));
        foreach ($requests as $key => $row) {
            $isMine = in_array((int)$row['owner_area_id'], $areaIds, true);
            $fwd = $open[$key] ?? null;
            $toMe = $fwd && in_array((int)$fwd['to_area_id'], $areaIds, true);
            $fromMe = $fwd && in_array((int)$fwd['from_area_id'], $areaIds, true);
            $sent = $lastSent[$key] ?? null;
            $row['worker_name'] = $people[$key]['worker'] ?? '';
            $row['context'] = ['', ''];
            if ($fwd) {
                $row['open_forward'] = $fwd;
            }

            if ($toMe && $fwd['status'] === 'pending') {
                $row['group'] = 'chegaram';
                $row['context'] = [$fwd['from_label'] . ($fwd['mode'] === 'help' ? ' pede ajuda' : ' quer transferir'), 'in'];
                $tabs['fazer'][] = $row;
            } elseif ($toMe) {
                $row['group'] = 'ajudando';
                $row['context'] = ['Ajudando ' . $fwd['from_label'], ''];
                $tabs['fazer'][] = $row;
            } elseif ($isMine && $fromMe) {
                $row['group'] = 'esperando';
                if ($fwd['mode'] === 'transfer') {
                    $row['context'] = ['Aguardando ' . $fwd['to_label'] . ' aceitar', ''];
                } elseif ($fwd['status'] === 'pending') {
                    $row['context'] = ['Aguardando ' . $fwd['to_label'] . ' responder', ''];
                } else {
                    $row['context'] = [$fwd['to_label'] . ' está ajudando', ''];
                }
                $tabs['esperando'][] = $row;
            } elseif ($isMine && in_array($row['st_raw'], ['Y', 'W'], true)) {
                $row['group'] = $row['st_raw'] === 'Y' ? 'novos' : 'andamento';
                $reply = self::replyContext($sent, $recent);
                if ($reply) {
                    $row['context'] = $reply;
                } elseif ($row['group'] === 'andamento' && $row['worker_name'] !== '') {
                    $row['context'] = ['com ' . self::firstName($row['worker_name']), ''];
                }
                $tabs['fazer'][] = $row;
            } elseif ($isMine && $row['st_raw'] === 'C') {
                $closedAt = (string)($people[$key]['closed_at'] ?? '');
                $closer = (string)($people[$key]['closer'] ?? '');
                $row['group'] = 'historico';
                $row['closed_at'] = $closedAt !== '' ? $closedAt : (string)$row['created_at'];
                $row['context'] = ['Concluído'
                    . ($closer !== '' ? ' por ' . self::firstName($closer) : '')
                    . ($closedAt !== '' ? ' em ' . date('d/m', strtotime($closedAt)) : ''), ''];
                $tabs['historico'][] = $row;
            } elseif (!$isMine && $sent && $sent['mode'] === 'transfer' && in_array($sent['status'], ['accepted', 'completed'], true)) {
                $when = (string)($sent['responded_at'] ?: $sent['created_at']);
                $row['group'] = 'historico';
                $row['closed_at'] = $when;
                $row['context'] = ['Transferido para ' . $sent['to_label'] . ' em ' . date('d/m', strtotime($when)), ''];
                $tabs['historico'][] = $row;
            }
        }

        // Urgência e faixa da tela (só exibição; 'group' fica como está)
        foreach ($tabs as $tab => $rows) {
            foreach ($rows as $i => $row) {
                [$tabs[$tab][$i]['is_urgent'], $tabs[$tab][$i]['urgent_reason']] = self::urgencyOf($row);
            }
        }
        foreach ($tabs['fazer'] as $i => $row) {
            $tabs['fazer'][$i]['lane'] = $row['is_urgent'] ? 'urgentes' : match ($row['group']) {
                'chegaram', 'ajudando' => 'outros',
                'novos' => 'novos',
                default => 'andamento',
            };
        }

        // Ordem: faixas na ordem do trabalho e prazo mais próximo primeiro; histórico do mais recente
        $laneOrder = array_flip(array_keys(self::LANES));
        usort($tabs['fazer'], fn($a, $b) => ($laneOrder[$a['lane']] <=> $laneOrder[$b['lane']]) ?: self::byDeadline($a, $b));
        usort($tabs['esperando'], [self::class, 'byDeadline']);
        usort($tabs['historico'], fn($a, $b) => strcmp((string)$b['closed_at'], (string)$a['closed_at']));
        $tabs['historico'] = array_slice($tabs['historico'], 0, self::HISTORY_LIMIT);

        foreach ($tabs as $tab => $rows) {
            foreach ($rows as $i => $row) {
                $tabs[$tab][$i]['tab'] = $tab;
            }
        }

        return ['tabs' => $tabs, 'counts' => self::countRows($tabs)];
    }

    /** Linhas da aba, na ordem da tela; com busca, procura nas três abas */
    public static function rowsFor(array $inbox, string $tab, string $q = ''): array
    {
        if (trim($q) !== '') {
            return self::search(array_merge(...array_values($inbox['tabs'])), $q);
        }
        return $inbox['tabs'][$tab] ?? [];
    }

    /** Busca por título, número ou solicitante */
    public static function search(array $rows, string $term): array
    {
        $term = mb_strtolower(trim($term));
        if ($term === '') {
            return $rows;
        }
        return array_values(array_filter($rows, function ($r) use ($term) {
            $hay = mb_strtolower(($r['title'] ?? '') . ' #' . ($r['id'] ?? '') . ' ' . ($r['solicitor_name'] ?? ''));
            return mb_strpos($hay, ltrim($term, 'nº ')) !== false || mb_strpos($hay, $term) !== false;
        }));
    }

    /** Áreas que o usuário enxerga na caixa (admin: todas as de atendimento) */
    public static function areasForUser(PDO $pdo, int $userId, string $role): array
    {
        if (isGlobalAdminRole($role)) {
            return array_column(RequestForwardService::availableAreas($pdo), 'id');
        }
        return RequestForwardService::userAreaIds($pdo, $userId);
    }

    /** Áreas da caixa com o filtro de setor do admin aplicado */
    public static function areasFor(PDO $pdo, int $userId, string $role, int $areaFilter = 0): array
    {
        $areaIds = array_map('intval', self::areasForUser($pdo, $userId, $role));
        if (isGlobalAdminRole($role) && $areaFilter > 0 && in_array($areaFilter, $areaIds, true)) {
            return [$areaFilter];
        }
        return $areaIds;
    }

    /** Grupos da aba "Em andamento" de Minhas requisições */
    public const MINE_GROUPS = [
        'resposta' => 'Com resposta do setor',
        'aprovacao' => 'Aguardando aprovação',
        'setor' => 'Com o setor',
    ];

    /**
     * Pedidos de quem pede: "andamento" (por grupo) e "concluidas" (concluídos e recusados).
     * Cada linha ganha context (de quem é a vez, em uma frase), subline (o setor) e group.
     * Com $q, as duas listas trazem só o que bate com o título ou o número.
     */
    public static function forRequester(PDO $pdo, int $userId, string $q = ''): array
    {
        require_once __DIR__ . '/../components/request_card.php';
        $rows = RequestManager::getRequests($pdo, $userId)['requests'];
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['table'] . ':' . (int)$row['id']] = $row;
        }
        $people = self::statusPeople($pdo, array_keys($byKey));
        [$lastComment, $transferredTo] = self::requesterSignals($pdo, array_keys($byKey));

        $recent = date('Y-m-d H:i:s', strtotime('-' . self::RECENT_REPLY_DAYS . ' days'));
        $needle = mb_strtolower(ltrim($q, '#'));
        $out = ['andamento' => [], 'concluidas' => []];
        foreach ($byKey as $key => $row) {
            if ($needle !== '' && (string)$row['id'] !== $needle && mb_strpos(mb_strtolower((string)$row['title']), $needle) === false) {
                continue;
            }
            $st = $row['st_raw'];
            $sector = isset($transferredTo[$key])
                ? RequestForwardService::areaLabel($pdo, $transferredTo[$key])
                : requestSectorName((string)$row['table']);
            $worker = $people[$key]['worker'] ?? '';
            $closedAt = $people[$key]['closed_at'] ?? '';
            $comment = $lastComment[$key] ?? null;
            $row['subline'] = $sector;
            $row['closed_at'] = $closedAt;

            if ($st === 'C') {
                $row['context'] = ['Concluído' . ($closedAt !== '' ? ' em ' . date('d/m', strtotime($closedAt)) : ''), 'ok'];
                $out['concluidas'][] = $row;
                continue;
            }
            if ($st === 'N') {
                $row['context'] = ['Recusado', 'bad'];
                $out['concluidas'][] = $row;
                continue;
            }
            if ($comment && (int)$comment['user_id'] !== $userId && $comment['created_at'] >= $recent) {
                $row['group'] = 'resposta';
                $row['context'] = [self::firstName((string)$comment['user_name']) . ' respondeu', 'in'];
            } elseif ($st === 'P') {
                $row['group'] = 'aprovacao';
                $row['context'] = ['Aguardando a coordenação', ''];
            } else {
                $row['group'] = 'setor';
                $row['context'] = match (true) {
                    $st === 'W' && $worker !== '' => [$sector . ', ' . self::firstName($worker) . ' atendendo', ''],
                    $st === 'W' => ['Em atendimento em ' . $sector, ''],
                    $st === 'Y' => ['Aprovado, na fila de ' . $sector, ''],
                    default => ['Com ' . $sector, ''],
                };
            }
            $out['andamento'][] = $row;
        }

        $order = array_flip(array_keys(self::MINE_GROUPS));
        usort($out['andamento'], fn($a, $b) => ($order[$a['group']] <=> $order[$b['group']]) ?: self::byDeadline($a, $b));
        usort($out['concluidas'], fn($a, $b) => strcmp((string)($b['closed_at'] ?: $b['created_at']), (string)($a['closed_at'] ?: $a['created_at'])));
        $out['counts'] = ['andamento' => count($out['andamento']), 'concluidas' => count($out['concluidas'])];
        return $out;
    }

    /** Última mensagem da conversa e setor que recebeu por transferência, por pedido */
    private static function requesterSignals(PDO $pdo, array $keys): array
    {
        if (!$keys) {
            return [[], []];
        }
        $bySector = [];
        foreach ($keys as $key) {
            [$tbl, $id] = explode(':', $key);
            $bySector[$tbl][] = (int)$id;
        }
        $conds = [];
        $params = [];
        foreach ($bySector as $tbl => $ids) {
            $conds[] = '(request_table = ? AND request_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
            $params = array_merge($params, [$tbl], $ids);
        }
        $where = implode(' OR ', $conds);

        $last = [];
        $stmt = $pdo->prepare("SELECT request_table, request_id, user_id, user_name, created_at FROM request_comments WHERE $where ORDER BY id ASC");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $last[$c['request_table'] . ':' . $c['request_id']] = $c;
        }

        $owner = [];
        $stmt = $pdo->prepare("SELECT request_table, request_id, to_area_id FROM request_forwards WHERE mode = 'transfer' AND status IN ('accepted', 'completed') AND ($where) ORDER BY id ASC");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $owner[$f['request_table'] . ':' . $f['request_id']] = (int)$f['to_area_id'];
        }
        return [$last, $owner];
    }

    /**
     * Fila da tela Aprovar pedidos: pendentes, prazo mais próximo primeiro.
     * $subdivisionIds: subdivisões da coordenação (null = todas, para o admin geral).
     * @return array{all: array, rows: array}
     */
    public static function approvals(PDO $pdo, ?array $subdivisionIds, string $sector = '', string $q = ''): array
    {
        $all = RequestManager::getRequests($pdo, null, null, 'P', null, null, 'DESC', [], null, null, $subdivisionIds)['requests'] ?? [];
        $today = strtotime(date('Y-m-d'));
        foreach ($all as &$r) {
            $r['table'] = str_replace(['ctd_', '_frm'], '', strtolower((string)($r['table'] ?? '')));
            // Há quanto tempo o pedido espera a decisão
            $days = (int)round(($today - strtotime(date('Y-m-d', strtotime((string)$r['created_at'])))) / 86400);
            $r['context'] = [$days <= 0 ? 'Hoje' : ($days === 1 ? 'Ontem' : 'Há ' . $days . ' dias'), $days >= 3 ? 'in' : ''];
            [$r['is_urgent'], $r['urgent_reason']] = self::urgencyOf($r);
        }
        unset($r);
        // Urgentes primeiro; depois prazo mais próximo (mesma ordem no Anterior/Próximo do pedido)
        usort($all, fn($a, $b) => ($b['is_urgent'] <=> $a['is_urgent']) ?: self::byDeadline($a, $b));

        $term = mb_strtolower(trim($q));
        $rows = array_values(array_filter($all, function ($r) use ($sector, $term) {
            if ($sector !== '' && $r['table'] !== $sector) {
                return false;
            }
            if ($term === '') {
                return true;
            }
            $hay = mb_strtolower(($r['title'] ?? '') . ' #' . ($r['id'] ?? '') . ' ' . ($r['solicitor_name'] ?? ''));
            return mb_strpos($hay, $term) !== false;
        }));
        return ['all' => $all, 'rows' => $rows];
    }

    /**
     * Urgente = prioridade alta/crítica (3-4) ou marcado como urgente.
     * O motivo vem da primeira linha das observações ("Urgente: ..."), como api/add_request.php grava.
     * @return array{0: bool, 1: string}
     */
    public static function urgencyOf(array $row): array
    {
        $flag = mb_strtolower(trim((string)($row['urgent'] ?? '')));
        $urgent = (int)($row['priority'] ?? 2) >= 3 || !in_array($flag, ['', '0', 'nao', 'não'], true);
        $reason = '';
        $first = trim(explode("\n", (string)($row['obs'] ?? ''), 2)[0]);
        if (preg_match('/^urgente:\s*(.+)$/iu', $first, $m)) {
            $reason = trim($m[1]);
        }
        return [$urgent, $reason];
    }

    /** Números do topo da Caixa: para fazer, urgentes, atrasados e esperando outro setor */
    public static function summary(array $inbox): array
    {
        $today = date('Y-m-d');
        $urgent = $late = 0;
        foreach ($inbox['tabs']['fazer'] ?? [] as $row) {
            $urgent += !empty($row['is_urgent']) ? 1 : 0;
            $date = substr((string)($row['date'] ?? ''), 0, 10);
            $late += ($date !== '' && strpos($date, '0000') !== 0 && $date < $today) ? 1 : 0;
        }
        return [
            'fazer' => (int)($inbox['counts']['fazer'] ?? 0),
            'urgentes' => $urgent,
            'atrasados' => $late,
            'esperando' => (int)($inbox['counts']['esperando'] ?? 0),
        ];
    }

    /** Prazo mais próximo primeiro; sem prazo por último; empate: mais recente primeiro */
    public static function byDeadline(array $a, array $b): int
    {
        $da = (string)($a['date'] ?? '');
        $db = (string)($b['date'] ?? '');
        $ha = $da !== '' && strpos($da, '0000') !== 0;
        $hb = $db !== '' && strpos($db, '0000') !== 0;
        if ($ha !== $hb) {
            return $ha ? -1 : 1;
        }
        return $ha && $da !== $db ? strcmp($da, $db) : strcmp((string)$b['created_at'], (string)$a['created_at']);
    }

    /** Contadores por aba e por grupo de "Para fazer" (o menu usa novos + chegaram) */
    private static function countRows(array $tabs): array
    {
        $counts = array_map('count', $tabs);
        foreach (array_keys(self::GROUPS) as $group) {
            $counts[$group] = 0;
        }
        foreach ($tabs['fazer'] as $row) {
            $counts[$row['group']]++;
        }
        return $counts;
    }

    /** Resposta recente a um encaminhamento feito pelo setor: o pedido voltou com recado */
    private static function replyContext(?array $sent, string $since): ?array
    {
        if (!$sent || empty($sent['responded_at']) || $sent['responded_at'] < $since) {
            return null;
        }
        if ($sent['status'] === 'refused') {
            return [$sent['to_label'] . ' recusou o encaminhamento', 'bad'];
        }
        if ($sent['status'] === 'returned') {
            return [$sent['to_label'] . ' devolveu a ajuda', 'in'];
        }
        return null;
    }

    /**
     * Quem começou o atendimento (ou reabriu) e quem concluiu, por pedido.
     * @param string[] $keys "tabela:id"
     */
    public static function statusPeople(PDO $pdo, array $keys): array
    {
        $bySector = [];
        foreach ($keys as $key) {
            [$tbl, $id] = explode(':', $key);
            $bySector[$tbl][] = (int)$id;
        }
        $people = [];
        if (!$bySector) {
            return $people;
        }
        $conds = [];
        $params = [statusLabel('W'), statusLabel('W'), statusLabel('C')];
        foreach ($bySector as $tbl => $ids) {
            $conds[] = '(request_table = ? AND request_id IN (' . implode(',', array_fill(0, count($ids), '?')) . '))';
            $params = array_merge($params, [$tbl], $ids);
        }
        $stmt = $pdo->prepare('
            SELECT request_table, request_id, user_name, created_at, (new_value = ?) AS is_start
            FROM request_history
            WHERE new_value IN (?, ?) AND (' . implode(' OR ', $conds) . ')
            ORDER BY id ASC
        ');
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $key = $h['request_table'] . ':' . $h['request_id'];
            if ((int)$h['is_start'] === 1) {
                $people[$key]['worker'] = (string)$h['user_name'];
            } else {
                $people[$key]['closer'] = (string)$h['user_name'];
                $people[$key]['closed_at'] = (string)$h['created_at'];
            }
        }
        return $people;
    }

    private static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        if (!$parts || $parts[0] === '') {
            return '—';
        }
        return count($parts) > 1 ? $parts[0] . ' ' . end($parts) : $parts[0];
    }

    private static function firstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        return $parts && $parts[0] !== '' ? $parts[0] : $name;
    }
}
