<?php
/**
 * Peças das listas de pedidos: linha da fila em colunas, cabeçalho, prazo em palavras e barra de lote.
 */

if (!function_exists('renderQueueRow')) {
    function requestSectorName(string $sector): string
    {
        return [
            'mkt' => 'Marketing', 'xerox' => 'Reprografia', 'shop' => 'Compras',
            'service' => 'Manutenção', 'ti' => 'TI',
        ][$sector] ?? ucfirst($sector);
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
     * Linha das filas (Caixa e Aprovar), em colunas: nº | pedido e quem pediu | [setor] | situação | prazo.
     * $opt:
     *   href    link do pedido
     *   sector  mostra a coluna Setor
     *   check   '' sem coluna de seleção | 'none' coluna vazia | grupo do lote ('novos', 'approve'...)
     *   due     false para não mostrar o prazo (histórico)
     *   tab     nome da aba (resultados de busca)
     */
    function renderQueueRow(array $row, array $opt = []): string
    {
        $id = (int)($row['id'] ?? 0);
        $sector = str_replace(['ctd_', '_frm'], '', strtolower(trim((string)($row['table'] ?? ''))));
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
     * $actions: [ação => [rótulo, classe do botão, ícone, grupo?]]; com grupo, o botão age só nos marcados daquele grupo.
     */
    function renderBulkBar(array $actions): string
    {
        $buttons = '';
        foreach ($actions as $action => $a) {
            [$label, $class, $icon] = $a;
            $group = (string)($a[3] ?? '');
            $buttons .= '<button type="button" class="btn ' . e($class) . '" data-bulk-action="' . e($action) . '"'
                . ($group !== '' ? ' data-bulk-group="' . e($group) . '" hidden' : '') . '>'
                . '<i class="fa-solid ' . e($icon) . '" aria-hidden="true"></i> ' . e($label)
                . ($group !== '' ? ' <span data-bulk-n></span>' : '') . '</button>';
        }
        return '<div class="bulk-bar" data-bulk hidden role="region" aria-label="Pedidos selecionados">'
            . '<span class="bulk-bar__count" data-bulk-count aria-live="polite">0 pedidos selecionados</span>'
            . '<div class="bulk-bar__actions">' . $buttons
            . '<button type="button" class="btn btn--ghost" data-bulk-clear>Limpar</button></div>'
            . '</div>';
    }
}

if (!function_exists('renderWorkRow')) {
    /**
     * UI 2.0 — pedido da Caixa do setor: cartão no celular, linha tipo tabela no computador.
     * Função nova (não muda renderQueueRow). Estilos em assets/css/pages/inbox.css.
     * $opt:
     *   href    link do pedido
     *   check   '' sem seleção | 'none' espaço vazio | grupo do lote ('novos', 'andamento')
     *   sector  nome do setor dono (Caixa com vários setores) ou ''
     *   badge   selo de outro setor (ex.: "Ajuda para Manutenção") ou ''
     *   meta    linha de apoio: quem pediu · área · motivo
     *   note    [texto, tipo] situação curta ('in', 'bad', 'ok' ou '')
     *   due     false para não mostrar o prazo (histórico)
     *   tab     nome da aba (resultados de busca)
     *   action  null | [label, quick ('start'|'finish'|'accept'|'assume'|'return'|''), forward id, primário?]
     *           quick '' vira link para o pedido
     */
    function renderWorkRow(array $row, array $opt = []): string
    {
        $id = (int)($row['id'] ?? 0);
        $sector = str_replace(['ctd_', '_frm'], '', strtolower(trim((string)($row['table'] ?? ''))));
        $href = (string)($opt['href'] ?? ('request_detail?id=' . $id . '&table=' . urlencode($sector)));
        $check = (string)($opt['check'] ?? '');
        $urgent = !empty($row['is_urgent']);
        $badge = trim((string)($opt['badge'] ?? ''));
        [$note, $noteKind] = ($opt['note'] ?? []) + ['', ''];

        $due = '';
        $dueKind = '';
        if (($opt['due'] ?? true) !== false) {
            [$due, $dueKind] = deadlineLabel((string)($row['date'] ?? ''));
        }
        $dueClass = 'work-row__due' . ($dueKind !== '' ? ' work-row__due--' . e($dueKind) : '');

        $classes = 'work-row' . ($urgent ? ' is-urgent' : ($badge !== '' ? ' is-other' : '')) . ($check !== '' ? ' has-check' : '');
        $html = '<li class="' . $classes . '" data-key="' . e($sector) . ':' . $id . '">';
        $html .= '<span class="work-row__bar" aria-hidden="true"></span>';
        if ($check === 'none') {
            $html .= '<span class="work-row__check"></span>';
        } elseif ($check !== '') {
            $html .= '<label class="work-row__check"><input type="checkbox" data-pick data-group="' . e($check) . '" value="' . e($sector) . ':' . $id . '" aria-label="Selecionar pedido nº ' . $id . '"></label>';
        }

        $html .= '<a class="work-row__link" href="' . e($href) . '">'
            . '<span class="work-row__head">'
            .   '<span class="work-row__num">Nº ' . $id . '</span>'
            .   '<span class="work-row__title">' . e($row['title'] ?? 'Sem título') . '</span>'
            .   ($urgent ? '<span class="badge-urgent">Urgente</span>' : '')
            .   ($badge !== '' ? '<span class="badge-sector">' . e($badge) . '</span>' : '')
            .   (!empty($opt['sector']) ? '<span class="work-row__sector">' . e($opt['sector']) . '</span>' : '')
            .   (!empty($opt['tab']) ? '<span class="work-row__tab">' . e($opt['tab']) . '</span>' : '')
            .   ($due !== '' ? '<span class="' . $dueClass . ' work-row__due--top">' . e($due) . '</span>' : '')
            . '</span>';
        if (trim((string)($opt['meta'] ?? '')) !== '') {
            $html .= '<span class="work-row__meta">' . e($opt['meta']) . '</span>';
        }
        if ($note !== '') {
            $html .= '<span class="work-row__note' . ($noteKind !== '' ? ' work-row__note--' . e($noteKind) : '') . '">' . e($note) . '</span>';
        }
        $html .= '</a>';

        // Prazo em coluna própria (computador); no celular ele vai na primeira linha do cartão
        $html .= '<span class="' . $dueClass . ' work-row__due--col" aria-hidden="true">' . e($due) . '</span>';

        $action = $opt['action'] ?? null;
        if (is_array($action)) {
            [$label, $quick, $forwardId, $primary] = $action + ['', '', 0, false];
            $btnClass = 'btn work-row__act' . ($primary ? ' work-row__act--go' : '');
            if ($quick === '') {
                $html .= '<a class="' . $btnClass . '" href="' . e($href) . '">' . e($label) . '</a>';
            } else {
                $html .= '<button type="button" class="' . $btnClass . '" data-quick="' . e($quick) . '"'
                    . ' data-id="' . $id . '" data-table="' . e($sector) . '"'
                    . ((int)$forwardId > 0 ? ' data-forward="' . (int)$forwardId . '"' : '')
                    . ' aria-label="' . e($label . ': pedido nº ' . $id) . '">' . e($label) . '</button>';
            }
        }
        return $html . '</li>';
    }
}

if (!function_exists('renderApproveCard')) {
    /** Resumo curto do pedido para decidir sem abrir: descrição, ou cópias e tipo (Reprografia) */
    function requestSummary(array $row, int $max = 220): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string)($row['descp'] ?? '')));
        if ($text === '' && !empty($row['qtd'])) {
            $text = (int)$row['qtd'] . ' cópias' . (trim((string)($row['type'] ?? '')) !== '' ? ' · ' . trim((string)$row['type']) : '');
        }
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    /**
     * UI 2.0 — cartão da tela Aprovar pedidos (um por vez no celular, grade no computador).
     * $opt: href (link do pedido), check (true mostra a caixa de seleção do lote)
     */
    function renderApproveCard(array $row, array $opt = []): string
    {
        $id = (int)($row['id'] ?? 0);
        $sector = str_replace(['ctd_', '_frm'], '', strtolower(trim((string)($row['table'] ?? ''))));
        $href = (string)($opt['href'] ?? ('request_detail?id=' . $id . '&table=' . urlencode($sector)));
        $urgent = !empty($row['is_urgent']);
        $reason = trim((string)($row['urgent_reason'] ?? ''));
        $who = trim((string)($row['solicitor_name_formatted'] ?? $row['solicitor_name'] ?? ''));
        $who = $who === '—' ? '' : $who;
        $sub = trim((string)($row['subdivision_name'] ?? ''));
        [$age] = ($row['context'] ?? []) + [''];
        [$due, $dueKind] = deadlineLabel((string)($row['date'] ?? ''));
        $summary = requestSummary($row);
        $uid = $sector . '-' . $id;
        $title = (string)($row['title'] ?? 'Sem título');

        $html = '<li class="approve-card' . ($urgent ? ' is-urgent' : '') . '" data-approve-card data-key="' . e($sector) . ':' . $id . '"'
            . ' data-id="' . $id . '" data-table="' . e($sector) . '" aria-labelledby="ac-' . e($uid) . '">'
            . '<div class="approve-card__head">';
        if (!empty($opt['check'])) {
            $html .= '<label class="approve-card__check"><input type="checkbox" data-pick data-group="approve" value="' . e($sector) . ':' . $id . '" aria-label="Selecionar pedido nº ' . $id . '"></label>';
        }
        $html .= '<span class="approve-card__meta">Nº ' . $id . ' · ' . e(requestSectorName($sector)) . ($age !== '' ? ' · ' . e(mb_strtolower($age)) : '') . '</span>'
            . ($urgent ? '<span class="badge-urgent">Urgente</span>' : '')
            . '</div>'
            . '<a class="approve-card__title" id="ac-' . e($uid) . '" href="' . e($href) . '">' . e($title) . '</a>';
        if ($summary !== '') {
            $html .= '<p class="approve-card__desc">' . e($summary) . '</p>';
        }
        if ($urgent) {
            $html .= '<p class="approve-card__reason"><strong>Motivo da urgência:</strong> ' . e($reason !== '' ? $reason : 'não informado') . '</p>';
        }
        $html .= '<dl class="approve-card__facts">'
            . '<div><dt>Pediu</dt><dd>' . e(implode(' · ', array_filter([$who, $sub])) ?: '—') . '</dd></div>'
            . '<div><dt>Prazo</dt><dd class="approve-card__due' . ($dueKind !== '' ? ' approve-card__due--' . e($dueKind) : '') . '">' . e($due) . '</dd></div>'
            . '</dl>'
            . '<div class="approve-card__refuse" data-refuse-box hidden>'
            .   '<label for="rr-' . e($uid) . '">Motivo da recusa <span class="req">*</span> <small>(quem pediu vai ler)</small></label>'
            .   '<input type="text" id="rr-' . e($uid) . '" maxlength="1000" data-refuse-reason autocomplete="off" placeholder="Ex.: já temos esse material no almoxarifado">'
            .   '<p class="field-error" data-refuse-error hidden>Escreva o motivo da recusa.</p>'
            . '</div>'
            . '<div class="approve-card__actions">'
            .   '<button type="button" class="btn btn--primary" data-decide="approve" aria-label="Aprovar pedido nº ' . $id . '">Aprovar</button>'
            .   '<button type="button" class="btn" data-decide="refuse" aria-label="Recusar pedido nº ' . $id . '">Recusar</button>'
            . '</div>'
            . '</li>';
        return $html;
    }
}
