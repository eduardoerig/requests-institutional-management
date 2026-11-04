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
        <div class="notice notice--warn" role="alert">
            <i class="fa-solid fa-key" aria-hidden="true"></i>
            <div class="notice__body">
                <p><strong>Crie sua senha pessoal.</strong> Você entrou com uma senha temporária. Troque agora para continuar usando o sistema.</p>
                <div><button type="button" class="btn btn--primary" onclick="document.getElementById('btnOpenChangePass').click()">Criar minha senha</button></div>
            </div>
        </div>
    <?php endif; ?>

    <section class="panel">
        <div class="panel__body">
            <div class="profile-head">
                <div class="avatar-lg" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string)($user['name'] ?? 'U'), 0, 1))) ?></div>
                <div>
                    <h2 class="profile-head__name"><?= e($user['name'] ?? 'Usuário') ?></h2>
                    <p class="muted"><?= e($role_labels[$user['role']] ?? 'Solicitante') ?></p>
                </div>
            </div>
            <dl class="info-grid profile-info">
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
            <li><button type="button" class="is-danger" data-logout><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Sair</button></li>
        </ul>
    </section>
</div>

<!-- Trocar senha. Com senha temporária (force_reset) não fecha: só Sair ou salvar a senha nova -->
<div class="modal-overlay pw" id="modalChangePass">
    <div class="modal-card pw__card" role="dialog" aria-modal="true" aria-labelledby="changePassTitle"<?= $force_reset == 1 ? ' aria-describedby="changePassLead"' : '' ?>>
        <?php if ($force_reset == 1): ?>
            <div class="pw__banner">
                <i class="fa-solid fa-lock" aria-hidden="true"></i>
                <div>
                    <h3 id="changePassTitle">Crie sua senha para continuar</h3>
                    <p id="changePassLead">Você entrou com uma senha temporária. Troque por uma senha só sua para liberar o sistema.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="pw__head">
                <h3 id="changePassTitle">Trocar minha senha</h3>
                <button type="button" class="pw__close" data-pw-close aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
        <?php endif; ?>

        <form id="formChangePass" novalidate>
            <div class="pw__body">
                <div class="field">
                    <label for="currentPass"><?= $force_reset == 1 ? 'Senha temporária' : 'Senha atual' ?></label>
                    <input id="currentPass" name="currentPass" type="password" required autocomplete="current-password">
                </div>
                <div class="field">
                    <label for="password">Nova senha</label>
                    <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password" aria-describedby="pwStrength pwRules">
                </div>
                <div class="field">
                    <label for="passwordConfirm">Confirmar nova senha</label>
                    <input id="passwordConfirm" type="password" required minlength="8" autocomplete="new-password">
                </div>
                <label class="check-line pw__show">
                    <input type="checkbox" id="showPasswords"> Mostrar senhas
                </label>

                <div class="pw__meter" data-level="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
                <p class="pw__strength" id="pwStrength" data-level="0" aria-live="polite">Força: digite a nova senha</p>
                <ul class="pw__rules" id="pwRules">
                    <li data-rule="length">Pelo menos 8 caracteres</li>
                    <li data-rule="upper">Uma letra maiúscula</li>
                    <li data-rule="number">Um número</li>
                    <li data-rule="symbol">Um símbolo, como ! ou #</li>
                    <li data-rule="different">Diferente da <?= $force_reset == 1 ? 'temporária' : 'atual' ?></li>
                    <li data-rule="match">As duas senhas iguais</li>
                </ul>
                <p id="passError" class="field-error" role="alert" hidden></p>
            </div>

            <div class="pw__actions">
                <?php if ($force_reset == 1): ?>
                    <button type="button" class="btn" data-logout>Sair</button>
                    <button type="submit" class="btn btn--primary" id="pwSubmit" disabled>Salvar senha e entrar</button>
                <?php else: ?>
                    <button type="button" class="btn" data-pw-close>Voltar</button>
                    <button type="submit" class="btn btn--primary" id="pwSubmit" disabled>Salvar nova senha</button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        const mustReset = <?= (int)$force_reset ?> === 1;
        const modal = document.getElementById('modalChangePass');
        const form = document.getElementById('formChangePass');
        const current = document.getElementById('currentPass');
        const pass = document.getElementById('password');
        const confirmPass = document.getElementById('passwordConfirm');
        const submit = document.getElementById('pwSubmit');
        const meter = modal.querySelector('.pw__meter');
        const strength = document.getElementById('pwStrength');
        const passError = document.getElementById('passError');

        // As mesmas regras de passwordProblems() em includes/helpers.php
        const rules = {
            // Como o servidor: sem espaços nas pontas e contando caracteres (emoji = 1, como mb_strlen)
            length: v => [...v.trim()].length >= 8,
            upper: v => /\p{Lu}/u.test(v),
            number: v => /\d/.test(v),
            symbol: v => /[^\p{L}\p{N}\s]/u.test(v),
            different: v => v.trim() !== '' && v.trim() !== current.value.trim(),
            match: v => v !== '' && v === confirmPass.value,
        };

        // Marca cada regra e enche o medidor (as 4 primeiras regras dizem a força)
        function check() {
            const v = pass.value;
            let ok = 0;
            let all = true;
            Object.entries(rules).forEach(([name, test]) => {
                const passed = test(v);
                modal.querySelector(`[data-rule="${name}"]`).classList.toggle('is-ok', passed);
                if (passed && ['length', 'upper', 'number', 'symbol'].includes(name)) ok++;
                all = all && passed;
            });
            const level = v === '' ? 0 : Math.max(1, ok);
            meter.dataset.level = level;
            strength.dataset.level = level;
            strength.textContent = v === '' ? 'Força: digite a nova senha'
                : 'Força: ' + ['fraca', 'fraca', 'média', 'boa', 'forte'][level] + (all ? ' · todas as regras cumpridas' : '');
            submit.disabled = !all || current.value === '';
            return all && current.value !== '';
        }

        const showError = (text) => { passError.textContent = text; passError.hidden = !text; };
        const open = () => {
            modal.classList.add('active');
            setTimeout(() => current.focus(), 50);
        };
        const close = () => {
            if (mustReset) return; // senha temporária: só Sair ou salvar
            modal.classList.remove('active');
            form.reset();
            showError('');
            check();
        };
        document.getElementById('btnOpenChangePass').addEventListener('click', open);
        modal.querySelectorAll('[data-pw-close]').forEach(b => b.addEventListener('click', close));
        modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && modal.classList.contains('active')) close(); });
        if (mustReset) setTimeout(open, 300);

        document.getElementById('showPasswords').addEventListener('change', function () {
            [current, pass, confirmPass].forEach(i => { i.type = this.checked ? 'text' : 'password'; });
        });

        form.addEventListener('input', () => { showError(''); check(); });

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            if (!check()) return;
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            try {
                const data = await postWithSession('api/update_user_admin.php?self=1', new FormData(this));
                if (data.success) {
                    modal.classList.remove('active');
                    if (!mustReset) await showAlert({ title: 'Senha alterada', message: 'Pronto! Use a nova senha nos próximos acessos.', type: 'success' });
                    location.href = 'home';
                    return;
                }
                showError(data.message);
            } catch (err) {
                showError(requestErrorMessage(err));
            }
            submit.removeAttribute('aria-busy');
            check();
        });
    })();
</script>
