<?php
/**
 * Caixa do setor: monta as abas de trabalho de um ou mais setores (ctd_area.id).
 *
 *  novos      aprovados, sob responsabilidade do setor, ainda não começados
 *  andamento  em atendimento, sob responsabilidade do setor
 *  chegaram   encaminhamentos pendentes para o setor (transferência ou ajuda)
 *  ajudando   ajudas aceitas pelo setor, ainda não devolvidas
 *  enviados   encaminhamentos feitos pelo setor nos últimos 30 dias (com a resposta)
 *  concluidos concluídos sob responsabilidade do setor (os 100 mais recentes)
 */

require_once __DIR__ . '/RequestForwardService.php';
require_once __DIR__ . '/../config/security.php';

class RequestInbox
{
    public const TABS = [
        'novos' => 'Novos',
        'andamento' => 'Em atendimento',
        'chegaram' => 'Chegaram para mim',
        'ajudando' => 'Ajudando',
        'enviados' => 'Enviados',
        'concluidos' => 'Concluídos',
    ];

    /**
     * @param int[] $areaIds setores considerados
     * @return array{tabs: array<string, array>, counts: array<string,int>}
     */
    public static function build(PDO $pdo, array $areaIds): array
    {
        $areaIds = array_values(array_unique(array_map('intval', $areaIds)));
        $tabs = array_fill_keys(array_keys(self::TABS), []);
        if (!$areaIds) {
            return ['tabs' => $tabs, 'counts' => array_map('count', $tabs)];
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

        $requests = [];
        foreach (allowedRequestTables() as $sector) {
            $where = [];
            $params = [];
            if (isset($originSlugs[$sector])) {
                $where[] = "r.status IN ('Y', 'W', 'F', 'C')";
            }
            if (!empty($keysBySector[$sector])) {
                $ids = array_keys($keysBySector[$sector]);
                $where[] = 'r.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $params = array_merge($params, $ids);
            }
            if (!$where) {
                continue;
            }
            $q = $pdo->prepare("
                SELECT r.*, u.name AS solicitor_name, sub.name AS subdivision_name
                FROM `ctd_{$sector}_frm` r
                LEFT JOIN ctd_users u ON u.id = r.created_by
                LEFT JOIN ctd_subdivision sub ON sub.id = r.subdivision_id
                WHERE " . implode(' OR ', $where) . "
                ORDER BY r.id DESC
            ");
            $q->execute($params);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['table'] = $sector;
                $row['st_raw'] = strtoupper((string)($row['status'] ?? 'P')) ?: 'P';
                $row['solicitor_name_formatted'] = self::shortName($row['solicitor_name'] ?? '');
                $key = $sector . ':' . $row['id'];
                $row['owner_area_id'] = $owner[$key] ?? RequestForwardService::originAreaId($pdo, $sector);
                $requests[$key] = $row;
            }
        }

        // 3) Encaminhamento em aberto por pedido
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

        // 4) Distribuir nas abas
        foreach ($requests as $key => $row) {
            $isMine = in_array((int)$row['owner_area_id'], $areaIds, true);
            $fwd = $open[$key] ?? null;
            if ($fwd) {
                $row['open_forward'] = $fwd;
            }
            if ($isMine && $row['st_raw'] === 'Y') {
                $tabs['novos'][] = $row;
            } elseif ($isMine && $row['st_raw'] === 'W') {
                $tabs['andamento'][] = $row;
            } elseif ($isMine && $row['st_raw'] === 'C') {
                $tabs['concluidos'][] = $row;
            }
            if ($fwd && in_array((int)$fwd['to_area_id'], $areaIds, true)) {
                if ($fwd['status'] === 'pending') {
                    $tabs['chegaram'][] = $row + ['inbox_forward' => $fwd];
                } else {
                    $tabs['ajudando'][] = $row + ['inbox_forward' => $fwd];
                }
            }
        }

        $cut = date('Y-m-d H:i:s', strtotime('-30 days'));
        foreach ($lastSent as $key => $f) {
            if (isset($requests[$key]) && ($f['created_at'] >= $cut || $f['status'] === 'pending' || ($f['mode'] === 'help' && $f['status'] === 'accepted'))) {
                $tabs['enviados'][] = $requests[$key] + ['inbox_forward' => $f];
            }
        }

        // Ordem: prazo mais próximo primeiro nas filas de trabalho; mais recentes nas demais
        foreach (['novos', 'andamento', 'chegaram', 'ajudando'] as $tab) {
            usort($tabs[$tab], [self::class, 'byDeadline']);
        }
        usort($tabs['enviados'], fn($a, $b) => strcmp($b['inbox_forward']['created_at'], $a['inbox_forward']['created_at']));
        usort($tabs['concluidos'], fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
        $tabs['concluidos'] = array_slice($tabs['concluidos'], 0, 100);

        return ['tabs' => $tabs, 'counts' => array_map('count', $tabs)];
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

    private static function byDeadline(array $a, array $b): int
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

    private static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        if (!$parts || $parts[0] === '') {
            return '—';
        }
        return count($parts) > 1 ? $parts[0] . ' ' . end($parts) : $parts[0];
    }
}
