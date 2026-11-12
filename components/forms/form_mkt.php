<form class="form_request" novalidate>
    <input type="hidden" name="type" value="mkt">

    <div class="form-step" data-form-step="2">
        <h2 class="form-step__title">2. O que você precisa?</h2>
<!--AREA-->
        <div class="form-group">
            <label for="title">Resumo em uma frase <span class="req">*</span></label>
            <input type="text" name="title" id="title" required maxlength="80" placeholder="Ex.: Divulgação da festa junina">
        </div>
        <div class="form-group">
            <label for="details">Detalhes <span class="req">*</span></label>
            <textarea name="details" id="details" required rows="4" maxlength="5000" placeholder="Textos que devem aparecer, cores, tamanho e qualquer referência."></textarea>
        </div>
        <fieldset class="form-group">
            <legend>Onde vai ser usado? <span class="req">*</span></legend>
            <div class="aplication_select">
                <label><input type="radio" class="model_radius" name="model" value="Whatsapp" required> WhatsApp</label>
                <label><input type="radio" class="model_radius" name="model" value="Instagram"> Instagram</label>
                <label><input type="radio" class="model_radius" name="model" value="Posts"> Posts (outras redes)</label>
                <label><input type="radio" class="model_radius" name="model" value="Panfleto"> Panfleto</label>
                <label><input type="radio" class="model_radius" name="model" value="Banner"> Banner</label>
                <label><input type="radio" class="model_radius" name="model" value="Ingressos"> Ingressos</label>
                <label><input type="radio" class="model_radius" name="model" value="ClipEscola"> ClipEscola</label>
                <label><input type="radio" class="model_radius" name="model" value="outros" id="radio_outros"> Outro</label>
                <input type="text" id="input_outros" name="outros_texto" maxlength="255" placeholder="Qual?" aria-label="Qual outro lugar?" hidden>
            </div>
        </fieldset>
        <div class="form-group date-group">
            <label for="end">Para quando? <span class="req">*</span></label>
            <div class="date-field">
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
