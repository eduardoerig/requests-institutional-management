<?php
/**
 * Tela Aprovar pedidos: linha da lista e painel do pedido (no molde de components/inbox_item.php).
 *
 * O painel responde, em palavras simples, o que é o pedido, quem pediu, quem vai fazer e para quando,
 * e tem três ações: Perguntar (abre a conversa do pedido com quem pediu), Recusar (pede o motivo numa
 * janela) e Aprovar. Os setores que também participam (sugestão de quem pediu) ficam numa linha só;
 * "Alterar" abre a janela <dialog data-partner-dialog> com o seletor (components/partner_picker.php).
 * A janela fica DENTRO do painel para partnerFields() do script.js achar a escolha ao aprovar.
 *
 * $item: key ("tabela:id"), href (página do pedido), ask (página do pedido aberta na conversa),
 *        pos e total (posição na fila, para "2 de 6").
 * Comportamento: assets/js/pages/approve.js · estilos: assets/css/pages/approve.css
 */
require_once __DIR__ . '/request_card.php';
require_once __DIR__ . '/partner_picker.php';
require_once __DIR__ . '/../classes/RequestItems.php';

if (!function_exists('renderApproveRow')) {
    /**
     * Prazo pedido, do ponto de vista de quem aprova: [curto (lista), longo (painel), tipo].
     * Um prazo vencido não é culpa de quem aprova: diz só que a data "já passou".
     */
    function approveDue(string $date): array
    {
        if ($date === '' || strpos($date, '0000') === 0) {
            return ['sem prazo', 'Sem data definida', 'muted'];
        }
        $ts = strtotime(date('Y-m-d', strtotime($date)));
        $days = (int)round(($ts - strtotime(date('Y-m-d'))) / 86400);
        $day = date(date('Y', $ts) === date('Y') ? 'd/m' : 'd/m/Y', $ts);
        if ($days < 0) {
            return ['data já passou', $day . ' (já passou)', 'bad'];
        }
        if ($days === 0) {
            return ['para hoje', 'Hoje (' . $day . ')', 'warn'];
        }
        if ($days === 1) {
            return ['para amanhã', 'Amanhã (' . $day . ')', 'warn'];
        }
        return ['até ' . $day, $day . ' (daqui a ' . $days . ' dias)', ''];
    }

    /** "hoje", "ontem" ou "há N dias", para "pediu há 2 dias" */
    function approveAskedWhen(string $createdAt): string
    {
        if ($createdAt === '') {
            return '';
        }
        $days = (int)round((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($createdAt)))) / 86400);
        return $days <= 0 ? 'hoje' : ($days === 1 ? 'ontem' : 'há ' . $days . ' dias');
    }

    /** "Urgente", "Crítica" ou '' (pedido normal não ganha etiqueta) */
    function approveUrgencyLabel(array $row): string
    {
        if (empty($row['is_urgent'])) {
            return '';
        }
        return (int)($row['priority'] ?? 2) >= 4 ? 'Crítica' : 'Urgente';
    }

    /** Nome de quem pediu, sem o "—" de quando não há */
    function approveWho(array $row): string
    {
        $who = trim((string)($row['solicitor_name_formatted'] ?? $row['solicitor_name'] ?? ''));
        return $who === '—' ? '' : $who;
    }

    function renderApproveRow(array $row, array $item): string
    {
        $id = (int)$row['id'];
        $sector = requestSlug((string)($row['table'] ?? ''));
        $urgency = approveUrgencyLabel($row);
        [$due, , $dueKind] = approveDue((string)($row['date'] ?? ''));
        $who = approveWho($row);
        $sub = array_filter([$who, 'para ' . requestSectorTo($sector)]);

        return '<li class="ap-row' . ($urgency !== '' ? ' is-urgent' : '') . '" data-row="' . e($item['key']) . '">'
            . '<a class="ap-row__link" href="' . e($item['href']) . '" data-select="' . e($item['key']) . '">'
            .   '<span class="ap-row__top">'
            .     '<span class="ap-row__title">' . e($row['title'] ?? 'Sem título') . '</span>'
            .     ($urgency !== '' ? '<span class="ap-tag">' . e($urgency) . '</span>' : '')
            .   '</span>'
            .   '<span class="ap-row__sub">' . e(implode(' · ', $sub)) . '</span>'
            .   '<span class="ap-row__due' . ($dueKind !== '' ? ' ap-row__due--' . e($dueKind) : '') . '">' . e($due) . '</span>'
            . '</a>'
            // Decidido, esperando o Desfazer (approve.js preenche e mostra)
            . '<span class="ap-row__pending" data-row-pending hidden>'
            .   '<span class="ap-row__stamp" data-row-stamp></span>'
            .   '<span class="ap-row__secs" data-row-secs aria-hidden="true"></span>'
            .   '<button type="button" class="ap-row__undo" data-row-undo aria-label="Desfazer a decisão do pedido nº ' . $id . '">Desfazer</button>'
            . '</span>'
            . '</li>';
    }

    /**
     * O que foi pedido, para o painel: a descrição (ou as cópias, na Reprografia), até $max letras.
     * @return array{0: string, 1: bool} [texto, cortado]
     */
    function approveWhat(array $row, int $max = 600): array
    {
        $text = trim(str_replace("\r\n", "\n", (string)($row['descp'] ?? '')));
        if ($text === '' && !empty($row['qtd'])) {
            $text = xeroxSummary($row);
        }
        if (mb_strlen($text) > $max) {
            return [rtrim(mb_substr($text, 0, $max - 1)) . '…', true];
        }
        return [$text, false];
    }

    /** Observações sem a linha "Urgente: ..." (o motivo já aparece na faixa de urgência) */
    function approveObs(array $row, int $max = 300): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", (string)($row['obs'] ?? '')));
        if ($lines && preg_match('/^\s*urgente:/iu', $lines[0])) {
            array_shift($lines);
        }
        $text = trim(implode("\n", $lines));
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    /** "Compras" ou "Compras e Marketing" */
    function approveNames(array $labels): string
    {
        return implode(' e ', $labels);
    }

    /**
     * @param array $partnerAreas setores possíveis (RequestForwardService::availableAreas); vazio = sem a linha
     */
    function renderApproveDetail(array $row, array $item, array $partnerAreas): string
    {
        $id = (int)$row['id'];
        $sector = requestSlug((string)($row['table'] ?? ''));
        $sectorName = requestSectorName($sector);
        $sectorTo = requestSectorTo($sector);
        $uid = $sector . '-' . $id;
        $who = approveWho($row);
        $area = trim((string)($row['subdivision_name'] ?? ''));
        $when = approveAskedWhen((string)($row['created_at'] ?? ''));
        [, $dueLong, $dueKind] = approveDue((string)($row['date'] ?? ''));
        [$what, $cut] = approveWhat($row);
        $obs = approveObs($row);
        $urgency = approveUrgencyLabel($row);
        $reason = trim((string)($row['urgent_reason'] ?? ''));

        // Setores que também participam: a sugestão de quem pediu já vem escolhida
        $areas = array_values(array_filter($partnerAreas, fn($a) => $a['slug'] !== $sector));
        $suggested = [];
        foreach ($areas as $a) {
            if (in_array((int)$a['id'], $row['suggested_partners'] ?? [], true)) {
                $suggested[(int)$a['id']] = (string)$a['label'];
            }
        }
        $suggestedIds = array_keys($suggested);
        $names = approveNames(array_values($suggested));

        $html = '<article class="ap-detail' . ($urgency !== '' ? ' is-urgent' : '') . '" data-detail="' . e($item['key']) . '"'
            . ' data-id="' . $id . '" data-table="' . e($sector) . '" data-sector-to="' . e($sectorTo) . '" data-who="' . e($who) . '"'
            . ' aria-labelledby="ap-t-' . e($uid) . '" hidden>';

        // Barra: Lista (celular) · "2 de 6" · anterior/próximo
        $html .= '<header class="ap-detail__bar">'
            . '<button type="button" class="btn btn--sm btn--ghost ap-detail__list" data-show-list><i class="fa-solid fa-list" aria-hidden="true"></i> Ver lista</button>'
            . '<span class="ap-detail__pos" data-pos>' . (int)$item['pos'] . ' de ' . (int)$item['total'] . '</span>'
            . '<span class="ap-detail__pager">'
            .   '<button type="button" class="btn btn--sm btn--ghost" data-nav="prev" aria-label="Pedido anterior"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>'
            .   '<button type="button" class="btn btn--sm btn--ghost" data-nav="next" aria-label="Próximo pedido"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>'
            . '</span>'
            . '</header>';

        if ($urgency !== '') {
            $html .= '<p class="ap-urgent"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <strong>' . e($urgency) . '</strong>'
                . ($reason !== '' ? ' · ' . e($reason) : '') . '</p>';
        }

        $html .= '<h2 class="ap-detail__title" id="ap-t-' . e($uid) . '" tabindex="-1">' . e($row['title'] ?? 'Sem título') . '</h2>'
            . '<p class="ap-detail__who">'
            . ($who !== '' ? '<strong>' . e($who) . '</strong>' : 'Alguém')
            . ($area !== '' ? ' (' . e($area) . ')' : '')
            . ' pediu' . ($when !== '' ? ' ' . e($when) : '')
            . ' · pedido nº ' . $id . '</p>';

        $html .= '<dl class="ap-facts">';
        if ($what !== '') {
            $html .= '<div class="ap-facts__what"><dt>O que precisa</dt><dd><p class="ap-text">' . e($what) . '</p>'
                . ($cut ? '<a class="ap-more" href="' . e($item['href']) . '">Ver o pedido completo</a>' : '') . '</dd></div>';
        }
        if (!empty($row['items'])) { // itens de Compras, links do Marketing (RequestInbox::approvals)
            $html .= '<div class="ap-facts__what"><dt>' . e(RequestItems::heading($sector)) . '</dt><dd>'
                . RequestItems::html($row['items'], $sector) . '</dd></div>';
        }
        if ($obs !== '') {
            $html .= '<div class="ap-facts__what"><dt>Observações</dt><dd><p class="ap-text">' . e($obs) . '</p></dd></div>';
        }
        $html .= '<div><dt>Quem vai fazer</dt><dd>' . e($sectorName) . '</dd></div>'
            . '<div><dt>Para quando</dt><dd class="ap-due' . ($dueKind !== '' ? ' ap-due--' . e($dueKind) : '') . '">' . e($dueLong) . '</dd></div>';

        if ($areas) {
            // approve.js reescreve esta linha quando a pessoa muda a escolha na janela
            $html .= '<div class="ap-partners" data-partner-line data-suggested="' . e(implode(',', $suggestedIds)) . '">'
                . '<dt data-partner-label>' . ($names !== '' ? 'Também participam' : 'Outros setores') . '</dt>'
                . '<dd>'
                .   '<span class="ap-partners__names" data-partner-names' . ($names === '' ? ' hidden' : '') . '>' . e($names) . '</span>'
                .   '<span class="ap-partners__origin" data-partner-origin' . ($names === '' ? ' hidden' : '') . '>sugestão de quem pediu</span>'
                .   '<button type="button" class="ap-link" data-partner-edit aria-haspopup="dialog">'
                .     ($names === '' ? '<i class="fa-solid fa-plus" aria-hidden="true"></i> Chamar outro setor para ajudar' : 'Alterar')
                .   '</button>'
                . '</dd>'
                . '</div>';
        }
        $html .= '</dl>';

        // O que acontece ao aprovar, em uma frase
        $html .= '<p class="ap-detail__hint" data-approve-hint>Aprovar envia o pedido para ' . e($sectorTo) . ' começar'
            . ($names !== '' ? ' e avisa ' . e($names) : '') . '.</p>';

        $html .= '<div class="ap-actions">'
            . '<a class="btn ap-actions__ask" href="' . e($item['ask']) . '"><i class="fa-regular fa-comment" aria-hidden="true"></i> Perguntar</a>'
            . '<button type="button" class="btn ap-actions__refuse" data-decide="refuse" aria-label="Recusar o pedido nº ' . $id . '">Recusar</button>'
            . '<button type="button" class="btn btn--primary ap-actions__approve" data-decide="approve" aria-label="Aprovar o pedido nº ' . $id . '"><i class="fa-solid fa-check" aria-hidden="true"></i> Aprovar</button>'
            . '</div>';

        if ($areas) {
            $html .= '<dialog class="ap-sheet" data-partner-dialog aria-labelledby="ap-pd-' . e($uid) . '">'
                . '<div class="ap-sheet__card">'
                .   '<h3 class="ap-sheet__title" id="ap-pd-' . e($uid) . '">Setores que também participam</h3>'
                .   '<p class="ap-sheet__lead">' . e(ucfirst($sectorTo)) . ' continua responsável pelo pedido. Os setores escolhidos aqui fazem uma parte, cada um com o seu prazo.</p>'
                .   renderPartnerPicker($areas, 'ap-' . $uid, $suggestedIds[0] ?? null, RequestForwardService::defaultPartnerDue($row), 'Nenhum', true, $suggestedIds[1] ?? null)
                .   '<p class="field-error" data-partner-error hidden></p>'
                .   '<div class="ap-sheet__actions">'
                .     '<button type="button" class="btn" data-partner-cancel>Cancelar</button>'
                .     '<button type="button" class="btn btn--primary" data-partner-done>Pronto</button>'
                .   '</div>'
                . '</div>'
                . '</dialog>';
        }

        return $html . '</article>';
    }
}
