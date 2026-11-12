<?php
/**
 * Linha do tempo do pedido: eventos do histórico (por event_code, migração 008) e mensagens em
 * balões. renderRequestFeed é usada pela página e por api/get_comments.php (a saída precisa ser a mesma).
 */

require_once __DIR__ . '/../../classes/RequestForwardService.php';

/**
 * Evento do histórico contado por quem fez ("aprovou o pedido"); null = não mostrar.
 * Pelo event_code (migração 008); registros sem código (ou legados) caem nas regras pelo texto.
 */
function requestEventText(string $action, ?string $old, ?string $new, ?string $code = null): ?string
{
    $area = trim((string)$new);
    $byCode = [
        'created' => null, // o próprio pedido abre a linha do tempo
        'approved' => 'aprovou o pedido',
        'refused' => 'recusou o pedido',
        'started' => 'começou o atendimento',
        'finished' => 'concluiu o pedido',
        'reopened' => 'reabriu o pedido',
        'forward_transfer' => "encaminhou para o setor $area",
        'forward_help' => "pediu ajuda ao setor $area",
        'accepted_transfer' => "assumiu o pedido ($area)",
        'accepted_help' => "aceitou ajudar ($area)",
        'forward_refused' => "recusou o encaminhamento ($area)",
        'forward_cancelled' => "cancelou o encaminhamento para $area",
        'help_returned' => "devolveu a ajuda ($area)",
        'partner_suggested' => "sugeriu $area como setor parceiro",
        'partner_added' => "incluiu $area como setor parceiro",
        'partner_refused' => "recusou ser parceiro ($area)",
    ];
    if ($code === 'priority') {
        // Número (1-4) desde a migração 008; rótulo nos registros que ela não converteu
        $label = fn(?string $v) => ctype_digit((string)$v) ? priorityLabel((int)$v) : (string)$v;
        return 'mudou a urgência' . ($old && $new ? ' de ' . $label($old) . ' para ' . $label($new) : '');
    }
    $needsArea = in_array($code, ['forward_transfer', 'forward_help', 'accepted_transfer', 'accepted_help', 'forward_refused', 'forward_cancelled', 'help_returned', 'partner_suggested', 'partner_added', 'partner_refused'], true);
    if ($code !== null && array_key_exists($code, $byCode) && (!$needsArea || $area !== '')) {
        return $byCode[$code];
    }
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
    $st = $pdo->prepare('SELECT user_name, action, event_code, old_value, new_value, created_at FROM request_history WHERE request_id = ? AND request_table = ? ORDER BY created_at ASC, id ASC');
    $st->execute([$reqId, $sector]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Quem fala na conversa: [user_id => ['label' => 'TI', 'tone' => 'owner']].
 * tone: req (quem pediu), coord (coordenação), owner (setor responsável), help (outro setor), other.
 */
function requestSpeakers(PDO $pdo, array $userIds, int $creatorId, ?int $ownerArea, array $forwards): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    $out = [];
    $others = [];
    foreach ($userIds as $id) {
        if ($id === $creatorId) {
            $out[$id] = ['label' => 'Quem pediu', 'tone' => 'req'];
        } else {
            $others[] = $id;
        }
    }
    if (!$others) {
        return $out;
    }
    $st = $pdo->prepare('SELECT id, role FROM ctd_users WHERE id IN (' . implode(',', array_fill(0, count($others), '?')) . ')');
    $st->execute($others);
    $roles = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $roles[(int)$row['id']] = (string)$row['role'];
    }
    // Setores que passaram pelo pedido (para escolher a área certa de quem tem várias)
    $involved = [];
    foreach ($forwards as $f) {
        $involved[(int)$f['to_area_id']] = true;
        $involved[(int)$f['from_area_id']] = true;
    }
    foreach ($others as $id) {
        $role = $roles[$id] ?? 'solicitante';
        if (isAdminRole($role)) {
            $out[$id] = ['label' => $role === 'admin' ? 'Administração' : 'Coordenação', 'tone' => 'coord'];
            continue;
        }
        if (!isGestorRole($role)) {
            $out[$id] = ['label' => 'Solicitante', 'tone' => 'other'];
            continue;
        }
        $areas = RequestForwardService::userAreaIds($pdo, $id);
        $area = null;
        if ($ownerArea !== null && in_array($ownerArea, $areas, true)) {
            $area = $ownerArea;
        } else {
            foreach ($areas as $a) {
                if (isset($involved[$a])) { $area = $a; break; }
            }
            $area = $area ?? ($areas[0] ?? null);
        }
        $out[$id] = [
            'label' => $area ? RequestForwardService::areaLabel($pdo, $area) : 'Setor',
            'tone' => $area !== null && $area === $ownerArea ? 'owner' : 'help',
        ];
    }
    return $out;
}

