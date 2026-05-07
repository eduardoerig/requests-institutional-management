<?php
// Buscar dados completos do usuário logado (Apenas admins acessam esta página)
if (!in_array($_SESSION['role'], ['admin', 'adm'])) {
    echo '<div class="main"><p>Acesso negado.</p></div>';
    exit;
}

// Buscar todos os usuários com seus respectivos setores (Concatenados para evitar duplicidade)
$query_users = "
    SELECT u.*, 
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
    'xerox' => 'Xerox', 
    'ti' => 'TI'
];

$role_labels = [
    'admin' => 'Administrador',
    'adm_sub' => 'Admin de Subdivisão',
    'gestor' => 'Gestor de TI',
    'ti' => 'Gestor de TI',
    'xerox' => 'Gestor de Xerox',
    'service' => 'Gestor de Manutenção',
    'shop' => 'Gestor de Compras',
    'mkt' => 'Gestor de Marketing',
    'marketing' => 'Gestor de Marketing',
    'solicitante' => 'Solicitante'
];
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
                <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr; gap: 15px; align-items: start;">
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

    <!-- 4. Card Contas Registradas (Painel Principal) -->
    <div class="card" style="border-top: 4px solid var(--primary);">
        <div class="card-header" style="padding: 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
            <h4 style="margin: 0; font-size: 1.1rem; color: var(--text-main); display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-list-check" style="color: var(--primary);"></i> Contas Registradas
            </h4>
            <div style="display: flex; gap: 15px; flex: 1; max-width: 700px;">
                <select id="roleFilter" style="padding: 10px; border: 1px solid var(--border-color); border-radius: 20px; font-size: 0.8rem; outline: none; background: #f8fafc; color: var(--text-muted);">
                    <option value="all">Todos os Perfis</option>
                    <?php foreach($role_labels as $key => $label): ?>
                        <option value="<?= $key ?>"><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="position: relative; flex: 1;">
                    <i class="fa-solid fa-search" style="position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
                    <input type="text" id="userSearch" placeholder="Buscar por nome ou login..." 
                           style="padding: 10px 15px 10px 45px; border: 1px solid var(--border-color); border-radius: 25px; width: 100%; outline: none; font-size: 0.85rem; background: #f8fafc;">
                </div>
            </div>
        </div>
        
        <div style="max-height: 700px; overflow-y: auto; overflow-x: auto;">
            <table id="tableUsers" style="width: 100%; border-collapse: collapse; min-width: 1000px;">
                <thead style="position: sticky; top: 0; background: white; z-index: 10; box-shadow: 0 1px 0 var(--border-color);">
                    <tr style="text-align: left;">
                        <th style="padding: 18px 20px; font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px;">Usuário</th>
                        <th style="padding: 18px 20px; font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px;">Login</th>
                        <th style="padding: 18px 20px; font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px;">Setor</th>
                        <th style="padding: 18px 20px; font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; text-align: center;">Status</th>
                        <th style="padding: 18px 20px; font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px;">Alterar Senha</th>
                        <th style="padding: 18px 20px; font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; text-align: center;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($all_users as $u): 
                        $isActive = ($u['status'] ?? 1) == 1;
                        $opacity = $isActive ? '1' : '0.5';
                    ?>
                        <tr class="user-row" data-role="<?= $u['role'] ?>" style="border-bottom: 1px solid #f1f5f9; transition: all 0.2s; opacity: <?= $opacity ?>;">
                            <td style="padding: 15px 20px;">
                                <div style="display: flex; align-items: center; gap: 12px;">
                                    <div style="width: 38px; height: 38px; background: <?= $isActive ? 'var(--primary)' : '#94a3b8' ?>; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.9rem;">
                                        <?= strtoupper(substr($u['name'], 0, 1)) ?>
                                    </div>
                                    <div style="display: flex; flex-direction: column;">
                                        <span class="user-name" style="font-size: 0.95rem; color: var(--text-main); font-weight: 600;"><?= htmlspecialchars($u['name']) ?></span>
                                        <select onchange="updateAccountField(<?= $u['id'] ?>, 'role', this.value)" style="border: none; background: transparent; font-size: 0.75rem; color: var(--text-muted); cursor: pointer; padding: 0;">
                                            <?php foreach($role_labels as $key => $label): ?>
                                                <option value="<?= $key ?>" <?= $u['role'] == $key ? 'selected' : '' ?>><?= $label ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if($u['all_subdivisions']): ?>
                                            <div style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px;">
                                                <?php 
                                                $sub_list = explode(', ', $u['all_subdivisions']);
                                                foreach($sub_list as $sname):
                                                ?>
                                                    <span style="background: #e0e7ff; color: #4338ca; padding: 1px 6px; border-radius: 10px; font-size: 0.6rem; font-weight: 700; border: 1px solid #c7d2fe;">
                                                        <?= htmlspecialchars($sname) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="font-size: 0.75rem; color: var(--text-muted); font-style: italic; display: block; margin-top: 4px;">Sem subdivisão</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td style="padding: 15px 20px;">
                                <input type="text" value="<?= htmlspecialchars($u['login']) ?>" 
                                       onblur="updateAccountField(<?= $u['id'] ?>, 'login', this.value)"
                                       style="padding: 8px 12px; border: 1px solid transparent; border-radius: 6px; width: 140px; outline: none; font-size: 0.85rem; transition: border 0.2s;"
                                       onfocus="this.style.borderColor='var(--border-color)'" onblur="this.style.borderColor='transparent'">
                            </td>
                            <td style="padding: 15px 20px;">
                                <?php if($u['all_sectors']): ?>
                                    <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                        <?php 
                                        $sec_list = explode(', ', $u['all_sectors']);
                                        foreach($sec_list as $stitle):
                                            $label = $categories[$stitle] ?? ucfirst($stitle);
                                        ?>
                                            <span style="background: #f1f5f9; color: #475569; padding: 2px 8px; border-radius: 12px; font-size: 0.65rem; font-weight: 700; border: 1px solid #e2e8f0; white-space: nowrap;">
                                                <?= $label ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #cbd5e1; font-size: 0.75rem; font-style: italic;">Nenhum</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 15px 20px; text-align: center;">
                                <label class="switch">
                                    <input type="checkbox" <?= $isActive ? 'checked' : '' ?> onchange="toggleUserStatus(<?= $u['id'] ?>, this.checked)">
                                    <span class="slider round"></span>
                                </label>
                                <div style="font-size: 0.6rem; margin-top: 4px; font-weight: 800; color: <?= $isActive ? '#10b981' : '#ef4444' ?>;">
                                    <?= $isActive ? 'ATIVO' : 'INATIVO' ?>
                                </div>
                            </td>
                            <td style="padding: 15px 20px;">
                                <input type="password" id="pass_<?= $u['id'] ?>" placeholder="Nova senha..." 
                                       style="padding: 8px 12px; border: 1px solid var(--border-color); border-radius: 6px; width: 130px; outline: none; font-size: 0.85rem;">
                            </td>
                            <td style="padding: 15px 20px; text-align: center;">
                                <div style="display: flex; justify-content: center; gap: 8px;">
                                    <button onclick="saveUserChanges(<?= $u['id'] ?>)" class="btn-primary" style="width: 36px; height: 36px; border-radius: 8px; padding: 0; background: #1e293b;">
                                        <i class="fa-solid fa-save"></i>
                                    </button>
                                    <button onclick="deleteUser(<?= $u['id'] ?>)" class="btn-primary" style="width: 36px; height: 36px; border-radius: 8px; padding: 0; background: #ef4444;">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
    .card { transform: none !important; cursor: default !important; box-shadow: 0 4px 12px rgba(0,0,0,0.05) !important; transition: none !important; }
    .card:hover { transform: none !important; box-shadow: 0 4px 12px rgba(0,0,0,0.05) !important; transition: none !important; }
    .user-row:hover { background: #f8fafc; opacity: 1 !important; }
    #userSearch:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1); background: white; }
    
    /* Toggle Switch Styles */
    .switch { position: relative; display: inline-block; width: 34px; height: 20px; }
    .switch input { opacity: 0; width: 0; height: 0; }
    .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .4s; }
    .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 3px; bottom: 3px; background-color: white; transition: .4s; }
    input:checked + .slider { background-color: #10b981; }
    input:checked + .slider:before { transform: translateX(14px); }
    .slider.round { border-radius: 34px; }
    .slider.round:before { border-radius: 50%; }

    @media (max-width: 768px) {
        .actions-grid { grid-template-columns: 1fr !important; }
        #formCreateUser > div:first-child { grid-template-columns: 1fr !important; }
    }
</style>

<script>
    // AJAX: Criar Setor
    const formSector = document.getElementById('formCreateSector');
    if (formSector) {
        formSector.onsubmit = function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('api/create_sector.php', { method: 'POST', body: formData })
            .then(r => r.json()).then(data => {
                if(data.success) location.reload();
                else alert(data.message);
            });
        }
    }

    // Função: Excluir Setor
    function deleteSector(id) {
        if(!confirm('Tem certeza que deseja excluir este setor?')) return;
        const formData = new FormData();
        formData.append('id', id);
        fetch('api/delete_sector.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            if(data.success) location.reload();
            else alert(data.message);
        });
    }

    // AJAX: Criar Usuário
    const formUser = document.getElementById('formCreateUser');
    if (formUser) {
        formUser.onsubmit = function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('api/create_user.php', { method: 'POST', body: formData })
            .then(r => r.json()).then(data => {
                alert(data.message);
                if(data.success) location.reload();
            });
        };
    }

    // AJAX: Vincular Responsável
    const formAssign = document.getElementById('formAssignSector');
    if (formAssign) {
        formAssign.onsubmit = function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('api/assign_sector.php', { method: 'POST', body: formData })
            .then(r => r.json()).then(data => {
                alert(data.message);
                if(data.success) location.reload();
            });
        }
    }

    // Função: Toggle Status (Ativar/Inativar)
    function toggleUserStatus(id, active) {
        const formData = new FormData();
        formData.append('id', id);
        formData.append('status', active ? 1 : 0);
        fetch('api/update_user_admin.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            if(!data.success) alert(data.message);
            else location.reload();
        });
    }

    // Função: Salvar Mudanças (Senha)
    function saveUserChanges(id) {
        const pass = document.getElementById('pass_' + id).value;

        if (!pass) {
            alert('Informe uma nova senha para salvar.');
            return;
        }

        const formData = new FormData();
        formData.append('id', id);
        formData.append('password', pass);
        
        fetch('api/update_user_admin.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            alert(data.message);
            if(data.success) document.getElementById('pass_' + id).value = '';
        });
    }

    // Função: Edição Direta de Campo (Role, Login)
    function updateAccountField(id, field, value) {
        const formData = new FormData();
        formData.append('id', id);
        formData.append(field, value);
        fetch('api/update_user_admin.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            if(!data.success) alert(data.message);
        });
    }

    // Função: Excluir Usuário
    function deleteUser(id) {
        if(!confirm('ATENÇÃO: Deseja realmente excluir permanentemente esta conta? Esta ação não pode ser desfeita.')) return;
        const formData = new FormData();
        formData.append('id', id);
        formData.append('action', 'delete');
        fetch('api/update_user_admin.php', { method: 'POST', body: formData })
        .then(r => r.json()).then(data => {
            alert(data.message);
            if(data.success) location.reload();
        });
    }

    // Filtros e Busca
    document.getElementById('userSearch').addEventListener('input', filterTable);
    document.getElementById('roleFilter').addEventListener('change', filterTable);

    function filterTable() {
        const term = document.getElementById('userSearch').value.toLowerCase();
        const role = document.getElementById('roleFilter').value;
        const rows = document.querySelectorAll('.user-row');

        rows.forEach(row => {
            const name = row.querySelector('.user-name').textContent.toLowerCase();
            const login = row.querySelector('input[type="text"]').value.toLowerCase();
            const userRole = row.getAttribute('data-role');

            const matchesSearch = name.includes(term) || login.includes(term);
            const matchesRole = role === 'all' || userRole === role;

            row.style.display = (matchesSearch && matchesRole) ? '' : 'none';
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
