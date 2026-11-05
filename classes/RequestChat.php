<?php
/**
 * Conversas de um pedido e quem escreve em cada uma. A tela (components/request_view.php) desenha
 * as abas a partir de threads(); api/add_comment.php aceita a mensagem só se o destino for o
 * target de uma dessas conversas (targetFor). Uma regra só para mostrar e para gravar.
 */

require_once __DIR__ . '/RequestForwardService.php';
require_once __DIR__ . '/../config/security.php';

class RequestChat
{
    /**
     * Conversas do pedido que esta pessoa vê, na ordem das abas (no máximo duas). Cada uma tem:
     * - key e label (nome da aba); events: mostra os eventos do pedido; empty: texto sem mensagens;
     * - sources: de onde vêm as mensagens, [canal, setor que ajuda, etiqueta, etiqueta só nas de quem pediu];
     * - target: para onde vai o que se escreve, [channel, area, hint, placeholder, two (vai a dois setores)];
     *   null = só leitura, com readHint explicando;
     * - unread: a última mensagem para mim é de outra pessoa; default: aba que abre.
     * Quem pediu tem uma conversa só e não escolhe destino: com ajuda aceita, escreve no helper, que o
     * responsável e quem ajuda recebem. Quem só ajuda tem "Com <responsável>" e "Com <quem pediu>" e nunca
     * vê a conversa de quem pediu com o responsável. Responsável, coordenação e admin: "Com <quem pediu>"
     * (lendo também o helper) e "Com <setor que ajuda>". O helper só recebe mensagem com a ajuda aceita.
     */
    public static function threads(PDO $pdo, array $req, string $sector, int $viewerId, string $role, array $forwards, ?int $ownerArea): array
    {
        $ownerLabel = RequestForwardService::areaLabel($pdo, $ownerArea);
        $helperAreas = requestHelperAreaIds($forwards);
        $openAreas = requestOpenHelperAreaIds($forwards);
        $areaName = fn(int $a) => RequestForwardService::areaLabel($pdo, $a);
        // Abas com nomes de verdade: primeiro nome de quem pediu e nome do setor
        $who = explode(' ', trim((string)($req['creator_name'] ?? '')))[0] ?: 'quem pediu';
        $target = fn(string $channel, int $area, string $hint, string $placeholder, bool $two = false) => compact('channel', 'area', 'hint', 'placeholder', 'two');
        // O que quem pediu escreveu durante a ajuda: o setor que ajuda também recebeu
        $helperSources = array_map(fn($a) => ['helper', $a, $areaName($a) . ' também vê', true], $helperAreas);
        $threads = [];

        if ($role === 'solicitante') {
            $open = $openAreas[0] ?? null; // só um encaminhamento aberto por vez
            $both = $open ? $ownerLabel . ' e ' . $areaName($open) : '';
            $threads[] = ['key' => 'main', 'label' => 'Com ' . $ownerLabel, 'events' => true,
                'sources' => array_merge([['public', 0, '', false]], $helperSources),
                'target' => $open
                    ? $target('helper', $open, "$both recebem sua mensagem.", 'Mensagem para ' . $both, true)
                    : $target('public', 0, "$ownerLabel recebe sua mensagem.", 'Mensagem para ' . $ownerLabel),
                'empty' => 'Ninguém escreveu ainda. Use o campo abaixo para falar sobre o pedido.', 'readHint' => ''];
        } elseif (userSeesOnlyInternalChat($pdo, $req, $sector, $viewerId, $role)) {
            $threads[] = ['key' => 'internal', 'label' => 'Com ' . $ownerLabel, 'events' => true, 'sources' => [['internal', 0, '', false]],
                'target' => $target('internal', 0, "Quem lê: só os setores. $who não vê esta conversa.", 'Mensagem para ' . $ownerLabel),
                'empty' => "Nenhuma mensagem com $ownerLabel ainda. Use o campo abaixo para falar com o setor responsável.", 'readHint' => ''];
            foreach (array_intersect($helperAreas, RequestForwardService::userAreaIds($pdo, $viewerId)) as $a) {
                $open = in_array($a, $openAreas, true);
                $threads[] = ['key' => 'helper-' . $a, 'label' => 'Com ' . $who, 'events' => false, 'sources' => [['helper', $a, '', false]],
                    'target' => $open ? $target('helper', $a, "$who recebe sua mensagem; $ownerLabel também acompanha.", 'Mensagem para ' . $who) : null,
                    'empty' => "Nenhuma mensagem com $who ainda." . ($open ? ' Use o campo abaixo para falar com essa pessoa.' : ''),
                    'readHint' => 'A ajuda já foi devolvida. Esta conversa fica só para consulta.'];
            }
        } else {
            $myAreas = RequestForwardService::userAreaIds($pdo, $viewerId);
            $party = in_array($ownerArea, $myAreas, true); // coordenação e admin só acompanham
            // Setores do outro lado da conversa entre setores: chamados para ajudar, com transferência
            // pendente ou que já passaram o pedido adiante. Sempre o setor que não é o de quem vê (depois
            // de uma transferência, quem assumiu fala "Com TI", não consigo mesmo)
            $helpers = [];
            $handedOver = false; // meu setor transferiu o pedido e não é mais o responsável
            foreach ($forwards as $f) {
                $live = ($f['mode'] === 'help' && !in_array($f['status'], ['refused', 'cancelled'], true))
                    || ($f['mode'] === 'transfer' && in_array($f['status'], ['pending', 'accepted', 'completed'], true));
                if (!$live) {
                    continue;
                }
                $to = (int)$f['to_area_id'];
                $from = (int)$f['from_area_id'];
                $other = match (true) {
                    in_array($to, $myAreas, true) => $from,
                    in_array($from, $myAreas, true) => $to,
                    default => $to === $ownerArea ? $from : $to, // coordenação e admin: o setor que não é o responsável
                };
                if (!in_array($other, $myAreas, true)) {
                    $helpers[$other] = $other === $to ? (string)$f['to_label'] : (string)$f['from_label'];
                }
                $handedOver = $handedOver || ($f['mode'] === 'transfer' && $f['status'] !== 'pending' && in_array($from, $myAreas, true) && !$party);
            }
            unset($helpers[0]);
            $threads[] = ['key' => 'main', 'label' => 'Com ' . $who, 'events' => true, 'fwdEvents' => !$helpers,
                'sources' => array_merge([['public', 0, '', false]], $helperSources),
                'target' => $handedOver ? null : $target('public', 0, "$who (quem pediu) vê esta conversa. Dúvidas sobre o pedido, pergunte aqui.", 'Mensagem para ' . $who . ' (quem pediu)'),
                'empty' => 'Ninguém escreveu ainda. Use o campo abaixo para falar sobre o pedido.',
                'readHint' => $handedOver ? "O pedido foi transferido para $ownerLabel. Esta conversa fica só para consulta; fale com $ownerLabel na aba ao lado." : ''];
            if ($helpers) {
                $with = count($helpers) === 1 ? reset($helpers) : 'os setores';
                // Os encaminhamentos (pediu ajuda, devolveu, transferiu) ficam aqui: $who não vê
                $threads[] = ['key' => 'internal', 'label' => 'Com ' . $with, 'events' => false, 'fwdEvents' => true, 'sources' => [['internal', 0, '', false]],
                    'target' => $target('internal', 0, 'Quem lê: ' . implode(' e ', array_unique([$ownerLabel, $with])) . ". $who não vê esta conversa.", 'Mensagem para ' . $with),
                    'empty' => "Nenhuma mensagem com $with ainda.", 'readHint' => ''];
            }
        }

        // Esperando resposta: a última mensagem para mim é de outra pessoa (pedido encerrado não pede
        // resposta). Para mim = no canal em que escrevo, ou de quem pediu em qualquer fonte da aba.
        // Só para quem participa da conversa: coordenação e admin acompanham sem bolinha
        $party = $party ?? true;
        $st = $pdo->prepare('SELECT user_id, channel, helper_area_id FROM request_comments WHERE request_id = ? AND request_table = ? ORDER BY created_at ASC, id ASC');
        $st->execute([(int)$req['id'], $sector]);
        $comments = $st->fetchAll(PDO::FETCH_ASSOC);
        $creatorId = (int)($req['created_by'] ?? 0);
        $closed = in_array(strtoupper((string)($req['status'] ?? '')), ['C', 'N'], true);
        $matches = fn(array $c, string $channel, int $area) => $c['channel'] === $channel && ($channel !== 'helper' || (int)$c['helper_area_id'] === $area);
        foreach ($threads as &$t) {
            $last = null;
            foreach ($comments as $c) {
                $forMe = $t['target'] && $matches($c, $t['target']['channel'], $t['target']['area']);
                foreach ($t['sources'] as [$channel, $area]) {
                    $forMe = $forMe || ((int)$c['user_id'] === $creatorId && $matches($c, $channel, $area));
                }
                if ($forMe) {
                    $last = $c;
                }
            }
            $t['unread'] = $party && !$closed && $last && (int)$last['user_id'] !== $viewerId;
            $t['default'] = false;
        }
        unset($t);
        // Abre a primeira aba esperando resposta; sem nenhuma, a primeira
        $pick = 0;
        foreach ($threads as $i => $t) {
            if ($t['unread']) { $pick = $i; break; }
        }
        $threads[$pick]['default'] = true;
        return $threads;
    }

