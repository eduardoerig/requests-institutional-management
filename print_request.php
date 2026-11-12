<?php
/**
 * print_request.php
 * Folha A4 da requisição, para imprimir ou salvar em PDF.
 * Com ?embed=1 (iframe oculto da Caixa) abre o diálogo de impressão sozinha.
 */
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/conn.php';
require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/classes/RequestForwardService.php';
require_once __DIR__ . '/classes/RequestItems.php';
requireLoginJson($pdo);

$role = $_SESSION['role'] ?? 'solicitante';

$req_id    = isset($_GET['id'])    ? (int)$_GET['id'] : 0;
$req_table = isset($_GET['table']) ? preg_replace('/[^a-z]/', '', $_GET['table']) : '';
$embed     = ($_GET['embed'] ?? '') === '1';

if (!$req_id || !in_array($req_table, allowedRequestTables(), true)) die('Parâmetros inválidos.');


$stmt = $pdo->prepare("
    SELECT r.*, u.name as creator_name, s.name as subdivision_name
    FROM `ctd_{$req_table}_frm` r
    LEFT JOIN ctd_users u ON r.created_by = u.id
    LEFT JOIN ctd_subdivision s ON r.subdivision_id = s.id
    WHERE r.id = ?
");
$stmt->execute([$req_id]);
$req = $stmt->fetch();

if (!$req) die('Pedido não encontrado.');

// Mesma regra da tela de detalhes: solicitante dono, admin, admin da subdivisão ou gestor do setor/repasse
if (!userCanViewRequest($pdo, $req, $req_table, (int)$_SESSION['id'], $role)) {
    http_response_code(403);
    die('Você não tem permissão para ver este pedido.');
}

$stmt_his = $pdo->prepare("SELECT user_name, action, created_at FROM request_history WHERE request_id = ? AND request_table = ? ORDER BY created_at ASC, id ASC");
$stmt_his->execute([$req_id, $req_table]);
$history = $stmt_his->fetchAll();

$status   = strtoupper(trim((string)($req['status'] ?? 'P'))) ?: 'P';
$priority = (int)($req['priority'] ?? 2);
$owner    = RequestForwardService::areaLabel($pdo, RequestForwardService::ownerAreaId($pdo, $req_id, $req_table));

$rawDate  = (string)($req['date'] ?? '');
$deadline = ($rawDate === '' || strpos($rawDate, '0000') === 0) ? 'Sem prazo' : date('d/m/Y', strtotime($rawDate));
$created  = !empty($req['created_at']) ? date('d/m/Y \à\s H:i', strtotime((string)$req['created_at'])) : '—';
$number   = str_pad((string)$req_id, 4, '0', STR_PAD_LEFT);

// Campos do tipo de pedido (os mesmos da tela do pedido)
$extra = requestTypeFields($req_table, $req);

$facts = [
    'Setor' => sectorLabel($req_table),
    'Quem pediu' => trim(($req['creator_name'] ?? '—') . (!empty($req['subdivision_name']) ? ', ' . $req['subdivision_name'] : '')),
    'Pedido em' => $created,
    'Para quando' => $deadline,
    'Urgência' => priorityLabel($priority),
    'Quem atende' => $owner,
] + $extra;

$dotColor = ['P' => '#B45309', 'W' => '#2563EB', 'C' => '#15803D', 'N' => '#B91C1C'][$status] ?? '#6B6F78';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedido Nº <?= $number ?> | SisReq</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible+Mono:wght@500;700&family=Atkinson+Hyperlegible+Next:wght@400;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        @page { size: A4; margin: 16mm 14mm 18mm; }

        :root {
            --tinta: #26252B;
            --tinta-2: #565A63;
            --tinta-3: #6B6F78;
            --linha: #D5D8DE;
            --fundo: #F3F4F6;
        }
        *, *::before, *::after { box-sizing: border-box; }
        html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body {
            margin: 0;
            font-family: "Atkinson Hyperlegible Next", system-ui, "Segoe UI", sans-serif;
            font-size: 10.5pt;
            line-height: 1.5;
            color: var(--tinta);
            background: var(--fundo);
        }

        /* Barra da tela (não sai no papel) */
        .screen-bar {
            position: sticky; top: 0; z-index: 2;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 12px 24px;
            background: #fff;
            border-bottom: 1px solid var(--linha);
        }
        .screen-bar a { color: var(--tinta-2); text-decoration: none; font-weight: 600; }
        .screen-bar button {
            min-height: 44px; padding: 0 20px;
            border: 0; border-radius: 10px;
            background: #C2570C; color: #fff;
            font: inherit; font-weight: 700; cursor: pointer;
        }
        .screen-bar button:hover { background: #A34708; }

        /* Folha */
        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 24px auto;
            padding: 16mm 14mm;
            background: #fff;
            box-shadow: 0 1px 3px rgba(38, 37, 43, 0.12);
        }

        .sheet-head {
            display: flex; justify-content: space-between; align-items: flex-end; gap: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--tinta);
        }
        .sheet-head__brand { display: flex; align-items: flex-end; gap: 12px; }
        .sheet-head__brand img { height: 15mm; width: auto; }
        .sheet-head__brand span { font-size: 9pt; color: var(--tinta-2); padding-bottom: 2px; }
        .sheet-head__num { text-align: right; }
        .sheet-head__num strong { display: block; font-size: 16pt; font-weight: 800; }
        .sheet-head__num strong span { font-family: "Atkinson Hyperlegible Mono", ui-monospace, Consolas, monospace; }
        .sheet-head__num small { color: var(--tinta-3); font-size: 8.5pt; }

        h1 { font-size: 17pt; line-height: 1.25; margin: 18px 0 6px; overflow-wrap: anywhere; }
        .status { display: inline-flex; align-items: center; gap: 7px; font-weight: 700; }
        .status::before { content: ""; width: 9px; height: 9px; border-radius: 50%; background: <?= $dotColor ?>; }

        .facts {
            display: grid;
            grid-template-columns: 1fr 1fr;
            margin: 16px 0 0;
            border-top: 1px solid var(--linha);
        }
        .facts > div { display: grid; grid-template-columns: 30mm 1fr; gap: 8px; padding: 7px 0; border-bottom: 1px solid var(--linha); }
        .facts > div:nth-child(odd) { padding-right: 12px; }
        .facts dt { color: var(--tinta-2); }
        .facts dd { margin: 0; font-weight: 700; overflow-wrap: anywhere; }

        h2 { font-size: 11.5pt; margin: 22px 0 8px; }
        .desc { padding: 12px 14px; border: 1px solid var(--linha); border-radius: 6px; white-space: pre-wrap; overflow-wrap: anywhere; }

        .history { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
        .history td { padding: 5px 8px 5px 0; border-bottom: 1px solid var(--linha); vertical-align: top; }
        .history tr { break-inside: avoid; }
        .history td:first-child { width: 30mm; color: var(--tinta-2); white-space: nowrap; font-variant-numeric: tabular-nums; }
        .history td:nth-child(2) { width: 42mm; font-weight: 600; }

        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 14mm; margin-top: 18mm; break-inside: avoid; }
        .signatures div { border-top: 1px solid var(--tinta); padding-top: 6px; }
        .signatures strong { display: block; }
        .signatures span { color: var(--tinta-2); font-size: 9pt; }

        .sheet-foot { margin-top: 14mm; padding-top: 8px; border-top: 1px solid var(--linha); font-size: 8.5pt; color: var(--tinta-3); }

        @media print {
            body { background: #fff; }
            .screen-bar { display: none; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
            a { color: inherit; text-decoration: none; }
        }
        @media screen and (max-width: 820px) {
            .sheet { width: auto; min-height: 0; margin: 12px; padding: 20px 16px; }
            .facts { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php if (!$embed): ?>
    <div class="screen-bar">
        <a href="javascript:history.back()"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Voltar</a>
        <button type="button" onclick="window.print()"><i class="fa-solid fa-print" aria-hidden="true"></i> Imprimir</button>
    </div>
<?php endif; ?>

<main class="sheet">
    <header class="sheet-head">
        <div class="sheet-head__brand">
            <img src="<?= e(orgSetting('org_logo')) ?>" alt="<?= e(orgSetting('org_name')) ?>">
            <span>SisReq, pedidos internos</span>
        </div>
        <div class="sheet-head__num">
            <strong>Pedido Nº <span><?= e($number) ?></span></strong>
            <small>Impressa em <?= e(date('d/m/Y \à\s H:i')) ?></small>
        </div>
    </header>

    <h1><?= e($req['title'] ?? 'Sem título') ?></h1>
    <span class="status"><?= e(statusLabel($status)) ?></span>

    <dl class="facts">
        <?php foreach ($facts as $label => $value):
            $value = (string)$value;
        ?>
            <div><dt><?= e($label) ?></dt><dd><?= e($value !== '' ? $value : '—') ?></dd></div>
        <?php endforeach; ?>
    </dl>

    <h2>O que foi pedido</h2>
    <div class="desc"><?= e(trim((string)($req['descp'] ?? '')) !== '' ? $req['descp'] : 'Sem descrição.') ?></div>

    <?php $items = RequestItems::forRequest($pdo, $req_table, $req_id); ?>
    <?php if ($items): // no papel o link vai inteiro (não dá para clicar) ?>
        <h2><?= e(RequestItems::heading($req_table)) ?></h2>
        <table class="history">
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= e((string)($it['name'] ?? '')) ?></td>
                        <td><?= e((string)($it['qty'] ?? '')) ?></td>
                        <td style="overflow-wrap:anywhere"><?= e((string)($it['url'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($req['obs'])): ?>
        <h2>Observações</h2>
        <div class="desc"><?= e($req['obs']) ?></div>
    <?php endif; ?>

    <?php if ($history): ?>
        <h2>Histórico</h2>
        <table class="history">
            <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?= e(date('d/m/Y H:i', strtotime((string)$h['created_at']))) ?></td>
                        <td><?= e($h['user_name'] ?? '—') ?></td>
                        <td><?= e($h['action'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <section class="signatures" aria-label="Assinaturas">
        <div><strong>Entregue por</strong><span>Nome e data ____/____/________</span></div>
        <div><strong>Recebido por</strong><span>Nome e data ____/____/________</span></div>
    </section>

    <footer class="sheet-foot">SisReq<?= orgSetting('org_name') !== 'SisReq' ? ', ' . e(orgSetting('org_name')) : '' ?>. Pedido Nº <?= e($number) ?>.</footer>
</main>

<?php if ($embed): ?>
<script>
    // Aberta pela Caixa num iframe oculto: imprime assim que as fontes e o logo carregarem
    window.addEventListener('load', () => {
        (document.fonts ? document.fonts.ready : Promise.resolve()).then(() => window.print());
    });
</script>
<?php endif; ?>
</body>
</html>
