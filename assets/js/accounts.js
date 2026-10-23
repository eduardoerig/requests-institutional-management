// Usuários e setores (pages/accounts.php): filtros, edição em linha, setores e vínculos
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
