<?php
/**
 * print_request.php
 * RelatÃ³rio profissional para impressÃ£o/PDF de requisiÃ§Ãµes.
 */
session_start();
require_once 'config/conn.php';

$logged = $_SESSION['id'] ?? false;
if (!$logged) die('Acesso negado.');

$role = $_SESSION['role'] ?? 'solicitante';
$adminRoles = ['admin', 'adm', 'coord', 'adm_sub'];
$gestorRoles = ['gestor', 'ti', 'xerox', 'service', 'shop', 'mkt', 'marketing'];
$isAdmin  = in_array($role, $adminRoles);
$isGestor = in_array($role, $gestorRoles);

if (!$isAdmin && !$isGestor) {
    die('VocÃª nÃ£o tem permissÃ£o para gerar este relatÃ³rio.');
}

$req_id    = isset($_GET['id'])    ? (int)$_GET['id']              : 0;
$req_table = isset($_GET['table']) ? preg_replace('/[^a-z]/', '', $_GET['table']) : '';
$allowed_tables = ['mkt', 'shop', 'xerox', 'service', 'ti'];

if (!$req_id || !in_array($req_table, $allowed_tables)) die('ParÃ¢metros invÃ¡lidos.');

$label_map = ['mkt' => 'Marketing', 'shop' => 'Compras', 'xerox' => 'Reprografia', 'service' => 'Manutenção', 'ti' => 'TI'];

