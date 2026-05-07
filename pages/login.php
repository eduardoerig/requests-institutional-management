<div class="page-login">
    <div class="card-login" id="container">

        <!-- ====== LADO ESQUERDO (FORM LOGIN OU CADASTRO) ====== -->
        <div class="forms">
            <!-- LOGIN -->
            <form class="form-auth form-login" id="frm_login" autocomplete="off">
                <h1>Faça Login</h1>

                <label for="user">Usuário</label>
                <input type="text" id="user" name="user" required />

                <label for="password">Senha</label>
                <input type="password" id="password" name="password" required />

                <button type="submit" class="btn" id="btn_login">Entrar</button>

                <p class="switch-text">
                    Ainda não tem conta?
                    <a href="#" id="goCadastro">Cadastre-se</a>
                </p>
            </form>

            <!-- CADASTRO -->
            <form class="form-auth form-cadastro" id="frm_cadastro" autocomplete="off">
                <h1>Cadastre-se</h1>

                <label for="nome">Nome</label>
                <input type="text" id="nome" name="nome" required />

                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" required />

                <label for="senha_cad">Senha</label>
                <input type="password" id="senha_cad" name="senha_cad" required />

                <button type="submit" class="btn">Criar conta</button>

                <p class="switch-text">
                    Já tem conta?
                    <a href="#" id="goLogin">Fazer login</a>
                </p>
            </form>
        </div>

        <!-- ====== OVERLAY LARANJA (MOVE PRA ESQUERDA/DIREITA) ====== -->
        <aside class="overlay-login">
            <div class="overlay-content">
                <div class="logo-wrapper">
                    <img src="assets/img/LogoFavConSisreq.png" alt="Logo" class="logo-login">
                </div>
                <h2>Bem-Vindo a<br><span>SisReq</span></h2>
                <p>Insira suas credenciais!</p>
            </div>
        </aside>

    </div>
</div>


<script>
    const container = document.getElementById("container");
    const goCadastro = document.getElementById("goCadastro");
    const goLogin = document.getElementById("goLogin");

    goCadastro.addEventListener("click", (e) => {
        e.preventDefault();
        container.classList.add("active");
    });

    goLogin.addEventListener("click", (e) => {
        e.preventDefault();
        container.classList.remove("active");
    });

    // (Opcional) só pra testar sem backend
    document.getElementById("frm_login").addEventListener("submit", (e) => {
        e.preventDefault();
        alert("Login enviado (exemplo). Ligue no seu backend/API.");
    });

    document.getElementById("frm_cadastro").addEventListener("submit", (e) => {
        e.preventDefault();
        alert("Cadastro enviado (exemplo). Ligue no seu backend/API.");
    });
</script>