<main class="auth">
    <section class="auth__form-col">
        <div class="auth__panel">
            <div class="auth-brand">
                <span class="auth-brand__mark"><img src="<?= e(orgSetting('org_logo')) ?>" alt=""></span>
                <span class="auth-brand__text">
                    <strong>SisReq</strong>
                    <span><?= e(orgSetting('org_name')) ?></span>
                </span>
            </div>

            <div class="auth-intro">
                <h1>Entrar</h1>
                <p>Peça, acompanhe cada etapa e converse com quem está resolvendo.</p>
            </div>

            <form id="frm_login" class="auth-form form-auth form-login">
                <div class="auth-form__fields">
                    <div class="field">
                        <label for="user">Usuário</label>
                        <input type="text" id="user" name="user" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="seu.usuario" required>
                    </div>

                    <div class="field">
                        <label for="password">Senha</label>
                        <div class="pw-field">
                            <input type="password" id="password" name="password" autocomplete="current-password" required>
                            <button type="button" class="pw-toggle" id="toggle_password" aria-controls="password" aria-pressed="false">Mostrar</button>
                        </div>
                    </div>
                </div>

                <div class="auth-form__actions">
                    <button type="submit" class="btn btn--primary" id="btn_login">Entrar</button>
                    <p class="auth-form__help">Esqueceu a senha ou ainda não tem acesso? Fale com a administração: ela cria uma senha temporária para você.</p>
                </div>
            </form>
        </div>
    </section>

    <section class="auth__showcase" aria-hidden="true">
        <div class="auth-demo">
            <article class="auth-demo__card">
                <div class="auth-demo__head">
                    <span class="auth-demo__ref">Pedido nº <span data-demo="num">427</span> · <span data-demo="sector">TI</span></span>
                    <span class="status-pill status-pill--W" data-demo="pill">Em atendimento</span>
                </div>
                <p class="auth-demo__title" data-demo="title">Projetor da sala 12 sem imagem</p>
                <ol class="steps4" data-demo="steps">
                    <li class="is-done"><span>Enviado</span></li>
                    <li class="is-done"><span>Aprovado</span></li>
                    <li class="is-now"><span>No setor</span></li>
                    <li><span>Concluído</span></li>
                </ol>
                <div class="auth-demo__msg">
                    <span class="auth-demo__avatar" data-demo="avatar">TI</span>
                    <span data-demo="msg">Trocamos o cabo HDMI. Passo aí às 14h para testar com você.</span>
                </div>
            </article>
            <p class="auth-demo__caption">Cada pedido tem um número. Você vê em que etapa ele está e conversa direto com quem está resolvendo.</p>
        </div>
    </section>
</main>
<script src="assets/js/login.js?v=<?= (int)$assetVersion ?>" defer></script>
