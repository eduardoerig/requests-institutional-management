<?php
// Buscar dados completos do usuário logado (Apenas admins acessam esta página)
if (!in_array($_SESSION['role'], ['admin', 'adm'])) {
    echo '<div class="main"><p>Acesso negado.</p></div>';
    exit;
}

// Buscar todos os usuários com seus respectivos setores (Concatenados para evitar duplicidade)
$query_users = "
    SELECT u.*,
           GROUP_CONCAT(DISTINCT sub.id SEPARATOR ',') as all_subdivision_ids,
           GROUP_CONCAT(DISTINCT sub.name SEPARATOR ', ') as all_subdivisions,
           GROUP_CONCAT(DISTINCT a.title SEPARATOR ', ') as all_sectors
    FROM ctd_users u
    LEFT JOIN cfg_user_subdivision cus ON u.id = cus.id_user
    LEFT JOIN ctd_subdivision sub ON cus.id_subdivision = sub.id
    LEFT JOIN cfg_user_area cua ON u.id = cua.id_user
    LEFT JOIN ctd_area a ON cua.id_area = a.id
    GROUP BY u.id
    ORDER BY u.name ASC
";
$all_users = $pdo->query($query_users)->fetchAll();

// Buscar todos os setores disponíveis
$all_sectors = $pdo->query("SELECT * FROM ctd_area ORDER BY title ASC")->fetchAll();
$all_subdivisions = $pdo->query("SELECT * FROM ctd_subdivision ORDER BY name ASC")->fetchAll();

$categories = [
    'shop' => 'Compras',
    'mkt' => 'Marketing',
    'service' => 'Manutenção',
    'xerox' => 'Reprografia',
    'ti' => 'TI'
];

$role_labels = [
    'admin' => 'Administrador',
    'adm_sub' => 'Admin de Subdivisão',
    'ti' => 'Gestor de TI',
    'xerox' => 'Gestor de Reprografia',
    'service' => 'Gestor de Manutenção',
    'shop' => 'Gestor de Compras',
    'mkt' => 'Gestor de Marketing',
    'solicitante' => 'Solicitante'
];

// Mapeamento estendido para exibir nomes de perfis legados no banco de dados
$full_role_labels = array_merge($role_labels, [
    'gestor' => 'Gestor de TI',
    'marketing' => 'Gestor de Marketing'
]);

