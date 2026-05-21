<?php
// Buscar dados completos do usuário logado
$stmt = $pdo->prepare("SELECT * FROM ctd_users WHERE id = ?");
$stmt->execute([$_SESSION['id']]);
$user = $stmt->fetch();

$role_labels = [
    'admin' => 'Administrador',
    'adm_sub' => 'Admin de Subdivisão',
    'adm'   => 'Administrador',
    'coord' => 'Coordenador',
    'gestor'=> 'Gestor de TI',
    'ti'    => 'Gestor de TI',
    'xerox' => 'Gestor de Reprografia',
    'service' => 'Gestor de Manutenção',
    'shop'  => 'Gestor de Compras',
    'mkt'   => 'Gestor de Marketing',
    'marketing' => 'Gestor de Marketing',
    'solicitante' => 'Solicitante'
];
?>
<div class="main" style="max-width: 900px; margin: 0 auto;">
    <div class="page-header">
        <div style="display: flex; align-items: center; gap: 15px;">
            <i class="fa-solid fa-user-gear" style="font-size: 1.8rem; color: var(--primary);"></i>
            <h3>Minha Conta</h3>
        </div>
    </div>
    <!-- Banner Informativo -->
    <?php if ($force_reset == 1): ?>
    <div style="background: #fffbeb; border-left: 5px solid #f59e0b; padding: 20px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; gap: 20px; box-shadow: var(--shadow-sm);">
        <div style="width: 50px; height: 50px; background: #fef3c7; color: #d97706; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; flex-shrink: 0;">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <div style="flex: 1;">
            <h4 style="margin: 0 0 5px 0; color: #92400e; font-size: 1.1rem;">Troca de Senha Obrigatória</h4>
            <p style="margin: 0; color: #b45309; font-size: 0.9rem; line-height: 1.4;">Por motivos de segurança, sua conta foi configurada para exigir uma nova senha. Por favor, altere sua senha agora para continuar acessando o sistema.</p>
        </div>
        <button onclick="document.getElementById('btnOpenChangePass').click()" style="background: #d97706; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer;">Alterar Agora</button>
    </div>
    <?php endif; ?>

    <div class="account-grid">
        
        <!-- Bloco de Informações do Usuário -->
        <div class="card" style="cursor: default; width: 100%; border-left: 4px solid var(--primary); padding: 30px;">
            <div class="user-info-banner" style="display: flex; align-items: center; gap: 20px; margin-bottom: 25px;">
                <div class="user-avatar" style="width: 70px; height: 70px; border-radius: 50%; background: var(--primary); color: #fff; display: grid; place-items: center; font-size: 2rem; font-weight: 700;">
                    <?= strtoupper(substr($user['name'] ?? 'U', 0, 1)) ?>
                </div>
                <div>
                    <h2 style="margin: 0; font-size: 1.5rem; color: var(--text-main);"><?= htmlspecialchars($user['name'] ?? 'Usuário') ?></h2>
                    <span style="color: var(--text-muted); font-size: 0.9rem;"><?= $role_labels[$user['role']] ?? 'Solicitante' ?></span>
                </div>
            </div>

            <h4 style="color: var(--text-main); margin-bottom: 20px; font-size: 1.1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
                <i class="fa-solid fa-id-card" style="margin-right: 10px; color: var(--primary);"></i> Detalhes da Conta
            </h4>
            
            <div class="profile-details-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 25px;">
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Login de Acesso</span>
                    <span style="font-size: 1rem; color: var(--text-main); font-weight: 500;"><?= htmlspecialchars($user['login'] ?? 'Não informado') ?></span>
                </div>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Identificador</span>
                    <span style="font-size: 1rem; color: var(--text-main); font-weight: 500;">#<?= $user['id'] ?></span>
                </div>
            </div>

            <div style="margin-top: 40px; border-top: 1px solid var(--border-color); padding-top: 25px;">
                <button class="btn-primary" id="btnOpenChangePass" style="width: 100%; max-width: 280px; height: 50px;"><i class="fa-solid fa-lock" style="margin-right: 8px;"></i> Alterar Senha de Acesso</button>
            </div>
        </div>

    </div>
</div>

