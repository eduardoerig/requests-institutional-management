<form class="form_request" novalidate>
    <input type="hidden" name="type" value="colorPrint">

    <div class="form-step" data-form-step="2">
        <h2 class="form-step__title">2. O que você precisa?</h2>
<!--AREA-->
        <fieldset class="form-group kind-choice" data-kind-choice>
            <legend>O que a Reprografia vai fazer? <span class="req">*</span></legend>
            <div class="kind-choice__options">
                <label class="kind-choice__card">
                    <input type="radio" name="kind" value="copia_colorida" required>
                    <span class="kind-choice__name"><i class="fa-solid fa-print" aria-hidden="true"></i> Cópia colorida</span>
                    <span class="kind-choice__hint">Certificados, cartazes, folhas coloridas</span>
                </label>
                <label class="kind-choice__card">
                    <input type="radio" name="kind" value="plastificacao">
                    <span class="kind-choice__name"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Plastificação</span>
                    <span class="kind-choice__hint">Crachás, cartazes, fichas que você entrega</span>
                </label>
            </div>
        </fieldset>

        <div data-kind-part="copia_colorida" hidden>
            <div class="form-group">
                <label for="title">O que vai ser impresso? <span class="req">*</span></label>
                <input type="text" name="title" id="title" required maxlength="80" placeholder="Ex.: Certificados da formatura">
            </div>
            <div class="form-group">
                <label for="file">Onde está o arquivo? <span class="req">*</span></label>
                <input type="text" name="file" id="file" required maxlength="255" placeholder="Nome do arquivo, e-mail em que foi enviado ou link">
            </div>
<!--ANEXOS:pdf-->
            <div class="form-row">
                <div class="form-group">
                    <label for="qtd">Cópias <span class="req">*</span></label>
                    <input type="number" name="qtd" id="qtd" required min="1" max="100000" inputmode="numeric" placeholder="Ex.: 30">
                </div>
                <fieldset class="form-group">
                    <legend>Papel <span class="req">*</span></legend>
                    <div class="aplication_select">
                        <label><input type="radio" name="model" value="A4" required> A4 comum</label>
                        <label><input type="radio" name="model" value="A4/Cartolina"> A4 papel cartão</label>
                        <label><input type="radio" name="model" value="A3/Cartolina"> A3</label>
                    </div>
                </fieldset>
            </div>
        </div>

        <div data-kind-part="plastificacao" hidden>
            <div class="form-group">
                <label for="title_plast">O que vai ser plastificado? <span class="req">*</span></label>
                <input type="text" name="title" id="title_plast" required maxlength="80" placeholder="Ex.: Crachás dos professores">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="qtd_plast">Quantas folhas? <span class="req">*</span></label>
                    <input type="number" name="qtd" id="qtd_plast" required min="1" max="100000" inputmode="numeric" placeholder="Ex.: 10">
                </div>
                <fieldset class="form-group">
                    <legend>Tamanho <span class="req">*</span></legend>
                    <div class="aplication_select">
                        <label><input type="radio" name="size" value="A4" required> A4</label>
                        <label><input type="radio" name="size" value="A3"> A3</label>
                        <label><input type="radio" name="size" value="Pequeno"> Pequeno (crachá, cartão)</label>
                    </div>
                </fieldset>
            </div>
<!--ANEXOS-->
        </div>

        <div data-kind-part="*" hidden>
            <div class="form-group date-group">
                <label for="end">Para quando? <span class="req">*</span></label>
                <div class="date-field">
                    <input type="date" name="end" id="end" required>
                </div>
                <p class="date-chosen" data-date-chosen aria-live="polite"></p>
            </div>
        </div>
    </div>

    <div class="form-step" data-form-step="3">
        <h2 class="form-step__title">3. É urgente?</h2>
<!--PRIORIDADE-->
<!--OBS-->
        <div class="nr-review" data-review></div>
    </div>

    <div class="form-submit-bar">
        <button type="submit"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Enviar pedido</button>
        <a class="btn" href="myRequests">Cancelar</a>
    </div>
</form>