/**
 * Linha do tempo de uma conversa ($thread, de requestChatThreads): as mensagens são balões
 * (as minhas à direita) e, na conversa principal de cada pessoa, os eventos são pílulas no
 * centro. As mudanças automáticas de situação (sem texto) vêm marcadas com --sys e ficam
 * escondidas até alguém pedir o histórico completo.
 */
function renderRequestFeed(PDO $pdo, array $req, string $sector, int $viewerId, bool $isStaff, array $thread, ?array $forwards = null, ?array $history = null): string
{
    $reqId = (int)$req['id'];
    $creatorId = (int)($req['created_by'] ?? 0);
    $items = [];

    $allForwards = $forwards ?? RequestForwardService::forRequest($pdo, $reqId, $sector);
    $ownerArea = requestOwnerFromForwards($pdo, $allForwards, $sector);
    // Encaminhamentos (pediu ajuda, devolveu, transferiu) com a mensagem: só para os setores e, quando há
    // conversa entre setores, só nela — na conversa com quem pediu ele não vê esses recados
    $fwdEvents = $thread['fwdEvents'] ?? $thread['events'];
    if ($thread['events'] || $fwdEvents) {
        $forwards = $isStaff && $fwdEvents ? $allForwards : [];
        $skip = '/^(Encaminhou|Pediu ajuda|Setor .* (assumiu|aceitou|recusou|devolveu)|Cancelou o encaminhamento|Assumiu o pedido|Aceitou ajudar|Recusou o encaminhamento|Devolveu a ajuda'
            . ($forwards ? '|Requisição repassada|Repasse aceito' : '') . ')/u';

        if ($history === null && $thread['events']) {
            $history = requestHistoryRows($pdo, $reqId, $sector);
        }
        // Eventos de encaminhamento: para o setor vêm dos cartões de encaminhamento, com a mensagem
        $fwdCodes = array_merge(['forward_transfer', 'forward_help', 'accepted_transfer', 'accepted_help', 'forward_refused', 'forward_cancelled', 'help_returned', 'partner_added', 'partner_refused'],
            $forwards ? ['legacy_forward', 'legacy_forward_accepted'] : []);
        foreach ($thread['events'] ? $history : [] as $h) {
            $code = $h['event_code'] ?? null;
            if ($isStaff && ($code !== null ? in_array($code, $fwdCodes, true) : preg_match($skip, (string)$h['action']))) {
                continue; // vem dos encaminhamentos, com a mensagem
            }
            $text = requestEventText((string)$h['action'], $h['old_value'], $h['new_value'], $h['event_code'] ?? null);
            if ($text !== null) {
                $items[] = ['at' => (string)$h['created_at'], 'type' => 'event', 'who' => (string)$h['user_name'], 'text' => $text, 'note' => '', 'bad' => false];
            }
        }
        $responses = ['accepted' => 'aceitou', 'refused' => 'recusou', 'returned' => 'devolveu a ajuda', 'cancelled' => 'cancelou o envio', 'completed' => 'concluiu'];
        foreach ($forwards as $f) {
            $by = (string)($f['forwarded_by_name'] ?? '');
            $partner = RequestForwardService::isPartner($f);
            $byArea = $partner ? 'Coordenação' : (string)$f['from_label'];
            $items[] = [
                'at' => (string)$f['created_at'], 'type' => 'event',
                'who' => $by !== '' ? $by . ' (' . $byArea . ')' : $byArea,
                'text' => $partner ? 'incluiu ' . $f['to_label'] . ' como setor parceiro' : ($f['mode'] === 'help' ? 'pediu ajuda a ' : 'encaminhou para ') . $f['to_label'],
                // Pendente: o recado está no cartão acima da conversa; não repete aqui
                'note' => $f['status'] === 'pending' ? '' : (string)($f['observation'] ?? ''), 'bad' => false,
            ];
            if (!empty($f['responded_at']) && isset($responses[$f['status']])) {
                $items[] = [
                    'at' => (string)$f['responded_at'], 'type' => 'event',
                    'who' => (string)(($f['responded_by_name'] ?? '') ?: $f['to_label']),
                    // Ajuda cancelada com motivo = ajuda já aceita que o setor de origem ou a coordenação encerrou
                    'text' => $partner && $f['status'] === 'cancelled' ? 'tirou ' . $f['to_label'] . ' como setor parceiro'
                        : ($f['status'] === 'cancelled' && trim((string)($f['response_note'] ?? '')) !== '' ? 'encerrou a ajuda de ' . $f['to_label']
                        : $responses[$f['status']] . ($f['status'] === 'cancelled' ? '' : ' (' . $f['to_label'] . ')')),
                    'note' => (string)($f['response_note'] ?? ''), 'bad' => $f['status'] === 'refused',
                ];
            }
        }
    }

    // Só as fontes desta conversa (canal e, no helper, o setor que ajuda)
    $or = [];
    $params = [$reqId, $sector];
    foreach ($thread['sources'] as [$channel, $area]) {
        $or[] = $channel === 'helper' ? '(channel = ? AND helper_area_id = ?)' : 'channel = ?';
        array_push($params, ...($channel === 'helper' ? [$channel, $area] : [$channel]));
    }
    $st = $pdo->prepare('SELECT * FROM request_comments WHERE request_id = ? AND request_table = ? AND (' . implode(' OR ', $or) . ') ORDER BY created_at ASC, id ASC');
    $st->execute($params);
    $authorIds = [$creatorId];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $items[] = ['at' => (string)$c['created_at'], 'type' => 'message', 'c' => $c];
        $authorIds[] = (int)$c['user_id'];
    }
    $speakers = requestSpeakers($pdo, $authorIds, $creatorId, $ownerArea, $allForwards);

    // A conversa começa vazia: o pedido em si já está no topo da página
    $order = ['event' => 0, 'message' => 1];
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
    // Etiqueta de quem mais recebeu a mensagem, quando a conversa junta mais de uma fonte
    $tagOf = function (array $c) use ($thread, $creatorId): string {
        foreach ($thread['sources'] as [$channel, $area, $tag, $requesterOnly]) {
            if ($c['channel'] === $channel && ($channel !== 'helper' || (int)$c['helper_area_id'] === $area)) {
                return $tag !== '' && (!$requesterOnly || (int)$c['user_id'] === $creatorId) ? $tag : '';
            }
        }
        return '';
    };
    $bubble = function (int $userId, string $name, string $time, string $body, string $tag = '') use ($viewerId, $speakers): string {
        $side = $userId === $viewerId ? 'mine' : 'theirs';
        $sp = $speakers[$userId] ?? ['label' => '', 'tone' => 'other'];
        return '<div class="chat-item ' . $side . '" data-tone="' . e($sp['tone']) . '">'
            . '<p class="chat-meta"><strong class="chat-user-name">' . e($name !== '' ? $name : 'Alguém') . '</strong>'
            . ($sp['label'] !== '' ? ' <span class="chat-role">· ' . e($sp['label']) . '</span>' : '')
            . ' <time class="chat-time">· ' . $time . '</time>'
            . ($tag !== '' ? ' <span class="chat-to-tag">' . e($tag) . '</span>' : '')
            . '</p>'
            . '<div class="chat-bubble ' . $side . '">' . $body . '</div>'
            . '</div>';
    };
    foreach ($items as $it) {
        $day = date('d/m/Y', strtotime($it['at']));
        if ($day !== $lastDay) {
            $html .= '<p class="feed-day' . ($daySys[$day] ? ' feed-day--sys' : '') . '">' . ($day === $today ? 'Hoje' : ($day === $yesterday ? 'Ontem' : $day)) . '</p>';
            $lastDay = $day;
        }
        $time = date('H:i', strtotime($it['at']));
        if ($it['type'] === 'event') {
            $html .= '<div class="feed-event' . ($it['bad'] ? ' feed-event--bad' : '') . ($isSys($it) ? ' feed-event--sys' : '') . '">'
                . '<p class="feed-pill"><strong>' . e($it['who']) . '</strong> ' . e($it['text']) . ' <time>' . $time . '</time></p>'
                . (trim($it['note']) !== '' ? '<blockquote>' . nl2br(e($it['note'])) . '</blockquote>' : '')
                . '</div>';
        } else {
            $c = $it['c'];
            $html .= $bubble((int)$c['user_id'], (string)$c['user_name'], $time, nl2br(e($c['comment'])), $tagOf($c));
        }
    }
    if (count(array_filter($items, fn($it) => !$isSys($it))) === 0) {
        $html .= '<p class="feed-empty">' . e($thread['empty']) . '</p>';
    }
    return $html;
}
