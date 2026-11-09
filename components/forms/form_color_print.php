<form class="form_request" novalidate>
    <input type="hidden" name="type" value="colorPrint">

    <div class="form-step" data-form-step="2">
        <h2 class="form-step__title">2. O que você precisa?</h2>
<!--AREA-->
        <div class="form-group">
            <label for="title">O que vai ser impresso? <span class="req">*</span></label>
            <input type="text" name="title" id="title" required maxlength="80" placeholder="Ex.: Certificados da formatura">
        </div>
        <div class="form-group">
            <label for="file">Onde está o arquivo? <span class="req">*</span></label>
            <input type="text" name="file" id="file" required maxlength="255" placeholder="Nome do arquivo, e-mail em que foi enviado ou link">
        </div>
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
        <div class="form-group date-group">
            <label for="end">Para quando? <span class="req">*</span></label>
            <div class="date-field">
                <span class="date-quick" data-date-quick role="group" aria-label="Atalhos de prazo"></span>
                <input type="date" name="end" id="end" required>
            </div>
            <p class="date-chosen" data-date-chosen aria-live="polite"></p>
        </div>
    </div>

    <div class="form-step" data-form-step="3">
        <h2 class="form-step__title">3. É urgente?</h2>
<!--PRIORIDADE-->
        <details class="form-more">
            <summary>Mais alguma coisa? <small>(opcional)</small></summary>
            <div class="form-group">
                <label for="obs" class="visually-hidden">Observações</label>
                <textarea name="obs" id="obs" rows="2" maxlength="2000" placeholder="Sala, horário, contato ou algo que ajude quem vai aprovar ou atender"></textarea>
            </div>
        </details>
        <div class="nr-review" data-review></div>
    </div>

    <div class="form-submit-bar">
        <button type="submit"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Enviar pedido</button>
        <a class="btn" href="myRequests">Cancelar</a>
    </div>
</form>
