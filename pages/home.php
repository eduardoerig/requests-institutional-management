<?php
// pages/home.php - DASHBOARD ADMINISTRATIVO

$map = [
    'mkt' => 'MKT',
    'xerox' => 'Xerox',
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
if (!$isAdmin) {
    $stmtSectors = $pdo->prepare("SELECT LOWER(a.title) FROM ctd_area a JOIN cfg_user_area cua ON a.id = cua.id_area WHERE cua.id_user = ?");
    $stmtSectors->execute([$user_id]);
    $allowedSectors = $stmtSectors->fetchAll(PDO::FETCH_COLUMN);
    
    // Filtrar o $map para mostrar apenas os setores permitidos no dropdown
    $filteredMap = [];
    foreach ($map as $key => $label) {
        if (in_array(strtolower($key), $allowedSectors)) {
            $filteredMap[$key] = $label;
        }
    }
    $map = $filteredMap;
}

$managingId = $isAdmin ? null : $user_id;
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

// Ajustar contagem de "Em Andamento" para incluir repasses
if ($isAdmin || $isAdmSub) {
    // Admin vê todas as Repassadas do seu escopo como "Em Andamento"
    $emAndamento += ($repassadas ?? 0);
} else {
    // Gestor: O getRequests já trouxe as Repassadas OUT (status F na tabela dele) em $repassadas
    // Mas o badge "Em Andamento" do gestor deve ser: W + Repassadas recebidas
    // Então ignoramos o $repassadas (out) e buscamos os recebidos
    require_once __DIR__ . '/../classes/RequestForwardService.php';
    $pendingFwds = RequestForwardService::getPendingForwards($pdo, $user_id);
    if ($pendingFwds['success']) {
        $emAndamento += count($pendingFwds['data']);
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
<div class="main">
    <div class="page-header" style="display:flex; justify-content:space-between; align-items:center; width:100%;">
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
        <div class="flow-step">
            <div class="flow-icon pending-bg"><i class="fa-solid fa-clock"></i></div>
            <div class="flow-label">Pendentes</div>
            <div class="flow-count"><?= $pendentes ?></div>
        </div>
        <div class="flow-arrow"><i class="fa-solid fa-arrow-right"></i></div>
        <?php endif; ?>
        <div class="flow-step">
            <div class="flow-icon approved-bg"><i class="fa-solid fa-check-circle"></i></div>
            <div class="flow-label">Aprovadas</div>
            <div class="flow-count"><?= $aprovadas ?></div>
        </div>
        <div class="flow-arrow"><i class="fa-solid fa-arrow-right"></i></div>
        <div class="flow-step">
            <div class="flow-icon progress-bg"><i class="fa-solid fa-gears"></i></div>
            <div class="flow-label">Em Andamento</div>
            <div class="flow-count"><?= $emAndamento ?></div>
        </div>
        <div class="flow-arrow"><i class="fa-solid fa-arrow-right"></i></div>
        <div class="flow-step">
            <div class="flow-icon completed-bg"><i class="fa-solid fa-check-double"></i></div>
            <div class="flow-label">Concluídas</div>
            <div class="flow-count"><?= $concluidas ?></div>
        </div>
        <?php if ($isAdmin): ?>
        <div class="flow-divider"></div>
        <div class="flow-step">
            <div class="flow-icon rejected-bg"><i class="fa-solid fa-circle-xmark"></i></div>
            <div class="flow-label">Recusadas</div>
            <div class="flow-count"><?= $recusadas ?></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Cards de Resumo Secundários -->
    <div class="dashboard-cards" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 30px;">
        <div class="dash-card">
            <div class="dash-icon" style="background: rgba(37, 99, 235, 0.1); color: #2563eb;"><i class="fa-solid fa-list-check"></i></div>
            <div class="dash-info">
                <h4>Total</h4>
                <h2><?php echo $total; ?></h2>
            </div>
        </div>
        <div class="dash-card">
            <div class="dash-icon" style="background: rgba(245, 158, 11, 0.1); color: #f59e0b;"><i class="fa-solid fa-fire-flame-curved"></i></div>
            <div class="dash-info">
                <h4>Urgentes</h4>
                <h2><?php echo $urgentes; ?></h2>
            </div>
        </div>
        <div class="dash-card">
            <div class="dash-icon" style="background: #fef2f2; color: #dc2626;"><i class="fa-solid fa-fire-extinguisher"></i></div>
            <div class="dash-info">
                <h4>Críticas</h4>
                <h2><?php echo $criticas; ?></h2>
            </div>
        </div>
        <div class="dash-card">
            <div class="dash-icon" style="background: #fff1f2; color: #be123c;"><i class="fa-solid fa-hourglass-end"></i></div>
            <div class="dash-info">
                <h4>Atrasadas</h4>
                <h2><?php echo $atrasadas; ?></h2>
            </div>
        </div>
    </div>

    <!-- Gráficos -->
    <div class="dashboard-charts">
        <div class="chart-container">
            <h4>Requisições por Status</h4>
            <canvas id="statusChart"></canvas>
        </div>
        <div class="chart-container">
            <h4>Requisições por Categoria</h4>
            <canvas id="categoryChart"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabela Principal Unificada (Recentes) -->
    <div class="dashboard-table-container">
        <div class="table-header" style="flex-direction: column; align-items: stretch; gap: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h4><?= $tableTitle ?></h4>
                <button onclick="exportToExcel()" class="btn-primary" style="background: #fff; color: #15803d; border: 1px solid #bbf7d0; padding: 6px 12px; font-size: 0.85rem; font-weight: 500; box-shadow: none;">
                    <i class="fa-solid fa-file-excel" style="margin-right: 6px;"></i> Exportar
                </button>
            </div>
            
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: nowrap; overflow-x: auto; padding-bottom: 4px; width: 100%;">
                <div style="flex-grow: 1; flex-shrink: 1; min-width: 250px; background: transparent; border: 1px solid var(--border-color); border-radius: 6px; padding: 6px 12px; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-search" style="color: var(--text-muted); font-size: 0.85rem;"></i>
                    <input type="text" placeholder="Buscar requisição..." id="dashSearch" style="border: none; outline: none; width: 100%; background: transparent; font-family: var(--font-sans); color: var(--text-main); font-size: 0.85rem;">
                </div>
                
                <select id="sectorFilter" style="flex-shrink: 0; width: auto; padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border-color); outline: none; background: transparent; color: var(--text-muted); font-family: var(--font-sans); font-size: 0.85rem; cursor: pointer; white-space: nowrap;">
                    <option value="">Setor: Todos</option>
                    <?php foreach($map as $key => $label): ?>
                        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                
                <select id="statusFilter" style="flex-shrink: 0; width: auto; padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border-color); outline: none; background: transparent; color: var(--text-muted); font-family: var(--font-sans); font-size: 0.85rem; cursor: pointer; white-space: nowrap;">
                    <option value="">Status: Todos</option>
                    <?php if ($isAdmin): ?><option value="P" <?= $activeFilterStatus === 'P' ? 'selected' : '' ?>>Pendente</option><?php endif; ?>
                    <option value="Y" <?= $activeFilterStatus === 'Y' ? 'selected' : '' ?>>Aprovada</option>
                    <option value="W" <?= $activeFilterStatus === 'W' ? 'selected' : '' ?>>Em Andamento</option>
                    <option value="C" <?= $activeFilterStatus === 'C' ? 'selected' : '' ?>>Concluída</option>
                    <?php if ($isAdmin || $isGestor): ?>
                        <?php if ($isAdmin): ?>
                            <option value="N" <?= $activeFilterStatus === 'N' ? 'selected' : '' ?>>Recusada</option>
                        <?php endif; ?>
                    <?php endif; ?>
                </select>
                
                <div style="position: relative; flex-shrink: 0;">
                    <input type="date" id="dateFilter" title="Filtrar por data" style="padding: 6px 12px; border-radius: 6px; border: 1px solid var(--border-color); outline: none; background: transparent; color: var(--text-muted); font-family: var(--font-sans); font-size: 0.85rem; cursor: pointer; width: 140px;">
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
                        <th>Progresso</th>
                        <th style="text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($recent_requests) > 0): ?>
                        <?php foreach($recent_requests as $req): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600; color:var(--text-main); margin-bottom: 4px;"><?php echo htmlspecialchars($req['title']); ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);">#<?php echo $req['id']; ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:500; color:var(--text-sidebar); margin-bottom: 4px;"><?php echo htmlspecialchars($req['solicitor_name_formatted']); ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);"><i class="fa-regular fa-calendar" style="margin-right:4px;"></i><?php echo date('d/m/Y', strtotime($req['created_at'])); ?></div>
                                </td>
                                <td>
                                    <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-start;">
                                        <span class="badge-cat badge-<?php echo $req['table']; ?>"><?php echo $map[$req['table']] ?? 'Outros'; ?></span>
                                        <?php if (!empty($req['subdivision_name'])): ?>
                                            <span class="badge-subdivision badge-sub-<?= $req['subdivision_slug'] ?? 'default' ?>"><?= htmlspecialchars($req['subdivision_name']) ?></span>
                                        <?php endif; ?>
                                        <?php 
                                            $pri = $req['priority'] ?? 2;
                                            if($pri == 4) echo '<span class="badge-status urgent" style="background:#fef2f2; color:#dc2626;"><i class="fa-solid fa-fire-extinguisher"></i> Crítica</span>';
                                            elseif($pri == 3) echo '<span class="badge-status urgent" style="background:#fff7ed; color:#ea580c;"><i class="fa-solid fa-angles-up"></i> Alta</span>';
                                            elseif($pri == 2) echo '<span class="badge-status pending"><i class="fa-solid fa-angle-up"></i> Média</span>';
                                            elseif($pri == 1) echo '<span class="badge-status normal"><i class="fa-solid fa-angle-down"></i> Baixa</span>';
                                        ?>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                        $st = strtoupper($req['status']);
                                        $stepIndex = $stepperMap[$st] ?? 0;
                                        $isRejected = ($st === 'N');
                                        
                                        $steps = [
                                            ['label' => 'Pendente', 'icon' => 'fa-clock'],
                                            ['label' => 'Aprovada', 'icon' => 'fa-check'],
                                            ['label' => 'Atendendo', 'icon' => 'fa-gears'],
                                            ['label' => 'Concluída', 'icon' => 'fa-check-double'],
                                        ];
                                    ?>
                                    <?php if ($isRejected): ?>
                                        <span class="badge-status rejected"><i class="fa-solid fa-circle-xmark"></i> Recusada</span>
                                    <?php else: ?>
                                        <div class="mini-stepper">
                                            <?php foreach ($steps as $i => $step): ?>
                                                <div class="mini-step <?= $i <= $stepIndex ? 'active' : '' ?> <?= $i === $stepIndex ? 'current' : '' ?>">
                                                    <div class="mini-step-dot"><i class="fa-solid <?= $step['icon'] ?>"></i></div>
                                                    <?php if ($i < count($steps) - 1): ?>
                                                        <div class="mini-step-line <?= $i < $stepIndex ? 'filled' : '' ?>"></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 4px; text-align: center;">
                                            <?= $steps[$stepIndex]['label'] ?? '' ?>
                                            <?php if($req['is_atrasada']): ?>
                                                <span style="color: #be123c; font-weight:600;"> · Atrasada</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                        <a href="request_detail?id=<?php echo $req['id']; ?>&table=<?php echo $req['table']; ?>" class="btn-action btn-view" title="Ver detalhes">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if (($st == 'Y' || $st == 'A') && $canManage): ?>
                                            <button onclick="handleRequestAction('start_progress', '<?php echo $req['id']; ?>', 'ctd_<?php echo $req['table']; ?>_frm')" class="btn-action btn-start" title="Iniciar atendimento">
                                                <i class="fa-solid fa-play"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($st == 'W' && $canManage): ?>
                                            <button onclick="handleRequestAction('finish', '<?php echo $req['id']; ?>', 'ctd_<?php echo $req['table']; ?>_frm')" class="btn-action btn-finish" title="Marcar como concluída">
                                                <i class="fa-solid fa-check-double"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align:center; padding: 40px; color: var(--text-muted);">Nenhuma atividade recente.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php if (!$activeFilterStatus): ?>
// Dados PHP para JS
const statusData = [<?php echo $statusCounts['P']; ?>, <?php echo $statusCounts['Y']; ?>, <?php echo $statusCounts['N']; ?>, <?php echo $statusCounts['W']; ?>, <?php echo $statusCounts['C']; ?>];
const catLabels = <?php echo json_encode(array_map(function($k) use ($map) { return $map[$k] ?? $k; }, array_keys($categoryCounts))); ?>;
const catData = <?php echo json_encode(array_values($categoryCounts)); ?>;

const ctxStatus = document.getElementById('statusChart').getContext('2d');
new Chart(ctxStatus, {
    type: 'doughnut',
    data: {
        labels: ['Pendentes', 'Aprovadas', 'Recusadas', 'Em Andamento', 'Concluídas'],
        datasets: [{
            data: statusData,
            backgroundColor: ['#eab308', '#10b981', '#ef4444', '#3b82f6', '#15803d'],
            borderWidth: 0,
            hoverOffset: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' }
        }
    }
});

const ctxCat = document.getElementById('categoryChart').getContext('2d');
new Chart(ctxCat, {
    type: 'bar',
    data: {
        labels: catLabels,
        datasets: [{
            label: 'Total',
            data: catData,
            backgroundColor: '#3b82f6',
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } }
        }
    }
});
<?php endif; ?>

// Table Filters
const dashSearch = document.getElementById('dashSearch');
const statusFilter = document.getElementById('statusFilter');
const sectorFilter = document.getElementById('sectorFilter');
const dateFilter = document.getElementById('dateFilter');

function filterTable() {
    const term = dashSearch.value.toLowerCase();
    const status = statusFilter.value.toUpperCase();
    const sector = sectorFilter.value;
    const filterDate = dateFilter.value;
    
    const rows = document.querySelectorAll('.dash-table tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        
        // Sector check
        const badgeCat = row.querySelector('.badge-cat');
        const rowSector = badgeCat ? Array.from(badgeCat.classList).find(c => c.startsWith('badge-') && c !== 'badge-cat')?.replace('badge-', '') : '';
        let matchesSector = !sector || rowSector === sector;

        // Status check via stepper
        let matchesStatus = true;
        if (status) {
            if (status === 'P' && !row.querySelector('.mini-step.current:first-child')) matchesStatus = text.includes('pendente');
            if (status === 'Y' && !text.includes('aprovada')) matchesStatus = false;
            if (status === 'W' && !text.includes('atendendo')) matchesStatus = false;
            if (status === 'C' && !text.includes('concluída')) matchesStatus = false;
            if (status === 'N' && !row.querySelector('.rejected')) matchesStatus = false;
        }

        // Date check
        let matchesDate = true;
        if (filterDate) {
            const dateStrMatches = row.cells[1]?.textContent.match(/(\d{2})\/(\d{2})\/(\d{4})/);
            if (dateStrMatches) {
                const rowDateISO = `${dateStrMatches[3]}-${dateStrMatches[2]}-${dateStrMatches[1]}`;
                if (rowDateISO !== filterDate) matchesDate = false;
            }
        }

        row.style.display = (text.includes(term) && matchesSector && matchesStatus && matchesDate) ? '' : 'none';
    });
}

dashSearch.addEventListener('input', filterTable);
statusFilter.addEventListener('change', filterTable);
sectorFilter.addEventListener('change', filterTable);
if(dateFilter) dateFilter.addEventListener('change', filterTable);



function exportToExcel() {
    const term = document.getElementById('dashSearch').value;
    const sector = document.getElementById('sectorFilter').value;
    const status = document.getElementById('statusFilter').value;
    const date = document.getElementById('dateFilter').value;
    
    const params = new URLSearchParams({
        search: term,
        sector: sector,
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
