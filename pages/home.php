<?php
// pages/home.php - DASHBOARD ADMINISTRATIVO

$map = [
    'mkt' => 'MKT',
    'xerox' => 'Reprografia',
    'shop' => 'Compras',
    'service' => 'Manutenção',
    'ti' => 'TI',
];

$role = $_SESSION['role'] ?? 'view';
$user_id = $_SESSION['id'];

// Definição de perfis com permissão de gestão
$adminRoles = ['admin', 'adm', 'coord'];
$gestorRoles = ['gestor', 'ti', 'xerox', 'service', 'shop', 'mkt', 'marketing'];

$isAdmin = in_array($role, $adminRoles);
$isAdmSub = ($role === 'adm_sub');
$isGestor = in_array($role, $gestorRoles);
$canManage = $isAdmin || $isGestor || $isAdmSub;

if (!$canManage) {
    echo "<script>location.href='myRequests';</script>";
    exit;
}

require_once __DIR__ . '/../classes/RequestManager.php';

// Pegar setores permitidos para este usuário
$allowedSectors = [];
if (!$isAdmin && !$isAdmSub) {
    $stmtSectors = $pdo->prepare("SELECT a.title FROM ctd_area a JOIN cfg_user_area cua ON a.id = cua.id_area WHERE cua.id_user = ?");
    $stmtSectors->execute([$user_id]);
    $allowedSectors = array_values(array_filter(array_map('areaTitleToSector', $stmtSectors->fetchAll(PDO::FETCH_COLUMN))));
    
    // Filtrar o $map para mostrar apenas os setores permitidos no dropdown
    $filteredMap = [];
    foreach ($map as $key => $label) {
        if (in_array(strtolower($key), $allowedSectors)) {
            $filteredMap[$key] = $label;
        }
    }
    $map = $filteredMap;
}

$managingId = ($isAdmin || $isAdmSub) ? null : $user_id;
$activeFilterStatus = isset($_GET['status']) && $_GET['status'] !== '' ? $_GET['status'] : null;

$tableTitle = 'Atividade Recente';
if ($activeFilterStatus === 'C') $tableTitle = 'Requisições Concluídas';
elseif ($activeFilterStatus === 'P') $tableTitle = 'Requisições Pendentes';
elseif ($activeFilterStatus === 'Y') $tableTitle = 'Requisições Aprovadas';
elseif ($activeFilterStatus === 'W') $tableTitle = 'Requisições em Atendimento';
elseif ($activeFilterStatus === 'N') $tableTitle = 'Requisições Recusadas';

// REGRA: Gestor só vê requisições aprovadas (Y), em andamento (W) e concluídas (C)
$excludeStatuses = [];
if ($isGestor) {
    $excludeStatuses = ['P', 'N'];
}

// Filtro de subdivisão para adm_sub
$subdivisionFilter = $isAdmSub ? ($_SESSION['subdivision_ids'] ?? null) : null;

$data = RequestManager::getRequests($pdo, null, $managingId, $activeFilterStatus ?: null, null, null, 'DESC', $excludeStatuses, null, null, $subdivisionFilter);
$all_requests = $data['requests'];
$stats = $data['stats'];

extract($stats); // Extracts variables like $total, $pendentes, $emAndamento, $repassadas, etc.

// Ajustar contagem para incluir repasses
if ($isAdmin || $isAdmSub) {
    // Admin vê todas as Repassadas (F - que são pendentes) no seu escopo como "Aprovadas" (Aguardando Aceite)
    $aprovadas += ($repassadas ?? 0);
} else {
    require_once __DIR__ . '/../classes/RequestForwardService.php';
    
    // Repasses pendentes (aguardando aceite do gestor) vão para Aprovadas
    $pendingFwds = RequestForwardService::getPendingForwards($pdo, $user_id, 'pending');
    if ($pendingFwds['success']) {
        $aprovadas += count($pendingFwds['data']);
    }
    
    // Repasses aceitos (em atendimento pelo gestor) vão para Em Andamento
    $acceptedFwds = RequestForwardService::getPendingForwards($pdo, $user_id, 'accepted');
    if ($acceptedFwds['success']) {
        $emAndamento += count($acceptedFwds['data']);
    }
}

$recent_requests = $all_requests; // Mostrar todas para permitir filtro completo no Dashboard

// Buscar notificações não lidas
$unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
$unread_stmt->execute([$_SESSION['id'] ?? 0]);
$unread_count = $unread_stmt->fetchColumn();

