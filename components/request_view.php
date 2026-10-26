<?php
/**
 * Pedido completo (pages/request_detail.php e api/request_view.php) — UI 2.0.
 *
 * renderRequestView($pdo, $req, $sector)
 *   Cabeçalho (nº · setor · prazo, título, situação, 4 etapas e as ações), a conversa
 *   (linha do tempo em balões) e, ao lado, "Quem está envolvido" e os detalhes.
 *   No celular as três partes viram abas (Conversa / Detalhes / Envolvidos) e as ações
 *   principais ficam numa barra fixa embaixo, junto da caixa de mensagem.
 *
 * renderRequestFeed($pdo, $req, $sector, $viewerId, $isStaff)
 *   Só a linha do tempo. api/get_comments.php usa a mesma função para atualizar a tela
 *   (o JS compara o HTML; a saída precisa ser a mesma da página).
 *
 * Os botões chamam funções globais de assets/js/script.js (requestAct, decideRequest,
 * forwardAct, openForwardWizard, printRequest) com data-id/data-table.
 * As abas do celular ficam em assets/js/pages/request_detail.js.
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

    /** Setor responsável agora: destino da última transferência aceita; sem ela, o setor do formulário */
    function requestOwnerFromForwards(PDO $pdo, array $forwards, string $sector): ?int
    {
        $owner = null;
        foreach ($forwards as $f) {
            if ($f['mode'] === 'transfer' && in_array($f['status'], ['accepted', 'completed'], true)) {
                $owner = (int)$f['to_area_id'];
            }
        }
        return $owner ?? RequestForwardService::originAreaId($pdo, $sector);
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
     * Linha do tempo do pedido em formato de conversa: o pedido abre a conversa, as mensagens
     * são balões (as minhas à direita) e os eventos são pílulas no centro. As mudanças
     * automáticas de situação (sem texto) vêm marcadas com --sys e ficam escondidas até alguém
     * pedir o histórico completo.
     * Canais: quem pediu vê só a conversa pública; setor chamado para ajudar ($helperOnly) vê só a
     * interna; os demais setores veem as duas, com as internas marcadas.
     */
    function renderRequestFeed(PDO $pdo, array $req, string $sector, int $viewerId, bool $isStaff, ?array $forwards = null, ?array $history = null, bool $helperOnly = false): string
    {
        $reqId = (int)$req['id'];
        $creatorId = (int)($req['created_by'] ?? 0);
        $items = [];

        $allForwards = $forwards ?? RequestForwardService::forRequest($pdo, $reqId, $sector);
        $ownerArea = requestOwnerFromForwards($pdo, $allForwards, $sector);
        // Encaminhamentos com a mensagem: só para os setores
        $forwards = $isStaff ? $allForwards : [];
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
            $by = (string)($f['forwarded_by_name'] ?? '');
            $items[] = [
                'at' => (string)$f['created_at'], 'type' => 'event',
                'who' => $by !== '' ? $by . ' (' . $f['from_label'] . ')' : (string)$f['from_label'],
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

        $channelFilter = !$isStaff ? " AND channel = 'public'" : '';
        $params = [$reqId, $sector];
        if ($helperOnly) {
            // Interna + o que o próprio setor escreveu (mensagens de antes da conversa interna eram públicas)
            $myAreas = RequestForwardService::userAreaIds($pdo, $viewerId) ?: [0];
            $channelFilter = " AND (channel = 'internal' OR user_id IN (SELECT id_user FROM cfg_user_area WHERE id_area IN (" . implode(',', array_fill(0, count($myAreas), '?')) . ')))';
            $params = array_merge($params, $myAreas);
        }
        $st = $pdo->prepare("SELECT * FROM request_comments WHERE request_id = ? AND request_table = ?$channelFilter ORDER BY created_at ASC, id ASC");
        $st->execute($params);
        $authorIds = [$creatorId];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $items[] = ['at' => (string)$c['created_at'], 'type' => 'message', 'c' => $c];
            $authorIds[] = (int)$c['user_id'];
        }
        $speakers = requestSpeakers($pdo, $authorIds, $creatorId, $ownerArea, $allForwards);

        $order = ['open' => 0, 'event' => 1, 'message' => 2];
        usort($items, fn($a, $b) => strcmp($a['at'], $b['at']) ?: ($order[$a['type']] <=> $order[$b['type']]));

        // O pedido abre a conversa, como a primeira mensagem de quem pediu
        $openedAt = (string)($req['created_at'] ?? '');
        $desc = trim((string)($req['descp'] ?? ''));
        array_unshift($items, ['at' => $openedAt !== '' ? $openedAt : date('Y-m-d H:i:s'), 'type' => 'open']);

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
        $bubble = function (int $userId, string $name, string $time, string $body, string $extraClass = '', bool $internal = false) use ($viewerId, $speakers): string {
            $side = $userId === $viewerId ? 'mine' : 'theirs';
            $sp = $speakers[$userId] ?? ['label' => '', 'tone' => 'other'];
            return '<div class="chat-item ' . $side . $extraClass . ($internal ? ' chat-item--internal' : '') . '" data-tone="' . e($sp['tone']) . '">'
                . '<p class="chat-meta"><strong class="chat-user-name">' . e($name !== '' ? $name : 'Alguém') . '</strong>'
                . ($sp['label'] !== '' ? ' <span class="chat-role">· ' . e($sp['label']) . '</span>' : '')
                . ' <time class="chat-time">· ' . $time . '</time>'
                . ($internal ? ' <span class="chat-internal-tag"><i class="fa-solid fa-lock" aria-hidden="true"></i> Só setores</span>' : '')
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
            if ($it['type'] === 'open') {
                $body = '<span class="chat-open">Abriu o pedido</span>'
                    . ($desc !== '' ? nl2br(e($desc)) : e($req['title'] ?? 'Sem descrição.'));
                $html .= $bubble($creatorId, (string)($req['creator_name'] ?? ''), $time, $body, ' chat-item--open');
            } elseif ($it['type'] === 'event') {
                $html .= '<div class="feed-event' . ($it['bad'] ? ' feed-event--bad' : '') . ($isSys($it) ? ' feed-event--sys' : '') . '">'
                    . '<p class="feed-pill"><strong>' . e($it['who']) . '</strong> ' . e($it['text']) . ' <time>' . $time . '</time></p>'
                    . (trim($it['note']) !== '' ? '<blockquote>' . nl2br(e($it['note'])) . '</blockquote>' : '')
                    . '</div>';
            } else {
                $c = $it['c'];
                // Para quem só ajuda, tudo aqui é interno: não precisa marcar cada balão
                $internal = ($c['channel'] ?? 'public') === 'internal' && !$helperOnly;
                $html .= $bubble((int)$c['user_id'], (string)$c['user_name'], $time, nl2br(e($c['comment'])), '', $internal);
            }
        }
        if (count(array_filter($items, fn($it) => $it['type'] !== 'open' && !$isSys($it))) === 0) {
            $html .= '<p class="feed-empty">' . ($helperOnly
                ? 'Nenhuma mensagem entre os setores ainda. Use o campo abaixo para falar com o setor que pediu ajuda.'
                : 'Ninguém escreveu ainda. Use o campo abaixo para falar sobre o pedido.') . '</p>';
        }
        return $html;
    }

    /**
     * Próximo passo para quem trabalha no pedido (setor, coordenação, administração), em uma frase.
     * Nomeia o botão a usar; devolve HTML já escapado ('' quando não há nada a dizer).
     */
    function requestStaffGuide(string $status, ?array $open, bool $openIsForMe, bool $openIsFromMe, bool $canApprove, bool $canWork, bool $canReopen, bool $canForward, string $ownerLabel, string $sectorName): string
    {
        $b = fn(string $label) => '<strong>' . e($label) . '</strong>';
        if ($open && $openIsForMe && $open['status'] === 'pending') {
            return $open['mode'] === 'transfer'
                ? e($open['from_label']) . ' quer passar este pedido para vocês. Toque em ' . $b('Aceitar e assumir') . ' ou recuse dizendo o motivo.'
                : e($open['from_label']) . ' pediu a ajuda de vocês. Toque em ' . $b('Aceitar ajudar') . ' ou recuse dizendo o motivo.';
        }
        if ($open && $openIsForMe && $open['mode'] === 'help' && $open['status'] === 'accepted') {
            return 'Vocês estão ajudando ' . e($open['from_label']) . '. Quando terminar a sua parte, toque em ' . $b('Devolver com retorno') . '.';
        }
        if ($open && $openIsFromMe) {
            if ($open['mode'] === 'transfer') {
                return 'Esperando ' . e($open['to_label']) . ' aceitar a transferência. Nada a fazer agora; você recebe um aviso.';
            }
            return ($open['status'] === 'pending' ? 'Vocês pediram ajuda a ' . e($open['to_label']) . '.' : e($open['to_label']) . ' está ajudando.')
                . ' Sigam com o atendimento; dá para concluir depois que devolverem.';
        }
        if ($canApprove) {
            return 'Leia o pedido e decida. ' . $b('Aprovar') . ' envia para ' . e($sectorName) . '; ' . $b('Recusar') . ' pede um motivo para quem pediu.';
        }
        if ($canWork && $status === 'Y') {
            return 'Toque em ' . $b('Começar atendimento') . ' quando iniciar.' . ($canForward ? ' Se for de outro setor, use ' . $b('Encaminhar') . '.' : '');
        }
        if ($canWork && $status === 'W') {
            return 'Quando terminar, toque em ' . $b('Concluir') . '. Quem pediu é avisado na hora.';
        }
        return match ($status) {
            'P' => 'Esperando a coordenação aprovar. Depois disso o pedido entra na Caixa de ' . e($ownerLabel) . '.',
            'Y' => 'Aprovado. Esperando ' . e($ownerLabel) . ' começar o atendimento.',
            'W' => e($ownerLabel) . ' está atendendo.',
            'C' => $canReopen ? 'Pedido concluído. Se precisar refazer, use ' . $b('Reabrir pedido') . ' em ••• (mais opções).' : '',
            default => '',
        };
    }

    /**
     * "Quem está envolvido": quem pediu, a coordenação (quem aprovou ou recusou), o setor
     * responsável agora e os setores chamados para ajudar ou que já tiveram o pedido.
     * Cada item: ['name' => 'TI · Carlos', 'role' => 'Responsável agora', 'tone' => 'owner']
     */
    function requestPeople(array $req, string $status, string $subdivision, array $history, array $forwards, ?int $ownerArea, string $ownerLabel, string $worker): array
    {
        $day = fn($d) => $d ? date('d/m', strtotime((string)$d)) : '';
        $people = [];

        // Setor responsável agora
        $ownerRole = ['P' => 'Vai atender depois da aprovação', 'Y' => 'Responsável · ainda não começou', 'F' => 'Responsável agora',
            'W' => 'Responsável · atendendo agora', 'C' => 'Atendeu o pedido', 'N' => 'Setor do pedido'][$status] ?? 'Responsável agora';
        $people[] = ['name' => $ownerLabel . ($worker !== '' && in_array($status, ['W', 'C'], true) ? ' · ' . $worker : ''), 'role' => $ownerRole, 'tone' => 'owner'];

        // Outros setores: o último encaminhamento de cada um conta a história
        $areas = [];
        foreach ($forwards as $f) {
            if ($f['status'] === 'cancelled') {
                continue;
            }
            $to = (int)$f['to_area_id'];
            $from = (int)$f['from_area_id'];
            $by = (string)($f['responded_by_name'] ?? '');
            $when = $day($f['responded_at'] ?? null);
            if ($f['mode'] === 'help') {
                $role = [
                    'pending' => 'Chamado para ajudar · aguardando resposta',
                    'accepted' => 'Ajudando agora',
                    'returned' => 'Ajudou' . ($when ? ' · devolveu em ' . $when : ''),
                    'completed' => 'Ajudou',
                    'refused' => 'Recusou ajudar',
                ][$f['status']] ?? '';
            } else {
                $role = [
                    'pending' => 'Transferência aguardando aceite',
                    'accepted' => 'Assumiu o pedido' . ($when ? ' em ' . $when : ''),
                    'completed' => 'Assumiu o pedido',
                    'refused' => 'Recusou a transferência',
                ][$f['status']] ?? '';
                if (in_array($f['status'], ['accepted', 'completed'], true) && $from !== (int)$ownerArea) {
                    $areas[$from] = ['name' => (string)$f['from_label'], 'role' => 'Responsável anterior · transferiu', 'tone' => 'help'];
                }
            }
            if ($role !== '' && $to !== (int)$ownerArea) {
                $areas[$to] = ['name' => $f['to_label'] . ($by !== '' && $f['status'] !== 'pending' ? ' · ' . $by : ''), 'role' => $role, 'tone' => 'help'];
            }
        }
        unset($areas[(int)$ownerArea]);
        foreach ($areas as $p) {
            $people[] = $p;
        }

        // Coordenação: quem aprovou ou recusou (do histórico)
        $coord = null;
        foreach ($history as $h) {
            if (preg_match('/^Requisição (aprovada|recusada)$/iu', (string)$h['action'], $m)) {
                $coord = ['name' => 'Coordenação · ' . $h['user_name'], 'role' => (mb_strtolower($m[1]) === 'aprovada' ? 'Aprovou' : 'Recusou') . ' em ' . $day($h['created_at']), 'tone' => 'coord'];
            }
        }
        if (!$coord && $status === 'P') {
            $coord = ['name' => 'Coordenação', 'role' => 'Precisa aprovar o pedido', 'tone' => 'coord'];
        }
        if ($coord) {
            $people[] = $coord;
        }

        // Quem pediu
        $people[] = [
            'name' => (string)($req['creator_name'] ?? 'Quem pediu'),
            'role' => 'Pediu' . ($subdivision !== '' ? ' · ' . $subdivision : '') . (!empty($req['created_at']) ? ' · ' . $day($req['created_at']) : ''),
            'tone' => 'req',
        ];
        return $people;
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
        $open = null;
        foreach ($forwards as $f) { // do mais antigo ao mais novo: fica o último
            if ($f['status'] === 'pending' || ($f['mode'] === 'help' && $f['status'] === 'accepted')) {
                $open = $f;
            }
        }
        $ownerArea = requestOwnerFromForwards($pdo, $forwards, $sector);
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

        // Conversa: quem só ajuda fala apenas com os setores; os demais setores escolhem o canal
        // quando o pedido tem (ou teve) ajuda de outro setor
        $helperOnly = $isStaff && userSeesOnlyInternalChat($pdo, $req, $sector, $viewerId, $role);
        $hasHelp = false;
        foreach ($forwards as $f) {
            $hasHelp = $hasHelp || ($f['mode'] === 'help' && !in_array($f['status'], ['refused', 'cancelled'], true));
        }
        $chooseChannel = $isStaff && !$helperOnly && $hasHelp;
        $defaultInternal = $open && $open['mode'] === 'help';

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
        $workerAt = '';
        foreach ($history as $h) {
            // Pelo nome da ação também: registros antigos podem ter outro texto em new_value
            $action = (string)$h['action'];
            if ($action === 'Atendimento iniciado') {
                $statusAt[statusLabel('W')] = $h['created_at'];
                $worker = (string)$h['user_name'];
                $workerAt = (string)$h['created_at'];
            } elseif (preg_match('/^Requisição concluída/iu', $action)) {
                $statusAt[statusLabel('C')] = $h['created_at'];
            } elseif (preg_match('/^Requisição (aprovada|recusada)$/iu', $action, $m)) {
                $statusAt[mb_strtolower($m[1]) === 'aprovada' ? 'Aprovada' : 'Recusada'] = $h['created_at'];
            }
            if ($h['new_value'] === null) {
                continue;
            }
            $statusAt[(string)$h['new_value']] = $h['created_at'];
            if ((string)$h['new_value'] === statusLabel('W')) {
                $worker = (string)$h['user_name'];
                $workerAt = (string)$h['created_at'];
            }
        }
        // Quem começou antes de uma transferência aceita era do setor anterior
        foreach ($forwards as $f) {
            if ($f['mode'] === 'transfer' && in_array($f['status'], ['accepted', 'completed'], true)
                && strcmp((string)($f['responded_at'] ?? $f['created_at']), $workerAt) > 0) {
                $worker = '';
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
        $isUrgent = $priority >= 3 || !empty($req['urgent']);
        $isClosed = in_array($status, ['C', 'N'], true);

        // As 4 etapas (Enviado · Aprovado · No setor · Concluído); recusado tem só duas
        $shortDate = fn($d) => $d ? date('d/m', strtotime((string)$d)) : '';
        $withDate = fn(string $label, string $d) => $label . ($d !== '' ? ' · ' . $d : '');
        if ($status === 'N') {
            $steps = [
                $withDate('Enviado', $shortDate($req['created_at'] ?? '')),
                $withDate('Recusado', $shortDate($statusAt['Recusada'] ?? '')),
            ];
            $stepNow = 1;
        } else {
            $stepNow = ['P' => 0, 'Y' => 1, 'F' => 1, 'W' => 2, 'C' => 3][$status] ?? 0;
            $workSince = $shortDate($statusAt[statusLabel('W')] ?? '');
            $steps = [
                $withDate('Enviado', $shortDate($req['created_at'] ?? '')),
                $withDate('Aprovado', $stepNow >= 1 ? $shortDate($statusAt['Aprovada'] ?? '') : ''),
                'Em atendimento' . ($stepNow >= 2 && $workSince !== '' ? ' · desde ' . $workSince : ''),
                $withDate('Concluído', $status === 'C' ? $shortDate($statusAt[statusLabel('C')] ?? '') : ''),
            ];
        }
        $now = [
            'P' => 'Seu pedido aguarda a aprovação da coordenação.',
            'Y' => "Seu pedido foi aprovado. O setor de $ownerLabel vai começar o atendimento.",
            'F' => $open && $open['mode'] === 'transfer'
                ? "Seu pedido está passando de {$open['from_label']} para {$open['to_label']}. Você recebe um aviso quando for aceito."
                : "Seu pedido foi aprovado. O setor de $ownerLabel vai começar o atendimento.",
            'W' => "O setor de $ownerLabel está atendendo.",
            'C' => "Seu pedido foi concluído pelo setor de $ownerLabel.",
            'N' => 'Pedido recusado.',
        ][$status] ?? '';
        $nowIcon = ['P' => 'fa-hourglass-half', 'Y' => 'fa-thumbs-up', 'F' => 'fa-thumbs-up', 'W' => 'fa-gears', 'C' => 'fa-circle-check', 'N' => 'fa-circle-xmark'][$status] ?? 'fa-circle-info';

        // Ações: uma principal (a próxima coisa a fazer, laranja) e as secundárias ao lado
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
        $actionsHtml = implode('', $sideButtons) . $mainButton;
        $canCancelForward = $openIsFromMe && $open['status'] === 'pending' && !$openIsForMe;
        $hasMenu = $canReopen || $canCancelForward || $canCancelRequest;
        $guide = $isStaff ? requestStaffGuide($status, $open, (bool)$openIsForMe, (bool)$openIsFromMe, $canApprove, $canWork, $canReopen, $canForward, $ownerLabel, $sectorName) : '';

        // Detalhes
        $props = [];
        $props['Prazo'] = $hasDeadline
            ? e(date('d/m/Y', strtotime((string)$req['date']))) . ($isClosed ? '' : ' ' . requestDeadlineFlag((string)$req['date']))
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
        foreach ($extra as $label => $value) { // campos do tipo de pedido
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            $props[$label] = preg_match('#^https?://#i', $value)
                ? '<a href="' . e($value) . '" target="_blank" rel="noopener noreferrer">Abrir arquivo</a>'
                : e($value);
        }
        $props['Aberto em'] = e(date('d/m/Y \à\s H:i', strtotime((string)$req['created_at'])));
        $desc = trim((string)($req['descp'] ?? ''));
        $people = requestPeople($req, $status, $subdivision, $history, $forwards, $ownerArea, $ownerLabel, $worker);
        $metaLine = 'Nº ' . $reqId;
        $deadlineShort = $hasDeadline ? date('d/m', strtotime((string)$req['date'])) : '';
        $deadlineFlag = $hasDeadline && !$isClosed ? requestDeadlineFlag((string)$req['date']) : '';
        $tabId = 'rv' . $reqId . '-';
        ?>
<article class="request-view request-view--<?= e($context) ?> rv2" <?= $data ?> data-title="<?= e($req['title'] ?? '') ?>">
    <header class="rv-head card">
        <div class="rv-head__main">
            <div class="rv-head__top">
                <p class="rv-meta"><span class="rv-num"><?= e($metaLine) ?></span> · <span class="sector-tag" data-sector="<?= e($sector) ?>"><?= e($sectorName) ?></span><?php if ($deadlineShort !== ''): ?> · prazo <?= e($deadlineShort) ?><?php endif; ?></p>
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
            <div class="rv-tags">
                <span class="status-pill status-pill--<?= e($status) ?>"><?= e(statusLabel($status)) ?></span>
                <?php if ($isUrgent): ?><span class="badge-urgent">Urgente</span><?php endif; ?>
                <?= $deadlineFlag ?>
            </div>
            <?php if ($guide !== ''): ?>
                <p class="rv-guide"><i class="fa-solid fa-circle-arrow-right" aria-hidden="true"></i> <span><?= $guide ?></span></p>
            <?php endif; ?>
        </div>
        <?php if ($actionsHtml !== ''): ?>
            <div class="rv-actions rv-actions--head"><?= $actionsHtml ?></div>
        <?php endif; ?>
        <ol class="steps4 rv-steps4<?= $status === 'N' ? ' steps4--refused' : '' ?>" aria-label="Etapas do pedido">
            <?php foreach ($steps as $i => $label):
                $state = $i < $stepNow ? 'is-done' : ($i === $stepNow ? 'is-now' : '');
            ?>
                <li<?= $state !== '' ? ' class="' . $state . '"' : '' ?><?= $i === $stepNow ? ' aria-current="step"' : '' ?>><span><?= e($label) ?></span></li>
            <?php endforeach; ?>
        </ol>
        <div class="rv-tabs" role="tablist" aria-label="Partes do pedido">
            <button type="button" role="tab" id="<?= $tabId ?>tab-chat" aria-controls="<?= $tabId ?>chat" aria-selected="true" data-rv-tab="chat">Conversa</button>
            <button type="button" role="tab" id="<?= $tabId ?>tab-info" aria-controls="<?= $tabId ?>info" aria-selected="false" tabindex="-1" data-rv-tab="info">Detalhes</button>
            <button type="button" role="tab" id="<?= $tabId ?>tab-people" aria-controls="<?= $tabId ?>people" aria-selected="false" tabindex="-1" data-rv-tab="people">Envolvidos</button>
        </div>
    </header>

    <div class="rv-body">
        <section class="rv-talk card" id="<?= $tabId ?>chat" role="tabpanel" aria-labelledby="<?= $tabId ?>tab-chat">
            <div class="rv-talk__head">
                <h3 class="rv-section__title" id="rv-feed-<?= $reqId ?>">Conversa</h3>
                <button type="button" class="btn btn--ghost btn--sm" data-history-toggle aria-pressed="false">Mostrar histórico completo</button>
            </div>

            <?php if (!$isStaff): ?>
                <div class="rv-notice rv-now rv-now--<?= e($status) ?>">
                    <p class="rv-now__text"><i class="fa-solid <?= e($nowIcon) ?>" aria-hidden="true"></i> <span><?= e($now) ?></span></p>
                    <?php if ($refuseReason !== ''): ?><blockquote class="rv-now__note"><?= nl2br(e($refuseReason)) ?></blockquote><?php endif; ?>
                </div>
            <?php elseif ($open): ?>
                <div class="rv-notice forward-box" role="note" aria-label="Encaminhamento em aberto">
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
                </div>
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
                <div class="rv-notice forward-box forward-box--done<?= $lastDone['status'] === 'refused' ? ' forward-box--bad' : '' ?>" role="note" aria-label="Último encaminhamento">
                    <p class="forward-box__title"><?= e($doneText) ?></p>
                    <?php if (trim($doneNote) !== ''): ?><blockquote class="forward-box__msg"><?= nl2br(e($doneNote)) ?></blockquote><?php endif; ?>
                    <p class="forward-box__meta"><?= e(($lastDone['status'] === 'accepted' ? ($lastDone['forwarded_by_name'] ?? '') : ($lastDone['responded_by_name'] ?? '')) ?: '') ?><?= !empty($lastDone['responded_at']) ? ', ' . e(date('d/m \à\s H:i', strtotime((string)$lastDone['responded_at']))) : '' ?></p>
                </div>
            <?php elseif ($refuseReason !== ''): ?>
                <div class="rv-notice forward-box forward-box--bad" role="note" aria-label="Motivo da recusa">
                    <p class="forward-box__title">Pedido recusado</p>
                    <blockquote class="forward-box__msg"><?= nl2br(e($refuseReason)) ?></blockquote>
                </div>
            <?php endif; ?>

            <div class="rv-feed" data-chat-feed <?= $data ?> role="log" aria-labelledby="rv-feed-<?= $reqId ?>"><?= renderRequestFeed($pdo, $req, $sector, $viewerId, $isStaff, $forwards, $history, $helperOnly) ?></div>

            <div class="rv-dock">
                <?php if ($actionsHtml !== ''): ?>
                    <div class="rv-actions rv-actions--dock"><?= $actionsHtml ?></div>
                <?php endif; ?>
                <?php $startsInternal = $helperOnly || ($chooseChannel && $defaultInternal); ?>
                <form class="chat-footer rv-composer<?= $startsInternal ? ' rv-composer--internal' : '' ?>" data-chat-form <?= $data ?>>
                    <?php if ($chooseChannel): ?>
                        <div class="chat-channel" role="radiogroup" aria-label="Quem vê a mensagem">
                            <label><input type="radio" name="channel" value="public"<?= $defaultInternal ? '' : ' checked' ?>><span>Todos</span></label>
                            <label><input type="radio" name="channel" value="internal"<?= $defaultInternal ? ' checked' : '' ?>><span><i class="fa-solid fa-lock" aria-hidden="true"></i> Só setores</span></label>
                        </div>
                    <?php elseif ($helperOnly): ?>
                        <input type="hidden" name="channel" value="internal">
                    <?php endif; ?>
                    <div class="chat-input-area">
                        <textarea name="comment" class="chat-input" placeholder="<?= $startsInternal ? 'Mensagem para os setores' : 'Mensagem para todos' ?>" rows="1" required maxlength="3000" aria-label="Mensagem"></textarea>
                        <button type="submit" class="btn-send" aria-label="Enviar mensagem"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
                    </div>
                    <p class="chat-hint" data-chat-hint data-hint-public="Quem pediu também vê esta conversa." data-hint-internal="Só os setores veem. Quem pediu não vê esta mensagem."><?php
                        if ($helperOnly) {
                            echo 'Conversa entre os setores. Quem pediu não vê.';
                        } elseif ($chooseChannel) {
                            echo $defaultInternal ? 'Só os setores veem. Quem pediu não vê esta mensagem.' : 'Quem pediu também vê esta conversa.';
                        } else {
                            echo $isStaff ? 'Quem pediu também vê esta conversa.' : 'Quem cuida do pedido recebe sua mensagem.';
                        }
                    ?></p>
                </form>
            </div>
        </section>

        <aside class="rv-side" aria-label="Resumo do pedido">
            <section class="rv-people card" id="<?= $tabId ?>people" role="tabpanel" aria-labelledby="<?= $tabId ?>tab-people">
                <h3 class="rv-section__title">Quem está envolvido</h3>
                <ul class="rv-people__list">
                    <?php foreach ($people as $p): ?>
                        <li class="rv-person" data-tone="<?= e($p['tone']) ?>">
                            <span class="rv-person__dot" aria-hidden="true"></span>
                            <span class="rv-person__text"><strong><?= e($p['name']) ?></strong><span><?= e($p['role']) ?></span></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <section class="rv-info card" id="<?= $tabId ?>info" role="tabpanel" aria-labelledby="<?= $tabId ?>tab-info">
                <h3 class="rv-section__title">Detalhes</h3>
                <div class="rv-info__ask">
                    <p class="rv-info__label">O que foi pedido</p>
                    <div class="rv-ask__text"><?= $desc !== '' ? nl2br(e($desc)) : '<span class="muted">Sem descrição.</span>' ?></div>
                </div>
                <dl class="rv-props">
                    <?php foreach ($props as $label => $value): ?>
                        <div><dt><?= e($label) ?></dt><dd><?= $value ?></dd></div>
                    <?php endforeach; ?>
                </dl>
                <?php if (trim((string)($req['obs'] ?? '')) !== ''): ?>
                    <p class="rv-ask__obs"><strong>Observações:</strong> <?= nl2br(e($req['obs'])) ?></p>
                <?php endif; ?>
            </section>
        </aside>
    </div>
</article>
        <?php
    }
}
