<?php
/**
 * Pedido completo (pages/request_detail.php e api/request_view.php).
 *
 * renderRequestView($pdo, $req, $sector)
 *   Cabeçalho fixo com as ações, um aviso quando há algo a decidir e duas colunas:
 *   Atividade (o pedido, os eventos e a conversa numa linha do tempo só) e Detalhes.
 *
 * renderRequestFeed($pdo, $req, $sector, $viewerId, $isStaff)
 *   Só a linha do tempo. api/get_comments.php usa a mesma função para atualizar a tela.
 *
 * Os botões chamam funções globais de assets/js/script.js (requestAct, decideRequest,
 * forwardAct, openForwardWizard) com data-id/data-table.
 */

require_once __DIR__ . '/../classes/RequestForwardService.php';
require_once __DIR__ . '/request_card.php';

if (!function_exists('renderRequestView')) {
    /** Evento do histórico contado por quem fez ("aprovou o pedido"); null = não mostrar */
    function requestEventText(string $action, ?string $old, ?string $new): ?string
    {
        if ($action === 'Prioridade alterada') {
            return 'mudou a urgência' . ($old && $new ? " de $old para $new" : '');
        }
        $rules = [
            '/^Requisição criada$/u' => null, // o próprio pedido abre a linha do tempo
            '/^Requisição aprovada$/iu' => 'aprovou o pedido',
            '/^Requisição recusada$/iu' => 'recusou o pedido',
            '/^Atendimento iniciado$/u' => 'começou o atendimento',
            '/^Requisição concluída via repasse \((.+)\)$/iu' => 'concluiu o pedido ($1)',
            '/^Requisição concluída$/iu' => 'concluiu o pedido',
            '/^Requisição reaberta.*$/iu' => 'reabriu o pedido',
            '/^Requisição repassada para (.+)$/iu' => 'encaminhou para $1',
            '/^Repasse aceito por .*?\(([^)]+)\).*$/u' => 'assumiu o pedido ($1)',
            '/^Setor (.+?) (assumiu o pedido|aceitou ajudar|recusou o encaminhamento|devolveu a ajuda)$/u' => '$2 ($1)',
        ];
        foreach ($rules as $pattern => $text) {
            if (preg_match($pattern, $action)) {
                return $text === null ? null : preg_replace($pattern, $text, $action);
            }
        }
        return mb_strtolower(mb_substr($action, 0, 1)) . mb_substr($action, 1);
    }

    /** Histórico do pedido, do mais antigo ao mais novo */
    function requestHistoryRows(PDO $pdo, int $reqId, string $sector): array
    {
        $st = $pdo->prepare('SELECT user_name, action, old_value, new_value, created_at FROM request_history WHERE request_id = ? AND request_table = ? ORDER BY created_at ASC, id ASC');
        $st->execute([$reqId, $sector]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Conversa do pedido: mensagens e o que os setores escreveram ao encaminhar ou responder.
     * As mudanças automáticas de situação (sem texto) vêm marcadas com --sys e ficam escondidas
     * até alguém pedir o histórico completo.
     */
    function renderRequestFeed(PDO $pdo, array $req, string $sector, int $viewerId, bool $isStaff, ?array $forwards = null, ?array $history = null): string
    {
        $reqId = (int)$req['id'];
        $items = [];

        // Encaminhamentos com a mensagem: só para os setores (a página do pedido já manda os que leu)
        $forwards = $isStaff ? ($forwards ?? RequestForwardService::forRequest($pdo, $reqId, $sector)) : [];
        $skip = '/^(Encaminhou|Pediu ajuda|Setor .* (assumiu|aceitou|recusou|devolveu)|Cancelou o encaminhamento|Assumiu o pedido|Aceitou ajudar|Recusou o encaminhamento|Devolveu a ajuda'
            . ($forwards ? '|Requisição repassada|Repasse aceito' : '') . ')/u';

        if ($history === null) {
            $history = requestHistoryRows($pdo, $reqId, $sector);
        }
        foreach ($history as $h) {
            if ($isStaff && preg_match($skip, (string)$h['action'])) {
                continue; // vem dos encaminhamentos, com a mensagem
            }
            $text = requestEventText((string)$h['action'], $h['old_value'], $h['new_value']);
            if ($text !== null) {
                $items[] = ['at' => (string)$h['created_at'], 'type' => 'event', 'who' => (string)$h['user_name'], 'text' => $text, 'note' => '', 'bad' => false];
            }
        }
        $responses = ['accepted' => 'aceitou', 'refused' => 'recusou', 'returned' => 'devolveu a ajuda', 'cancelled' => 'cancelou o envio', 'completed' => 'concluiu'];
        foreach ($forwards as $f) {
            $items[] = [
                'at' => (string)$f['created_at'], 'type' => 'event',
                'who' => (string)($f['forwarded_by_name'] ?? $f['from_label']),
                'text' => ($f['mode'] === 'help' ? 'pediu ajuda a ' : 'encaminhou para ') . $f['to_label'],
                'note' => (string)($f['observation'] ?? ''), 'bad' => false,
            ];
            if (!empty($f['responded_at']) && isset($responses[$f['status']])) {
                $items[] = [
                    'at' => (string)$f['responded_at'], 'type' => 'event',
                    'who' => (string)(($f['responded_by_name'] ?? '') ?: $f['to_label']),
                    'text' => $responses[$f['status']] . ($f['status'] === 'cancelled' ? '' : ' (' . $f['to_label'] . ')'),
                    'note' => (string)($f['response_note'] ?? ''), 'bad' => $f['status'] === 'refused',
                ];
            }
        }

        $st = $pdo->prepare('SELECT * FROM request_comments WHERE request_id = ? AND request_table = ? ORDER BY created_at ASC, id ASC');
        $st->execute([$reqId, $sector]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $items[] = ['at' => (string)$c['created_at'], 'type' => 'message', 'c' => $c];
        }

        $order = ['event' => 1, 'message' => 2];
        usort($items, fn($a, $b) => strcmp($a['at'], $b['at']) ?: ($order[$a['type']] <=> $order[$b['type']]));

        // Automático = evento sem texto escrito por alguém
        $isSys = fn(array $it) => $it['type'] === 'event' && trim($it['note']) === '' && !$it['bad'];
        $daySys = []; // dia => só tem automáticos?
        foreach ($items as $it) {
            $d = date('d/m/Y', strtotime($it['at']));
            $daySys[$d] = ($daySys[$d] ?? true) && $isSys($it);
        }

        $today = date('d/m/Y');
        $yesterday = date('d/m/Y', strtotime('-1 day'));
        $html = '';
        $lastDay = '';
        foreach ($items as $it) {
            $day = date('d/m/Y', strtotime($it['at']));
            if ($day !== $lastDay) {
                $html .= '<p class="feed-day' . ($daySys[$day] ? ' feed-day--sys' : '') . '">' . ($day === $today ? 'Hoje' : ($day === $yesterday ? 'Ontem' : $day)) . '</p>';
                $lastDay = $day;
            }
            $time = date('H:i', strtotime($it['at']));
            if ($it['type'] === 'event') {
                $html .= '<div class="feed-event' . ($it['bad'] ? ' feed-event--bad' : '') . ($isSys($it) ? ' feed-event--sys' : '') . '">'
                    . '<p><strong>' . e($it['who']) . '</strong> ' . e($it['text']) . ' <time>' . $time . '</time></p>'
                    . (trim($it['note']) !== '' ? '<blockquote>' . nl2br(e($it['note'])) . '</blockquote>' : '')
                    . '</div>';
            } else {
                $c = $it['c'];
                $side = (int)$c['user_id'] === $viewerId ? 'mine' : 'theirs';
                $html .= '<div class="chat-item ' . $side . '">'
                    . '<div class="chat-avatar" aria-hidden="true">' . e(mb_strtoupper(mb_substr((string)$c['user_name'], 0, 1))) . '</div>'
                    . '<div class="chat-bubble ' . $side . '">'
                    . '<div class="chat-user-name">' . e($c['user_name']) . '</div>'
                    . nl2br(e($c['comment']))
                    . '<span class="chat-time">' . $time . '</span>'
                    . '</div></div>';
            }
        }
        if (count(array_filter($items, fn($it) => !$isSys($it))) === 0) {
            $html .= '<p class="feed-empty">Ninguém escreveu ainda. Use o campo abaixo para falar sobre o pedido.</p>';
        }
        return $html;
    }

    function renderRequestView(PDO $pdo, array $req, string $sector, string $context = 'page'): void
    {
        $viewerId = (int)($_SESSION['id'] ?? 0);
        $role = $_SESSION['role'] ?? 'solicitante';
        $reqId = (int)$req['id'];
        $status = strtoupper((string)($req['status'] ?? 'P')) ?: 'P';
        $isStaff = $role !== 'solicitante';
        $isRequester = (int)($req['created_by'] ?? 0) === $viewerId;
        $isGlobalAdmin = isGlobalAdminRole($role);
        $isGestor = isGestorRole($role);
        $sectorName = requestSectorName($sector);

        // Encaminhamentos lidos uma vez; deles saem o responsável atual e o encaminhamento em aberto
        $forwards = RequestForwardService::forRequest($pdo, $reqId, $sector);
        $ownerArea = null;
        $open = null;
        foreach ($forwards as $f) { // do mais antigo ao mais novo: fica o último de cada
            if ($f['mode'] === 'transfer' && in_array($f['status'], ['accepted', 'completed'], true)) {
                $ownerArea = (int)$f['to_area_id'];
            }
            if ($f['status'] === 'pending' || ($f['mode'] === 'help' && $f['status'] === 'accepted')) {
                $open = $f;
            }
        }
        $ownerArea = $ownerArea ?? RequestForwardService::originAreaId($pdo, $sector);
        $req['owner_area_id'] = $ownerArea; // as checagens de permissão abaixo não precisam consultar de novo
        $ownerLabel = RequestForwardService::areaLabel($pdo, $ownerArea);
        $viewerAreas = RequestForwardService::userAreaIds($pdo, $viewerId);

        // O que esta pessoa pode fazer
        $isOwner = userIsRequestOwner($pdo, $req, $sector, $viewerId, $role);
        $canWork = $isGestor && $isOwner;
        $canApprove = $status === 'P' && isAdminRole($role) && userCanManageOriginalRequest($pdo, $req, $sector, $viewerId, $role);
        $canForward = ($canWork || $isGlobalAdmin) && in_array($status, ['Y', 'W'], true) && !$open;
        $canPriority = userCanActOnRequest($pdo, $req, $sector, $viewerId, $role) && !in_array($status, ['C', 'N'], true);
        $canReopen = $isOwner && $status === 'C';
        $canCancelRequest = $isRequester && $status === 'P';
        $openIsForMe = $open && ($isGlobalAdmin || in_array((int)$open['to_area_id'], $viewerAreas, true));
        $openIsFromMe = $open && ($isGlobalAdmin || (int)$open['forwarded_by'] === $viewerId || in_array((int)$open['from_area_id'], $viewerAreas, true));

        // Sem encaminhamento aberto: mostra o último respondido (por que veio, o que voltou, por que recusaram)
        $lastDone = null;
        if (!$open && $isStaff && !in_array($status, ['C', 'N'], true)) {
            foreach (array_reverse($forwards) as $f) {
                if (in_array($f['status'], ['accepted', 'returned', 'refused'], true)) {
                    $lastDone = $f;
                }
                if ($f['status'] !== 'cancelled') {
                    break;
                }
            }
        }

        // Datas de cada situação e quem está atendendo (do histórico, lido uma vez para tudo)
        $history = requestHistoryRows($pdo, $reqId, $sector);
        $statusAt = [];
        $worker = '';
        foreach ($history as $h) {
            if ($h['new_value'] === null) {
                continue;
            }
            $statusAt[(string)$h['new_value']] = $h['created_at'];
            if ((string)$h['new_value'] === statusLabel('W')) {
                $worker = (string)$h['user_name'];
            }
        }

        // Motivo da recusa fica à vista (vem da conversa)
        $refuseReason = '';
        if ($status === 'N') {
            $st = $pdo->prepare("SELECT comment FROM request_comments WHERE request_id = ? AND request_table = ? AND comment LIKE 'Motivo da recusa: %' ORDER BY id DESC LIMIT 1");
            $st->execute([$reqId, $sector]);
            $refuseReason = substr((string)$st->fetchColumn(), strlen('Motivo da recusa: '));
        }

        // Campos do tipo de pedido
        $extra = [];
        if ($sector === 'xerox') {
            $extra['Arquivo'] = $req['arquive'] ?? '';
            $extra['Cópias'] = $req['qtd'] ?? '';
            $extra['Tamanho do papel'] = $req['type'] ?? '';
        } elseif ($sector === 'mkt') {
            $extra['Onde será usada'] = $req['type'] ?? '';
        }

        $subdivision = (string)($req['subdivision_name'] ?? '');
        if ($subdivision === '' && !array_key_exists('subdivision_name', $req) && !empty($req['subdivision_id'])) {
            $st = $pdo->prepare('SELECT name FROM ctd_subdivision WHERE id = ?');
            $st->execute([$req['subdivision_id']]);
            $subdivision = (string)$st->fetchColumn();
        }

        $data = 'data-id="' . $reqId . '" data-table="' . e($sector) . '"';
        $fullTable = 'ctd_' . $sector . '_frm';
        $hasDeadline = !empty($req['date']) && strpos((string)$req['date'], '0000') !== 0;
        $priority = (int)($req['priority'] ?? 2);

        // Para quem pediu: etapas e uma frase do que está acontecendo
        $shortDate = fn($d) => $d ? date('d/m', strtotime((string)$d)) : '';
        if ($status === 'N') {
            $steps = [['Enviado', $shortDate($req['created_at'] ?? '')], ['Recusado', $shortDate($statusAt['Recusada'] ?? '')]];
            $stepNow = 1;
        } else {
            $steps = [
                ['Enviado', $shortDate($req['created_at'] ?? '')],
                ['Aprovado', $shortDate($statusAt['Aprovada'] ?? '')],
                ['Em atendimento', $shortDate($statusAt[statusLabel('W')] ?? '')],
                ['Concluído', $status === 'C' ? $shortDate($statusAt[statusLabel('C')] ?? '') : ''],
            ];
            $stepNow = ['P' => 0, 'Y' => 1, 'F' => 1, 'W' => 2, 'C' => 3][$status] ?? 0;
        }
        $now = [
            'P' => 'Seu pedido aguarda a aprovação da coordenação.',
            'Y' => "Seu pedido foi aprovado. O setor de $ownerLabel vai começar o atendimento.",
            'F' => "Seu pedido foi aprovado. O setor de $ownerLabel vai começar o atendimento.",
            'W' => "O setor de $ownerLabel está atendendo.",
            'C' => "Seu pedido foi concluído pelo setor de $ownerLabel.",
            'N' => 'Pedido recusado.',
        ][$status] ?? '';
        $nowIcon = ['P' => 'fa-hourglass-half', 'Y' => 'fa-thumbs-up', 'F' => 'fa-thumbs-up', 'W' => 'fa-gears', 'C' => 'fa-circle-check', 'N' => 'fa-circle-xmark'][$status] ?? 'fa-circle-info';

        // Barra de ações: uma ação principal (a próxima coisa a fazer) e as secundárias ao lado
        $jsSector = e($sector);
        $candidates = [];
        if ($openIsForMe && $open['status'] === 'pending') {
            $candidates[] = ['main', '<button type="button" class="btn %s" onclick="forwardAct(\'accept\', ' . (int)$open['id'] . ')"><i class="fa-solid fa-check" aria-hidden="true"></i> ' . ($open['mode'] === 'transfer' ? 'Aceitar e assumir' : 'Aceitar ajudar') . '</button>'];
            $candidates[] = ['side', '<button type="button" class="btn" onclick="forwardAct(\'refuse\', ' . (int)$open['id'] . ')">Recusar</button>'];
        }
        if ($openIsForMe && $open['mode'] === 'help' && $open['status'] === 'accepted') {
            $candidates[] = ['main', '<button type="button" class="btn %s" onclick="forwardAct(\'return\', ' . (int)$open['id'] . ')"><i class="fa-solid fa-reply" aria-hidden="true"></i> Devolver com retorno</button>'];
        }
        if ($canApprove) {
            $candidates[] = ['main', '<button type="button" class="btn %s" onclick="decideRequest(\'Y\', ' . $reqId . ', \'' . $jsSector . '\')"><i class="fa-solid fa-check" aria-hidden="true"></i> Aprovar</button>'];
            $candidates[] = ['side', '<button type="button" class="btn" onclick="decideRequest(\'N\', ' . $reqId . ', \'' . $jsSector . '\')">Recusar</button>'];
        }
        if ($canWork && $status === 'Y' && !($open && $open['mode'] === 'transfer')) {
            $candidates[] = ['main', '<button type="button" class="btn %s" onclick="requestAct(\'start_progress\', ' . $reqId . ', \'' . e($fullTable) . '\')"><i class="fa-solid fa-play" aria-hidden="true"></i> Começar atendimento</button>'];
        } elseif ($canWork && $status === 'W' && !$open) {
            $candidates[] = ['main', '<button type="button" class="btn %s" onclick="requestAct(\'finish\', ' . $reqId . ', \'' . e($fullTable) . '\')"><i class="fa-solid fa-check-double" aria-hidden="true"></i> Concluir</button>'];
        }
        if ($canForward) {
            $candidates[] = ['side', '<button type="button" class="btn" onclick="openForwardWizard(' . $reqId . ', \'' . $jsSector . '\')"><i class="fa-solid fa-share-from-square" aria-hidden="true"></i> Encaminhar</button>'];
        }
        // Só a primeira ação principal fica laranja e vai para a direita
        $mainButton = '';
        $sideButtons = [];
        foreach ($candidates as [$kind, $html]) {
            if ($kind === 'main' && $mainButton === '') {
                $mainButton = sprintf($html, 'btn--primary');
            } else {
                $sideButtons[] = sprintf($html, '');
            }
        }
        $canCancelForward = $openIsFromMe && $open['status'] === 'pending' && !$openIsForMe;
        $hasMenu = $canReopen || $canCancelForward || $canCancelRequest;

        // Detalhes (coluna lateral)
        $props = [];
        $props['Prazo'] = $hasDeadline
            ? e(date('d/m/Y', strtotime((string)$req['date']))) . (in_array($status, ['C', 'N'], true) ? '' : ' ' . requestDeadlineFlag((string)$req['date']))
            : 'Sem prazo';
        if ($canPriority) {
            $options = '';
            foreach (priorityLabels() as $v => $l) {
                $options .= '<option value="' . $v . '"' . ($priority === $v ? ' selected' : '') . '>' . e($l) . '</option>';
            }
            $props['Urgência'] = '<select class="rv-props__select" aria-label="Urgência" onchange="requestAct(\'set_priority\', ' . $reqId . ', \'' . e($fullTable) . '\', this.value)">' . $options . '</select>';
        } else {
            $props['Urgência'] = '<span class="' . ($priority >= 3 ? 'prio-tag prio-tag--' . $priority : '') . '">' . e(priorityLabel($priority)) . '</span>';
        }
        $workerNote = $worker !== '' && in_array($status, ['W', 'C'], true) ? ($status === 'C' ? 'atendido por ' : '') . $worker : '';
        $props['Atendimento'] = e($ownerLabel)
            . ($workerNote !== '' ? '<small>' . e($workerNote) . '</small>' : '')
            . ($ownerLabel !== $sectorName ? '<small>veio de ' . e($sectorName) . '</small>' : '');
        $props['Quem pediu'] = e($req['creator_name'] ?? '—') . ($subdivision !== '' ? '<small>' . e($subdivision) . '</small>' : '');

        // Campos do tipo de pedido ficam junto do texto do pedido
        $facts = [];
        foreach ($extra as $label => $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $facts[$label] = preg_match('#^https?://#i', $value)
                ? '<a href="' . e($value) . '" target="_blank" rel="noopener noreferrer">Abrir arquivo</a>'
                : e($value);
        }
        $desc = trim((string)($req['descp'] ?? ''));
        ?>
<article class="request-view request-view--<?= e($context) ?>" <?= $data ?> data-title="<?= e($req['title'] ?? '') ?>">
    <header class="rv-head">
        <div class="rv-head__top">
            <span class="rv-num">Nº <?= $reqId ?></span>
            <span class="sector-tag" data-sector="<?= e($sector) ?>"><?= e($sectorName) ?></span>
            <span class="rv-head__tools">
                <?php if ($isStaff): ?>
                    <a class="icon-link" href="print_request.php?id=<?= $reqId ?>&table=<?= e($sector) ?>" onclick="return printRequest(event, this.href)" title="Imprimir" aria-label="Imprimir"><i class="fa-solid fa-print" aria-hidden="true"></i></a>
                <?php endif; ?>
                <?php if ($hasMenu): ?>
                    <details class="rv-more">
                        <summary class="icon-link" title="Mais opções" aria-label="Mais opções"><i class="fa-solid fa-ellipsis" aria-hidden="true"></i></summary>
                        <div class="rv-more__menu">
                            <?php if ($canReopen): ?>
                                <button type="button" class="rv-menu-item" onclick="requestAct('reset_status', <?= $reqId ?>, '<?= e($fullTable) ?>')"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reabrir pedido</button>
                            <?php endif; ?>
                            <?php if ($canCancelForward): ?>
                                <button type="button" class="rv-menu-item" onclick="forwardAct('cancel', <?= (int)$open['id'] ?>)"><i class="fa-solid fa-ban" aria-hidden="true"></i> Cancelar envio</button>
                            <?php endif; ?>
                            <?php if ($canCancelRequest): ?>
                                <button type="button" class="rv-menu-item rv-menu-item--danger" onclick="requestAct('delete', <?= $reqId ?>, '<?= e($fullTable) ?>')"><i class="fa-solid fa-trash-can" aria-hidden="true"></i> Cancelar este pedido</button>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </span>
        </div>
        <h2 class="rv-title"><?= e($req['title'] ?? 'Sem título') ?></h2>
        <div class="rv-bar">
            <span class="status-pill status-pill--<?= e($status) ?>"><?= e(statusLabel($status)) ?></span>
            <?php if ($mainButton !== '' || $sideButtons): ?>
                <div class="rv-bar__actions"><?= implode('', $sideButtons) ?><?= $mainButton ?></div>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$isStaff): ?>
        <section class="rv-now rv-now--<?= e($status) ?>" aria-label="Situação agora">
            <ol class="rv-steps<?= $status === 'N' ? ' rv-steps--refused' : '' ?>" aria-label="Etapas do pedido">
                <?php foreach ($steps as $i => [$label, $when]):
                    $state = $i < $stepNow ? 'done' : ($i === $stepNow ? 'now' : 'next');
                ?>
                    <li class="rv-step rv-step--<?= $state ?>"<?= $state === 'now' ? ' aria-current="step"' : '' ?>>
                        <span class="rv-step__dot" aria-hidden="true"><?php if ($state === 'done' || ($state === 'now' && in_array($status, ['C', 'N'], true))): ?><i class="fa-solid <?= $status === 'N' && $i === 1 ? 'fa-xmark' : 'fa-check' ?>"></i><?php endif; ?></span>
                        <span class="rv-step__label"><?= e($label) ?></span>
                        <?php if ($when !== '' && $state !== 'next'): ?><span class="rv-step__date"><?= e($when) ?></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
            <p class="rv-now__text"><i class="fa-solid <?= e($nowIcon) ?>" aria-hidden="true"></i> <span><?= e($now) ?></span></p>
            <?php if ($refuseReason !== ''): ?><blockquote class="rv-now__note"><?= nl2br(e($refuseReason)) ?></blockquote><?php endif; ?>
        </section>
    <?php elseif ($open): ?>
        <section class="forward-box" aria-label="Encaminhamento em aberto">
            <p class="forward-box__title">
                <?php if ($open['mode'] === 'transfer'): ?>
                    <i class="fa-solid fa-right-left" aria-hidden="true"></i> <?= e($open['from_label']) ?> quer transferir para <?= e($open['to_label']) ?>
                <?php elseif ($open['status'] === 'accepted'): ?>
                    <i class="fa-solid fa-handshake-angle" aria-hidden="true"></i> <?= e($open['to_label']) ?> está ajudando <?= e($open['from_label']) ?>
                <?php else: ?>
                    <i class="fa-solid fa-handshake-angle" aria-hidden="true"></i> <?= e($open['from_label']) ?> pediu ajuda a <?= e($open['to_label']) ?>
                <?php endif; ?>
            </p>
            <?php if (trim((string)$open['observation']) !== ''): ?>
                <blockquote class="forward-box__msg"><?= nl2br(e($open['observation'])) ?></blockquote>
            <?php endif; ?>
            <p class="forward-box__meta"><?= e($open['forwarded_by_name'] ?? '') ?>, <?= e(date('d/m \à\s H:i', strtotime($open['created_at']))) ?></p>
        </section>
    <?php elseif ($lastDone):
        $doneText = [
            'accepted' => $lastDone['mode'] === 'transfer'
                ? 'Veio de ' . $lastDone['from_label'] . ' por transferência'
                : $lastDone['to_label'] . ' está ajudando',
            'returned' => $lastDone['to_label'] . ' devolveu a ajuda',
            'refused' => $lastDone['to_label'] . ' recusou o encaminhamento',
        ][$lastDone['status']];
        $doneNote = $lastDone['status'] === 'accepted' ? (string)$lastDone['observation'] : (string)$lastDone['response_note'];
        if ($lastDone['status'] === 'accepted' && $lastDone['mode'] === 'help') { $doneNote = ''; }
    ?>
        <section class="forward-box forward-box--done<?= $lastDone['status'] === 'refused' ? ' forward-box--bad' : '' ?>" aria-label="Último encaminhamento">
            <p class="forward-box__title"><?= e($doneText) ?></p>
            <?php if (trim($doneNote) !== ''): ?><blockquote class="forward-box__msg"><?= nl2br(e($doneNote)) ?></blockquote><?php endif; ?>
            <p class="forward-box__meta"><?= e(($lastDone['status'] === 'accepted' ? ($lastDone['forwarded_by_name'] ?? '') : ($lastDone['responded_by_name'] ?? '')) ?: '') ?><?= !empty($lastDone['responded_at']) ? ', ' . e(date('d/m \à\s H:i', strtotime((string)$lastDone['responded_at']))) : '' ?></p>
        </section>
    <?php elseif ($refuseReason !== ''): ?>
        <section class="forward-box forward-box--bad" aria-label="Motivo da recusa">
            <p class="forward-box__title">Pedido recusado</p>
            <blockquote class="forward-box__msg"><?= nl2br(e($refuseReason)) ?></blockquote>
        </section>
    <?php endif; ?>

    <div class="rv-body">
        <aside class="rv-side" aria-label="Resumo do pedido">
            <dl class="rv-props">
                <?php foreach ($props as $label => $value): ?>
                    <div><dt><?= e($label) ?></dt><dd><?= $value ?></dd></div>
                <?php endforeach; ?>
            </dl>
        </aside>

        <div class="rv-main">
            <section class="rv-ask" aria-labelledby="rv-ask-<?= $reqId ?>">
                <h3 class="rv-section__title" id="rv-ask-<?= $reqId ?>">O que foi pedido</h3>
                <div class="rv-ask__text"><?= $desc !== '' ? nl2br(e($desc)) : '<span class="muted">Sem descrição.</span>' ?></div>
                <?php if ($facts): ?>
                    <dl class="rv-ask__facts">
                        <?php foreach ($facts as $label => $value): ?>
                            <div><dt><?= e($label) ?></dt><dd><?= $value ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                <?php endif; ?>
                <?php if (trim((string)($req['obs'] ?? '')) !== ''): ?>
                    <p class="rv-ask__obs"><strong>Observações:</strong> <?= nl2br(e($req['obs'])) ?></p>
                <?php endif; ?>
                <p class="rv-ask__meta"><?= e($req['creator_name'] ?? 'Quem pediu') ?><?= $subdivision !== '' ? ', ' . e($subdivision) : '' ?>, em <?= e(date('d/m/Y \à\s H:i', strtotime((string)$req['created_at']))) ?></p>
            </section>

            <section class="rv-talk" aria-labelledby="rv-feed-<?= $reqId ?>">
                <div class="rv-talk__head">
                    <h3 class="rv-section__title" id="rv-feed-<?= $reqId ?>">Conversa</h3>
                    <button type="button" class="btn btn--ghost btn--sm" data-history-toggle aria-pressed="false">Mostrar histórico completo</button>
                </div>
                <div class="rv-feed" data-chat-feed <?= $data ?>><?= renderRequestFeed($pdo, $req, $sector, $viewerId, $isStaff, $forwards, $history) ?></div>
            <form class="chat-footer" data-chat-form <?= $data ?>>
                <div class="chat-input-area">
                    <textarea name="comment" class="chat-input" placeholder="Escreva uma mensagem" rows="1" required maxlength="3000" aria-label="Mensagem"></textarea>
                    <button type="submit" class="btn-send" aria-label="Enviar mensagem"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
                </div>
                <p class="chat-hint"><?= $isStaff ? 'Quem pediu também vê esta conversa.' : 'Quem cuida do pedido recebe sua mensagem.' ?></p>
            </form>
            </section>
        </div>
    </div>
</article>
        <?php
    }
}