// Status stepper labels
$stepperMap = ['P' => 0, 'Y' => 1, 'W' => 2, 'C' => 3, 'N' => -1];
?>
<style>
/* === DASHBOARD COMPACTO === */
.db-kpi-strip{display:flex;align-items:center;background:#fff;border:1px solid var(--border-color);border-radius:10px;padding:10px 16px;margin-bottom:18px;gap:0;overflow-x:auto;}
.db-kpi{display:flex;align-items:center;gap:10px;flex:1;min-width:110px;text-decoration:none;color:inherit;padding:4px 14px;}
.db-kpi--active .db-kpi__val{color:var(--primary);}
.db-kpi__icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:0.82rem;flex-shrink:0;}
.db-kpi__val{font-size:1.3rem;font-weight:800;color:var(--text-main);line-height:1;}
.db-kpi__lbl{font-size:0.65rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:0.4px;margin-top:1px;}
.db-kpi__bar{height:3px;background:#f1f5f9;border-radius:2px;margin-top:4px;width:80px;}
.db-kpi__bar-fill{height:3px;background:#15803d;border-radius:2px;}
.db-kpi__sep{width:1px;height:32px;background:var(--border-color);flex-shrink:0;margin:0 2px;}
.db-charts{display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:16px;margin-bottom:20px;}
.db-chart-box{background:#fff;border:1px solid var(--border-color);border-radius:10px;padding:16px;display:flex;flex-direction:column;gap:10px;min-width:0;}
.db-chart-head{font-size:0.76rem;font-weight:700;color:var(--text-main);display:flex;align-items:center;gap:7px;border-bottom:1px solid var(--border-color);padding-bottom:10px;}
.db-canvas-wrap{position:relative;height:160px;width:100%;}
.db-donut-center{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;pointer-events:none;}
.db-donut-center__val{font-size:1.5rem;font-weight:800;color:var(--text-main);line-height:1;}
.db-donut-center__lbl{font-size:0.6rem;color:var(--text-muted);font-weight:600;text-transform:uppercase;}
.db-legend{display:flex;flex-wrap:wrap;gap:4px 10px;}
.db-legend__item{display:flex;align-items:center;gap:5px;font-size:0.67rem;color:var(--text-muted);font-weight:600;}
.db-legend__dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.db-health{display:flex;flex-direction:column;gap:9px;flex:1;justify-content:center;}
.db-health__row{display:grid;grid-template-columns:85px 1fr 28px;align-items:center;gap:8px;}
.db-health__lbl{font-size:0.7rem;color:var(--text-muted);font-weight:600;white-space:nowrap;}
.db-health__bar{height:5px;background:#f1f5f9;border-radius:4px;overflow:hidden;}
.db-health__fill{height:5px;border-radius:4px;}
.db-health__num{font-size:0.72rem;font-weight:800;text-align:right;}
@media(max-width:900px){.db-charts{grid-template-columns:1fr;}.db-kpi__sep{display:none;}.db-kpi-strip{flex-wrap:wrap;}}
</style>
<div class="main">

    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; width:100%; flex-wrap:wrap; gap:15px;">
        <div style="display:flex; align-items:center; gap: 15px;">
            <i class="fa-solid fa-chart-pie"></i>
            <div>
                <h3 style="margin: 0;">Dashboard</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin: 2px 0 0;">
                    <?php if ($isGestor): ?>
                        Visualizando requisições aprovadas dos seus setores
                    <?php else: ?>
                        Visão geral de todas as requisições
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div style="display:flex; align-items:center; gap: 20px;">
            <a href="new_request" class="btn-primary"><i class="fas fa-plus"></i> Nova Requisição</a>
        </div>
    </div>

    <?php if (!$activeFilterStatus): ?>
    <!-- Pipeline Visual (Fluxo) -->
    <div class="flow-pipeline">
        <?php if ($isAdmin): ?>
        <a href="home?status=P" class="flow-step <?= $activeFilterStatus === 'P' ? 'active' : '' ?>">
            <div class="flow-icon pending-bg"><i class="fa-solid fa-clock"></i></div>
            <div class="flow-label">Pendentes</div>
            <div class="flow-count"><?= $pendentes ?></div>
        </a>
        <div class="flow-arrow"><i class="fa-solid fa-arrow-right"></i></div>
        <?php endif; ?>
        <a href="home?status=Y" class="flow-step <?= $activeFilterStatus === 'Y' ? 'active' : '' ?>">
            <div class="flow-icon approved-bg"><i class="fa-solid fa-check-circle"></i></div>
            <div class="flow-label">Aprovadas</div>
            <div class="flow-count"><?= $aprovadas ?></div>
        </a>
        <div class="flow-arrow"><i class="fa-solid fa-arrow-right"></i></div>
        <a href="home?status=W" class="flow-step <?= $activeFilterStatus === 'W' ? 'active' : '' ?>">
            <div class="flow-icon progress-bg"><i class="fa-solid fa-gears"></i></div>
            <div class="flow-label">Em Andamento</div>
            <div class="flow-count"><?= $emAndamento ?></div>
        </a>
        <div class="flow-arrow"><i class="fa-solid fa-arrow-right"></i></div>
        <a href="home?status=C" class="flow-step <?= $activeFilterStatus === 'C' ? 'active' : '' ?>">
            <div class="flow-icon completed-bg"><i class="fa-solid fa-check-double"></i></div>
            <div class="flow-label">Concluídas</div>
            <div class="flow-count"><?= $concluidas ?></div>
        </a>
        <?php if ($isAdmin): ?>
        <div class="flow-divider"></div>
        <a href="home?status=N" class="flow-step <?= $activeFilterStatus === 'N' ? 'active' : '' ?>">
            <div class="flow-icon rejected-bg"><i class="fa-solid fa-circle-xmark"></i></div>
            <div class="flow-label">Recusadas</div>
            <div class="flow-count"><?= $recusadas ?></div>
        </a>
        <?php endif; ?>
    </div>

    <?php $taxa = ($total > 0) ? round(($concluidas / $total) * 100) : 0; ?>
    <!-- KPI Strip Compacto -->
    <div class="db-kpi-strip">
        <a href="home" class="db-kpi <?= !$activeFilterStatus ? 'db-kpi--active' : '' ?>">
            <span class="db-kpi__icon" style="background:#eff6ff;color:#2563eb"><i class="fa-solid fa-list-check"></i></span>
            <div><div class="db-kpi__val"><?= $total ?></div><div class="db-kpi__lbl">Total</div></div>
        </a>
        <div class="db-kpi__sep"></div>
        <div class="db-kpi"><span class="db-kpi__icon" style="background:#fffbeb;color:#d97706"><i class="fa-solid fa-fire-flame-curved"></i></span><div><div class="db-kpi__val"><?= $urgentes ?></div><div class="db-kpi__lbl">Urgentes</div></div></div>
        <div class="db-kpi__sep"></div>
        <div class="db-kpi"><span class="db-kpi__icon" style="background:#fef2f2;color:#dc2626"><i class="fa-solid fa-fire"></i></span><div><div class="db-kpi__val"><?= $criticas ?></div><div class="db-kpi__lbl">Críticas</div></div></div>
        <div class="db-kpi__sep"></div>
        <div class="db-kpi"><span class="db-kpi__icon" style="background:#fff1f2;color:#be123c"><i class="fa-solid fa-hourglass-end"></i></span><div><div class="db-kpi__val"><?= $atrasadas ?></div><div class="db-kpi__lbl">Atrasadas</div></div></div>
        <div class="db-kpi__sep"></div>
        <div class="db-kpi"><span class="db-kpi__icon" style="background:#f0fdf4;color:#15803d"><i class="fa-solid fa-chart-line"></i></span>
            <div><div class="db-kpi__val"><?= $taxa ?>%</div><div class="db-kpi__lbl">Taxa Conclusão</div>
            <div class="db-kpi__bar"><div class="db-kpi__bar-fill" style="width:<?= $taxa ?>%"></div></div></div>
        </div>
    </div>

    <!-- Gráficos 3 colunas -->
    <div class="db-charts">
        <div class="db-chart-box">
            <div class="db-chart-head"><i class="fa-solid fa-circle-half-stroke" style="color:var(--primary)"></i> Status</div>
            <div class="db-canvas-wrap">
                <canvas id="statusChart"></canvas>
                <div class="db-donut-center"><div class="db-donut-center__val"><?= $total ?></div><div class="db-donut-center__lbl">Total</div></div>
            </div>
            <div class="db-legend" id="statusLegend"></div>
        </div>
        <div class="db-chart-box">
            <div class="db-chart-head"><i class="fa-solid fa-layer-group" style="color:var(--primary)"></i> Por Setor</div>
            <div class="db-canvas-wrap"><canvas id="categoryChart"></canvas></div>
        </div>
        <div class="db-chart-box">
            <div class="db-chart-head"><i class="fa-solid fa-gauge-high" style="color:var(--primary)"></i> Saúde do Fluxo</div>
            <div class="db-health">
                <?php
                $hi = [
                    ['Pendentes',$pendentes,'#eab308'],['Aprovadas',$aprovadas,'#10b981'],
                    ['Em Andamento',$emAndamento,'#3b82f6'],['Concluídas',$concluidas,'#15803d'],
                    ['Recusadas',$recusadas,'#ef4444'],['Atrasadas',$atrasadas,'#be123c'],
                ];
                foreach($hi as $h):
                    $p = $total>0 ? round($h[1]/$total*100) : 0;
                ?>
                <div class="db-health__row">
                    <div class="db-health__lbl"><?= $h[0] ?></div>
                    <div class="db-health__bar"><div class="db-health__fill" style="width:<?= $p ?>%;background:<?= $h[2] ?>"></div></div>
                    <div class="db-health__num" style="color:<?= $h[2] ?>"><?= $h[1] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabela Principal Unificada (Recentes) -->
    <div class="dashboard-table-container">
        <div class="table-header" style="flex-direction: column; align-items: stretch; gap: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h4><?= $tableTitle ?></h4>
                <button onclick="exportToExcel()" class="btn-primary" title="Exportar dados atuais para CSV (Excel)" style="background: #f0fdf4; color: #15803d; border: 1px solid #86efac; padding: 8px 16px; font-size: 0.85rem; font-weight: 600; box-shadow: none; border-radius: 8px; display:inline-flex; align-items:center; gap:7px;">
                    <i class="fa-solid fa-file-csv"></i> Exportar Dados
                </button>
            </div>
            
            <div style="display: flex; gap: 24px; align-items: center; flex-wrap: wrap; padding-bottom: 4px; width: 100%;">
                <!-- Busca -->
                <div style="flex: 1; min-width: 250px; background: #fff; border: 1px solid var(--border-color); border-radius: 8px; padding: 8px 14px; display: flex; align-items: center; gap: 10px; box-shadow: var(--shadow-sm);">
                    <i class="fas fa-search" style="color: var(--text-muted); font-size: 0.9rem;"></i>
                    <input type="text" placeholder="Buscar requisição..." id="dashSearch" style="border: none; outline: none; width: 100%; background: transparent; font-family: var(--font-sans); color: var(--text-main); font-size: 0.9rem;">
                </div>
                
                <!-- Filtro de Data -->
                <div style="display: flex; flex-direction: column; gap: 4px;">
                    <span style="font-size: 0.7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-left: 2px;">Data</span>
                    <input type="date" id="dateFilter" title="Filtrar por data" style="padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border-color); outline: none; background: #fff; color: var(--text-muted); font-family: var(--font-sans); font-size: 0.85rem; cursor: pointer; width: 140px; height: 32px; box-shadow: var(--shadow-sm);">
                </div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="dash-table">
                <thead>
                    <tr>
                        <th>Detalhes</th>
                        <th>Solicitante</th>
                        <th>Classificação</th>
                        <th>Prazo</th>
                        <th>Progresso</th>
                        <th style="text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($recent_requests) > 0): ?>
                        <?php foreach($recent_requests as $req): ?>
                            <?php
                                // Formatação de data de criação
                                $createdTs   = isset($req['created_at']) ? strtotime($req['created_at']) : 0;
                                $createdFmt  = $createdTs ? date('d/m/Y', $createdTs) : '—';

                                // Formatação robusta do prazo (date)
                                $rawDate     = $req['date'] ?? '';
                                $hasPrazo    = (!empty($rawDate) && $rawDate !== '0000-00-00');
                                $prazoTs     = $hasPrazo ? strtotime($rawDate) : 0;
                                $prazoFmt    = ($prazoTs && $prazoTs > 0) ? date('d/m/Y', $prazoTs) : null;

                                // Determina se está atrasada com base na data de prazo
                                $isAtrasada  = $req['is_atrasada'] ?? false;
                                $isVencHoje  = ($prazoTs && date('Y-m-d', $prazoTs) === date('Y-m-d'));

                                // Status atual
                                $st          = strtoupper($req['status'] ?? 'P');
                                $stepIndex   = $stepperMap[$st] ?? 0;
                                $isRejected  = ($st === 'N');
                                $steps = [
                                    ['label' => 'Pendente',  'icon' => 'fa-clock'],
                                    ['label' => 'Aprovada',  'icon' => 'fa-check'],
                                    ['label' => 'Atendendo', 'icon' => 'fa-gears'],
                                    ['label' => 'Concluída', 'icon' => 'fa-check-double'],
                                ];
                                $pri = $req['priority'] ?? 2;
                            ?>
                            <tr>
                                <!-- COLUNA: Detalhes -->
                                <td style="min-width: 200px;">
                                    <div style="font-weight:600; color:var(--text-main); line-height:1.4; margin-bottom:4px;">
                                        <?= htmlspecialchars($req['title']) ?>
                                    </div>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <span style="font-size:0.75rem; color:var(--text-muted); background:#f1f5f9; padding:1px 7px; border-radius:5px; font-weight:600;">#<?= $req['id'] ?></span>
                                        <?php if (!empty($req['is_forwarded'])): ?>
                                            <span style="font-size:0.68rem; background:#fef3c7; color:#d97706; padding:1px 7px; border-radius:5px; font-weight:700; border:1px solid #fde68a;">
                                                <i class="fa-solid fa-share-nodes"></i> Repassada
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- COLUNA: Solicitante -->
                                <td style="white-space: nowrap;">
                                    <div style="font-weight:500; color:var(--text-sidebar); margin-bottom:4px;">
                                        <?= htmlspecialchars($req['solicitor_name_formatted']) ?>
                                    </div>
                                    <div style="font-size:0.78rem; color:var(--text-muted);">
                                        <i class="fa-regular fa-calendar" style="margin-right:4px;"></i><?= $createdFmt ?>
                                    </div>
                                </td>

                                <!-- COLUNA: Classificação -->
                                <td>
                                    <div style="display:flex; flex-direction:column; gap:5px; align-items:flex-start;">
                                        <span class="badge-cat badge-<?= $req['table'] ?>"><?= $map[$req['table']] ?? 'Outros' ?></span>
                                        <?php if (!empty($req['subdivision_name'])): ?>
                                            <span style="font-size:0.68rem; background:#e0e7ff; color:#4338ca; padding:2px 7px; border-radius:5px; font-weight:700; border:1px solid #c7d2fe; white-space:nowrap;">
                                                <?= htmlspecialchars($req['subdivision_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php
                                            if     ($pri == 4) echo '<span class="badge-status urgent" style="background:#fef2f2;color:#dc2626;"><i class="fa-solid fa-fire"></i> Crítica</span>';
                                            elseif ($pri == 3) echo '<span class="badge-status urgent" style="background:#fff7ed;color:#ea580c;"><i class="fa-solid fa-angles-up"></i> Alta</span>';
                                            elseif ($pri == 2) echo '<span class="badge-status pending"><i class="fa-solid fa-angle-up"></i> Média</span>';
                                            else               echo '<span class="badge-status normal"><i class="fa-solid fa-angle-down"></i> Baixa</span>';
                                        ?>
                                    </div>
                                </td>

                                <!-- COLUNA: Prazo -->
                                <td style="white-space: nowrap;">
                                    <?= getPrazoBadge($rawDate, $st) ?>
                                </td>

                                <!-- COLUNA: Progresso (Mini-Stepper) -->
                                <td>
                                    <?php if ($isRejected): ?>
                                        <span class="badge-status rejected"><i class="fa-solid fa-circle-xmark"></i> Recusada</span>
                                    <?php else: ?>
                                        <div class="mini-stepper">
                                            <?php foreach ($steps as $i => $step): ?>
                                                <div class="mini-step <?= $i <= $stepIndex ? 'active' : '' ?> <?= $i === $stepIndex ? 'current' : '' ?>">
                                                    <div class="mini-step-dot" title="<?= $step['label'] ?>"><i class="fa-solid <?= $step['icon'] ?>"></i></div>
                                                    <?php if ($i < count($steps) - 1): ?>
                                                        <div class="mini-step-line <?= $i < $stepIndex ? 'filled' : '' ?>"></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div style="font-size:0.68rem; color:var(--text-muted); margin-top:5px; text-align:center; font-weight:500;">
                                            <?= $steps[$stepIndex]['label'] ?? '' ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- COLUNA: Ações -->
                                <td style="text-align: right;">
                                    <div style="display:flex; gap:6px; justify-content:flex-end;">
                                        <a href="request_detail?id=<?= $req['id'] ?>&table=<?= $req['table'] ?>" class="btn-action btn-view" title="Ver detalhes">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (($st === 'Y' || $st === 'A') && $canManage): ?>
                                            <button onclick="handleRequestAction('start_progress', '<?= $req['id'] ?>', 'ctd_<?= $req['table'] ?>_frm')" class="btn-action btn-start" title="Iniciar atendimento">
                                                <i class="fa-solid fa-play"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($st === 'W' && $canManage): ?>
                                            <button onclick="handleRequestAction('finish', '<?= $req['id'] ?>', 'ctd_<?= $req['table'] ?>_frm')" class="btn-action btn-finish" title="Marcar como concluída">
                                                <i class="fa-solid fa-check-double"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="padding: 56px 20px;">
                                <div style="display:flex; flex-direction:column; align-items:center; gap:12px; color:var(--text-muted);">
                                    <div style="width:56px; height:56px; background:#f1f5f9; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:1.6rem; color:#cbd5e1;">
                                        <i class="fa-solid fa-inbox"></i>
                                    </div>
                                    <p style="font-weight:600; font-size:0.95rem; margin:0;">Nenhuma atividade encontrada</p>
                                    <p style="font-size:0.82rem; margin:0;">Tente ajustar os filtros ou aguarde novas requisições.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php if (!$activeFilterStatus): ?>
const statusLabels = ['Pendentes','Aprovadas','Recusadas','Em Andamento','Concluídas'];
const statusColors = ['#eab308','#10b981','#ef4444','#3b82f6','#15803d'];
const statusData   = [<?= $statusCounts['P']?>,<?= $statusCounts['Y']?>,<?= $statusCounts['N']?>,<?= $statusCounts['W']?>,<?= $statusCounts['C']?>];
const catLabels    = <?= json_encode(array_map(fn($k)=>$map[$k]??$k, array_keys($categoryCounts))) ?>;
const catData      = <?= json_encode(array_values($categoryCounts)) ?>;
const catColors    = ['#8b5cf6','#10b981','#3b82f6','#eab308','#f97316'];

// Donut de Status
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: { labels: statusLabels, datasets: [{ data: statusData, backgroundColor: statusColors, borderWidth: 2, borderColor: '#fff', hoverOffset: 6 }] },
    options: {
        responsive: true, maintainAspectRatio: false, cutout: '68%',
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.parsed}` } }
        },
        animation: { animateRotate: true, duration: 700 }
    }
});

// Build legend
const leg = document.getElementById('statusLegend');
statusLabels.forEach((l,i) => {
    if (!statusData[i]) return;
    leg.innerHTML += `<div class="db-legend__item"><span class="db-legend__dot" style="background:${statusColors[i]}"></span>${l} <strong>${statusData[i]}</strong></div>`;
});

// Bar por Setor
new Chart(document.getElementById('categoryChart'), {
    type: 'bar',
    data: { labels: catLabels, datasets: [{ data: catData, backgroundColor: catColors.slice(0,catData.length), borderRadius: 6, borderSkipped: false }] },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ` ${ctx.parsed.y} requisições` } } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 11, weight: '600' } } },
            y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: '#f1f5f9' } }
        },
        animation: { duration: 700 }
    }
});
<?php endif; ?>

// Table Filters
const dashSearch = document.getElementById('dashSearch');
const dateFilter = document.getElementById('dateFilter');

function filterTable() {
    const term = dashSearch.value.toLowerCase();
    const filterDate = dateFilter.value;
    const rows = document.querySelectorAll('.dash-table tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        
        // Date check
        let matchesDate = true;
        if (filterDate) {
            const dateStrMatches = row.cells[1]?.textContent.match(/(\d{2})\/(\d{2})\/(\d{4})/);
            if (dateStrMatches) {
                const rowDateISO = `${dateStrMatches[3]}-${dateStrMatches[2]}-${dateStrMatches[1]}`;
                if (rowDateISO !== filterDate) matchesDate = false;
            }
        }

        row.style.display = (text.includes(term) && matchesDate) ? '' : 'none';
    });
}

dashSearch.addEventListener('input', filterTable);
if(dateFilter) dateFilter.addEventListener('change', filterTable);

function exportToExcel() {
    const term = document.getElementById('dashSearch').value;
    const date = document.getElementById('dateFilter').value;
    const status = '<?= $activeFilterStatus ?>';
    
    const params = new URLSearchParams({
        search: term,
        status: status,
        date: date
    });
    
    window.location.href = `api/export_requests.php?${params.toString()}`;
}

function handleRequestAction(action, id, table) {
    // Agora chama a função global para aproveitar as confirmações e lógica centralizada
    globalRequestAction(action, id, table);
}
</script>
