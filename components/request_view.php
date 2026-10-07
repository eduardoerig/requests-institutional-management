<?php
/**
 * Pedido completo, igual na página (request_detail) e no painel das filas (api/request_view.php).
 *
 * renderRequestView($pdo, $req, $sector, $context)
 *   $req     linha do pedido (com creator_name)
 *   $context 'page' | 'panel'
 *
 * Os botões chamam funções globais de assets/js/script.js (requestAct, decideRequest,
 * forwardAct, openForwardWizard) com data-id/data-table — funcionam também quando o
 * HTML é inserido no painel via fetch.
 */

require_once __DIR__ . '/../classes/RequestForwardService.php';
require_once __DIR__ . '/request_card.php';

if (!function_exists('renderRequestView')) {
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

        // Responsável e encaminhamentos
        $ownerArea = RequestForwardService::ownerAreaId($pdo, $reqId, $sector);
        $ownerLabel = RequestForwardService::areaLabel($pdo, $ownerArea);
        $ownerSince = RequestForwardService::ownerSince($pdo, $reqId, $sector);
        $forwards = RequestForwardService::forRequest($pdo, $reqId, $sector);
        $open = RequestForwardService::openForward($pdo, $reqId, $sector);
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

        // Frase "Agora": o que falta e de quem depende
        if ($status === 'P') {
            $now = 'Aguardando a aprovação da coordenação.';
        } elseif ($status === 'N') {
            $now = 'Pedido recusado. O motivo está na conversa abaixo.';
        } elseif ($status === 'C') {
            $now = "Concluído pelo setor de $ownerLabel.";
        } elseif ($open && $open['mode'] === 'transfer') {
            $now = "{$open['from_label']} encaminhou este pedido para {$open['to_label']}. Falta {$open['to_label']} aceitar.";
        } else {
            $now = $status === 'Y' ? "Aprovado. O setor de $ownerLabel vai começar o atendimento." : "O setor de $ownerLabel está atendendo.";
            if ($open && $isStaff) {
                $now .= $open['status'] === 'pending'
                    ? " Pediu ajuda a {$open['to_label']}, aguardando resposta."
                    : " {$open['to_label']} está ajudando.";
            }
        }
        if ($isRequester && !$isStaff) {
            $now = str_replace(['Concluído pelo', 'Aprovado.', 'Aguardando a aprovação'], ['Seu pedido foi concluído pelo', 'Seu pedido foi aprovado.', 'Seu pedido aguarda a aprovação'], $now);
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
        if (!empty($req['obs'])) {
            $extra['Observações'] = $req['obs'];
        }

        $subdivision = '';
        if (!empty($req['subdivision_id'])) {
            $st = $pdo->prepare('SELECT name FROM ctd_subdivision WHERE id = ?');
            $st->execute([$req['subdivision_id']]);
            $subdivision = (string)$st->fetchColumn();
        }

        // Linha do tempo: histórico + encaminhamentos com mensagens (mensagens só para setores/admin)
        $st = $pdo->prepare('SELECT user_name, action, created_at FROM request_history WHERE request_id = ? AND request_table = ? ORDER BY created_at ASC, id ASC');
        $st->execute([$reqId, $sector]);
        $events = [];
        $forwardActionPattern = '/^(Encaminhou|Pediu ajuda|Setor .* (assumiu|aceitou|recusou|devolveu)|Cancelou o encaminhamento)/u';
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
            if ($isStaff && preg_match($forwardActionPattern, $h['action'])) {
                continue; // contado abaixo, com a mensagem
            }
            $events[] = ['at' => $h['created_at'], 'who' => $h['user_name'], 'text' => $h['action'], 'note' => null];
        }
        if ($isStaff) {
            $responses = ['accepted' => 'aceitou', 'refused' => 'recusou', 'returned' => 'devolveu a ajuda', 'cancelled' => 'cancelou o envio', 'completed' => 'concluiu'];
            foreach ($forwards as $f) {
                $events[] = [
                    'at' => $f['created_at'],
                    'who' => $f['forwarded_by_name'] ?? $f['from_label'],
                    'text' => ($f['mode'] === 'help' ? 'pediu ajuda a ' : 'encaminhou para ') . $f['to_label'],
                    'note' => $f['observation'] ?: null,
                    'kind' => 'forward',
                ];
                if (!empty($f['responded_at']) && isset($responses[$f['status']])) {
                    $events[] = [
                        'at' => $f['responded_at'],
                        'who' => ($f['responded_by_name'] ?? '') ?: $f['to_label'],
                        'text' => $responses[$f['status']] . ($f['status'] === 'cancelled' ? '' : ' (' . $f['to_label'] . ')'),
                        'note' => $f['response_note'] ?: null,
                        'kind' => $f['status'] === 'refused' ? 'bad' : 'forward',
                    ];
                }
            }
        }
        usort($events, fn($a, $b) => strcmp((string)$a['at'], (string)$b['at']));

        $st = $pdo->prepare('SELECT * FROM request_comments WHERE request_id = ? AND request_table = ? ORDER BY created_at ASC, id ASC');
        $st->execute([$reqId, $sector]);
        $comments = $st->fetchAll(PDO::FETCH_ASSOC);

        $data = 'data-id="' . $reqId . '" data-table="' . e($sector) . '"';
        $fullTable = 'ctd_' . $sector . '_frm';
        $deadline = (!empty($req['date']) && strpos((string)$req['date'], '0000') !== 0) ? date('d/m/Y', strtotime((string)$req['date'])) : 'Sem prazo';
        $priority = (int)($req['priority'] ?? 2);
        ?>
<article class="request-view request-view--<?= e($context) ?>" <?= $data ?> data-title="<?= e($req['title'] ?? '') ?>">
    <header class="rv-head">
        <div class="rv-head__top">
            <span class="rv-num">Nº <?= $reqId ?></span>
            <span class="status-pill status-pill--<?= e($status) ?>"><?= e(statusLabel($status)) ?></span>
            <?php if ($context === 'panel'): ?>
                <a class="rv-open" href="request_detail?id=<?= $reqId ?>&table=<?= e($sector) ?>">Abrir página <i class="fa-solid fa-up-right-from-square" aria-hidden="true"></i></a>
            <?php endif; ?>
        </div>
        <h2 class="rv-title"><?= e($req['title'] ?? 'Sem título') ?></h2>
        <p class="rv-owner">
            <span class="sector-tag" data-sector="<?= e(RequestForwardService::areaSlug($pdo, $ownerArea) ?? $sector) ?>"><?= e($ownerLabel) ?></span>
            <?php if ($ownerSince): ?><span class="muted">cuida desde <?= e(date('d/m', strtotime($ownerSince))) ?></span><?php endif; ?>
            <?php if ($ownerLabel !== $sectorName): ?><span class="muted">pedido original de <?= e($sectorName) ?></span><?php endif; ?>
        </p>
    </header>

    <section class="rv-now" aria-label="Situação agora">
        <p><?= e($now) ?></p>
        <div class="rv-actions">
            <?php if ($canApprove): ?>
                <button type="button" class="btn btn--success" onclick="decideRequest('Y', <?= $reqId ?>, '<?= e($sector) ?>')"><i class="fa-solid fa-check" aria-hidden="true"></i> Aprovar</button>
                <button type="button" class="btn btn--danger" onclick="decideRequest('N', <?= $reqId ?>, '<?= e($sector) ?>')">Recusar</button>
            <?php endif; ?>
            <?php if ($canWork && $status === 'Y' && !($open && $open['mode'] === 'transfer')): ?>
                <button type="button" class="btn btn--primary" onclick="requestAct('start_progress', <?= $reqId ?>, '<?= e($fullTable) ?>')"><i class="fa-solid fa-play" aria-hidden="true"></i> Começar atendimento</button>
            <?php elseif ($canWork && $status === 'W'): ?>
                <button type="button" class="btn btn--success" onclick="requestAct('finish', <?= $reqId ?>, '<?= e($fullTable) ?>')"><i class="fa-solid fa-check-double" aria-hidden="true"></i> Concluir</button>
            <?php endif; ?>
            <?php if ($canForward): ?>
                <button type="button" class="btn" onclick="openForwardWizard(<?= $reqId ?>, '<?= e($sector) ?>')"><i class="fa-solid fa-share-from-square" aria-hidden="true"></i> Encaminhar</button>
            <?php endif; ?>
            <?php if ($canReopen || $canPriority || $isStaff): ?>
                <details class="rv-more">
                    <summary class="btn btn--ghost">Mais ações</summary>
                    <div class="rv-more__menu">
                        <?php if ($canPriority): ?>
                            <label class="field" style="margin:0;">
                                <span class="field-label">Urgência</span>
                                <select onchange="requestAct('set_priority', <?= $reqId ?>, '<?= e($fullTable) ?>', this.value)">
                                    <?php foreach (priorityLabels() as $v => $l): ?>
                                        <option value="<?= $v ?>" <?= $priority === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>
                        <?php if ($canReopen): ?>
                            <button type="button" class="btn btn--sm" onclick="requestAct('reset_status', <?= $reqId ?>, '<?= e($fullTable) ?>')"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reabrir</button>
                        <?php endif; ?>
                        <?php if ($isStaff): ?>
                            <a class="btn btn--sm" href="print_request.php?id=<?= $reqId ?>&table=<?= e($sector) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-print" aria-hidden="true"></i> Imprimir</a>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($open && $isStaff): ?>
        <section class="forward-box forward-box--<?= e($open['mode']) ?>" aria-label="Encaminhamento em aberto">
            <p class="forward-box__title">
                <?php if ($open['mode'] === 'transfer'): ?>
                    <i class="fa-solid fa-right-left" aria-hidden="true"></i> <?= e($open['from_label']) ?> quer transferir para <?= e($open['to_label']) ?>
                <?php else: ?>
                    <i class="fa-solid fa-handshake-angle" aria-hidden="true"></i> <?= e($open['from_label']) ?> pediu ajuda a <?= e($open['to_label']) ?>
                <?php endif; ?>
            </p>
            <blockquote class="forward-box__msg"><?= nl2br(e($open['observation'])) ?></blockquote>
            <p class="forward-box__meta"><?= e($open['forwarded_by_name'] ?? '') ?>, <?= e(date('d/m \à\s H:i', strtotime($open['created_at']))) ?><?= $open['status'] === 'accepted' ? ' · aceito' : '' ?></p>
            <div class="rv-actions">
                <?php if ($openIsForMe && $open['status'] === 'pending'): ?>
                    <button type="button" class="btn btn--success" onclick="forwardAct('accept', <?= (int)$open['id'] ?>)"><i class="fa-solid fa-check" aria-hidden="true"></i> <?= $open['mode'] === 'transfer' ? 'Aceitar e assumir' : 'Aceitar ajudar' ?></button>
                    <button type="button" class="btn btn--danger" onclick="forwardAct('refuse', <?= (int)$open['id'] ?>)">Recusar</button>
                <?php endif; ?>
                <?php if ($openIsForMe && $open['mode'] === 'help'): ?>
                    <button type="button" class="btn btn--primary" onclick="forwardAct('return', <?= (int)$open['id'] ?>)"><i class="fa-solid fa-reply" aria-hidden="true"></i> Devolver com retorno</button>
                <?php endif; ?>
                <?php if ($openIsFromMe && $open['status'] === 'pending' && !$openIsForMe): ?>
                    <button type="button" class="btn btn--ghost" onclick="forwardAct('cancel', <?= (int)$open['id'] ?>)">Cancelar envio</button>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="rv-section" aria-label="O que foi pedido">
        <dl class="rv-facts">
            <div><dt>Quem pediu</dt><dd><?= e($req['creator_name'] ?? '—') ?><?= $subdivision ? ', ' . e($subdivision) : '' ?></dd></div>
            <div><dt>Para quando</dt><dd><?= e($deadline) ?> <?= in_array($status, ['C', 'N'], true) ? '' : getPrazoBadge($req['date'] ?? '', $status) ?></dd></div>
            <div><dt>Urgência</dt><dd class="<?= $priority >= 3 ? 'prio-tag prio-tag--' . $priority : '' ?>"><?= e(priorityLabel($priority)) ?></dd></div>
            <div><dt>Pedido em</dt><dd><?= e(date('d/m/Y \à\s H:i', strtotime((string)$req['created_at']))) ?></dd></div>
        </dl>
        <div class="prose rv-desc"><?= nl2br(e($req['descp'] ?? '')) ?: '<span class="muted">Sem descrição.</span>' ?></div>
        <?php if ($extra): ?>
            <dl class="rv-facts rv-facts--extra">
                <?php foreach ($extra as $label => $value):
                    $isUrl = preg_match('#^https?://#i', (string)$value);
                ?>
                    <div><dt><?= e($label) ?></dt><dd><?= $isUrl ? '<a href="' . e($value) . '" target="_blank" rel="noopener noreferrer">' . e($value) . '</a>' : e($value !== '' ? $value : '—') ?></dd></div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    </section>

    <section class="rv-section chat" aria-label="Conversa sobre o pedido">
        <h3 class="rv-section__title">Conversa</h3>
        <div class="chat-messages" data-chat-feed <?= $data ?>>
            <?php if ($comments):
                $lastDate = '';
                foreach ($comments as $c):
                    $day = date('d/m/Y', strtotime($c['created_at']));
                    if ($day !== $lastDate) { echo '<div class="chat-date-header">' . e($day) . '</div>'; $lastDate = $day; }
                    $side = (int)$c['user_id'] === $viewerId ? 'mine' : 'theirs';
            ?>
                <div class="chat-item <?= $side ?>">
                    <div class="chat-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string)$c['user_name'], 0, 1))) ?></div>
                    <div class="chat-bubble <?= $side ?>">
                        <div class="chat-user-name"><?= e($c['user_name']) ?></div>
                        <?= nl2br(e($c['comment'])) ?>
                        <span class="chat-time"><?= e(date('H:i', strtotime($c['created_at']))) ?></span>
                    </div>
                </div>
            <?php endforeach; else: ?>
                <p class="chat-empty"><?= $isStaff ? 'Nenhuma mensagem. O que você escrever aqui o solicitante também vê.' : 'Tem alguma dúvida? Escreva abaixo: quem cuida do pedido recebe sua mensagem.' ?></p>
            <?php endif; ?>
        </div>
        <form class="chat-footer" data-chat-form <?= $data ?>>
            <div class="chat-input-area">
                <textarea name="comment" class="chat-input" placeholder="Escreva sua mensagem" rows="1" required maxlength="3000" aria-label="Mensagem"></textarea>
                <button type="submit" class="btn-send" aria-label="Enviar mensagem"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
            </div>
        </form>
    </section>

    <details class="rv-section rv-timeline" <?= $context === 'page' ? 'data-open-desktop' : '' ?>>
        <summary class="rv-section__title">Linha do tempo <span class="muted">(<?= count($events) ?>)</span></summary>
        <ol class="timeline">
            <?php foreach ($events as $ev): ?>
                <li class="<?= e($ev['kind'] ?? '') ?>">
                    <div>
                        <time datetime="<?= e($ev['at']) ?>"><?= e(date('d/m/Y H:i', strtotime((string)$ev['at']))) ?></time>
                        <strong><?= e($ev['who']) ?></strong> <?= e(mb_strtolower(mb_substr($ev['text'], 0, 1)) . mb_substr($ev['text'], 1)) ?>
                        <?php if (!empty($ev['note'])): ?><blockquote><?= nl2br(e($ev['note'])) ?></blockquote><?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    </details>

    <?php if ($canCancelRequest): ?>
        <button type="button" class="btn btn--ghost rv-cancel" onclick="requestAct('delete', <?= $reqId ?>, '<?= e($fullTable) ?>')"><i class="fa-solid fa-trash-can" aria-hidden="true"></i> Cancelar este pedido</button>
    <?php endif; ?>
</article>
        <?php
    }
}
