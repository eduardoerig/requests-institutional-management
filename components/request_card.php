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
}
