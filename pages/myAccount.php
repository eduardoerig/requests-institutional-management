<?php
$stmt = $pdo->prepare("SELECT id, name, login, phone, role FROM ctd_users WHERE id = ?");
$stmt->execute([$_SESSION['id']]);
$user = $stmt->fetch();

$role_labels = [
    'admin' => 'Administrador', 'adm' => 'Administrador', 'coord' => 'Coordenação',
    'adm_sub' => 'Coordenação de subdivisão',
    'gestor' => 'Setor de TI', 'ti' => 'Setor de TI', 'xerox' => 'Setor de Reprografia',
    'service' => 'Setor de Manutenção', 'shop' => 'Setor de Compras',
    'mkt' => 'Setor de Marketing', 'marketing' => 'Setor de Marketing',
    'solicitante' => 'Solicitante',
];
?>
<div class="narrow stack">
    <div class="page-head page-head--hide-mobile">
        <h1>Minha conta</h1>
    </div>

    <?php if ($force_reset == 1): ?>
        <div class="next-step" role="alert">
            <i class="fa-solid fa-key" aria-hidden="true"></i>
            <div>
                <strong>Crie sua senha pessoal.</strong> Você entrou com uma senha temporária. Troque agora para continuar usando o sistema.
                <div class="approve-box"><button type="button" class="btn btn--primary" onclick="document.getElementById('btnOpenChangePass').click()">Criar minha senha</button></div>
            </div>
        </div>
    <?php endif; ?>

    <section class="panel">
        <div class="panel__body">
            <div class="profile-head">
                <div class="avatar-lg" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string)($user['name'] ?? 'U'), 0, 1))) ?></div>
                <div>
                    <h2 style="font-size:1.3rem;"><?= e($user['name'] ?? 'Usuário') ?></h2>
                    <p class="muted"><?= e($role_labels[$user['role']] ?? 'Solicitante') ?></p>
                </div>
            </div>
            <dl class="info-grid mt-16" style="margin-bottom:0;">
                <div class="info-item"><dt>Usuário para entrar</dt><dd><?= e($user['login'] ?? '') ?></dd></div>
                <?php if (!empty($_SESSION['subdivision_names'])): ?>
                    <div class="info-item"><dt>Subdivisão</dt><dd><?= e(implode(', ', $_SESSION['subdivision_names'])) ?></dd></div>
                <?php endif; ?>
            </dl>
        </div>
    </section>

    <section class="panel" aria-label="Opções da conta">
        <ul class="list-links">
            <li><button type="button" id="btnOpenChangePass"><i class="fa-solid fa-lock" aria-hidden="true"></i> Trocar minha senha</button></li>
            <li><a href="ajuda"><i class="fa-solid fa-circle-question" aria-hidden="true"></i> Como usar o sistema</a></li>
            <li><a href="logout" class="is-danger"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Sair</a></li>
        </ul>
    </section>
</div>

<!-- Trocar senha -->
<div class="modal-overlay" id="modalChangePass">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="changePassTitle">
        <div class="modal-header">
            <h3 id="changePassTitle" class="modal-title" style="margin:0;">Criar nova senha</h3>
            <button type="button" class="modal-close" id="btnCloseModal" aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>

        <form id="formChangePass">
            <p class="modal-message">Use pelo menos 8 caracteres. Dica: junte duas palavras e um número (ex.: <strong>Cafe-Azul-27</strong>).</p>

            <div class="field">
                <label for="currentPass">Senha atual</label>
                <input id="currentPass" name="currentPass" type="password" required autocomplete="current-password" placeholder="A senha que você usou para entrar">
            </div>
            <div class="field">
                <label for="password">Nova senha</label>
                <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password" placeholder="Mínimo 8 caracteres">
            </div>
            <div class="field">
                <label for="passwordConfirm">Repita a nova senha</label>
                <input id="passwordConfirm" type="password" required minlength="8" autocomplete="new-password" placeholder="Digite a mesma senha">
            </div>
            <label class="row" style="min-height:44px; cursor:pointer;">
                <input type="checkbox" id="showPasswords" style="width:20px; height:20px; accent-color: var(--laranja);"> Mostrar senhas
            </label>
            <p id="passError" class="field-error" role="alert" hidden></p>

            <div class="modal-actions">
                <button type="submit" class="btn-modal btn-modal-confirm">Salvar nova senha</button>
                <button type="button" class="btn-modal btn-modal-cancel" id="btnCloseModal2">Voltar</button>
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

    (function () {
        const mustReset = <?= (int)$force_reset ?> === 1;
        const modal = document.getElementById('modalChangePass');
        const passError = document.getElementById('passError');

        document.getElementById('btnOpenChangePass').addEventListener('click', () => {
            modal.classList.add('active');
            setTimeout(() => document.getElementById('currentPass').focus(), 50);
        });

        const close = () => {
            if (mustReset) {
                showAlert({ title: 'Falta pouco', message: 'Crie sua senha pessoal para continuar usando o sistema.', type: 'warning' });
                return;
            }
            modal.classList.remove('active');
        };
        document.getElementById('btnCloseModal').addEventListener('click', close);
        document.getElementById('btnCloseModal2').addEventListener('click', close);
        modal.addEventListener('click', (e) => { if (e.target === modal && !mustReset) modal.classList.remove('active'); });

        if (mustReset) setTimeout(() => modal.classList.add('active'), 300);

        document.getElementById('showPasswords').addEventListener('change', function () {
            ['currentPass', 'password', 'passwordConfirm'].forEach(id => {
                document.getElementById(id).type = this.checked ? 'text' : 'password';
            });
        });

        const showError = (text) => { passError.textContent = text; passError.hidden = !text; };

        document.getElementById('formChangePass').addEventListener('submit', async function (e) {
            e.preventDefault();
            const newPass = document.getElementById('password').value;
            const confirmPass = document.getElementById('passwordConfirm').value;
            if (!document.getElementById('currentPass').value) return showError('Digite sua senha atual.');
            if (newPass.length < 8) return showError('A nova senha precisa ter pelo menos 8 caracteres.');
            if (newPass !== confirmPass) return showError('As duas senhas novas não são iguais. Digite de novo.');
            showError('');

            try {
                const response = await fetch('api/update_user_admin.php?self=1', { method: 'POST', body: withCsrf(new FormData(this)) });
                const data = await response.json();
                if (data.success) {
                    modal.classList.remove('active');
                    await showAlert({ title: 'Senha alterada', message: 'Pronto! Use a nova senha nos próximos acessos.', type: 'success' });
                    location.href = 'home';
                } else {
                    showError(data.message);
                }
            } catch (err) {
                showError('Sem conexão. Tente de novo.');
            }
        });
    })();
</script>
