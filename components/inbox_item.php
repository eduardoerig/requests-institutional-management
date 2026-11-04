<?php
/**
 * Peças da Caixa do setor (tela 2b): linha da lista (coluna da esquerda) e painel de detalhes (direita).
 * O que cada pedido mostra (próximo passo, selos, situação, ação) é montado em pages/inbox.php ($present)
 * e chega aqui pronto em $item. Estilos: assets/css/pages/inbox.css · seleção e ações: assets/js/pages/inbox.js
 *
 * $item:
 *   key       "tabela:id" (liga a linha ao painel)
 *   href      página do pedido (leva aba, setor, busca e filtro)
 *   owner     nome do setor dono
 *   showOwner mostra o setor dono na linha de apoio
 *   tabLabel  aba onde o pedido está (só na busca) ou ''
 *   next      [texto, tipo] próximo passo da linha ('go' ação do setor, 'wait' a vez é de outro, 'done' histórico)
 *   due       false no histórico (sem prazo na linha)
 *   status    [chave, rótulo] selo de situação (novo, chegou, andamento, ajuda, aguardando, concluido, transferido)
 *   badge     selo do outro setor ("Ajuda para Manutenção", "Com Compras"...) ou ''
 *   notes     frases em destaque (motivo da urgência, mensagem de quem encaminhou)
 *   situation [texto, tom] tom '' neutro | 'bad' ruim | 'in' informativo
 *   action    null | [rótulo, quick ('start'|'finish'|'accept'|'assume'|'return'), forward id, principal?]
 *   check     '' sem seleção | 'none' sem caixa (não entra no lote) | grupo do lote ('novos', 'andamento')
 */

require_once __DIR__ . '/request_card.php';

