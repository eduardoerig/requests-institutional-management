<?php
/**
 * Peças das listas de pedidos: linha da fila em colunas, cabeçalho, prazo em palavras e barra de lote.
 */

if (!function_exists('renderQueueRow')) {
    function requestSectorName(string $sector): string
    {
        return sectorLabel($sector); // config/sectors.php
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

    /** Prazo em palavras para as listas: [texto, tipo]; tipo 'bad' (atrasado), 'warn' (hoje/amanhã), 'muted' ou '' */
    function deadlineLabel(string $date): array
    {
        if ($date === '' || strpos($date, '0000') === 0) {
            return ['Sem prazo', 'muted'];
        }
        $ts = strtotime(date('Y-m-d', strtotime($date)));
        $days = (int)round(($ts - strtotime(date('Y-m-d'))) / 86400);
        if ($days < 0) {
            return ['Atrasado há ' . -$days . ($days === -1 ? ' dia' : ' dias'), 'bad'];
        }
        if ($days === 0) return ['Vence hoje', 'warn'];
        if ($days === 1) return ['Vence amanhã', 'warn'];
        return [date(date('Y', $ts) === date('Y') ? 'd/m' : 'd/m/Y', $ts), ''];
    }

    /**
     * Linha das listas de pedidos (Painel), em colunas: nº | pedido e quem pediu | [setor] | situação | prazo.
     * $opt:
     *   href    link do pedido
     *   sector  mostra a coluna Setor
     *   check   '' sem coluna de seleção | 'none' coluna vazia | grupo do lote ('novos', 'andamento')
     *   due     false para não mostrar o prazo (histórico)
     *   tab     nome da aba (resultados de busca)
     */
    function renderQueueRow(array $row, array $opt = []): string
    {
        $id = (int)($row['id'] ?? 0);
        $sector = requestSlug((string)($row['table'] ?? ''));
        $who = trim((string)($row['solicitor_name_formatted'] ?? $row['solicitor_name'] ?? ''));
        $sub = trim((string)($row['subdivision_name'] ?? ''));
        [$context, $kind] = ($row['context'] ?? []) + ['', ''];
        $check = (string)($opt['check'] ?? '');

        $cells = '<span class="queue-row__num">' . $id . '</span>'
            . '<span class="queue-row__main">'
            .   '<span class="queue-row__title">' . e($row['title'] ?? 'Sem título')
            .     ((int)($row['priority'] ?? 2) === 4 ? ' <span class="prio-tag prio-tag--4">Crítica</span>' : '') . '</span>'
            .   '<span class="queue-row__sub">' . e($row['subline'] ?? implode(', ', array_filter([$who !== '—' ? $who : '', $sub]))) . '</span>'
            . '</span>';
        if (!empty($opt['sector'])) {
            $cells .= '<span class="queue-row__sector">' . e(requestSectorName($sector)) . '</span>';
        }
        $cells .= '<span class="queue-row__context' . ($kind !== '' ? ' queue-row__context--' . e($kind) : '') . '">'
            . (!empty($opt['tab']) ? '<span class="queue-row__tab">' . e($opt['tab']) . '</span>' : '')
            . e($context) . '</span>';
        if (($opt['due'] ?? true) !== false) {
            [$due, $dueKind] = deadlineLabel((string)($row['date'] ?? ''));
            $cells .= '<span class="queue-row__due' . ($dueKind !== '' ? ' queue-row__due--' . $dueKind : '') . '">' . e($due) . '</span>';
        } else {
            $cells .= '<span class="queue-row__due"></span>';
        }

        $html = '<li class="queue-row' . ($check !== '' ? ' has-check' : '') . '">';
        if ($check === 'none') {
            $html .= '<span class="queue-row__check"></span>';
        } elseif ($check !== '') {
            $html .= '<label class="queue-row__check"><input type="checkbox" data-pick data-group="' . e($check) . '" value="' . e($sector) . ':' . $id . '" aria-label="Selecionar pedido nº ' . $id . '"></label>';
        }
        return $html . '<a class="queue-row__link" href="' . e($opt['href'] ?? ('request_detail?id=' . $id . '&table=' . urlencode($sector))) . '">' . $cells . '</a></li>';
    }

    /** Nomes das colunas da fila (só no computador); $opt como em renderQueueRow, com check 'all' para "selecionar todos" */
    function renderQueueHead(array $opt = []): string
    {
        $check = (string)($opt['check'] ?? '');
        $html = '<div class="queue-head' . ($check !== '' ? ' has-check' : '') . '">';
        if ($check === 'all') {
            $html .= '<label class="queue-row__check"><input type="checkbox" data-pick-all aria-label="Selecionar todos"></label>';
        } elseif ($check !== '') {
            $html .= '<span class="queue-row__check"></span>';
        }
        return $html . '<span class="queue-head__cols" aria-hidden="true"><span>Nº</span><span>Pedido</span>'
            . (!empty($opt['sector']) ? '<span>Setor</span>' : '')
            . '<span>' . e($opt['contextLabel'] ?? 'Situação') . '</span><span>' . e($opt['dueLabel'] ?? 'Prazo') . '</span></span></div>';
    }

    /**
     * Barra que aparece quando há pedidos marcados.
     * $actions: [ação => [rótulo, classe do botão, ícone, grupo?]]; com grupo, o botão age só nos marcados daquele grupo
     * e fica desativado quando nenhum desse grupo está marcado. [data-bulk-pending] mostra os avisos em espera.
     */
    function renderBulkBar(array $actions): string
    {
        $buttons = '';
        foreach ($actions as $action => $a) {
            [$label, $class, $icon] = $a;
            $group = (string)($a[3] ?? '');
            $buttons .= '<button type="button" class="btn ' . e($class) . '" data-bulk-action="' . e($action) . '"'
                . ($group !== '' ? ' data-bulk-group="' . e($group) . '" disabled' : '') . '>'
                . '<i class="fa-solid ' . e($icon) . '" aria-hidden="true"></i> ' . e($label)
                . ($group !== '' ? ' <span data-bulk-n></span>' : '') . '</button>';
        }
        return '<div class="bulk-bar" data-bulk hidden role="region" aria-label="Pedidos selecionados">'
            . '<span class="bulk-bar__count"><span data-bulk-count aria-live="polite">0 pedidos selecionados</span>'
            . '<span class="bulk-bar__pending" data-bulk-pending hidden></span></span>'
            . '<div class="bulk-bar__actions">' . $buttons
            . '<button type="button" class="btn btn--ghost" data-bulk-clear>Limpar</button></div>'
            . '</div>';
    }
}

if (!function_exists('requestSectorTo')) {
    /** Setor com artigo, para "Enviando para a Manutenção" */
    function requestSectorTo(string $sector): string
    {
        return sectorLabelTo($sector); // config/sectors.php
    }
}
