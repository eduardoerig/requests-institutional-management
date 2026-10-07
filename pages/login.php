<main class="login-screen">
    <div class="login-card">
        <img src="assets/img/LogoFavConSisreq.png" alt="" class="login-card__logo">
        <h1>Entrar no SisReq</h1>
        <p>Pedidos de TI, manutenção, compras, impressão e marketing em um só lugar.</p>

        <form id="frm_login" class="form-auth form-login">
            <div class="field">
                <label for="user">Usuário</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-user" aria-hidden="true"></i>
                    <input type="text" id="user" name="user" placeholder="Seu usuário" autocomplete="username" autocapitalize="none" required>
                </div>
            </div>

            <div class="field">
                <label for="password">Senha</label>
                <div class="input-with-icon">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <input type="password" id="password" name="password" placeholder="Sua senha" autocomplete="current-password" required>
                </div>
            </div>

            <button type="submit" class="btn btn--primary" id="btn_login">
                <i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i> Entrar
            </button>
        </form>

        <p class="login-card__help">
            Esqueceu a senha ou ainda não tem acesso? Fale com a administração da escola:
            ela cria uma senha temporária para você.
        </p>
    </div>
</main>