if (!function_exists('renderInboxRow')) {
    /** Prazo curto da linha: [texto, tipo] — "há 3 dias", "Vence hoje", "Vence amanhã", "12/11" ou "Sem prazo" */
    function inboxDueShort(string $date): array
    {
        [$label, $kind] = deadlineLabel($date);
        if ($kind === 'bad') {
            return [preg_replace('/^Atrasado /u', '', $label), 'bad'];
        }
        return [$label, $kind];
    }

    /** Prazo do painel: "Atrasado há N dias", "Vence hoje", "Vence amanhã", "Até dd/mm" ou "Sem prazo" */
    function inboxDueLong(string $date): array
    {
        [$label, $kind] = deadlineLabel($date);
        return [$kind === '' ? 'Até ' . $label : $label, $kind];
    }

    function renderInboxRow(array $row, array $item): string
    {
        $id = (int)$row['id'];
        $urgent = !empty($row['is_urgent']);
        $check = (string)($item['check'] ?? '');
        [$next, $nextKind] = ($item['next'] ?? []) + ['', ''];

        $who = trim((string)($row['solicitor_name_formatted'] ?? ''));
        $sub = array_filter([
            '#' . $id,
            !empty($item['showOwner']) ? (string)$item['owner'] : '',
            $who !== '—' ? $who : '',
            // "em Começar", "em Esperando"...; "Em andamento" já diz onde está
            !empty($item['tabLabel']) ? (str_starts_with($item['tabLabel'], 'Em ') ? $item['tabLabel'] : 'em ' . $item['tabLabel']) : '',
        ]);

        $due = '';
        $dueKind = '';
        if (($item['due'] ?? true) !== false) {
            [$due, $dueKind] = inboxDueShort((string)($row['date'] ?? ''));
        }

        $html = '<li class="inbox-row' . ($urgent ? ' is-urgent' : '') . ($check !== '' ? ' has-check' : '') . '" data-row="' . e($item['key']) . '">';
        if ($check === 'none') {
            $html .= '<span class="inbox-row__check"></span>';
        } elseif ($check !== '') {
            $html .= '<label class="inbox-row__check"><input type="checkbox" data-pick data-group="' . e($check) . '" value="' . e($item['key']) . '" aria-label="Selecionar pedido nº ' . $id . '"></label>';
        }
        $html .= '<a class="inbox-row__link" href="' . e($item['href']) . '" data-select="' . e($item['key']) . '">'
            . '<span class="inbox-row__top">'
            .   '<span class="inbox-row__title">' . e($row['title'] ?? 'Sem título') . '</span>'
            .   ((int)($row['priority'] ?? 2) === 4 ? '<span class="prio-tag prio-tag--4">Crítica</span>' : '')
            .   ($due !== '' ? '<span class="inbox-row__due' . ($dueKind !== '' ? ' inbox-row__due--' . e($dueKind) : '') . '">' . e($due) . '</span>' : '')
            . '</span>'
            . '<span class="inbox-row__sub">' . e(implode(' · ', $sub)) . '</span>'
            . ($next !== '' ? '<span class="inbox-row__next' . ($nextKind !== '' ? ' inbox-row__next--' . e($nextKind) : '') . '">' . e($next) . '</span>' : '')
            . '</a></li>';
        return $html;
    }

    function renderInboxDetail(array $row, array $item): string
    {
        $id = (int)$row['id'];
        $table = (string)$row['table'];
        [$stKey, $stLabel] = ($item['status'] ?? []) + ['', ''];
        [$situation, $tone] = ($item['situation'] ?? []) + ['', ''];
        [$due, $dueKind] = inboxDueLong((string)($row['date'] ?? ''));
        $who = trim((string)($row['solicitor_name'] ?? ''));
        $area = trim((string)($row['subdivision_name'] ?? ''));

        $html = '<article class="inbox-detail" data-detail="' . e($item['key']) . '" hidden aria-labelledby="d-' . e($table . '-' . $id) . '">'
            . '<header class="inbox-detail__bar">'
            .   '<button type="button" class="btn btn--sm btn--ghost inbox-detail__close" data-panel-close><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Voltar</button>'
            .   '<span class="inbox-detail__num">#' . $id . ' · ' . e((string)$item['owner']) . '</span>'
            .   '<span class="inbox-detail__pager">'
            .     '<button type="button" class="btn btn--sm btn--ghost" data-nav="prev"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i> Anterior</button>'
            .     '<button type="button" class="btn btn--sm btn--ghost" data-nav="next">Próximo <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>'
            .   '</span>'
            . '</header>';

        $html .= '<div class="inbox-detail__chips">'
            . ($stLabel !== '' ? '<span class="status-chip status-chip--' . e($stKey) . '">' . e($stLabel) . '</span>' : '')
            . (!empty($row['is_urgent']) ? '<span class="badge-urgent">Urgente</span>' : '')
            . (trim((string)($item['badge'] ?? '')) !== '' ? '<span class="badge-sector">' . e($item['badge']) . '</span>' : '')
            . '</div>';

        $html .= '<h2 class="inbox-detail__title" id="d-' . e($table . '-' . $id) . '">' . e($row['title'] ?? 'Sem título')
            . ((int)($row['priority'] ?? 2) === 4 ? ' <span class="prio-tag prio-tag--4">Crítica</span>' : '') . '</h2>';

        foreach ($item['notes'] ?? [] as $note) {
            $html .= '<p class="inbox-detail__note">' . e($note) . '</p>';
        }

        $html .= '<dl class="inbox-detail__fields">'
            . '<div><dt>Prazo</dt><dd class="inbox-detail__due' . ($dueKind !== '' ? ' inbox-detail__due--' . e($dueKind) : '') . '">' . e($due) . '</dd></div>'
            . ($situation !== '' ? '<div><dt>Situação</dt><dd class="inbox-detail__situation' . ($tone !== '' ? ' inbox-detail__situation--' . e($tone) : '') . '">' . e($situation) . '</dd></div>' : '')
            . '<div><dt>Pedido por</dt><dd>' . e($who !== '' ? $who : '—') . '</dd></div>'
            . '<div><dt>Área</dt><dd>' . e($area !== '' ? $area : '—') . '</dd></div>'
            . '</dl>';

        $html .= '<div class="inbox-detail__actions">';
        $action = $item['action'] ?? null;
        if (is_array($action)) {
            [$label, $quick, $forwardId, $primary] = $action + ['', '', 0, false];
            $html .= '<button type="button" class="btn' . ($primary ? ' btn--primary' : '') . ' inbox-detail__act" data-quick="' . e($quick) . '"'
                . ' data-id="' . $id . '" data-table="' . e($table) . '"'
                . ((int)$forwardId > 0 ? ' data-forward="' . (int)$forwardId . '"' : '')
                . ' aria-label="' . e($label . ': pedido nº ' . $id) . '">' . e($label) . '</button>';
        }
        $html .= '<a class="inbox-detail__open" href="' . e($item['href']) . '">Abrir página do pedido <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>'
            . '</div></article>';
        return $html;
    }
}
