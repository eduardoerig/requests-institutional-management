<main class="login-screen">
    <section class="login-form-col">
        <div class="login-card">
            <img src="assets/img/logoMartin.png" alt="Colégio Martin Luther" class="login-card__school">
            <p class="login-card__app">SisReq, pedidos internos da escola</p>

            <h1>Entrar no SisReq</h1>
            <p class="login-card__lead">Use o usuário e a senha que a escola passou para você.</p>

            <form id="frm_login" class="form-auth form-login">
                <div class="field">
                    <label for="user">Usuário</label>
                    <input type="text" id="user" name="user" autocomplete="username" autocapitalize="none" spellcheck="false" required>
                </div>

                <div class="field">
                    <label for="password">Senha</label>
                    <div class="pw-field">
                        <input type="password" id="password" name="password" autocomplete="current-password" required>
                        <button type="button" class="pw-toggle" id="toggle_password" aria-controls="password" aria-pressed="false">Mostrar</button>
                    </div>
                </div>

                <button type="submit" class="btn btn--primary" id="btn_login">Entrar</button>
            </form>

            <p class="login-card__help">
                Esqueceu a senha ou ainda não tem acesso? Fale com a administração da escola.
                Ela cria uma senha temporária para você.
            </p>
        </div>
    </section>

    <section class="login-board" aria-hidden="true">
        <div class="login-board__inner">
            <div class="flow">
                <div class="flow__rail">
                    <div class="flow__track"><div class="flow__fill"></div></div>
                    <div class="flow__packet"></div>
                    <ol class="flow__steps">
                        <li class="flow__step"><span class="flow__dot"><i class="fa-solid fa-paper-plane"></i></span><span class="flow__label">Enviado</span></li>
                        <li class="flow__step"><span class="flow__dot"><i class="fa-solid fa-thumbs-up"></i></span><span class="flow__label">Aprovado</span></li>
                        <li class="flow__step"><span class="flow__dot"><i class="fa-solid fa-screwdriver-wrench"></i></span><span class="flow__label">No setor</span></li>
                        <li class="flow__step"><span class="flow__dot"><i class="fa-solid fa-check"></i></span><span class="flow__label">Concluído</span></li>
                    </ol>
                </div>

                <article class="flow-card is-sent">
                    <header class="flow-card__head">
                        <span class="flow-card__num">#0427</span>
                        <span class="flow-card__status"><i class="fa-solid fa-circle"></i> <span class="flow-card__status-text">Aguardando aprovação</span></span>
                    </header>
                    <p class="flow-card__title">Projetor da sala 12 sem imagem</p>
                    <div class="flow-card__meta">
                        <span class="flow-card__sector"><i class="fa-solid fa-desktop"></i> <span class="flow-card__sector-name">TI</span></span>
                        <span class="flow-card__when"><i class="fa-regular fa-clock"></i> agora</span>
                    </div>
                    <p class="flow-card__log">Pedido enviado pela professora Ana.</p>
                    <span class="flow-card__done"><i class="fa-solid fa-check"></i></span>
                </article>
            </div>

            <p class="login-board__caption">Cada pedido recebe um número. Você acompanha cada etapa até a entrega.</p>
        </div>
    </section>
</main>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js" defer></script>
<script src="assets/js/login.js?v=<?= (int)$assetVersion ?>" defer></script>
