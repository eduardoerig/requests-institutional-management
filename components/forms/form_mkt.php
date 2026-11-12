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
        <fieldset class="form-group repeater" data-repeater data-max="30">
            <legend>Links de referência <small>(opcional)</small></legend>
            <small class="form-hint">Exemplos de arte, posts ou materiais parecidos com o que você quer.</small>
            <div class="repeater__rows" data-repeater-rows></div>
            <button type="button" class="btn btn--sm repeater__add" data-repeater-add><i class="fa-solid fa-plus" aria-hidden="true"></i> Adicionar link</button>
            <template data-repeater-template>
                <div class="repeater__row" data-repeater-row>
                    <div class="repeater__fields repeater__fields--link">
                        <input type="url" data-field="url" data-key data-message="Cole o link (ou tire a linha)." maxlength="1000" inputmode="url" placeholder="Link (https://...)" aria-label="Link de referência">
                        <input type="text" data-field="name" maxlength="255" placeholder="Sobre o link (opcional)" aria-label="Sobre o link">
                    </div>
                    <button type="button" class="btn btn--sm btn--ghost repeater__remove" data-repeater-remove aria-label="Tirar esta linha"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </div>
            </template>
        </fieldset>
        <fieldset class="form-group">
            <legend>Onde vai ser usado? <span class="req">*</span></legend>
            <small class="form-hint">Pode marcar mais de um.</small>
            <div class="aplication_select">
                <label><input type="checkbox" class="model_radius" name="model[]" value="Whatsapp" data-required-group> WhatsApp</label>
                <label><input type="checkbox" class="model_radius" name="model[]" value="Instagram"> Instagram</label>
                <label><input type="checkbox" class="model_radius" name="model[]" value="Posts"> Posts (outras redes)</label>
                <label><input type="checkbox" class="model_radius" name="model[]" value="Panfleto"> Panfleto</label>
                <label><input type="checkbox" class="model_radius" name="model[]" value="Banner"> Banner</label>
                <label><input type="checkbox" class="model_radius" name="model[]" value="Ingressos"> Ingressos</label>
                <label><input type="checkbox" class="model_radius" name="model[]" value="ClipEscola"> ClipEscola</label>
                <label><input type="checkbox" class="model_radius" name="model[]" value="outros" id="radio_outros"> Outro</label>
                <input type="text" id="input_outros" name="outros_texto" maxlength="255" placeholder="Qual?" aria-label="Qual outro lugar?" hidden>
            </div>
        </fieldset>
<!--ANEXOS-->
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
<!--OBS-->
        <div class="nr-review" data-review></div>
    </div>

    <div class="form-submit-bar">
        <button type="submit"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Enviar pedido</button>
        <a class="btn" href="myRequests">Cancelar</a>
    </div>
</form>
