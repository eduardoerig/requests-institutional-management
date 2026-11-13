<?php
/**
 * Usuários e setores (somente administrador geral).
 * Pessoas: lista só de leitura; tocar/clicar abre a edição (folha no celular, painel lateral no PC).
 * Setores: um cartão por setor com pedidos abertos e responsáveis.
 * Áreas: um cartão por divisão de quem pede (ctd_subdivision), com a coordenação; gravação em api/subdivisions.php.
 * Comportamento em assets/js/pages/accounts.js; estilos em assets/css/pages/accounts.css.
 */
if (!isGlobalAdminRole($_SESSION['role'] ?? '')) {
    echo '<div class="panel"><div class="empty-big"><i class="fa-solid fa-lock" aria-hidden="true"></i><h2>Sem acesso</h2><p>Somente administradores gerenciam usuários.</p></div></div>';
    return;
}

// "Último acesso": coluna last_login_at (migração 003)
$accHasLastLogin = true; // ctd_users.last_login_at (migração 003)

// Pessoas (sem a senha), com áreas (subdivisões) e setores ligados
$accUsers = $pdo->query("
    SELECT u.id, u.name, u.login, u.phone, u.role, u.status" . ($accHasLastLogin ? ", u.last_login_at" : "") . ",
           GROUP_CONCAT(DISTINCT cus.id_subdivision) AS sub_ids,
           GROUP_CONCAT(DISTINCT cua.id_area) AS area_ids
    FROM ctd_users u
    LEFT JOIN cfg_user_subdivision cus ON u.id = cus.id_user
    LEFT JOIN cfg_user_area cua ON u.id = cua.id_user
    GROUP BY u.id
    ORDER BY u.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$accSubdivisions = [];
$accActiveSubdivisions = []; // só as ativas entram na escolha de área de uma pessoa
$accInactiveSubdivisions = [];
foreach ($pdo->query("SELECT id, name, status FROM ctd_subdivision ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $accSubdivisions[(int)$row['id']] = (string)$row['name'];
    if ((int)$row['status'] === 1) {
        $accActiveSubdivisions[(int)$row['id']] = (string)$row['name'];
    } else {
        $accInactiveSubdivisions[(int)$row['id']] = (string)$row['name'];
    }
}
$accAreas = areaTitles($pdo);
$accAreaDescriptions = areaDescriptions($pdo);

// Setores de atendimento do sistema: o perfil (role) é o próprio setor. Ícones iguais aos de "Novo pedido".
$accSectorMeta = sectorRegistry(); // label e icon de cada setor (config/sectors.php)
$accKindMeta = [
    'req'    => ['label' => 'Faz pedidos',     'icon' => 'fa-user',                'hint' => 'Professores e funcionários. Abrem e acompanham os próprios pedidos.'],
    'coord'  => ['label' => 'Coordenação',     'icon' => 'fa-user-check',          'hint' => 'Aprova os pedidos da sua área antes de irem para os setores.'],
    'sector' => ['label' => 'Atende um setor', 'icon' => 'fa-screwdriver-wrench',  'hint' => 'TI, Manutenção, Compras, Reprografia ou Marketing. Recebe e resolve os pedidos.'],
    'admin'  => ['label' => 'Administrador',   'icon' => 'fa-user-shield',         'hint' => 'Vê tudo, aprova e cadastra pessoas e setores.'],
];

$accKindOf = function (string $role): string {
    if (isGlobalAdminRole($role)) return 'admin';
    if ($role === 'adm_sub') return 'coord';
    if (isGestorRole($role)) return 'sector';
    return 'req';
};
$accAreaLabel = function (string $title) use ($accSectorMeta): string {
    $key = areaTitleToSector($title);
    return $key !== null && isset($accSectorMeta[$key]) ? $accSectorMeta[$key]['label'] : $title;
};
$accInitials = function (string $name): string {
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $first = mb_substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($first . $last);
};
$accLastLabel = function (?string $value) use ($accHasLastLogin): string {
    if (!$accHasLastLogin) return '—';
    if (empty($value)) return 'nunca entrou';
    try {
        $when = new DateTimeImmutable($value);
    } catch (Exception $e) {
        return '—';
    }
    $days = (int)$when->setTime(0, 0)->diff(new DateTimeImmutable('today'))->format('%r%a');
    if ($days <= 0) return 'hoje';
    if ($days === 1) return 'ontem';
    if ($days <= 30) return 'há ' . $days . ' dias';
    return $when->format('d/m/Y');
};

// Monta cada pessoa uma vez: a lista (PHP) e a edição (JS) usam os mesmos dados
$accPeople = [];
$accCounts = ['active' => 0, 'inactive' => 0];
foreach ($accUsers as $row) {
    $role = (string)$row['role'];
    $kind = $accKindOf($role);
    $sector = roleDefaultSector($role);
    $subIds = array_values(array_filter(array_map('intval', explode(',', (string)($row['sub_ids'] ?? '')))));
    $areaIds = array_values(array_filter(array_map('intval', explode(',', (string)($row['area_ids'] ?? '')))));
    $subNames = array_values(array_filter(array_map(fn($id) => $accSubdivisions[$id] ?? null, $subIds)));

    if ($kind === 'sector') {
        $roleLabel = 'Setor de ' . ($accSectorMeta[$sector]['label'] ?? 'TI');
        $roleIcon = $accSectorMeta[$sector]['icon'] ?? $accKindMeta['sector']['icon'];
        $where = $accSectorMeta[$sector]['label'] ?? '';
    } else {
        $roleLabel = $kind === 'req' && !in_array($role, ['solicitante', ''], true) ? $role : $accKindMeta[$kind]['label'];
        $roleIcon = $accKindMeta[$kind]['icon'];
        $where = $kind === 'admin' ? 'Tudo' : implode(', ', $subNames);
    }

    $active = (int)($row['status'] ?? 1) === 1;
    $accCounts[$active ? 'active' : 'inactive']++;
    $accPeople[] = [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'login' => (string)$row['login'],
        'phone' => (string)($row['phone'] ?? ''),
        'role' => $role,
        'kind' => $kind,
        'sector' => $sector,
        'roleLabel' => $roleLabel,
        'roleIcon' => $roleIcon,
        'where' => $where,
        'active' => $active,
        'subs' => $subIds,
        'areas' => $areaIds,
        'last' => $accLastLabel($row['last_login_at'] ?? null),
        'initials' => $accInitials((string)$row['name']),
    ];
}

// Pedidos abertos por setor do sistema (aprovados aguardando ou em atendimento): uma consulta só
$accOpen = [];
try {
    $parts = [];
    foreach (allowedRequestTables() as $table) {
        $parts[] = "SELECT ? AS sector, COUNT(*) AS total FROM `ctd_{$table}_frm` WHERE status IN ('Y', 'W')";
    }
    $stmtOpen = $pdo->prepare(implode(' UNION ALL ', $parts));
    $stmtOpen->execute(allowedRequestTables());
    foreach ($stmtOpen->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $accOpen[(string)$row['sector']] = (int)$row['total'];
    }
} catch (PDOException $e) {
    error_log('accounts.php (pedidos abertos): ' . $e->getMessage());
}

// Setores com seus responsáveis ativos e quem pode ser adicionado
$accSectorCards = [];
foreach ($accAreas as $areaId => $title) {
    $key = areaTitleToSector($title);
    $isSystem = $key !== null && isset($accSectorMeta[$key]);
    $resp = [];
    $eligible = [];
    foreach ($accPeople as $p) {
        if (!$p['active']) continue;
        if (in_array($areaId, $p['areas'], true)) {
            $resp[] = $p + ['byRole' => $p['sector'] !== null && $p['sector'] === $key];
        } elseif ($p['kind'] === 'sector' || $p['kind'] === 'admin') {
            $eligible[] = $p;
        }
    }
    $accSectorCards[] = [
        'id' => $areaId,
        'label' => $accAreaLabel($title),
        'description' => $accAreaDescriptions[$areaId] ?? '',
        'icon' => $isSystem ? $accSectorMeta[$key]['icon'] : 'fa-building',
        'system' => $isSystem,
        'open' => $isSystem ? ($accOpen[$key] ?? null) : null,
        'resp' => $resp,
        'eligible' => $eligible,
    ];
}

// Áreas: ativas primeiro; pessoas ligadas e pedidos de cada uma, numa consulta só
$accAreaRequests = [];
try {
    $parts = array_map(fn($table) => "SELECT subdivision_id, status FROM `ctd_{$table}_frm` WHERE subdivision_id IS NOT NULL", allowedRequestTables());
    $accRequestRows = $pdo->query("SELECT subdivision_id, COUNT(*) AS total, SUM(status = 'P') AS pending FROM (" . implode(' UNION ALL ', $parts) . ") r GROUP BY subdivision_id");
    foreach ($accRequestRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $accAreaRequests[(int)$row['subdivision_id']] = ['total' => (int)$row['total'], 'pending' => (int)$row['pending']];
    }
} catch (PDOException $e) {
    error_log('accounts.php (pedidos por área): ' . $e->getMessage());
}
$accAreaCards = [];
foreach ($accActiveSubdivisions + $accInactiveSubdivisions as $subId => $subName) {
    $members = array_filter($accPeople, fn($p) => in_array($subId, $p['subs'], true));
    $accAreaCards[] = [
        'id' => $subId,
        'name' => $subName,
        'active' => isset($accActiveSubdivisions[$subId]),
        'people' => count($members),
        'coordinators' => array_values(array_filter($members, fn($p) => $p['kind'] === 'coord' && $p['active'])),
        'requests' => $accAreaRequests[$subId]['total'] ?? 0,
        'pending' => $accAreaRequests[$subId]['pending'] ?? 0,
    ];
}

$accScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$accAppUrl = $accScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($basePath ?? '/');

$accData = [
    'people' => array_map(fn($p) => array_diff_key($p, ['roleIcon' => 1]), $accPeople),
    'subdivisions' => array_map(fn($id, $name) => ['id' => $id, 'name' => $name], array_keys($accSubdivisions), $accSubdivisions),
    'sectors' => array_map(fn($k, $m) => ['key' => $k, 'label' => $m['label']], array_keys($accSectorMeta), $accSectorMeta),
    'me' => (int)($_SESSION['id'] ?? 0),
    'appUrl' => $accAppUrl,
    'orgName' => orgSetting('org_name'),
];
?>
<div class="acc" id="accApp">
    <header class="acc-head">
        <div class="acc-head__title">
            <h1>Usuários e setores</h1>
            <p>Quem usa o sistema, o que cada pessoa faz e quem responde por cada setor e área.</p>
        </div>
        <div class="acc-head__actions">
            <a href="api/export_accounts_csv.php" class="btn acc-head__export" download><i class="fa-solid fa-file-csv" aria-hidden="true"></i> Exportar planilha</a>
            <button type="button" class="btn btn--primary" data-acc-action="new-person"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Nova pessoa</button>
        </div>
    </header>

    <div class="acc-tabs" role="tablist" aria-label="Seção">
        <button type="button" class="acc-tab is-active" role="tab" id="accTabPeople" aria-controls="accPanelPeople" aria-selected="true" data-acc-tab="pessoas">
            <i class="fa-solid fa-users" aria-hidden="true"></i> Pessoas · <?= count($accPeople) ?>
        </button>
        <button type="button" class="acc-tab" role="tab" id="accTabSectors" aria-controls="accPanelSectors" aria-selected="false" tabindex="-1" data-acc-tab="setores">
            <i class="fa-solid fa-building" aria-hidden="true"></i> Setores · <?= count($accSectorCards) ?>
        </button>
        <button type="button" class="acc-tab" role="tab" id="accTabAreas" aria-controls="accPanelAreas" aria-selected="false" tabindex="-1" data-acc-tab="areas">
            <i class="fa-solid fa-layer-group" aria-hidden="true"></i> Áreas · <?= count($accActiveSubdivisions) ?>
        </button>
    </div>

    <!-- ===================== Pessoas ===================== -->
    <section class="acc-panel" id="accPanelPeople" role="tabpanel" aria-labelledby="accTabPeople">
        <div class="acc-people" id="accPeopleLayout">
            <div class="acc-list">
                <div class="acc-toolbar">
                    <label class="acc-search">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        <span class="visually-hidden">Buscar pessoa</span>
                        <input type="search" id="accSearch" placeholder="Nome ou usuário" aria-label="Buscar por nome ou usuário" autocomplete="off" autocapitalize="none">
                    </label>
                    <div class="acc-filters" role="group" aria-label="Filtrar por perfil">
                        <button type="button" class="acc-pill is-active" aria-pressed="true" data-acc-kind="all">Todos</button>
                        <button type="button" class="acc-pill" aria-pressed="false" data-acc-kind="req"><i class="fa-solid fa-user" aria-hidden="true"></i> Fazem pedidos</button>
                        <button type="button" class="acc-pill" aria-pressed="false" data-acc-kind="coord"><i class="fa-solid fa-user-check" aria-hidden="true"></i> Coordenação</button>
                        <button type="button" class="acc-pill" aria-pressed="false" data-acc-kind="sector"><i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i> Setores</button>
                        <button type="button" class="acc-pill" aria-pressed="false" data-acc-kind="admin"><i class="fa-solid fa-user-shield" aria-hidden="true"></i> Admin</button>
                    </div>
                    <div class="acc-state-tabs" role="group" aria-label="Situação da conta">
                        <button type="button" class="acc-state-tab is-active" aria-pressed="true" data-acc-state="active">Ativos · <span data-acc-count="active"><?= (int)$accCounts['active'] ?></span></button>
                        <button type="button" class="acc-state-tab" aria-pressed="false" data-acc-state="inactive">Desativados · <span data-acc-count="inactive"><?= (int)$accCounts['inactive'] ?></span></button>
                    </div>
                </div>

                <div class="acc-rows-head" aria-hidden="true">
                    <span>Pessoa</span><span>Perfil</span><span>Área ou setor</span><span><i class="fa-solid fa-clock"></i> Último acesso</span>
                </div>
                <ul class="acc-rows" id="accRows">
                    <?php foreach ($accPeople as $p) { include __DIR__ . '/../partials/_account_row.php'; } ?>
                </ul>
                <p class="acc-empty" id="accEmpty" hidden>Ninguém com esse filtro.</p>
            </div>

            <!-- Edição: folha de baixo para cima no celular, painel lateral no PC -->
            <aside class="acc-editor" id="accEditor" aria-labelledby="accEdName" hidden>
                <div class="acc-editor__top">
                    <button type="button" class="acc-back" data-acc-action="close-editor">
                        <i class="fa-solid fa-arrow-left acc-only-mobile" aria-hidden="true"></i><span class="acc-only-mobile">Pessoas</span>
                        <i class="fa-solid fa-xmark acc-only-desktop" aria-hidden="true"></i><span class="visually-hidden acc-only-desktop">Fechar edição</span>
                    </button>
                    <div class="acc-editor__who">
                        <span class="acc-avatar acc-avatar--lg" id="accEdAvatar" aria-hidden="true"></span>
                        <div class="acc-editor__id">
                            <h2 id="accEdName" tabindex="-1"></h2>
                            <div class="acc-chipline">
                                <span class="acc-role" id="accEdRole"></span>
                                <span class="acc-state" id="accEdState"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="acc-editor__body">
                    <p class="acc-note" id="accEdSelfNote" hidden><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Esta é a sua conta: perfil, situação e exclusão ficam travados. Sua senha você troca em "Minha conta".</p>

                    <section class="acc-sec" aria-labelledby="accSecData">
                        <h3 id="accSecData"><i class="fa-solid fa-id-card" aria-hidden="true"></i> Dados</h3>
                        <div class="acc-field">
                            <label for="accEdNameInput">Nome completo</label>
                            <input type="text" id="accEdNameInput" maxlength="150" autocomplete="off">
                            <p class="field-error" id="accEdNameErr" hidden></p>
                        </div>
                        <div class="acc-field">
                            <label for="accEdLogin">Usuário para entrar</label>
                            <input type="text" id="accEdLogin" maxlength="100" autocomplete="off" autocapitalize="none" spellcheck="false">
                            <p class="field-error" id="accEdLoginErr" hidden></p>
                        </div>
                        <div class="acc-field">
                            <label for="accEdPhone">WhatsApp <small>(opcional)</small></label>
                            <input type="tel" id="accEdPhone" inputmode="numeric" maxlength="20" placeholder="(11) 99999-8888" autocomplete="off">
                            <p class="field-error" id="accEdPhoneErr" hidden></p>
                        </div>
                    </section>

                    <section class="acc-sec" aria-labelledby="accSecAccess">
                        <h3 id="accSecAccess"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Acesso</h3>
                        <div class="acc-field">
                            <label for="accEdKind">O que a pessoa faz</label>
                            <select id="accEdKind">
                                <?php foreach ($accKindMeta as $k => $m): ?>
                                    <option value="<?= e($k) ?>"><?= e($m['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="acc-field" data-acc-ed-group="subs">
                            <span class="acc-label" id="accEdSubsLabel">Área</span>
                            <div class="acc-choices" role="group" aria-labelledby="accEdSubsLabel" id="accEdSubs">
                                <?php foreach ($accActiveSubdivisions as $subId => $subName): ?>
                                    <button type="button" class="acc-choice" aria-pressed="false" data-value="<?= (int)$subId ?>"><?= e($subName) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="acc-field" data-acc-ed-group="sector">
                            <span class="acc-label" id="accEdSectorLabel">Setor que atende</span>
                            <div class="acc-choices" role="group" aria-labelledby="accEdSectorLabel" id="accEdSector">
                                <?php foreach ($accSectorMeta as $key => $m): ?>
                                    <button type="button" class="acc-choice" aria-pressed="false" data-value="<?= e($key) ?>"><i class="fa-solid <?= e($m['icon']) ?>" aria-hidden="true"></i> <?= e($m['label']) ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <p class="acc-note" data-acc-ed-group="admin"><i class="fa-solid fa-user-shield" aria-hidden="true"></i> O administrador vê tudo e cadastra pessoas. Não precisa escolher área.</p>
                        <p class="field-error" id="accEdAccessErr" hidden></p>
                    </section>

                    <section class="acc-sec" aria-labelledby="accSecPass">
                        <h3 id="accSecPass"><i class="fa-solid fa-key" aria-hidden="true"></i> Senha</h3>
                        <p class="acc-muted">Esqueceu a senha? Gere uma temporária: a pessoa cria a própria no próximo acesso.</p>
                        <div class="acc-secret" id="accEdSecret" hidden>
                            <span class="acc-secret__label">Senha temporária (aparece só agora)</span>
                            <code class="acc-secret__value" id="accEdSecretValue"></code>
                            <div class="acc-secret__actions">
                                <button type="button" class="btn" data-acc-action="copy-edit"><i class="fa-solid fa-copy" aria-hidden="true"></i> Copiar usuário e senha</button>
                                <a class="btn acc-btn-whats" id="accEdWhats" href="#" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Enviar pelo WhatsApp</a>
                            </div>
                        </div>
                        <button type="button" class="btn btn--block" id="accEdResetBtn" data-acc-action="reset-password"><i class="fa-solid fa-key" aria-hidden="true"></i> <span>Gerar senha temporária</span></button>
                    </section>

                    <section class="acc-sec" aria-labelledby="accSecState">
                        <h3 id="accSecState"><i class="fa-solid fa-toggle-on" aria-hidden="true"></i> Situação</h3>
                        <label class="acc-switch-row" for="accEdActive">
                            <span class="acc-switch-row__text">
                                <strong>Conta ativa</strong>
                                <small>Desativada, a pessoa não entra, mas o histórico fica.</small>
                            </span>
                            <span class="switch">
                                <input type="checkbox" id="accEdActive">
                                <span class="slider round"></span>
                            </span>
                        </label>
                    </section>

                    <button type="button" class="btn btn--danger btn--block" id="accEdDelete" data-acc-action="delete-user"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir conta</button>
                </div>

                <div class="acc-editor__foot">
                    <button type="button" class="btn btn--primary btn--block" id="accEdSave" data-acc-action="save-user" disabled><i class="fa-solid fa-check" aria-hidden="true"></i> <span>Nada para salvar</span></button>
                </div>
            </aside>
        </div>
    </section>

    <!-- ===================== Setores ===================== -->
    <section class="acc-panel" id="accPanelSectors" role="tabpanel" aria-labelledby="accTabSectors" hidden>
        <div class="acc-sectors">
            <?php foreach ($accSectorCards as $s): ?>
                <article class="card acc-sector<?= empty($s['resp']) ? ' is-alert' : '' ?>" data-area-id="<?= (int)$s['id'] ?>" data-area-label="<?= e($s['label']) ?>">
                    <header class="acc-sector__head">
                        <h3><i class="fa-solid <?= e($s['icon']) ?>" aria-hidden="true"></i> <?= e($s['label']) ?></h3>
                        <?php if ($s['open'] !== null): ?>
                            <span class="acc-sector__open"><?= (int)$s['open'] ?> <?= (int)$s['open'] === 1 ? 'pedido aberto' : 'pedidos abertos' ?></span>
                        <?php endif; ?>
                    </header>

                    <div class="acc-sector__desc">
                        <p class="acc-sector__desc-text<?= $s['description'] === '' ? ' is-empty' : '' ?>"><?= $s['description'] !== '' ? e($s['description']) : 'Sem descrição. Ela aparece para quem encaminha um pedido.' ?></p>
                        <button type="button" class="btn btn--ghost btn--sm" data-acc-action="toggle-desc" aria-expanded="false" aria-label="<?= $s['description'] !== '' ? 'Editar' : 'Escrever' ?> a descrição de <?= e($s['label']) ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i> <?= $s['description'] !== '' ? 'Editar' : 'Escrever' ?></button>
                    </div>
                    <form class="acc-add-form" data-acc-form="sector-desc" hidden>
                        <label for="accDesc<?= (int)$s['id'] ?>">O que <?= e($s['label']) ?> faz <small>(aparece no Encaminhar)</small></label>
                        <input type="text" id="accDesc<?= (int)$s['id'] ?>" name="description" maxlength="160" value="<?= e($s['description']) ?>" placeholder="Ex.: Compra de peças, materiais e serviços">
                        <div class="acc-add-form__actions">
                            <button type="button" class="btn" data-acc-action="toggle-desc">Cancelar</button>
                            <button type="submit" class="btn btn--primary">Salvar</button>
                        </div>
                    </form>

                    <p class="acc-label">Responsáveis</p>
                    <?php if (!empty($s['resp'])): ?>
                        <ul class="acc-resp">
                            <?php foreach ($s['resp'] as $r): ?>
                                <li>
                                    <span class="acc-avatar acc-avatar--sm acc-avatar--<?= e($r['kind']) ?>" aria-hidden="true"><?= e($r['initials']) ?></span>
                                    <span class="acc-resp__name"><?= e($r['name']) ?><small><?= e($r['roleLabel']) ?></small></span>
                                    <?php if ($r['byRole']): ?>
                                        <span class="acc-resp__note" title="Para tirar, mude o perfil da pessoa">pelo perfil</span>
                                    <?php else: ?>
                                        <button type="button" class="acc-icon-btn" data-acc-action="remove-resp" data-user-id="<?= (int)$r['id'] ?>" data-user-name="<?= e($r['name']) ?>" aria-label="Tirar <?= e($r['name']) ?> do setor <?= e($s['label']) ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="acc-alert" role="note"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Ninguém responde por este setor. Os pedidos ficam parados.</p>
                    <?php endif; ?>

                    <button type="button" class="acc-add" data-acc-action="toggle-add-resp" aria-expanded="false"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Adicionar responsável</button>
                    <form class="acc-add-form" data-acc-form="add-resp" hidden>
                        <?php if (!empty($s['eligible'])): ?>
                            <label for="accAddResp<?= (int)$s['id'] ?>">Quem passa a responder por <?= e($s['label']) ?></label>
                            <select id="accAddResp<?= (int)$s['id'] ?>" name="user_id" required data-combo data-combo-empty="Ninguém com esse nome">
                                <option value="">Escolha a pessoa</option>
                                <?php foreach ($s['eligible'] as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>"><?= e($p['name'] . ' · ' . $p['roleLabel']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="acc-add-form__actions">
                                <button type="button" class="btn" data-acc-action="toggle-add-resp">Cancelar</button>
                                <button type="submit" class="btn btn--primary"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Adicionar</button>
                            </div>
                        <?php else: ?>
                            <p class="acc-muted">Ninguém disponível. Só pessoas ativas com perfil "Atende um setor" ou "Administrador" podem responder por um setor. Cadastre em "Nova pessoa".</p>
                        <?php endif; ?>
                    </form>

                    <footer class="acc-sector__foot">
                        <?php if ($s['system']): ?>
                            <span class="acc-muted"><i class="fa-solid fa-lock" aria-hidden="true"></i> Setor do sistema</span>
                        <?php else: ?>
                            <button type="button" class="btn btn--sm btn--ghost acc-danger-text" data-acc-action="delete-sector"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir setor</button>
                        <?php endif; ?>
                    </footer>
                </article>
            <?php endforeach; ?>

        </div>
    </section>

    <!-- ===================== Áreas ===================== -->
    <section class="acc-panel" id="accPanelAreas" role="tabpanel" aria-labelledby="accTabAreas" hidden>
        <div class="acc-areas-intro">
            <p class="acc-muted">Cada pessoa que faz pedidos pertence a uma área, e a coordenação da área aprova os pedidos dela. Quem coordena se escolhe na própria pessoa, em Pessoas.</p>
            <button type="button" class="btn btn--primary" data-acc-action="toggle-new-area" aria-expanded="false"><i class="fa-solid fa-plus" aria-hidden="true"></i> Nova área</button>
        </div>
        <form class="card acc-add-form acc-area-new" data-acc-form="area-new" hidden>
            <label for="accNewArea">Nome da nova área</label>
            <input type="text" id="accNewArea" name="name" maxlength="100" required placeholder="Ex.: Ensino Técnico" autocomplete="off">
            <div class="acc-add-form__actions">
                <button type="button" class="btn" data-acc-action="toggle-new-area">Cancelar</button>
                <button type="submit" class="btn btn--primary">Criar área</button>
            </div>
        </form>
        <div class="acc-sectors">
            <?php foreach ($accAreaCards as $a):
                $canDelete = $a['people'] === 0 && $a['requests'] === 0;
                $peopleLabel = $a['people'] === 1 ? '1 pessoa' : $a['people'] . ' pessoas';
                $requestsLabel = $a['requests'] === 1 ? '1 pedido' : $a['requests'] . ' pedidos';
            ?>
                <article class="card acc-sector acc-area<?= $a['active'] ? '' : ' is-off' ?>" data-area-sub-id="<?= (int)$a['id'] ?>" data-area-sub-name="<?= e($a['name']) ?>">
                    <header class="acc-sector__head">
                        <h3 data-area-title><i class="fa-solid fa-layer-group" aria-hidden="true"></i> <?= e($a['name']) ?></h3>
                        <?php if (!$a['active']): ?>
                            <span class="acc-state acc-state--off">Desativada</span>
                        <?php elseif ($a['pending'] > 0): ?>
                            <span class="acc-sector__open"><?= (int)$a['pending'] ?> para aprovar</span>
                        <?php endif; ?>
                    </header>
                    <p class="acc-muted acc-area__count"><?= e($peopleLabel) ?> · <?= e($requestsLabel) ?> no total</p>

                    <form class="acc-add-form" data-acc-form="area-rename" hidden>
                        <label for="accAreaName<?= (int)$a['id'] ?>">Novo nome <small>(os pedidos antigos passam a mostrar este nome)</small></label>
                        <input type="text" id="accAreaName<?= (int)$a['id'] ?>" name="name" maxlength="100" required value="<?= e($a['name']) ?>" autocomplete="off">
                        <div class="acc-add-form__actions">
                            <button type="button" class="btn" data-acc-action="toggle-area-rename">Cancelar</button>
                            <button type="submit" class="btn btn--primary">Salvar</button>
                        </div>
                    </form>

                    <?php if ($a['active']): ?>
                        <p class="acc-label">Coordenação</p>
                        <?php if (!empty($a['coordinators'])): ?>
                            <ul class="acc-resp">
                                <?php foreach ($a['coordinators'] as $c): ?>
                                    <li>
                                        <span class="acc-avatar acc-avatar--sm acc-avatar--coord" aria-hidden="true"><?= e($c['initials']) ?></span>
                                        <span class="acc-resp__name"><?= e($c['name']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="acc-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Sem coordenação: só o administrador aprova os pedidos desta área.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="acc-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Não aparece mais ao cadastrar pessoas. Os pedidos antigos continuam com este nome.</p>
                    <?php endif; ?>

                    <footer class="acc-sector__foot acc-area__foot">
                        <button type="button" class="btn btn--sm btn--ghost" data-acc-action="toggle-area-rename" aria-expanded="false" aria-label="Renomear <?= e($a['name']) ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i> Renomear</button>
                        <?php if (!$a['active']): ?>
                            <button type="button" class="btn btn--sm btn--ghost" data-acc-action="area-status" data-active="1"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reativar</button>
                        <?php elseif ($a['people'] === 0 && !$canDelete): ?>
                            <button type="button" class="btn btn--sm btn--ghost" data-acc-action="area-status" data-active="0"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Desativar</button>
                        <?php endif; ?>
                        <?php if ($canDelete): ?>
                            <button type="button" class="btn btn--sm btn--ghost acc-danger-text" data-acc-action="delete-area"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</button>
                        <?php endif; ?>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<!-- ===================== Nova pessoa (assistente) ===================== -->
<div class="acc-wizard" id="accWizard" hidden>
    <div class="acc-wizard__card" role="dialog" aria-modal="true" aria-labelledby="accWzTitle">
        <header class="acc-wizard__head">
            <div class="acc-wizard__titlebar">
                <button type="button" class="acc-icon-btn acc-icon-btn--lg" data-acc-action="close-wizard" aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                <div>
                    <span class="acc-wizard__step" id="accWzStep">Passo 1 de 3</span>
                    <h2 id="accWzTitle" tabindex="-1">O que a pessoa faz?</h2>
                </div>
            </div>
            <div class="acc-wizard__bars" id="accWzBars" aria-hidden="true"><span></span><span></span><span></span></div>
        </header>

        <div class="acc-wizard__body">
            <!-- Passo 1 -->
            <div class="acc-wz-step" data-acc-step="1">
                <div class="acc-kinds" role="group" aria-label="O que a pessoa faz">
                    <?php foreach ($accKindMeta as $k => $m): ?>
                        <button type="button" class="acc-kind" aria-pressed="false" data-acc-wz-kind="<?= e($k) ?>">
                            <span class="acc-kind__icon acc-avatar--<?= e($k) ?>" aria-hidden="true"><i class="fa-solid <?= e($m['icon']) ?>"></i></span>
                            <span class="acc-kind__text">
                                <strong><?= e($m['label']) ?></strong>
                                <span><?= e($m['hint']) ?></span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <p class="field-error" id="accWzKindErr" hidden></p>
            </div>

            <!-- Passo 2 -->
            <div class="acc-wz-step" data-acc-step="2" hidden>
                <div class="acc-field">
                    <label for="accWzName">Nome completo</label>
                    <input type="text" id="accWzName" maxlength="150" placeholder="Ex.: Ana Souza" autocomplete="off">
                    <p class="field-error" id="accWzNameErr" hidden></p>
                </div>
                <div class="acc-field">
                    <label for="accWzLogin">Usuário para entrar</label>
                    <input type="text" id="accWzLogin" maxlength="100" placeholder="ana.souza" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="accWzLoginHint">
                    <span class="acc-muted" id="accWzLoginHint">Sugerido a partir do nome. Pode trocar.</span>
                    <p class="field-error" id="accWzLoginErr" hidden></p>
                </div>
                <div class="acc-field">
                    <label for="accWzPhone">WhatsApp <small>(opcional)</small></label>
                    <input type="tel" id="accWzPhone" inputmode="numeric" maxlength="20" placeholder="(11) 99999-8888" autocomplete="off" aria-describedby="accWzPhoneHint">
                    <span class="acc-muted" id="accWzPhoneHint">Com DDD. Serve para mandar o acesso e os avisos.</span>
                    <p class="field-error" id="accWzPhoneErr" hidden></p>
                </div>
            </div>

            <!-- Passo 3 -->
            <div class="acc-wz-step" data-acc-step="3" hidden>
                <p class="acc-wz-hint" id="accWzWhereHint"></p>
                <div class="acc-choices" role="group" aria-labelledby="accWzTitle" id="accWzSubs" data-acc-wz-group="subs">
                    <?php foreach ($accActiveSubdivisions as $subId => $subName): ?>
                        <button type="button" class="acc-choice" aria-pressed="false" data-value="<?= (int)$subId ?>"><?= e($subName) ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="acc-choices" role="group" aria-labelledby="accWzTitle" id="accWzSector" data-acc-wz-group="sector">
                    <?php foreach ($accSectorMeta as $key => $m): ?>
                        <button type="button" class="acc-choice" aria-pressed="false" data-value="<?= e($key) ?>"><i class="fa-solid <?= e($m['icon']) ?>" aria-hidden="true"></i> <?= e($m['label']) ?></button>
                    <?php endforeach; ?>
                </div>
                <p class="acc-note" data-acc-wz-group="admin"><i class="fa-solid fa-user-shield" aria-hidden="true"></i> O administrador vê tudo e cadastra pessoas. Não precisa escolher área.</p>
                <p class="field-error" id="accWzWhereErr" hidden></p>
            </div>

            <!-- Resultado -->
            <div class="acc-wz-step acc-wz-done" data-acc-step="4" hidden>
                <span class="acc-wz-done__icon" aria-hidden="true"><i class="fa-solid fa-check"></i></span>
                <p class="acc-wz-done__title"><span id="accWzDoneName"></span> já pode entrar</p>
                <p class="acc-muted">Passe o usuário e a senha abaixo. No primeiro acesso a pessoa cria a própria senha.</p>
                <dl class="acc-credentials">
                    <div><dt>Usuário</dt><dd id="accWzDoneLogin"></dd></div>
                    <div><dt>Senha temporária (aparece só agora)</dt><dd><code id="accWzDonePass"></code></dd></div>
                </dl>
                <button type="button" class="btn btn--block acc-btn-tall" data-acc-action="copy-wizard"><i class="fa-solid fa-copy" aria-hidden="true"></i> <span>Copiar usuário e senha</span></button>
                <a class="btn btn--block acc-btn-tall acc-btn-whats" id="accWzWhats" href="#" target="_blank" rel="noopener noreferrer"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Enviar pelo WhatsApp</a>
            </div>
        </div>

        <footer class="acc-wizard__foot">
            <button type="button" class="btn acc-btn-tall" id="accWzBack" data-acc-action="wizard-back" hidden>Voltar</button>
            <button type="button" class="btn btn--primary acc-btn-tall acc-grow" id="accWzNext" data-acc-action="wizard-next">Continuar</button>
            <button type="button" class="btn acc-btn-tall acc-grow acc-btn-dark" id="accWzFinish" data-acc-action="wizard-finish" hidden><i class="fa-solid fa-check" aria-hidden="true"></i> Concluir</button>
        </footer>
    </div>
</div>

<script type="application/json" id="accData"><?= json_encode($accData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
