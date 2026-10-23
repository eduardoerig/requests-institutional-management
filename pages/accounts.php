<?php
// Buscar dados completos do usuário logado (Apenas admins acessam esta página)
if (!isGlobalAdminRole($_SESSION['role'] ?? '')) {
    echo '<div class="panel"><div class="empty-big"><i class="fa-solid fa-lock"></i><h2>Sem acesso</h2><p>Somente administradores gerenciam usuários.</p></div></div>';
    return;
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
    'solicitante' => 'Solicitante (faz pedidos)',
    'adm_sub' => 'Coordenação (aprova a subdivisão)',
    'ti' => 'Setor de TI',
    'service' => 'Setor de Manutenção',
    'shop' => 'Setor de Compras',
    'xerox' => 'Setor de Reprografia',
    'mkt' => 'Setor de Marketing',
    'admin' => 'Administrador (acesso total)',
];

// Mapeamento estendido para exibir nomes de perfis legados no banco de dados
$full_role_labels = array_merge($role_labels, [
    'gestor' => 'Gestor de TI',
    'marketing' => 'Gestor de Marketing'
]);

?>
<div class="page-head">
    <div class="page-head--hide-mobile">
        <h1>Usuários e setores</h1>
        <p>Cadastre pessoas, defina o que cada uma pode fazer e ligue os responsáveis aos setores.</p>
    </div>
    <a href="api/export_accounts_csv.php" class="btn btn--ghost hide-mobile"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> Exportar planilha</a>
</div>

