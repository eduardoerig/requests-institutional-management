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
    'xerox' => 'Gestor de Xerox',
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

    <div style="display: flex; flex-direction: column; gap: 30px;">
        
        <!-- Bloco de Informações do Usuário -->
        <div class="card" style="cursor: default; width: 100%; border-left: 4px solid var(--primary); padding: 30px;">
            <h4 style="color: var(--text-main); margin-bottom: 20px; font-size: 1.1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
                <i class="fa-solid fa-id-card" style="margin-right: 10px; color: var(--primary);"></i> Perfil do Usuário
            </h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Nome Completo</span>
                    <span style="font-size: 1.05rem; color: var(--text-main); font-weight: 500;"><?= htmlspecialchars($user['name'] ?? 'Não informado') ?></span>
                </div>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Login de Acesso</span>
                    <span style="font-size: 1.05rem; color: var(--text-main); font-weight: 500;"><?= htmlspecialchars($user['login'] ?? 'Não informado') ?></span>
                </div>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Tipo de Perfil</span>
                    <span style="font-size: 1.05rem; color: var(--text-main); font-weight: 500;"><?= $role_labels[$user['role']] ?? 'Solicitante' ?></span>
                </div>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Usuário ID</span>
                    <span style="font-size: 1.05rem; color: var(--text-main); font-weight: 500;">#<?= $user['id'] ?></span>
                </div>
            </div>
            <div style="margin-top: 30px;">
                <button class="btn-primary" id="btnOpenChangePass" style="width: 220px;"><i class="fa-solid fa-lock" style="margin-right: 8px;"></i> Alterar senha</button>
            </div>
        </div>

    </div>
</div>

<!-- MODAL ALTERAR SENHA -->
<div class="modal-overlay" id="modalChangePass" aria-hidden="true">
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-header">
      <h3 id="modalTitle">Alterar senha</h3>
      <button class="modal-close" id="btnCloseModal" aria-label="Fechar">✕</button>
    </div>

    <form class="modal-body" id="formChangePass">
      <div class="modal-field">
        <label for="currentPass">Senha atual</label>
        <input id="currentPass" name="currentPass" type="password" required />
      </div>

      <div class="modal-field">
        <label for="newPass">Nova senha</label>
        <input id="newPass" name="newPass" type="password" required minlength="6" />
      </div>

      <div class="modal-actions">
        <button type="submit" class="btn-primary">Confirmar</button>
      </div>
    </form>
    </div>
</div>

<script>
    // Gerenciamento do Modal de Senha
    const modal = document.getElementById("modalChangePass");
    const btnOpen = document.getElementById("btnOpenChangePass");
    const btnClose = document.getElementById("btnCloseModal");

    if (btnOpen) {
        btnOpen.addEventListener("click", () => modal.classList.add("open"));
    }
    if (btnClose) {
        btnClose.addEventListener("click", () => modal.classList.remove("open"));
    }
    modal.addEventListener("click", (e) => {
        if (e.target === modal) modal.classList.remove("open");
    });
</script>