<!-- MODAL ALTERAR SENHA -->
<div class="modal-overlay" id="modalChangePass">
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-header">
      <h3 id="modalTitle">Alterar senha</h3>
      <button class="modal-close" id="btnCloseModal" aria-label="Fechar">✕</button>
    </div>

    <form class="modal-body" id="formChangePass">
      <div style="background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px; display: flex; align-items: flex-start; gap: 12px;">
        <i class="fa-solid fa-shield-halved" style="color: var(--primary); margin-top: 3px;"></i>
        <p style="margin: 0; font-size: 0.85rem; color: #64748b; line-height: 1.5;">Para sua segurança, escolha uma senha forte que não tenha sido usada anteriormente.</p>
      </div>

      <div class="modal-field">
        <label for="currentPass" style="font-weight: 600; color: var(--text-main); margin-bottom: 6px; display: block; font-size: 0.85rem;">Senha Atual</label>
        <div style="position: relative;">
            <i class="fa-solid fa-key" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.8rem;"></i>
            <input id="currentPass" name="currentPass" type="password" required style="width: 100%; padding: 10px 10px 10px 35px; border: 1px solid #e2e8f0; border-radius: 8px; outline: none; font-size: 0.9rem;" placeholder="Digite sua senha atual" />
        </div>
      </div>

      <div class="modal-field" style="margin-top: 15px;">
        <label for="password" style="font-weight: 600; color: var(--text-main); margin-bottom: 6px; display: block; font-size: 0.85rem;">Nova Senha</label>
        <div style="position: relative;">
            <i class="fa-solid fa-lock" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.8rem;"></i>
            <input id="password" name="password" type="password" required minlength="6" style="width: 100%; padding: 10px 10px 10px 35px; border: 1px solid #e2e8f0; border-radius: 8px; outline: none; font-size: 0.9rem;" placeholder="Mínimo 6 caracteres" />
        </div>
      </div>

      <div class="modal-actions" style="margin-top: 25px; gap: 12px;">
        <button type="submit" class="btn-modal btn-modal-confirm" style="flex: 2; height: 45px; font-weight: 600;">Atualizar Senha</button>
        <button type="button" class="btn-modal btn-modal-cancel" id="btnCloseModal2" style="flex: 1; height: 45px; font-weight: 600;">Voltar</button>
      </div>
    </form>
    </div>
</div>

<script>
    function withCsrf(formData) {
        if (!formData.has('csrf_token')) {
            formData.append('csrf_token', window.CSRF_TOKEN || '');
        }
        return formData;
    }

    // Gerenciamento do Modal de Senha
    const modal = document.getElementById("modalChangePass");
    const btnOpen = document.getElementById("btnOpenChangePass");
    const btnClose = document.getElementById("btnCloseModal");
    const btnClose2 = document.getElementById("btnCloseModal2");

    if (btnOpen) {
        btnOpen.addEventListener("click", () => modal.classList.add("active"));
    }
    
    const closeHandler = () => {
        if (<?= $force_reset ?> == 1) {
            showAlert({ title: 'Atenção', message: 'Você precisa alterar sua senha antes de continuar.', type: 'warning' });
            return;
        }
        modal.classList.remove("active");
    };

    if (btnClose) btnClose.addEventListener("click", closeHandler);
    if (btnClose2) btnClose2.addEventListener("click", closeHandler);
    modal.addEventListener("click", (e) => {
        if (e.target === modal) {
            if (<?= $force_reset ?> == 1) return;
            modal.classList.remove("active");
        }
    });

    // Se forçar reset, abre o modal automaticamente
    if (<?= $force_reset ?> == 1) {
        setTimeout(() => {
            modal.classList.add("active");
        }, 500);
    }

    // Envio do formulário
    document.getElementById("formChangePass").onsubmit = async function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        fetch('api/update_user_admin.php?self=1', { method: 'POST', body: withCsrf(formData) })
        .then(r => r.json()).then(async data => {
            if(data.success) {
                modal.classList.remove("active");
                await showAlert({ title: 'Sucesso', message: 'Sua senha foi alterada com sucesso!', type: 'success' });
                location.reload(); // Recarregar para atualizar estado da sessão
            } else {
                showAlert({ title: 'Erro', message: data.message, type: 'error' });
            }
        });
    };
</script>
