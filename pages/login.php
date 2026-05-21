<div class="page-login">
    
    <div class="card-login" id="container">

        <!-- ====== LADO ESQUERDO/ÚNICO (FORMULÁRIOS) ====== -->
        <div class="forms">
            <div class="login-brand-header-mobile">
                <img src="assets/img/logoMartin.png" alt="Colégio Martin Luther" class="school-logo-mobile">
            </div>
            <!-- LOGIN -->
            <form class="form-auth form-login" id="frm_login" autocomplete="off">
                <div class="form-title-wrapper">
                    <h1>Acessar o Painel</h1>
                    <p class="form-subtitle">Insira seu usuário e senha para continuar</p>
                </div>

                <div class="input-group">
                    <label for="user">Usuário</label>
                    <div class="input-with-icon">
                        <i class="fa-solid fa-user"></i>
                        <input type="text" id="user" name="user" placeholder="Digite seu usuário" required />
                    </div>
                </div>

                <div class="input-group">
                    <label for="password">Senha</label>
                    <div class="input-with-icon">
                        <i class="fa-solid fa-lock"></i>
                        <input type="password" id="password" name="password" placeholder="Digite sua senha" required />
                    </div>
                </div>

                <button type="submit" class="btn btn-login-submit" id="btn_login">
                    <span>Entrar</span>
                    <i class="fa-solid fa-arrow-right-to-bracket"></i>
                </button>

                <div class="switch-text" style="margin-top: 15px; text-align: center; width: 100%; position: relative; z-index: 10;">
                    <span style="color: var(--text-muted); font-size: 0.88rem;">Ainda não tem conta?</span>
                    <a href="javascript:void(0)" style="color: var(--primary); font-weight: 600; text-decoration: none; margin-left: 5px;" onclick="if(typeof showAlert === 'function') { showAlert({title: 'Entrar em Contato', message: 'O cadastro de novos usuários deve ser solicitado diretamente à administração do colégio Martin Luther.', type: 'info'}); } else { alert('O cadastro de novos usuários deve ser solicitado diretamente à administração do colégio Martin Luther.'); }">Solicitar Cadastro</a>
                </div>
            </form>

        </div>

        <!-- ====== OVERLAY DE DESKTOP (MOVE LATERIALMENTE) ====== -->
        <aside class="overlay-login">
            <div class="overlay-content">
                <img src="assets/img/logoMartin.png" alt="Colégio Martin Luther" class="school-logo-desktop">
                <h2>Bem-Vindo ao <br><span>Sistema de Requisições Martin Luther</span></h2>
                <p>Gerenciamento institucional moderno e simplificado.</p>
            </div>
        </aside>

    </div>
</div>