    /**
     * Para onde vai a mensagem que esta pessoa quer mandar.
     * Quem pediu não escolhe: vai para a conversa dela. Os outros mandam o canal (e o setor, no helper)
     * da aba em que escreveram; sem canal, vale a aba que abre primeiro.
     * @return array{0: ?array, 1: string} [target da conversa, ou null; mensagem de erro]
     */
    public static function targetFor(PDO $pdo, array $req, string $sector, int $userId, string $role, ?string $wanted, int $area): array
    {
        $forwards = RequestForwardService::forRequest($pdo, (int)$req['id'], $sector);
        if (!array_key_exists('creator_name', $req)) {
            $req['creator_name'] = null;
        }
        $threads = self::threads($pdo, $req, $sector, $userId, $role, $forwards, RequestForwardService::ownerFromForwards($pdo, $forwards, $sector));
        if ($role === 'solicitante' || $wanted === null || $wanted === '') {
            $thread = array_values(array_filter($threads, fn($t) => !empty($t['default'])))[0] ?? ($threads[0] ?? null);
            return $thread && $thread['target'] ? [$thread['target'], ''] : [null, $thread['readHint'] ?? 'Você não pode escrever nesta conversa.'];
        }
        // Setor que só ajuda (ou com transferência ainda não aceita) fala só com o responsável: o que
        // não for para a conversa com quem pediu (helper) vai para a interna, mesmo pedindo "public"
        if ($wanted !== 'helper' && userSeesOnlyInternalChat($pdo, $req, $sector, $userId, $role)) {
            $wanted = 'internal';
        }
        foreach ($threads as $t) {
            $to = $t['target'];
            if ($to && $to['channel'] === $wanted && ($wanted !== 'helper' || (int)$to['area'] === $area)) {
                return [$to, ''];
            }
        }
        // A aba existe mas é só leitura (ajuda devolvida, pedido transferido): diz o porquê
        foreach ($threads as $t) {
            if (!$t['target'] && $t['readHint'] !== '' && (($wanted === 'public' && $t['key'] === 'main') || ($wanted === 'helper' && $t['key'] === 'helper-' . $area))) {
                return [null, $t['readHint']];
            }
        }
        return [null, 'Você não pode escrever nesta conversa.'];
    }
}