?>
<div class="main" style="max-width: 1300px; margin: 0 auto; padding: 20px;">

    <!-- 1. Header da Página -->
    <div class="page-header" style="margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 20px;">
        <div style="display: flex; align-items: center; gap: 15px;">
            <div style="background: var(--primary); color: white; width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; box-shadow: 0 4px 12px rgba(124, 58, 237, 0.2);">
                <i class="fa-solid fa-users-gear"></i>
            </div>
            <div>
                <h3 style="margin: 0; font-size: 1.6rem; color: var(--text-main);">Gestão de Contas</h3>
                <p style="margin: 5px 0 0; font-size: 0.9rem; color: var(--text-muted);">Controle centralizado de usuários, permissões e setores do sistema.</p>
            </div>
        </div>
        <a href="api/export_accounts_csv.php" class="btn-primary" style="background: #1e293b; width: auto; padding: 0 20px; height: 42px; display: flex; align-items: center; gap: 8px; font-size: 0.85rem;">
            <i class="fa-solid fa-file-csv"></i> Exportar CSV
        </a>
    </div>

    <!-- 2. Grid de Três Colunas (Ações Rápidas) -->
    <div class="actions-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 20px; margin-bottom: 30px;">

        <!-- Card: Criar Novo Setor -->
        <div class="card" style="border-left: 4px solid #8b5cf6;">
            <div class="card-header" style="padding: 15px 20px; border-bottom: 1px solid var(--border-color);">
                <h4 style="margin: 0; font-size: 0.95rem; color: var(--text-main); display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-folder-plus" style="color: #8b5cf6;"></i> Criar Novo Setor
                </h4>
            </div>
            <div class="card-body" style="padding: 20px;">
                <form id="formCreateSector">
                    <div style="display: flex; gap: 10px;">
                        <input type="text" name="sector_title" placeholder="Ex: financeiro, rh..." required
                               style="padding: 10px 14px; border: 1px solid var(--border-color); border-radius: 8px; outline: none; flex: 1; font-size: 0.85rem;">
                        <button type="submit" class="btn-primary" style="width: auto; background: #8b5cf6; padding: 0 15px; border-radius: 8px;">
                            <i class="fa-solid fa-plus"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Card: Setores Cadastrados -->
        <div class="card" style="border-left: 4px solid #6366f1;">
            <div class="card-header" style="padding: 15px 20px; border-bottom: 1px solid var(--border-color);">
                <h4 style="margin: 0; font-size: 0.95rem; color: var(--text-main); display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-tags" style="color: #6366f1;"></i> Setores Cadastrados
                </h4>
            </div>
            <div class="card-body" style="padding: 15px 20px; max-height: 100px; overflow-y: auto;">
                <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                    <?php foreach($all_sectors as $s):
                        $label = $categories[$s['title']] ?? ucfirst($s['title']);
                    ?>
                        <span style="background: #f1f5f9; padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; color: #475569; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 6px;">
                            <?= $label ?>
                            <i class="fa-solid fa-times" onclick="deleteSector(<?= $s['id'] ?>)" style="cursor: pointer; opacity: 0.6; hover: opacity: 1;"></i>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Card: Vincular Responsável -->
        <div class="card" style="border-left: 4px solid var(--primary);">
            <div class="card-header" style="padding: 15px 20px; border-bottom: 1px solid var(--border-color);">
                <h4 style="margin: 0; font-size: 0.95rem; color: var(--text-main); display: flex; align-items: center; gap: 10px;">
                    <i class="fa-solid fa-link" style="color: var(--primary);"></i> Vincular Responsável
                </h4>
            </div>
            <div class="card-body" style="padding: 20px;">
                <form id="formAssignSector">
                    <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px;">
                        <select name="user_id" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 8px; background: #fff; font-size: 0.8rem;">
                            <option value="">Usuário...</option>
                            <?php foreach($all_users as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="sector_id" required style="padding: 10px; border: 1px solid var(--border-color); border-radius: 8px; background: #fff; font-size: 0.8rem;">
                            <option value="">Setor...</option>
                            <?php foreach($all_sectors as $s):
                                $label = $categories[$s['title']] ?? ucfirst($s['title']);
                            ?>
                                <option value="<?= $s['id'] ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-primary" style="height: 38px; width: 38px; border-radius: 8px; padding: 0;">
                            <i class="fa-solid fa-link"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 3. Card Cadastrar Novo Usuário (Largura Total - Expansivo) -->
    <div class="card" style="border-left: 4px solid #10b981; margin-bottom: 30px;">
        <div class="card-header" style="padding: 15px 20px; border-bottom: 1px solid var(--border-color);">
            <h4 style="margin: 0; font-size: 1rem; color: var(--text-main); display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-user-plus" style="color: #10b981;"></i> Cadastrar Novo Usuário
            </h4>
        </div>
        <div class="card-body" style="padding: 20px;">
            <form id="formCreateUser">
                <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 15px; align-items: start;">
                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted);">NOME COMPLETO</label>
                        <input type="text" name="new_name" placeholder="Nome do usuário" required style="padding: 11px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 0.85rem;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted);">LOGIN</label>
                        <input type="text" name="new_login" placeholder="Ex: joao.silva" required style="padding: 11px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 0.85rem;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted);">SENHA INICIAL</label>
                        <input type="password" name="new_password" placeholder="******" required style="padding: 11px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 0.85rem;">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted);">WHATSAPP <i class="fa-brands fa-whatsapp" style="color:#25d366;"></i></label>
                        <input type="text" name="new_phone" placeholder="5511999998888" maxlength="20" style="padding: 11px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 0.85rem;" title="Formato internacional sem + (ex: 5511999998888)">
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted);">PERFIL DE ACESSO</label>
                        <select name="new_role" required style="padding: 11px; border: 1px solid var(--border-color); border-radius: 8px; background: #fff; font-size: 0.85rem;">
                            <?php foreach($role_labels as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 5px;" id="subdivisionContainer">
                        <label style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); display: flex; justify-content: space-between; align-items: center;">
                            SUBDIVISÃO
                            <button type="button" onclick="addSubdivisionField()" style="background: var(--primary); color: white; border: none; border-radius: 4px; padding: 2px 6px; font-size: 0.7rem; cursor: pointer;">
                                <i class="fa-solid fa-plus"></i>
                            </button>
                        </label>
                        <select name="new_subdivisions[]" style="padding: 11px; border: 1px solid var(--border-color); border-radius: 8px; background: #fff; font-size: 0.85rem;">
                            <option value="">Nenhuma</option>
                            <?php foreach($all_subdivisions as $sub): ?>
                                <option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 15px; display: flex; justify-content: space-between; align-items: center;">
                    <label style="display: flex; align-items: center; gap: 10px; font-size: 0.85rem; color: var(--text-main); cursor: pointer;">
                        <input type="checkbox" name="force_reset" value="1" style="width: 18px; height: 18px; accent-color: var(--primary);">
                        Forçar redefinição de senha no próximo login
                    </label>
                    <button type="submit" class="btn-primary" style="width: auto; padding: 0 30px; background: #10b981; font-weight: 600;">
                        <i class="fa-solid fa-plus-circle" style="margin-right: 8px;"></i> Criar Usuário
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- 4. Card Contas Registradas -->
    <div class="card" style="border-left: 4px solid var(--primary);">
        <!-- Cabeçalho com filtros em linha, sem scroll -->
        <div style="padding: 12px 16px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 8px; min-width: 0;">
            <!-- Título fixo -->
            <h4 style="margin: 0; font-size: 0.9rem; color: var(--text-main); display: flex; align-items: center; gap: 6px; white-space: nowrap; flex-shrink: 0;">
                <i class="fa-solid fa-users" style="color: var(--primary);"></i>
                Contas
                <span id="userCount" style="background: var(--primary); color: #fff; font-size: 0.65rem; padding: 1px 7px; border-radius: 20px; font-weight: 700;"></span>
            </h4>
            <!-- Separador -->
            <div style="width: 1px; height: 20px; background: var(--border-color); flex-shrink: 0;"></div>
            <!-- Busca: cresce para preencher espaço disponível -->
            <div style="position: relative; flex: 2; min-width: 80px;">
                <i class="fa-solid fa-search" style="position: absolute; left: 9px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.72rem; pointer-events:none;"></i>
                <input type="text" id="userSearch" placeholder="Buscar..."
                       style="padding: 6px 8px 6px 28px; border: 1px solid var(--border-color); border-radius: 7px; font-size: 0.78rem; outline: none; width: 100%; box-sizing: border-box;">
            </div>
            <!-- Filtro de Perfil -->
            <select id="roleFilter" style="flex: 1.5; min-width: 80px; padding: 6px 6px; border: 1px solid var(--border-color); border-radius: 7px; font-size: 0.78rem; outline: none; background: #fff; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis;">
                <option value="all">Todos os Perfis</option>
                <?php foreach($role_labels as $key => $label): ?>
                    <option value="<?= $key ?>"><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <!-- Filtro de Subdivisão -->
            <select id="subdivisionFilter" style="flex: 1.5; min-width: 80px; padding: 6px 6px; border: 1px solid var(--border-color); border-radius: 7px; font-size: 0.78rem; outline: none; background: #fff; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis;">
                <option value="all">Todas as Subdivisões</option>
                <?php foreach($all_subdivisions as $sub): ?>
                    <option value="<?= $sub['id'] ?>"><?= htmlspecialchars($sub['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="overflow-x: auto; overflow-y: visible;">
            <table id="tableUsers" style="width: 100%; border-collapse: collapse; font-size: 0.82rem;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 2px solid var(--border-color);">
                        <th class="ac-th">Usuário</th>
                        <th class="ac-th">Login</th>
                        <th class="ac-th">WhatsApp <i class="fa-brands fa-whatsapp" style="color:#25d366;"></i></th>
                        <th class="ac-th">Perfil de Acesso</th>
                        <th class="ac-th">Setores</th>
                        <th class="ac-th">Subdivisões</th>
                        <th class="ac-th" style="text-align:center;">Status</th>
                        <th class="ac-th" style="text-align:center;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($all_users as $u):
                        $isActive = ($u['status'] ?? 1) == 1;
                        $subIds = array_filter(explode(',', $u['all_subdivision_ids'] ?? ''));
                    ?>
                    <tr class="user-row ac-row"
                        data-role="<?= $u['role'] ?>"
                        data-subdivisions="<?= htmlspecialchars(implode(',', $subIds)) ?>"
                        style="<?= !$isActive ? 'opacity:0.55;' : '' ?>">

                        <!-- Usuário -->
                        <td class="ac-td">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <div class="ac-avatar" style="background: <?= $isActive ? 'var(--primary)' : '#94a3b8' ?>;">
                                    <?= strtoupper(substr($u['name'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="user-name" style="font-weight:600; color:var(--text-main); white-space:nowrap;"><?= htmlspecialchars($u['name']) ?></div>
                                    <div style="font-size:0.7rem; color:var(--text-muted);">#<?= (int)$u['id'] ?></div>
                                </div>
                            </div>
                        </td>

                        <!-- Login -->
                        <td class="ac-td">
                            <input type="text" value="<?= htmlspecialchars($u['login']) ?>"
                                   class="row-login-input ac-input"
                                   onfocus="this.style.borderColor='var(--primary)'"
                                   onblur="this.style.borderColor='var(--border-color)'"
                                   oninput="markRowDirty(this)">
                        </td>

                        <!-- WhatsApp -->
                        <td class="ac-td">
                            <input type="text" value="<?= htmlspecialchars($u['phone'] ?? '') ?>"
                                   class="row-phone-input ac-input"
                                   placeholder="5511999998888"
                                   maxlength="20"
                                   onfocus="this.style.borderColor='var(--primary)'"
                                   onblur="this.style.borderColor='var(--border-color)'"
                                   oninput="markRowDirty(this)"
                                   title="Formato internacional sem + (ex: 5511999998888)">
                        </td>

                        <!-- Perfil -->
                        <td class="ac-td">
                            <select class="row-role-select ac-input" onchange="markRowDirty(this)">
                                <?php foreach($full_role_labels as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= $u['role'] == $key ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>

                        <!-- Setores -->
                        <td class="ac-td">
                            <?php if($u['all_sectors']): ?>
                                <div class="sector-list" style="display:flex; flex-wrap:wrap; gap:3px;">
                                    <?php foreach(explode(', ', $u['all_sectors']) as $stitle):
                                        $lbl = $categories[$stitle] ?? ucfirst($stitle);
                                    ?>
                                        <span class="ac-pill"><?= $lbl ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span style="color:#cbd5e1; font-style:italic; font-size:0.75rem;">Nenhum</span>
                            <?php endif; ?>
                        </td>

                        <!-- Subdivisões -->
                        <td class="ac-td">
                            <div class="sub-popover-wrap" style="position:relative;">

                                <!-- Exibição compacta: pills + botão editar -->
                                <div class="sub-display" onclick="toggleSubPopover(<?= $u['id'] ?>)">
                                    <?php
                                    $subNames = [];
                                    foreach ($all_subdivisions as $sub) {
                                        if (in_array((string)$sub['id'], $subIds, true)) {
                                            $subNames[] = htmlspecialchars($sub['name']);
                                        }
                                    }
                                    ?>
                                    <?php if ($subNames): ?>
                                        <?php foreach (array_slice($subNames, 0, 2) as $sn): ?>
                                            <span class="sub-pill"><?= $sn ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($subNames) > 2): ?>
                                            <span class="sub-pill sub-pill-more">+<?= count($subNames) - 2 ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="sub-none">Nenhuma</span>
                                    <?php endif; ?>
                                    <i class="fa-solid fa-pen-to-square sub-edit-icon"></i>
                                </div>

                                <!-- Popover com checkboxes (oculto) -->
                                <div id="subPop_<?= $u['id'] ?>" class="sub-popover" style="display:none;">
                                    <div class="sub-pop-arrow"></div>
                                    <p class="sub-pop-title"><i class="fa-solid fa-layer-group"></i> Subdivisões</p>
                                    <!-- Editor oculto mantido para compatibilidade com saveRowAccount -->
                                    <div class="subdivision-editor" data-subdivision-editor data-user-id="<?= $u['id'] ?>" style="display:flex; flex-direction:column; gap:6px;">
                                        <?php foreach($all_subdivisions as $sub): ?>
                                            <label class="sub-pop-check-label">
                                                <input type="checkbox"
                                                       value="<?= $sub['id'] ?>"
                                                       <?= in_array((string)$sub['id'], $subIds, true) ? 'checked' : '' ?>
                                                       onchange="syncSubDisplay(<?= $u['id'] ?>)"
                                                       style="accent-color: var(--primary); width:14px; height:14px; flex-shrink:0; cursor:pointer;">
                                                <span><?= htmlspecialchars($sub['name']) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div style="display:flex; gap:6px; margin-top:10px; border-top:1px solid #f1f5f9; padding-top:10px;">
                                        <button onclick="saveSubsAndClose(<?= $u['id'] ?>, this)" class="sub-pop-btn sub-pop-save"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
                                        <button onclick="closeSubPopover(<?= $u['id'] ?>)" class="sub-pop-btn sub-pop-cancel">Cancelar</button>
                                    </div>
                                </div>
                            </div>
                        </td>

                        <!-- Status -->
                        <td class="ac-td" style="text-align:center; white-space:nowrap;">
                            <label class="switch">
                                <input type="checkbox" <?= $isActive ? 'checked' : '' ?> onchange="toggleUserStatus(<?= $u['id'] ?>, this.checked)">
                                <span class="slider round"></span>
                            </label>
                            <div style="font-size:0.6rem; margin-top:3px; font-weight:800; color: <?= $isActive ? '#10b981' : '#ef4444' ?>;">
                                <?= $isActive ? 'ATIVO' : 'INATIVO' ?>
                            </div>
                        </td>

                        <!-- Ações -->
                        <td class="ac-td" style="text-align:center; white-space:nowrap;">
                            <div style="display:flex; gap:4px; justify-content:center; align-items:center; margin-bottom:4px;">
                                <button onclick="saveRowAccount(<?= $u['id'] ?>, this)" class="ac-btn ac-btn-save" title="Salvar alterações">
                                    <i class="fa-solid fa-floppy-disk"></i>
                                </button>
                                <div class="pass-popover-wrap" style="position:relative;">
                                    <button onclick="togglePassPopover(<?= $u['id'] ?>, this)" class="ac-btn ac-btn-key" title="Alterar senha">
                                        <i class="fa-solid fa-key"></i>
                                    </button>
                                    <div id="passPopover_<?= $u['id'] ?>" class="pass-popover" style="display:none;">
                                        <div class="pass-popover-arrow"></div>
                                        <p class="pass-popover-title"><i class="fa-solid fa-key"></i> Alterar Senha</p>
                                        <input type="password" id="pop_newpass_<?= $u['id'] ?>" placeholder="Nova senha..." class="pass-popover-input" autocomplete="new-password">
                                        <div style="display:flex; gap:6px; margin-top:8px;">
                                            <button onclick="confirmPassChange(<?= $u['id'] ?>)" class="pass-pop-btn pass-pop-confirm">Confirmar</button>
                                            <button onclick="closePassPopover(<?= $u['id'] ?>)" class="pass-pop-btn pass-pop-cancel">Cancelar</button>
                                        </div>
                                    </div>
                                </div>
                                <button onclick="deleteUser(<?= $u['id'] ?>)" class="ac-btn ac-btn-del" title="Excluir usuário">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                            <div class="row-save-state" style="font-size:0.6rem; color:var(--text-muted); font-weight:600;"></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
    /* === ACCOUNTS TABLE COMPACT UI === */
    .ac-th {
        padding: 10px 14px;
        font-size: 0.68rem;
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.6px;
        white-space: nowrap;
        text-align: left;
    }
    .ac-td {
        padding: 10px 14px;
        vertical-align: middle;
        border-bottom: 1px solid #f1f5f9;
    }
    .ac-row:hover { background: #f8fafc; }
    .ac-row:hover .ac-td { border-bottom-color: #e2e8f0; }
    .ac-avatar {
        width: 32px; height: 32px; border-radius: 50%;
        color: #fff; display: flex; align-items: center;
        justify-content: center; font-weight: 800; font-size: 0.85rem;
        flex-shrink: 0;
    }
    .ac-input {
        padding: 6px 10px;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        font-size: 0.8rem;
        outline: none;
        transition: border-color 0.15s;
        background: #fff;
        width: 100%;
    }
    .ac-input:focus { border-color: var(--primary); }
    .ac-pill {
        background: #f1f5f9;
        color: #475569;
        padding: 2px 7px;
        border-radius: 10px;
        font-size: 0.62rem;
        font-weight: 700;
        border: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    .ac-subs-editor {
        display: flex;
        flex-direction: column;
        gap: 4px;
        max-height: 90px;
        overflow-y: auto;
        padding: 4px 0;
    }
    .ac-check-label {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.72rem;
        color: var(--text-main);
        cursor: pointer;
        white-space: nowrap;
    }
    .ac-btn {
        width: 30px; height: 30px;
        border: none; border-radius: 6px;
        cursor: pointer; display: inline-flex;
        align-items: center; justify-content: center;
        font-size: 0.75rem; transition: opacity 0.15s, transform 0.1s;
    }
    .ac-btn:hover { opacity: 0.85; transform: scale(1.05); }
    .ac-btn-save { background: #1e293b; color: #fff; }
    .ac-btn-key  { background: #0f766e; color: #fff; }
    .ac-btn-del  { background: #ef4444; color: #fff; }
    /* Toggle Switch */
    .switch { position: relative; display: inline-block; width: 34px; height: 20px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; inset: 0; background: #cbd5e1; transition: .3s; }
    .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background: white; transition: .3s; }
    input:checked + .slider { background: #10b981; }
    input:checked + .slider:before { transform: translateX(14px); }
    .slider.round { border-radius: 34px; }
    .slider.round:before { border-radius: 50%; }
    /* Card overrides */
    .card { transform: none !important; cursor: default !important; transition: none !important; }
    .card:hover { transform: none !important; background: var(--bg-card) !important; border-top-color: var(--border-color) !important; border-right-color: var(--border-color) !important; border-bottom-color: var(--border-color) !important; }
    #userSearch:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(124,58,237,0.1); }
    @media (max-width: 768px) {
        #formCreateUser > div:first-child { grid-template-columns: 1fr !important; }
        #userSearch { width: 100% !important; }
    }
    /* === PASSWORD POPOVER === */
    .pass-popover-wrap { position: relative; display: inline-block; }
    .pass-popover {
        position: absolute;
        bottom: calc(100% + 10px);
        right: 0;
        width: 210px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px;
        box-shadow: 0 8px 24px rgba(15,23,42,0.14);
        z-index: 1000;
        animation: popoverIn 0.15s ease;
    }
    .pass-popover-arrow {
        position: absolute;
        bottom: -7px;
        right: 11px;
        width: 12px; height: 12px;
        background: #fff;
        border-right: 1px solid #e2e8f0;
        border-bottom: 1px solid #e2e8f0;
        transform: rotate(45deg);
    }
    .pass-popover-title {
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--text-main);
        margin: 0 0 10px;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .pass-popover-title i { color: #0f766e; }
    .pass-popover-input {
        width: 100%;
        padding: 7px 10px;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        font-size: 0.8rem;
        outline: none;
        transition: border-color 0.15s;
        box-sizing: border-box;
    }
    .pass-popover-input:focus { border-color: #0f766e; box-shadow: 0 0 0 2px rgba(15,118,110,0.12); }
    .pass-pop-btn {
        flex: 1;
        padding: 6px 0;
        border: none;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 700;
        cursor: pointer;
        transition: opacity 0.15s;
    }
    .pass-pop-btn:hover { opacity: 0.85; }
    .pass-pop-confirm { background: #0f766e; color: #fff; }
    .pass-pop-cancel  { background: #f1f5f9; color: #64748b; }
    @keyframes popoverIn {
        from { opacity: 0; transform: translateY(6px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    /* === SUB-POPOVER (Subdivis\u00f5es) === */
    .sub-display {
        display: flex; flex-wrap: wrap; gap: 4px; align-items: center;
        cursor: pointer; padding: 4px 6px; border-radius: 6px;
        transition: background 0.15s; min-height: 28px;
    }
    .sub-display:hover { background: #f1f5f9; }
    .sub-display:hover .sub-edit-icon { opacity: 1; }
    .sub-pill {
        background: #ede9fe; color: #6d28d9;
        padding: 2px 8px; border-radius: 10px;
        font-size: 0.62rem; font-weight: 700; white-space: nowrap;
        border: 1px solid #ddd6fe;
    }
    .sub-pill-more {
        background: #f1f5f9; color: #64748b; border-color: #e2e8f0;
    }
    .sub-none {
        color: #cbd5e1; font-style: italic; font-size: 0.75rem;
    }
    .sub-edit-icon {
        font-size: 0.65rem; color: #94a3b8;
        opacity: 0; transition: opacity 0.15s; margin-left: 2px;
    }
    .sub-popover-wrap { position: relative; }
    .sub-popover {
        position: absolute;
        bottom: calc(100% + 10px);
        left: 0;
        min-width: 200px;
        max-width: 260px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px;
        box-shadow: 0 8px 24px rgba(15,23,42,0.14);
        z-index: 999;
        animation: popoverIn 0.15s ease;
    }
    .sub-pop-arrow {
        position: absolute;
        bottom: -7px; left: 16px;
        width: 12px; height: 12px;
        background: #fff;
        border-right: 1px solid #e2e8f0;
        border-bottom: 1px solid #e2e8f0;
        transform: rotate(45deg);
    }
    .sub-pop-title {
        font-size: 0.75rem; font-weight: 700;
        color: var(--text-main); margin: 0 0 10px;
        display: flex; align-items: center; gap: 6px;
    }
    .sub-pop-title i { color: var(--primary); }
    .sub-pop-check-label {
        display: flex; align-items: center; gap: 8px;
        font-size: 0.78rem; color: var(--text-main);
        cursor: pointer; padding: 4px 6px; border-radius: 6px;
        transition: background 0.1s;
    }
    .sub-pop-check-label:hover { background: #f8fafc; }
    .sub-pop-btn {
        flex: 1; padding: 7px 0; border: none;
        border-radius: 6px; font-size: 0.75rem; font-weight: 700;
        cursor: pointer; transition: opacity 0.15s;
    }
    .sub-pop-btn:hover { opacity: 0.85; }
    .sub-pop-save   { background: var(--primary); color: #fff; display:flex; align-items:center; justify-content:center; gap:5px; }
    .sub-pop-cancel { background: #f1f5f9; color: #64748b; }
</style>


<script>
    function withCsrf(formData) {
        if (!formData.has('csrf_token')) {
            formData.append('csrf_token', window.CSRF_TOKEN || '');
        }
        return formData;
    }

    // Inicializa o contador de usu\u00e1rios
    function updateUserCount() {
        const visible = document.querySelectorAll('#tableUsers .user-row:not([style*="display: none"])').length;
        const el = document.getElementById('userCount');
        if (el) el.textContent = visible;
    }
    document.addEventListener('DOMContentLoaded', updateUserCount);
    // === UTILITÁRIO: posicionar popover com position:fixed (portal) ===
    function _positionPopover(pop, anchorEl) {
        if (!anchorEl) return;
        // Move para body (portal) para escapar de qualquer overflow pai
        if (pop.parentElement !== document.body) document.body.appendChild(pop);

        // Mede a altura real com visibility:hidden antes de exibir
        pop.style.visibility = 'hidden';
        pop.style.display    = 'block';
        pop.style.position   = 'fixed';
        pop.style.zIndex     = '99999';
        pop.style.bottom     = 'auto';
        pop.style.right      = 'auto';

        const popW = pop.offsetWidth;
        const popH = pop.offsetHeight;
        const rect = anchorEl.getBoundingClientRect();

        // Âncora: alinha pela borda direita do botão
        let left = rect.right - popW;
        let top  = rect.top - popH - 8;   // acima do botão por padrão

        // Se não couber acima, coloca abaixo
        if (top < 8) top = rect.bottom + 8;
        // Não ultrapassar bordas da viewport
        if (left < 8) left = 8;
        if (left + popW > window.innerWidth - 8) left = window.innerWidth - popW - 8;

        pop.style.top        = top  + 'px';
        pop.style.left       = left + 'px';
        pop.style.visibility = 'visible';
    }

    // === SUB-POPOVER: Subdivisões ===
    let _openSubPopId = null;

    function toggleSubPopover(id) {
        if (_openSubPopId && _openSubPopId !== id) closeSubPopover(_openSubPopId);
        const pop = document.getElementById('subPop_' + id);
        if (!pop) return;
        const isOpen = pop.style.display !== 'none';
        if (isOpen) { closeSubPopover(id); return; }
        // Ancora: o .sub-display clicado
        const anchor = document.querySelector(`[onclick="toggleSubPopover(${id})"]`);
        _positionPopover(pop, anchor || document.body);
        _openSubPopId = id;
        setTimeout(() => {
            function outsideClick(e) {
                if (!pop.contains(e.target) && !e.target.closest(`[onclick="toggleSubPopover(${id})"]`)) {
                    closeSubPopover(id);
                    document.removeEventListener('click', outsideClick);
                }
            }
            document.addEventListener('click', outsideClick);
        }, 0);
    }

    function closeSubPopover(id) {
        const pop = document.getElementById('subPop_' + id);
        if (pop) pop.style.display = 'none';
        if (_openSubPopId === id) _openSubPopId = null;
    }

    function syncSubDisplay(userId) {
        const pop = document.getElementById('subPop_' + userId);
        if (!pop) return;
        const editor = pop.querySelector('[data-subdivision-editor]');
        const checked = Array.from(editor.querySelectorAll('input[type="checkbox"]:checked'));
        const names = checked.map(cb => cb.closest('label')?.querySelector('span')?.textContent?.trim() || '');
        const row = pop.closest('.user-row');
        if (row) {
            row.dataset.subdivisions = checked.map(cb => cb.value).join(',');
            markRowDirty(pop.querySelector('input'));
        }
        // Atualiza o display de pills
        const display = pop.closest('.sub-popover-wrap')?.querySelector('.sub-display');
        if (!display) return;
        const iconEl = display.querySelector('.sub-edit-icon');
        display.innerHTML = '';
        if (names.length === 0) {
            display.innerHTML = '<span class="sub-none">Nenhuma</span>';
        } else {
            names.slice(0, 2).forEach(n => {
                const s = document.createElement('span');
                s.className = 'sub-pill'; s.textContent = n;
                display.appendChild(s);
            });
            if (names.length > 2) {
                const s = document.createElement('span');
                s.className = 'sub-pill sub-pill-more'; s.textContent = '+' + (names.length - 2);
                display.appendChild(s);
            }
        }
        const icon = document.createElement('i');
        icon.className = 'fa-solid fa-pen-to-square sub-edit-icon';
        display.appendChild(icon);
        display.onclick = () => toggleSubPopover(userId);
    }

    async function saveSubsAndClose(id, btn) {
        const row = btn.closest('.user-row');
        const saveBtn = row?.querySelector('.ac-btn-save');
        if (saveBtn) {
            closeSubPopover(id);
            await saveRowAccount(id, saveBtn);
            syncSubDisplay(id);
        } else {
            closeSubPopover(id);
        }
    }


    // AJAX: Criar Setor
    const formSector = document.getElementById('formCreateSector');
    if (formSector) {
        formSector.onsubmit = async function(e) {
            e.preventDefault();

            const confirm = await showConfirm({
                title: 'Criar Setor',
                message: 'Deseja realmente criar este novo setor no sistema?',
                type: 'info',
                confirmLabel: 'Criar Setor'
            });
            if(!confirm) return;

            const formData = new FormData(this);
            fetch('api/create_sector.php', { method: 'POST', body: withCsrf(formData) })
            .then(r => r.json()).then(data => {
                if(data.success) location.reload();
                else showAlert({ title: 'Atenção', message: data.message, type: 'warning' });
            });
        }
    }

    // Função: Excluir Setor
    async function deleteSector(id) {
        const confirm = await showConfirm({
            title: 'Excluir Setor',
            message: 'Tem certeza que deseja excluir este setor? Esta ação removerá o vínculo dele com os usuários.',
            type: 'warning',
            isDanger: true,
            confirmLabel: 'Excluir'
        });
        if(!confirm) return;

        const formData = new FormData();
        formData.append('id', id);
        fetch('api/delete_sector.php', { method: 'POST', body: withCsrf(formData) })
        .then(r => r.json()).then(data => {
            if(data.success) location.reload();
            else showAlert({ title: 'Erro', message: data.message, type: 'error' });
        });
    }

    // AJAX: Criar Usuário
    const formUser = document.getElementById('formCreateUser');
    if (formUser) {
        formUser.onsubmit = async function(e) {
            e.preventDefault();

            const confirm = await showConfirm({
                title: 'Confirmar Cadastro',
                message: 'Deseja realmente cadastrar este novo usuário no sistema?',
                type: 'info',
                confirmLabel: 'Cadastrar'
            });
            if(!confirm) return;

            const formData = new FormData(this);
            fetch('api/create_user.php', { method: 'POST', body: withCsrf(formData) })
            .then(r => r.json()).then(data => {
                if(data.success) {
                    showAlert({ title: 'Sucesso', message: 'Usuário cadastrado com sucesso!', type: 'success' });
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert({ title: 'Atenção', message: data.message, type: 'warning' });
                }
            });
        };
    }

    // AJAX: Vincular Responsável
    const formAssign = document.getElementById('formAssignSector');
    if (formAssign) {
        formAssign.onsubmit = async function(e) {
            e.preventDefault();

            const confirm = await showConfirm({
                title: 'Vincular Responsável',
                message: 'Deseja vincular este usuário como responsável pelo setor selecionado?',
                type: 'info',
                confirmLabel: 'Vincular'
            });
            if(!confirm) return;

            const formData = new FormData(this);
            fetch('api/assign_sector.php', { method: 'POST', body: withCsrf(formData) })
            .then(r => r.json()).then(data => {
                if(data.success) {
                    showAlert({ title: 'Sucesso', message: data.message, type: 'success' });
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert({ title: 'Erro', message: data.message, type: 'error' });
                }
            });
        }
    }

    // Função: Toggle Status (Ativar/Inativar)
    function toggleUserStatus(id, active) {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('status', active ? 1 : 0);
        fetch('api/update_user_admin.php', { method: 'POST', body: withCsrf(formData) })
        .then(r => r.json()).then(data => {
            if(!data.success) alert(data.message);
            else location.reload();
        });
    }

    // Função: Salvar Mudanças (Senha)
    // Controle do Pop-over de senha
    let _openPopoverId = null;

    function togglePassPopover(id, btn) {
        if (_openPopoverId && _openPopoverId !== id) closePassPopover(_openPopoverId);
        const pop = document.getElementById('passPopover_' + id);
        if (!pop) return;
        const isOpen = pop.style.display !== 'none';
        if (isOpen) { closePassPopover(id); return; }
        _positionPopover(pop, btn || document.querySelector(`[onclick*="togglePassPopover(${id}"]`));
        _openPopoverId = id;
        setTimeout(() => document.getElementById('pop_newpass_' + id)?.focus(), 50);
        setTimeout(() => {
            function outsideClick(e) {
                if (!pop.contains(e.target) && !e.target.closest('[onclick*="togglePassPopover"]')) {
                    closePassPopover(id);
                    document.removeEventListener('click', outsideClick);
                }
            }
            document.addEventListener('click', outsideClick);
        }, 0);
    }

    function closePassPopover(id) {
        const pop = document.getElementById('passPopover_' + id);
        if (pop) pop.style.display = 'none';
        const inp = document.getElementById('pop_newpass_' + id);
        if (inp) inp.value = '';
        if (_openPopoverId === id) _openPopoverId = null;
    }

    async function confirmPassChange(id) {
        const newPass = document.getElementById('pop_newpass_' + id)?.value?.trim();
        if (!newPass) {
            showAlert({ title: 'Campo vazio', message: 'Informe a nova senha.', type: 'warning' });
            return;
        }
        closePassPopover(id);
        const formData = new FormData();
        formData.append('id', id);
        formData.append('password', newPass);
        try {
            const r = await fetch('api/update_user_admin.php', { method: 'POST', body: withCsrf(formData) });
            const data = await r.json();
            if (data.success) showAlert({ title: 'Sucesso', message: 'Senha atualizada com sucesso!', type: 'success' });
            else showAlert({ title: 'Erro', message: data.message, type: 'error' });
        } catch(e) {
            showAlert({ title: 'Erro', message: 'Falha na comunicação com o servidor.', type: 'error' });
        }
    }

    // Mantém alias de compatibilidade
    async function saveUserChanges(id) { togglePassPopover(id); }

    // Função: Edição Direta de Campo (Role, Login)
    function setRowState(row, message, color = 'var(--text-muted)', dirty = false) {
    const saveState = row.querySelector('.row-save-state');
    if (saveState) {
        saveState.textContent = message;
        saveState.style.color = color;
    }
    row.dataset.dirty = dirty ? '1' : '0';
}

function markRowDirty(element) {
    const row = element.closest('.user-row');
    if (!row) return;
    setRowState(row, 'Alteracoes pendentes', '#d97706', true);
    const status = row.querySelector('.subdivision-save-status');
    if (status) {
        status.textContent = 'Alteracoes pendentes';
        status.style.color = '#d97706';
    }
}

async function saveRowAccount(id, button) {
    const row = button.closest('.user-row');
    if (!row) return;

    const loginInput = row.querySelector('.row-login-input');
    const roleSelect = row.querySelector('.row-role-select');
    const editor = row.querySelector('[data-subdivision-editor]');
    const status = row.querySelector('.subdivision-save-status');

    const selectedSubdivisions = Array.from(editor.querySelectorAll('input[type="checkbox"]:checked'))
        .map(input => input.value)
        .filter(Boolean);

    const formData = new FormData();
    formData.append('id', id);
    formData.append('login', (loginInput?.value || '').trim());
    formData.append('phone', (row.querySelector('.row-phone-input')?.value || '').trim());
    formData.append('role', roleSelect?.value || 'solicitante');
    if (selectedSubdivisions.length === 0) {
        formData.append('subdivisions[]', '');
    } else {
        selectedSubdivisions.forEach(value => formData.append('subdivisions[]', value));
    }

    setRowState(row, 'Salvando...', 'var(--text-muted)', false);
    if (status) {
        status.textContent = 'Salvando...';
        status.style.color = 'var(--text-muted)';
    }

    button.disabled = true;
    editor.querySelectorAll('input[type="checkbox"]').forEach(input => input.disabled = true);
    if (loginInput) loginInput.disabled = true;
    if (roleSelect) roleSelect.disabled = true;

    try {
        const response = await fetch('api/update_user_admin.php', { method: 'POST', body: withCsrf(formData) });
        const data = await response.json();
        if (!data.success) {
            setRowState(row, 'Erro ao salvar', '#ef4444', true);
            if (status) {
                status.textContent = 'Erro ao salvar';
                status.style.color = '#ef4444';
            }
            showAlert({ title: 'Erro', message: data.message, type: 'error' });
            return;
        }

        row.dataset.role = roleSelect?.value || row.dataset.role || '';
        row.dataset.subdivisions = selectedSubdivisions.join(',');
        setRowState(row, 'Salvo', '#10b981', false);
        if (status) {
            status.textContent = 'Subdivisoes salvas';
            status.style.color = '#10b981';
        }
        filterTable();
    } catch (e) {
        setRowState(row, 'Erro ao salvar', '#ef4444', true);
        if (status) {
            status.textContent = 'Erro ao salvar';
            status.style.color = '#ef4444';
        }
        showAlert({ title: 'Erro', message: 'Nao foi possivel salvar os dados da linha.', type: 'error' });
    } finally {
        button.disabled = false;
        editor.querySelectorAll('input[type="checkbox"]').forEach(input => input.disabled = false);
        if (loginInput) loginInput.disabled = false;
        if (roleSelect) roleSelect.disabled = false;
    }
}


    // Função: Excluir Usuário
    async function deleteUser(id) {
        const confirm = await showConfirm({
            title: 'Excluir Conta',
            message: 'ATENÇÃO: Deseja realmente excluir permanentemente esta conta? Esta ação não pode ser desfeita.',
            type: 'error',
            isDanger: true,
            confirmLabel: 'Excluir Permanentemente'
        });
        if(!confirm) return;

        const formData = new FormData();
        formData.append('id', id);
        formData.append('action', 'delete');
        fetch('api/update_user_admin.php', { method: 'POST', body: withCsrf(formData) })
        .then(r => r.json()).then(data => {
            if(data.success) location.reload();
            else showAlert({ title: 'Erro', message: data.message, type: 'error' });
        });
    }

    // Filtros e Busca
    document.getElementById('userSearch').addEventListener('input', filterTable);
    document.getElementById('roleFilter').addEventListener('change', filterTable);
    document.getElementById('subdivisionFilter').addEventListener('change', filterTable);

    function filterTable() {
        const term = document.getElementById('userSearch').value.trim().toLowerCase();
        const role = document.getElementById('roleFilter').value;
        const subdivision = document.getElementById('subdivisionFilter').value;
        const rows = document.querySelectorAll('.user-row');

        rows.forEach(row => {
            const loginInput = row.querySelector('.row-login-input');
            const userRole = row.getAttribute('data-role');
            const roleSelect = row.querySelector('.row-role-select');
            const selectedRole = roleSelect?.selectedOptions?.[0]?.textContent || userRole;
            const sectorText = row.querySelector('.sector-list')?.textContent || '';
            const subdivisionText = Array.from(row.querySelectorAll('[data-subdivision-editor] input[type="checkbox"]:checked'))
                .map(input => input.closest('label')?.textContent || '')
                .join(' ');
            const searchableText = [
                row.querySelector('.user-name')?.textContent || '',
                loginInput?.value || '',
                userRole || '',
                selectedRole || '',
                sectorText,
                subdivisionText
            ].join(' ').toLowerCase();
            const userSubdivisions = (row.dataset.subdivisions || '').split(',').filter(Boolean);

            const matchesSearch = !term || searchableText.includes(term);
            const matchesRole = role === 'all' || userRole === role;
            const matchesSubdivision = subdivision === 'all' || userSubdivisions.includes(subdivision);

            row.style.display = (matchesSearch && matchesRole && matchesSubdivision) ? '' : 'none';
        });
    }

    // Função: Adicionar Campo de Subdivisão
    function addSubdivisionField() {
        const container = document.getElementById('subdivisionContainer');
        const originalSelect = container.querySelector('select');
        const newSelect = originalSelect.cloneNode(true);
        newSelect.value = '';
        newSelect.style.marginTop = '8px';

        // Adicionar botão de remover
        const div = document.createElement('div');
        div.style.position = 'relative';
        div.style.marginTop = '8px';

        newSelect.style.marginTop = '0';
        div.appendChild(newSelect);

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.innerHTML = '<i class="fa-solid fa-times"></i>';
        removeBtn.style = 'position: absolute; right: -25px; top: 50%; transform: translateY(-50%); background: transparent; border: none; color: #ef4444; cursor: pointer; font-size: 0.8rem;';
        removeBtn.onclick = () => div.remove();

        div.appendChild(removeBtn);
        container.appendChild(div);
    }
</script>
