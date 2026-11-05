<?php
/**
 * api/get_notifications.php — avisos do usuário (sino).
 * Cada aviso vem com "kind" (ícone/cor na tela) deduzido do título e com o título reescrito
 * no formato da lista ("#221 · Compras espera sua aprovação"). O que está gravado não muda.
 */
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/conn.php';
require_once __DIR__ . '/../config/security.php';
requireLoginJson($pdo);
session_write_close(); // só leitura: não segura a sessão das outras abas e requisições
require_once __DIR__ . '/../components/request_card.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$userId = (int)$_SESSION['id'];

function notificationKind(string $title): string
{
    $t = mb_strtolower($title);
    $rules = [
        'message' => ['mensagem'],
        'returned' => ['devolveu a ajuda'],
        'refused' => ['recusad', 'recusou'],
        'done' => ['concluíd'],
        'transfer' => ['chegou de', 'assumiu', 'novo setor', 'encaminhado'],
        'help' => ['pediu ajuda', 'aceitou ajudar', 'chamou vocês', 'coordenação incluiu'],
        'approve' => ['para aprovar'],
        'approved' => ['aprovado', 'aprovada', 'novo para atender'],
        'started' => ['atendimento', 'reaberto'],
        'priority' => ['urgência', 'prioridade'],
    ];
    foreach ($rules as $kind => $words) {
        foreach ($words as $w) {
            if (mb_strpos($t, $w) !== false) {
                return $kind;
            }
        }
    }
    return 'info';
}

/**
 * Título da lista: "#nº · Setor" e o que aconteceu. Sem link de pedido, fica o título gravado.
 * Retorna [título, mensagem].
 */
function notificationText(string $title, string $message, ?string $link): array
{
    if (!preg_match('/[?&]id=(\d+)&table=([a-z_]+)/', (string)$link, $m)) {
        return [$title, $message];
    }
    $ref = '#' . $m[1] . ' · ' . requestSectorName($m[2]);
    $rules = [
        '/^(?:Nova mensagem|Mensagem entre setores) no pedido/u' => fn() => "Mensagem nova em $ref",
        '/^(Urgente: )?pedido nº \d+ para aprovar/iu' => fn($x) => "$ref espera sua aprovação" . (!empty($x[1]) ? ' (urgente)' : ''),
        '/chegou de (.+)$/u' => fn() => "Transferência recebida: $ref",
        '/^Coordenação chamou vocês \(nº/u' => fn() => "Coordenação chamou vocês em $ref",
        '/^Coordenação incluiu (.+) \(nº/u' => fn($x) => "Coordenação incluiu {$x[1]} em $ref",
        '/^(.+) recusou ser parceiro \(nº/u' => fn($x) => "{$x[1]} recusou ser parceiro em $ref",
        '/^(.+) pediu ajuda \(nº/u' => fn($x) => "{$x[1]} pediu ajuda em $ref",
        '/^(.+) devolveu a ajuda \(nº/u' => fn($x) => "{$x[1]} devolveu a ajuda em $ref",
        '/^(.+) aceitou ajudar \(nº/u' => fn($x) => "{$x[1]} aceitou ajudar em $ref",
        '/^(.+) assumiu o pedido nº/u' => fn($x) => "{$x[1]} assumiu $ref",
        '/^(.+) recusou o pedido nº/u' => fn($x) => "{$x[1]} recusou $ref",
        '/^Encaminhamento cancelado/u' => fn() => "Envio cancelado: $ref",
        '/com novo setor$/u' => fn() => "$ref mudou de setor",
        '/encaminhado$/u' => fn() => "$ref encaminhado",
        '/^Pedido novo para atender/u' => fn() => "$ref chegou para atender",
        '/aprovad[oa]$/u' => fn() => "$ref aprovado",
        '/recusad[oa]$/u' => fn() => "$ref recusado",
        '/^Pedido em atendimento/u' => fn() => "$ref em atendimento",
        '/^Pedido concluído/u' => fn() => "$ref concluído",
        '/^Pedido reaberto/u' => fn() => "$ref reaberto",
        '/urgência|prioridade/iu' => fn() => "Urgência mudou em $ref",
    ];
    foreach ($rules as $re => $make) {
        if (preg_match($re, $title, $x)) {
            $title = $make($x);
            break;
        }
    }
    // O número e o setor já estão no título: o texto diz quem fez e o quê ("Beatriz aprovou: barra antipânico")
    $verbs = ['aprovado' => 'aprovou', 'aprovada' => 'aprovou', 'recusado' => 'recusou', 'recusada' => 'recusou', 'concluído' => 'concluiu', 'reaberto' => 'reabriu'];
    $messageRules = [
        '/^(?:O pedido nº \d+ \([^)]*\)(?:, "(.+)",)?|Seu pedido "(.+)" \([^)]*\)) foi (aprovad[oa]|recusad[oa]|concluído|reaberto) por (.+?)(?: e voltou para atendimento)?\.(.*)$/su'
            => fn($x) => $x[4] . ' ' . $verbs[$x[3]] . (($x[1] ?: $x[2]) !== '' ? ': ' . ($x[1] ?: $x[2]) . '.' : '.') . $x[5],
        '/^O setor de .+ começou a atender o pedido nº \d+\. Responsável: (.+)\.$/su' => fn($x) => $x[1] . ' começou o atendimento.',
        '/^A urgência do pedido nº \d+ \([^)]*\) mudou para (.+)\. Alterado por (.+)\.$/su' => fn($x) => $x[2] . ' mudou a urgência para ' . $x[1] . '.',
        '/^(.+) pediu: "(.+)" \([^)]*\)\.(.*)$/su' => fn($x) => $x[1] . ' pediu: ' . $x[2] . '.' . $x[3],
        '/^O pedido nº \d+, "(.+)", foi aprovado e está na Caixa do setor\.$/su' => fn($x) => $x[1] . '. Já está na Caixa do setor.',
    ];
    foreach ($messageRules as $re => $make) {
        if (preg_match($re, $message, $x)) {
            $message = $make($x + array_fill(0, 6, ''));
            break;
        }
    }
    // Mensagem: "Nome: “texto”"; devolução de ajuda: "Retorno: texto"
    if (str_starts_with($title, 'Mensagem nova') && preg_match('/^([^:]{1,80}): (.+)$/su', $message, $x)) {
        $message = $x[1] . ': “' . $x[2] . '”';
    } elseif (str_contains($title, 'devolveu a ajuda') && $message !== '') {
        $message = 'Retorno: ' . $message;
    }
    return [$title, $message];
}

$stmt = $pdo->prepare('SELECT id, title, message, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 30');
$stmt->execute([$userId]);
$notifications = array_map(function ($n) {
    $n['id'] = (int)$n['id'];
    $n['is_read'] = (int)$n['is_read'];
    $n['kind'] = notificationKind((string)$n['title']);
    // Mensagens antigas tinham emoji no começo
    $message = preg_replace('/^[^\p{L}\p{N}"“(]+/u', '', (string)$n['message']);
    [$n['title'], $n['message']] = notificationText((string)$n['title'], $message, $n['link']);
    return $n;
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

$stmtCount = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
$stmtCount->execute([$userId]);

echo json_encode([
    'unreadCount' => (int)$stmtCount->fetchColumn(),
    'latestId' => $notifications[0]['id'] ?? 0,
    'serverTime' => date('Y-m-d H:i:s'),
    'notifications' => $notifications,
], JSON_UNESCAPED_UNICODE);
