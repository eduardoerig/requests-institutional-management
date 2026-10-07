<?php
/**
 * Ficha de requisição (mesma aparência em todas as listas).
 *
 * $context:
 *  - 'mine'  : lista do solicitante (mostra etapas)
 *  - 'queue' : filas de trabalho (mostra solicitante; no PC abre o painel ao lado)
 */

if (!function_exists('renderRequestCard')) {
    function requestSectorName(string $sector): string
    {
        return [
            'mkt' => 'Marketing', 'xerox' => 'Reprografia', 'shop' => 'Compras',
            'service' => 'Manutenção', 'ti' => 'TI',
        ][$sector] ?? ucfirst($sector);
    }

    function renderRequestCard(array $row, string $context = 'queue'): string
    {
        $id = (int)($row['id'] ?? 0);
        $sector = str_replace(['ctd_', '_frm'], '', strtolower(trim((string)($row['table'] ?? ''))));
        $status = strtoupper(trim((string)($row['st_raw'] ?? $row['status'] ?? 'P'))) ?: 'P';
        $priority = (int)($row['priority'] ?? 2);
        $link = 'request_detail?id=' . $id . '&table=' . urlencode($sector);
        $isForwarded = $status === 'F' || !empty($row['is_forwarded_to_me']);

        $attrs = ' data-id="' . $id . '" data-table="' . e($sector) . '" data-sector="' . e($sector) . '"';
        if ($isForwarded && !empty($row['forward_id'])) {
            $attrs .= ' data-forwarded="true" data-forward-id="' . (int)$row['forward_id'] . '"';
        }
        if ($context === 'mine') {
            $group = $status === 'C' ? 'done' : ($status === 'N' ? 'refused' : 'open');
            $attrs .= ' data-group="' . $group . '" data-search="' . e(mb_strtolower(($row['title'] ?? '') . ' #' . $id)) . '"';
        }

        $classes = 'ficha card_req' . ($context === 'queue' ? ' ficha--compact' : '') . ($isForwarded ? ' forwarded' : '');

        // Linha de informações
        $meta = ['<span class="sector-tag">' . e(requestSectorName($sector)) . '</span>'];
        if ($context === 'queue') {
            $who = $row['solicitor_name_formatted'] ?? $row['solicitor_name'] ?? '';
            if ($who !== '' && $who !== '—') {
                $meta[] = '<span><i class="fa-regular fa-user" aria-hidden="true"></i> ' . e($who) . '</span>';
            }
            if (!empty($row['subdivision_name'])) {
                $meta[] = '<span>' . e($row['subdivision_name']) . '</span>';
            }
        } else {
            $created = !empty($row['created_at']) ? date('d/m/Y', strtotime((string)$row['created_at'])) : '';
            if ($created) {
                $meta[] = '<span>Pedido em ' . e($created) . '</span>';
            }
        }

        // Rodapé: prazo, urgência, encaminhamento, etapas
        $foot = getPrazoBadge($row['date'] ?? '', $status);
        if ($priority >= 3) {
            $foot .= ' <span class="prio-tag prio-tag--' . $priority . '"><i class="fa-solid ' . ($priority === 4 ? 'fa-fire' : 'fa-angles-up') . '" aria-hidden="true"></i> ' . e(priorityLabel($priority)) . '</span>';
        }
        if ($isForwarded && !empty($row['forward_from'])) {
            $foot .= ' <span class="prio-tag"><i class="fa-solid fa-share" aria-hidden="true"></i> Veio de ' . e($row['forward_from']) . '</span>';
        }
        $progress = '';
        if ($context === 'mine' && $status !== 'N') {
            $step = ['P' => 1, 'Y' => 2, 'F' => 3, 'W' => 3, 'C' => 4][$status] ?? 1;
            $progress = '<span class="mini-progress" aria-label="Etapa ' . $step . ' de 4">';
            for ($i = 1; $i <= 4; $i++) {
                $progress .= '<i class="' . ($i <= $step ? 'on' : '') . '"></i>';
            }
            $progress .= '</span>';
        }

        return '<a class="' . $classes . '" href="' . e($link) . '"' . $attrs . '>'
            . '<span class="ficha__stub"><span class="ficha__num"><small>Nº</small>' . $id . '</span><span class="ficha__sector-bar" aria-hidden="true"></span></span>'
            . '<span class="ficha__body">'
            .   '<span class="ficha__top"><span class="ficha__title">' . e($row['title'] ?? 'Sem título') . '</span>'
            .   '<span class="status-pill status-pill--' . e($status) . '">' . e(statusLabel($status)) . '</span></span>'
            .   '<span class="ficha__meta">' . implode('', $meta) . '</span>'
            .   '<span class="ficha__foot"><span class="row">' . $foot . '</span>' . $progress . '</span>'
            . '</span>'
            . '</a>';
    }

    /** Aviso de prazo só quando importa: atrasada, vence hoje ou amanhã */
    function requestDeadlineFlag(string $date): string
    {
        if ($date === '' || strpos($date, '0000') === 0) {
            return '';
        }
        $days = (int)round((strtotime(date('Y-m-d', strtotime($date))) - strtotime(date('Y-m-d'))) / 86400);
        if ($days < 0) return '<span class="flag flag--bad">Atrasada</span>';
        if ($days === 0) return '<span class="flag flag--warn">Vence hoje</span>';
        if ($days === 1) return '<span class="flag flag--warn">Vence amanhã</span>';
        return '';
    }

    /**
     * Linha compacta das filas de trabalho: número, título, quem pediu, prazo e no máximo um aviso.
     * $note: frase curta sobre encaminhamento (opcional).
     */
    function renderRequestRow(array $row, string $note = '', string $noteKind = '', bool $selectable = false): string
    {
        $id = (int)($row['id'] ?? 0);
        $sector = str_replace(['ctd_', '_frm'], '', strtolower(trim((string)($row['table'] ?? ''))));
        $status = strtoupper(trim((string)($row['st_raw'] ?? $row['status'] ?? 'P'))) ?: 'P';
        $priority = (int)($row['priority'] ?? 2);
        $link = 'request_detail?id=' . $id . '&table=' . urlencode($sector);

        // Um aviso só, o mais importante
        $date = (string)($row['date'] ?? '');
        $done = in_array($status, ['C', 'N'], true);
        $flag = $done ? '' : requestDeadlineFlag($date);
        if (!$done && $flag === '' && $priority === 4) $flag = '<span class="flag flag--bad">Crítica</span>';
        elseif (!$done && $flag === '' && $priority === 3) $flag = '<span class="flag flag--warn">Urgente</span>';

        $who = $row['solicitor_name_formatted'] ?? $row['solicitor_name'] ?? '';
        $deadline = ($date !== '' && strpos($date, '0000') !== 0) ? 'até ' . date('d/m', strtotime($date)) : 'sem prazo';
        $meta = '<span>' . e(requestSectorName($sector)) . '</span><span>' . e($who) . '</span><span>' . $deadline . '</span>';

        $html = '<a class="row-item card_req" href="' . e($link) . '" data-id="' . $id . '" data-table="' . e($sector) . '" data-sector="' . e($sector) . '">'
            . '<span class="row-item__num">' . $id . '</span>'
            . '<span class="row-item__body">'
            .   '<span class="row-item__title">' . e($row['title'] ?? 'Sem título') . '</span>'
            .   '<span class="row-item__meta">' . $meta . '</span>'
            .   ($note !== '' ? '<span class="row-item__note' . ($noteKind ? ' row-item__note--' . e($noteKind) : '') . '">' . e($note) . '</span>' : '')
            . '</span>'
            . '<span class="row-item__flag">' . $flag . '</span>'
            . '</a>';
        if (!$selectable) {
            return $html;
        }
        // Caixinha para escolher vários pedidos de uma vez
        return '<div class="row-wrap">'
            . '<label class="row-check"><input type="checkbox" data-pick value="' . e($sector) . ':' . $id . '" aria-label="Selecionar pedido nº ' . $id . '"></label>'
            . $html
            . '</div>';
    }

    /** Atrasado: prazo vencido e pedido ainda aberto */
    function requestRowIsLate(array $row): bool
    {
        $date = (string)($row['date'] ?? '');
        $status = strtoupper((string)($row['st_raw'] ?? $row['status'] ?? ''));
        return $date !== '' && strpos($date, '0000') !== 0 && !in_array($status, ['C', 'N'], true)
            && date('Y-m-d', strtotime($date)) < date('Y-m-d');
    }

    function requestRowIsUrgent(array $row): bool
    {
        return (int)($row['priority'] ?? 2) >= 3;
    }

    /** Filtros rápidos das listas: todos | atrasados | urgentes */
    function filterRequestRows(array $rows, string $filter): array
    {
        if ($filter === 'atrasados') return array_values(array_filter($rows, 'requestRowIsLate'));
        if ($filter === 'urgentes') return array_values(array_filter($rows, 'requestRowIsUrgent'));
        return $rows;
    }

    /**
     * Topo da lista: filtros rápidos e "selecionar todos".
     * $link(string $filter) devolve a URL do filtro.
     */
    function renderListTools(array $rows, string $filter, callable $link, bool $selectable): string
    {
        $late = count(array_filter($rows, 'requestRowIsLate'));
        $urgent = count(array_filter($rows, 'requestRowIsUrgent'));
        if (!$rows || (!$selectable && !$late && !$urgent && $filter === '')) {
            return '';
        }
        $chip = function (string $key, string $label, int $n) use ($filter, $link) {
            $on = $filter === $key;
            return '<a class="chip' . ($on ? ' is-on' : '') . ($key !== '' && $n > 0 && $key === 'atrasados' ? ' chip--bad' : '') . '" href="' . e($link($key)) . '"' . ($on ? ' aria-current="true"' : '') . '>'
                . e($label) . ' <b>' . $n . '</b></a>';
        };
        $html = '<div class="list-tools">';
        if ($selectable) {
            $html .= '<label class="row-check row-check--all"><input type="checkbox" data-pick-all aria-label="Selecionar todos"></label>';
        }
        $html .= '<div class="chips" role="group" aria-label="Filtrar">'
            . $chip('', 'Todos', count($rows))
            . ($late || $filter === 'atrasados' ? $chip('atrasados', 'Atrasados', $late) : '')
            . ($urgent || $filter === 'urgentes' ? $chip('urgentes', 'Urgentes', $urgent) : '')
            . '</div></div>';
        return $html;
    }

    /** Barra que aparece quando há pedidos marcados. $actions: [acao => [rótulo, classe do botão, ícone]] */
    function renderBulkBar(array $actions): string
    {
        $buttons = '';
        foreach ($actions as $action => [$label, $class, $icon]) {
            $buttons .= '<button type="button" class="btn ' . e($class) . '" data-bulk-action="' . e($action) . '"><i class="fa-solid ' . e($icon) . '" aria-hidden="true"></i> ' . e($label) . '</button>';
        }
        return '<div class="bulk-bar" data-bulk hidden role="region" aria-label="Pedidos selecionados">'
            . '<span class="bulk-bar__count" data-bulk-count>0 selecionados</span>'
            . '<div class="bulk-bar__actions">' . $buttons
            . '<button type="button" class="btn btn--ghost" data-bulk-clear>Limpar</button></div>'
            . '</div>';
    }
}