<div class="users-layout">
    <section class="panel users-main" aria-labelledby="contasTitulo">
        <div class="panel__head">
            <h2 id="contasTitulo"><i class="fa-solid fa-users" aria-hidden="true"></i> Contas <span id="userCount" class="sidebar-badge"></span></h2>
            <div class="toolbar__filters">
                <div class="search-box">
                    <i class="fa-solid fa-search" aria-hidden="true"></i>
                    <input type="search" id="userSearch" placeholder="Buscar por nome ou login" aria-label="Buscar usuário">
                </div>
                <select id="roleFilter" aria-label="Filtrar por perfil">
                    <option value="all">Todos os perfis</option>
                    <?php foreach($role_labels as $key => $label): ?>
                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="subdivisionFilter" aria-label="Filtrar por subdivisão">
                    <option value="all">Todas as subdivisões</option>
                    <?php foreach($all_subdivisions as $sub): ?>
                        <option value="<?= (int)$sub['id'] ?>"><?= e($sub['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table id="tableUsers" class="data-table users-table">
                <thead>
                    <tr>
                        <th class="ac-th">Usuário</th>
                        <th class="ac-th">Login</th>
                        <th class="ac-th">WhatsApp</th>
                        <th class="ac-th">O que faz</th>
                        <th class="ac-th">Setores</th>
                        <th class="ac-th">Subdivisões</th>
                        <th class="ac-th is-center">Situação</th>
                        <th class="ac-th is-center">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($all_users as $u):
                        $isActive = ($u['status'] ?? 1) == 1;
                        $subIds = array_filter(explode(',', $u['all_subdivision_ids'] ?? ''));
                    ?>
                    <tr class="user-row ac-row<?= $isActive ? '' : ' is-inactive' ?>"
                        data-role="<?= e($u['role']) ?>"
                        data-subdivisions="<?= htmlspecialchars(implode(',', $subIds)) ?>">

                        <!-- Usuário -->
                        <td class="ac-td">
                            <div class="ac-person">
                                <div class="ac-avatar" aria-hidden="true">
                                    <?= e(mb_strtoupper(mb_substr((string)$u['name'], 0, 1))) ?>
                                </div>
                                <div>
                                    <div class="user-name"><?= htmlspecialchars($u['name']) ?></div>
                                    <div class="ac-id">Nº <?= (int)$u['id'] ?></div>
                                </div>
                            </div>
                        </td>

                        <!-- Login -->
                        <td class="ac-td">
                            <input type="text" value="<?= htmlspecialchars($u['login']) ?>"
                                   class="row-login-input ac-input"
                                   oninput="markRowDirty(this)">
                        </td>

                        <!-- WhatsApp -->
                        <td class="ac-td">
                            <input type="text" value="<?= htmlspecialchars($u['phone'] ?? '') ?>"
                                   class="row-phone-input ac-input"
                                   placeholder="5511999998888"
                                   maxlength="20"
                                   oninput="markRowDirty(this)"
                                   title="Formato internacional sem + (ex: 5511999998888)">
                        </td>

                        <!-- Perfil -->
                        <td class="ac-td">
                            <select class="row-role-select ac-input" onchange="markRowDirty(this)">
                                <?php foreach($full_role_labels as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= $u['role'] == $key ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>

                        <!-- Setores -->
                        <td class="ac-td">
                            <?php if($u['all_sectors']): ?>
                                <div class="sector-list">
                                    <?php foreach(explode(', ', $u['all_sectors']) as $stitle):
                                        $lbl = $categories[$stitle] ?? ucfirst($stitle);
                                    ?>
                                        <span class="ac-pill"><?= e($lbl) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="sub-none">Nenhum</span>
                            <?php endif; ?>
                        </td>

                        <!-- Subdivisões -->
                        <td class="ac-td">
                            <div class="sub-popover-wrap">

                                <!-- Exibição compacta: pills + botão editar -->
                                <div class="sub-display" role="button" tabindex="0" onclick="toggleSubPopover(<?= $u['id'] ?>)">
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
                                    <i class="fa-solid fa-pen-to-square sub-edit-icon" aria-hidden="true"></i>
                                </div>

                                <!-- Popover com checkboxes (oculto) -->
                                <div id="subPop_<?= $u['id'] ?>" class="sub-popover" style="display:none;">
                                    <div class="sub-pop-arrow"></div>
                                    <p class="sub-pop-title"><i class="fa-solid fa-layer-group"></i> Subdivisões</p>
                                    <!-- Editor oculto mantido para compatibilidade com saveRowAccount -->
                                    <div class="subdivision-editor" data-subdivision-editor data-user-id="<?= $u['id'] ?>">
                                        <?php foreach($all_subdivisions as $sub): ?>
                                            <label class="sub-pop-check-label">
                                                <input type="checkbox"
                                                       value="<?= $sub['id'] ?>"
                                                       <?= in_array((string)$sub['id'], $subIds, true) ? 'checked' : '' ?>
                                                       onchange="syncSubDisplay(<?= $u['id'] ?>)">
                                                <span><?= htmlspecialchars($sub['name']) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="popover-actions">
                                        <button onclick="saveSubsAndClose(<?= $u['id'] ?>, this)" class="sub-pop-btn sub-pop-save"><i class="fa-solid fa-floppy-disk"></i> Salvar</button>
                                        <button onclick="closeSubPopover(<?= $u['id'] ?>)" class="sub-pop-btn sub-pop-cancel">Cancelar</button>
                                    </div>
                                </div>
                            </div>
                        </td>

                        <!-- Status -->
                        <td class="ac-td is-center">
                            <label class="switch">
                                <input type="checkbox" <?= $isActive ? 'checked' : '' ?> onchange="toggleUserStatus(<?= $u['id'] ?>, this.checked, this)" aria-label="Conta ativa">
                                <span class="slider round"></span>
                            </label>
                            <div class="ac-status<?= $isActive ? '' : ' ac-status--off' ?>">
                                <?= $isActive ? 'Ativa' : 'Inativa' ?>
                            </div>
                        </td>

                        <!-- Ações -->
                        <td class="ac-td is-center">
                            <div class="ac-actions">
                                <button onclick="saveRowAccount(<?= $u['id'] ?>, this)" class="ac-btn ac-btn-save" title="Salvar alterações">
                                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                                </button>
                                <div class="pass-popover-wrap">
                                    <button onclick="togglePassPopover(<?= $u['id'] ?>, this)" class="ac-btn ac-btn-key" title="Alterar senha">
                                        <i class="fa-solid fa-key" aria-hidden="true"></i>
                                    </button>
                                    <div id="passPopover_<?= $u['id'] ?>" class="pass-popover" style="display:none;">
                                        <div class="pass-popover-arrow"></div>
                                        <p class="pass-popover-title">Nova senha</p>
                                        <input type="password" id="pop_newpass_<?= $u['id'] ?>" placeholder="Mínimo 8 caracteres" class="pass-popover-input" autocomplete="new-password">
                                        <div class="popover-actions">
                                            <button onclick="confirmPassChange(<?= $u['id'] ?>)" class="pass-pop-btn pass-pop-confirm">Confirmar</button>
                                            <button onclick="closePassPopover(<?= $u['id'] ?>)" class="pass-pop-btn pass-pop-cancel">Cancelar</button>
                                        </div>
                                    </div>
                                </div>
                                <button onclick="deleteUser(<?= $u['id'] ?>)" class="ac-btn ac-btn-del" title="Excluir usuário">
                                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                </button>
                            </div>
                            <div class="row-save-state" aria-live="polite"></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <aside class="stack" aria-label="Cadastros">
        <section class="panel" aria-labelledby="novoUsuarioTitulo">
            <div class="panel__head"><h2 id="novoUsuarioTitulo"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Cadastrar pessoa</h2></div>
            <div class="panel__body">
                <form id="formCreateUser">
                    <div class="field">
                        <label for="new_name">Nome completo</label>
                        <input type="text" id="new_name" name="new_name" required>
                    </div>
                    <div class="field">
                        <label for="new_login">Usuário para entrar</label>
                        <input type="text" id="new_login" name="new_login" placeholder="Ex.: joao.silva" autocapitalize="none" required>
                    </div>
                    <div class="field">
                        <label for="new_password">Senha temporária</label>
                        <input type="text" id="new_password" name="new_password" minlength="8" placeholder="Mínimo 8 caracteres" autocomplete="off" required>
                        <span>Passe para a pessoa; ela cria a própria senha no primeiro acesso.</span>
                    </div>
                    <div class="field">
                        <label for="new_role">O que a pessoa faz</label>
                        <select id="new_role" name="new_role" required>
                            <?php foreach($role_labels as $key => $label): ?>
                                <option value="<?= e($key) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" id="subdivisionContainer">
                        <label class="field__label-row">Subdivisão
                            <button type="button" class="btn btn--sm btn--ghost" onclick="addSubdivisionField()"><i class="fa-solid fa-plus" aria-hidden="true"></i> Outra</button>
                        </label>
                        <select name="new_subdivisions[]" aria-label="Subdivisão">
                            <option value="">Nenhuma</option>
                            <?php foreach($all_subdivisions as $sub): ?>
                                <option value="<?= (int)$sub['id'] ?>"><?= e($sub['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="new_phone">WhatsApp <small>(opcional)</small></label>
                        <input type="tel" id="new_phone" name="new_phone" placeholder="5511999998888" maxlength="20" inputmode="numeric">
                    </div>
                    <input type="hidden" name="force_reset" value="1">
                    <button type="submit" class="btn btn--primary btn--block"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Cadastrar pessoa</button>
                </form>
            </div>
        </section>

        <section class="panel" aria-labelledby="setoresTitulo">
            <div class="panel__head"><h2 id="setoresTitulo"><i class="fa-solid fa-building" aria-hidden="true"></i> Setores</h2></div>
            <div class="panel__body">
                <ul class="sector-admin">
                    <?php foreach($all_sectors as $s):
                        $label = $categories[$s['title']] ?? ucfirst($s['title']);
                        $isSystem = areaTitleToSector((string)$s['title']) !== null;
                    ?>
                        <li>
                            <span class="sector-admin__name"><?= e($label) ?></span>
                            <?php if ($isSystem): ?>
                                <span class="sector-admin__note">Setor do sistema</span>
                            <?php else: ?>
                                <button type="button" class="btn btn--sm btn--ghost" onclick="deleteSector(<?= (int)$s['id'] ?>)" aria-label="Excluir setor <?= e($label) ?>"><i class="fa-solid fa-trash-can" aria-hidden="true"></i> Excluir</button>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form id="formCreateSector" class="inline-form">
                    <input type="text" name="sector_title" placeholder="Novo setor" required aria-label="Nome do novo setor">
                    <button type="submit" class="btn"><i class="fa-solid fa-plus" aria-hidden="true"></i> Criar</button>
                </form>
            </div>
        </section>

        <section class="panel" aria-labelledby="vinculoTitulo">
            <div class="panel__head"><h2 id="vinculoTitulo"><i class="fa-solid fa-link" aria-hidden="true"></i> Responsável de setor</h2></div>
            <div class="panel__body">
                <form id="formAssignSector">
                    <div class="field">
                        <label for="assignUser">Pessoa</label>
                        <select id="assignUser" name="user_id" required>
                            <option value="">Escolha</option>
                            <?php foreach($all_users as $u): ?>
                                <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="assignSector">Setor</label>
                        <select id="assignSector" name="sector_id" required>
                            <option value="">Escolha</option>
                            <?php foreach($all_sectors as $s): ?>
                                <option value="<?= (int)$s['id'] ?>"><?= e($categories[$s['title']] ?? ucfirst($s['title'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn--block"><i class="fa-solid fa-link" aria-hidden="true"></i> Ligar</button>
                </form>
            </div>
        </section>
    </aside>
</div>



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
    async function toggleUserStatus(id, active, checkbox) {
        const ok = await showConfirm({
            title: active ? 'Reativar conta' : 'Desativar conta',
            message: active
                ? 'A pessoa voltará a conseguir entrar no sistema.'
                : 'A pessoa não conseguirá mais entrar. O histórico de pedidos é mantido.',
            type: 'warning',
            confirmLabel: active ? 'Reativar' : 'Desativar',
            isDanger: !active
        });
        if (!ok) {
            if (checkbox) checkbox.checked = !active;
            return;
        }
        const formData = new FormData();
        formData.append('id', id);
        formData.append('status', active ? 1 : 0);
        try {
            const data = await (await fetch('api/update_user_admin.php', { method: 'POST', body: withCsrf(formData) })).json();
            if (data.success) {
                location.reload();
            } else {
                if (checkbox) checkbox.checked = !active;
                showAlert({ title: 'Não foi possível', message: data.message, type: 'error' });
            }
        } catch (e) {
            if (checkbox) checkbox.checked = !active;
            showAlert({ title: 'Sem conexão', message: 'Tente de novo.', type: 'error' });
        }
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
        if (!newPass || newPass.length < 8) {
            showAlert({ title: 'Senha curta', message: 'A senha precisa ter pelo menos 8 caracteres.', type: 'warning' });
            return;
        }
        closePassPopover(id);
        const formData = new FormData();
        formData.append('id', id);
        formData.append('password', newPass);
        try {
            const r = await fetch('api/update_user_admin.php', { method: 'POST', body: withCsrf(formData) });
            const data = await r.json();
            if (data.success) showAlert({ title: 'Senha temporária definida', message: 'Passe a senha para a pessoa. No próximo acesso o sistema vai pedir para ela criar uma senha nova.', type: 'success' });
            else showAlert({ title: 'Erro', message: data.message, type: 'error' });
        } catch(e) {
            showAlert({ title: 'Erro', message: 'Falha na comunicação com o servidor.', type: 'error' });
        }
    }

    // Mantém alias de compatibilidade
    async function saveUserChanges(id) { togglePassPopover(id); }

    // Função: Edição Direta de Campo (Role, Login)
    function setRowState(row, message, color = 'var(--texto-2)', dirty = false) {
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
    setRowState(row, 'Alterações pendentes', 'var(--aviso)', true);
    const status = row.querySelector('.subdivision-save-status');
    if (status) {
        status.textContent = 'Alterações pendentes';
        status.style.color = 'var(--aviso)';
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

    setRowState(row, 'Salvando...', 'var(--texto-2)', false);
    if (status) {
        status.textContent = 'Salvando...';
        status.style.color = 'var(--texto-2)';
    }

    button.disabled = true;
    editor.querySelectorAll('input[type="checkbox"]').forEach(input => input.disabled = true);
    if (loginInput) loginInput.disabled = true;
    if (roleSelect) roleSelect.disabled = true;

    try {
        const response = await fetch('api/update_user_admin.php', { method: 'POST', body: withCsrf(formData) });
        const data = await response.json();
        if (!data.success) {
            setRowState(row, 'Erro ao salvar', 'var(--perigo)', true);
            if (status) {
                status.textContent = 'Erro ao salvar';
                status.style.color = 'var(--perigo)';
            }
            showAlert({ title: 'Erro', message: data.message, type: 'error' });
            return;
        }

        row.dataset.role = roleSelect?.value || row.dataset.role || '';
        row.dataset.subdivisions = selectedSubdivisions.join(',');
        setRowState(row, 'Salvo', 'var(--ok)', false);
        if (status) {
            status.textContent = 'Subdivisões salvas';
            status.style.color = 'var(--ok)';
        }
        filterTable();
    } catch (e) {
        setRowState(row, 'Erro ao salvar', 'var(--perigo)', true);
        if (status) {
            status.textContent = 'Erro ao salvar';
            status.style.color = 'var(--perigo)';
        }
        showAlert({ title: 'Erro', message: 'Não foi possível salvar os dados da linha.', type: 'error' });
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
        const newSelect = container.querySelector('select').cloneNode(true);
        newSelect.value = '';
        const row = document.createElement('div');
        row.className = 'row';
        row.classList.add('row--nowrap');
        row.appendChild(newSelect);
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn--sm btn--ghost';
        removeBtn.setAttribute('aria-label', 'Remover subdivisão');
        removeBtn.innerHTML = '<i class="fa-solid fa-xmark" aria-hidden="true"></i>';
        removeBtn.onclick = () => row.remove();
        row.appendChild(removeBtn);
        container.appendChild(row);
    }
</script>