$stmt = $pdo->prepare("
    SELECT r.*, u.name as creator_name, s.name as subdivision_name
    FROM `ctd_{$req_table}_frm` r
    LEFT JOIN ctd_users u ON r.created_by = u.id
    LEFT JOIN ctd_subdivision s ON r.subdivision_id = s.id
    WHERE r.id = ?
");
$stmt->execute([$req_id]);
$req = $stmt->fetch();

if (!$req) die('RequisiÃ§Ã£o nÃ£o encontrada.');

$stmt_his = $pdo->prepare("SELECT * FROM request_history WHERE request_id = ? AND request_table = ? ORDER BY created_at ASC");
$stmt_his->execute([$req_id, $req_table]);
$history = $stmt_his->fetchAll();

$status_label = ['P' => 'Pendente', 'Y' => 'Aprovado', 'N' => 'Recusado', 'W' => 'Em Andamento', 'C' => 'ConcluÃ­da', 'F' => 'Repassada'];
$status_color = [
    'P' => '#d97706', 'Y' => '#059669', 'N' => '#dc2626',
    'W' => '#2563eb', 'C' => '#15803d', 'F' => '#7c3aed'
];
$pri_labels = [1 => 'Baixa', 2 => 'MÃ©dia', 3 => 'Alta', 4 => 'CrÃ­tica'];
$pri_color  = [1 => '#10b981', 2 => '#f59e0b', 3 => '#ef4444', 4 => '#7f1d1d'];

$current_status = trim(strtoupper($req['status'] ?? 'P'));
$priority       = $req['priority'] ?? 2;

// Data de prazo/entrega
$rawDate = $req['date'] ?? '';
$prazoDisplay = (!$rawDate || $rawDate === '0000-00-00')
    ? 'Sem prazo definido'
    : (($ts = strtotime($rawDate)) ? date('d/m/Y', $ts) : 'Sem prazo definido');

$geradoEm = date('d/m/Y \Ã \s H:i');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RelatÃ³rio â€” RequisiÃ§Ã£o #<?= $req_id ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #3d3c42;
            background: #f8fafc;
            padding: 0;
        }

        /* ===== BARRA DE AÃ‡ÃƒO (nÃ£o imprime) ===== */
        .action-bar {
            position: fixed; top: 0; left: 0; right: 0;
            background: #2c2b31; color: #fff;
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 32px; z-index: 9999;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        .action-bar h1 { font-size: 0.9rem; font-weight: 600; opacity: 0.8; }
        .btn-print {
            background: #2563eb; color: #fff; border: none;
            padding: 9px 22px; border-radius: 8px; cursor: pointer;
            font-weight: 700; font-size: 0.85rem; display: flex; align-items: center; gap: 8px;
            transition: background 0.2s;
        }
        .btn-print:hover { background: #1d4ed8; }
        .btn-back-link {
            color: #94a3b8; text-decoration: none; font-size: 0.85rem;
            display: flex; align-items: center; gap: 6px;
            transition: color 0.2s;
        }
        .btn-back-link:hover { color: #fff; }

        /* ===== DOCUMENTO ===== */
        .document {
            max-width: 900px;
            margin: 80px auto 60px;
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,0.07);
            border: 1px solid #e2e8f0;
        }

        /* ===== CABEÃ‡ALHO ===== */
        .doc-header {
            background: linear-gradient(135deg, #2c2b31 0%, #3d3c42 100%);
            padding: 32px 40px;
            display: flex; justify-content: space-between; align-items: center;
            gap: 24px;
        }
        .doc-header-logo { display: flex; align-items: center; gap: 16px; }
        .doc-header-logo img { height: 56px; object-fit: contain; filter: brightness(1.1); }
        .doc-header-title { color: #fff; }
        .doc-header-title h2 { font-size: 1.35rem; font-weight: 800; margin-bottom: 4px; }
        .doc-header-title p { font-size: 0.8rem; color: #94a3b8; }

        .doc-header-meta { text-align: right; }
        .protocol-number {
            font-size: 2rem; font-weight: 800; color: #fff;
            font-variant-numeric: tabular-nums; letter-spacing: -0.5px;
        }
        .protocol-label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .generated-at { font-size: 0.75rem; color: #64748b; margin-top: 6px; }

        /* ===== STATUS BANNER ===== */
        .status-banner {
            padding: 12px 40px;
            display: flex; align-items: center; gap: 16px;
            border-bottom: 1px solid #f1f5f9;
        }
        .status-chip {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 6px 16px; border-radius: 999px;
            font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .status-chip .dot { width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
        .banner-separator { color: #cbd5e1; }
        .priority-chip {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 0.78rem; font-weight: 600; color: #64748b;
        }

        /* ===== CORPO ===== */
        .doc-body { padding: 36px 40px; }

        .section { margin-bottom: 32px; }
        .section-title {
            font-size: 0.68rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: 1px; color: #94a3b8;
            padding-bottom: 10px; margin-bottom: 16px;
            border-bottom: 2px solid #f1f5f9;
            display: flex; align-items: center; gap: 8px;
        }
        .section-title span { color: #cbd5e1; font-size: 0.8rem; }

        /* Grid de infos */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
        }
        .info-cell {
            background: #fff;
            padding: 16px 20px;
        }
        .info-cell label {
            display: block; font-size: 0.68rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; margin-bottom: 5px;
        }
        .info-cell span {
            font-size: 0.95rem; font-weight: 600; color: #3d3c42;
        }

        /* TÃ­tulo + DescriÃ§Ã£o */
        .req-title {
            font-size: 1.5rem; font-weight: 800; color: #2c2b31;
            margin-bottom: 16px; line-height: 1.3;
        }
        .description-box {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
            padding: 20px 24px; white-space: pre-wrap; line-height: 1.7;
            font-size: 0.95rem; color: #334155; min-height: 80px;
        }

        /* Tabela de HistÃ³rico */
        .history-table {
            width: 100%; border-collapse: collapse;
            font-size: 0.85rem;
            border: 1px solid #e2e8f0;
            border-radius: 10px; overflow: hidden;
        }
        .history-table thead tr {
            background: #f8fafc;
        }
        .history-table th {
            padding: 12px 16px; text-align: left;
            font-size: 0.68rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; color: #64748b;
            border-bottom: 1px solid #e2e8f0;
        }
        .history-table td {
            padding: 12px 16px; border-bottom: 1px solid #f1f5f9; vertical-align: top;
        }
        .history-table tr:last-child td { border-bottom: none; }
        .history-table tr:nth-child(even) td { background: #fafafa; }
        .history-date { white-space: nowrap; color: #64748b; font-size: 0.8rem; }
        .history-user { font-weight: 600; color: #334155; white-space: nowrap; }
        .history-action { color: #475569; }

        /* ===== ASSINATURA ===== */
        .signature-section { margin-top: 48px; padding-top: 24px; border-top: 1px solid #e2e8f0; }
        .signature-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 48px; }
        .signature-block { text-align: center; }
        .signature-line { border-top: 1px solid #334155; padding-top: 10px; margin-top: 0; }
        .signature-label { font-size: 0.78rem; font-weight: 600; color: #475569; margin-bottom: 2px; }
        .signature-sublabel { font-size: 0.7rem; color: #94a3b8; }

        /* ===== RODAPÃ‰ ===== */
        .doc-footer {
            background: #f8fafc; border-top: 1px solid #e2e8f0;
            padding: 16px 40px;
            display: flex; justify-content: space-between; align-items: center;
            gap: 16px;
        }
        .doc-footer p { font-size: 0.72rem; color: #94a3b8; }
        .doc-footer .footer-badge {
            background: #e2e8f0; color: #475569;
            padding: 4px 12px; border-radius: 6px;
            font-size: 0.72rem; font-weight: 700; white-space: nowrap;
        }

        /* ===== IMPRESSÃƒO ===== */
        @media print {
            body { background: #fff; }
            .action-bar { display: none !important; }
            .document {
                max-width: 100%; margin: 0;
                border: none; border-radius: 0; box-shadow: none;
            }
            .doc-header { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .status-banner { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .history-table thead tr { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>

<!-- Barra de AÃ§Ã£o (nÃ£o imprime) -->
<div class="action-bar">
    <a href="javascript:history.back()" class="btn-back-link">
        â† Voltar
    </a>
    <h1>PrÃ©-visualizaÃ§Ã£o do RelatÃ³rio â€” RequisiÃ§Ã£o #<?= $req_id ?></h1>
    <button class="btn-print" onclick="window.print()">
        ðŸ–¨ï¸ Imprimir / Salvar PDF
    </button>
</div>

<div class="document">

    <!-- CABEÃ‡ALHO -->
    <div class="doc-header">
        <div class="doc-header-logo">
            <img src="assets/img/LogoFavConSisreq.png" alt="SisReq">
            <div class="doc-header-title">
                <h2>RelatÃ³rio de RequisiÃ§Ã£o</h2>
                <p>Sistema Institucional de RequisiÃ§Ãµes Â· SisReq</p>
            </div>
        </div>
        <div class="doc-header-meta">
            <div class="protocol-label">Protocolo</div>
            <div class="protocol-number">#<?= str_pad($req_id, 4, '0', STR_PAD_LEFT) ?></div>
            <div class="generated-at">Gerado em <?= $geradoEm ?></div>
        </div>
    </div>

    <!-- BANNER DE STATUS -->
    <div class="status-banner">
        <?php $sc = $status_color[$current_status] ?? '#64748b'; ?>
        <span class="status-chip" style="background: <?= $sc ?>18; color: <?= $sc ?>; border: 1px solid <?= $sc ?>44;">
            <span class="dot"></span>
            <?= $status_label[$current_status] ?? 'Indefinido' ?>
        </span>
        <span class="banner-separator">Â·</span>
        <?php $pc = $pri_color[$priority] ?? '#64748b'; ?>
        <span class="priority-chip">
            <span style="width:8px;height:8px;border-radius:50%;background:<?= $pc ?>;display:inline-block;"></span>
            Prioridade <?= $pri_labels[$priority] ?? 'MÃ©dia' ?>
        </span>
        <span class="banner-separator">Â·</span>
        <span class="priority-chip">
            ðŸ“ <?= $label_map[$req_table] ?? ucfirst($req_table) ?>
        </span>
        <?php if (!empty($req['subdivision_name'])): ?>
        <span class="banner-separator">Â·</span>
        <span class="priority-chip">ðŸ« <?= htmlspecialchars($req['subdivision_name']) ?></span>
        <?php endif; ?>
    </div>

    <div class="doc-body">

        <!-- SEÃ‡ÃƒO: DETALHES DA REQUISIÃ‡ÃƒO -->
        <div class="section">
            <div class="section-title">ðŸ“‹ Detalhes da RequisiÃ§Ã£o</div>
            <div class="req-title"><?= htmlspecialchars($req['title'] ?? 'Sem tÃ­tulo') ?></div>
            <div class="description-box"><?= nl2br(htmlspecialchars($req['descp'] ?? 'Nenhuma descriÃ§Ã£o fornecida.')) ?></div>
        </div>

        <!-- SEÃ‡ÃƒO: INFORMAÃ‡Ã•ES GERAIS -->
        <div class="section">
            <div class="section-title">â„¹ï¸ InformaÃ§Ãµes Gerais</div>
            <div class="info-grid">
                <div class="info-cell">
                    <label>Solicitante</label>
                    <span><?= htmlspecialchars($req['creator_name'] ?? 'â€”') ?></span>
                </div>
                <div class="info-cell">
                    <label>Data de Abertura</label>
                    <span><?= isset($req['created_at']) ? date('d/m/Y H:i', strtotime($req['created_at'])) : 'â€”' ?></span>
                </div>
                <div class="info-cell">
                    <label>Prazo de Entrega</label>
                    <span><?= $prazoDisplay ?></span>
                </div>
                <div class="info-cell">
                    <label>Categoria / Setor</label>
                    <span><?= $label_map[$req_table] ?? ucfirst($req_table) ?></span>
                </div>
                <div class="info-cell">
                    <label>SubdivisÃ£o</label>
                    <span><?= htmlspecialchars($req['subdivision_name'] ?? 'N/A') ?></span>
                </div>
                <div class="info-cell">
                    <label>Local / Sala</label>
                    <span><?= htmlspecialchars($req['sala'] ?? 'N/A') ?></span>
                </div>
                <?php if (!empty($req['obs'])): ?>
                <div class="info-cell" style="grid-column: 1 / -1;">
                    <label>ObservaÃ§Ãµes</label>
                    <span><?= htmlspecialchars($req['obs']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- SEÃ‡ÃƒO: HISTÃ“RICO -->
        <?php if (!empty($history)): ?>
        <div class="section">
            <div class="section-title">ðŸ•’ HistÃ³rico de MovimentaÃ§Ãµes <span>(<?= count($history) ?> registros)</span></div>
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Data / Hora</th>
                        <th>UsuÃ¡rio</th>
                        <th>AÃ§Ã£o Registrada</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $h): ?>
                    <tr>
                        <td class="history-date"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
                        <td class="history-user"><?= htmlspecialchars($h['user_name'] ?? 'â€”') ?></td>
                        <td class="history-action"><?= htmlspecialchars($h['action'] ?? 'â€”') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ASSINATURA -->
        <div class="signature-section">
            <p style="font-size:0.78rem; color:#94a3b8; margin-bottom: 8px;">Para fins de protocolo institucional, as partes abaixo confirmam o atendimento desta requisiÃ§Ã£o:</p>
            <div class="signature-grid">
                <div class="signature-block">
                    <div style="height: 48px;"></div>
                    <div class="signature-line">
                        <div class="signature-label"><?= htmlspecialchars($req['creator_name'] ?? 'Solicitante') ?></div>
                        <div class="signature-sublabel">Assinatura do Solicitante</div>
                    </div>
                </div>
                <div class="signature-block">
                    <div style="height: 48px;"></div>
                    <div class="signature-line">
                        <div class="signature-label">ResponsÃ¡vel pelo Atendimento</div>
                        <div class="signature-sublabel">Assinatura e Carimbo</div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /doc-body -->

    <!-- RODAPÃ‰ -->
    <div class="doc-footer">
        <p>Documento gerado automaticamente pelo <strong>SisReq</strong>.<br>
        A autenticidade deste relatÃ³rio Ã© garantida pelo Protocolo #<?= $req_id ?> e pela data de geraÃ§Ã£o registrada.</p>
        <span class="footer-badge">SisReq Â· <?= date('Y') ?></span>
    </div>

</div><!-- /document -->

</body>
</html>

