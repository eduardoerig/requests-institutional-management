<form class="form_request" novalidate>
    <input type="hidden" name="type" value="mkt">

    <p class="form-intro" style="border-left-color: #54585a; color: #334155; background: #f8fafc;">Use para pedir <strong>artes e materiais de divulgação</strong>: posts, banners, panfletos, convites etc.</p>

    <div class="form-group">
        <label for="title">Título <span class="req">*</span></label>
        <input type="text" name="title" id="title" required maxlength="80" placeholder="Ex.: Divulgação da festa junina">
        <span>Uma frase curta que resuma o pedido.</span>
    </div>
    <div class="form-group">
        <label for="end">Para quando você precisa? <span class="req">*</span></label>
        <input type="date" name="end" id="end" required>
    </div>
    <div class="form-group">
        <label for="details">Explique o que você precisa <span class="req">*</span></label>
        <textarea name="details" id="details" required rows="5" placeholder="Textos que devem aparecer, cores, tamanho e qualquer referência."></textarea>
    </div>
    <fieldset class="form-group">
        <legend>Onde a peça será usada? <span class="req">*</span></legend>
        <div class="aplication_select">
            <label><input type="radio" class="model_radius" name="model" value="Whatsapp" required> WhatsApp</label>
            <label><input type="radio" class="model_radius" name="model" value="Instagram"> Instagram</label>
            <label><input type="radio" class="model_radius" name="model" value="Posts"> Posts (outras redes)</label>
            <label><input type="radio" class="model_radius" name="model" value="Panfleto"> Panfleto</label>
            <label><input type="radio" class="model_radius" name="model" value="Banner"> Banner</label>
            <label><input type="radio" class="model_radius" name="model" value="Ingressos"> Ingressos</label>
            <label><input type="radio" class="model_radius" name="model" value="ClipEscola"> ClipEscola</label>
            <label><input type="radio" class="model_radius" name="model" value="outros" id="radio_outros"> Outro</label>
            <input type="text" id="input_outros" name="outros_texto" maxlength="255" placeholder="Qual?" style="display:none;">
        </div>
    </fieldset>
    <div class="form-group">
        <label for="obs">Observações <small>(opcional)</small></label>
        <input type="text" name="obs" id="obs" maxlength="2000" placeholder="Algo mais que ajude quem vai aprovar ou atender">
    </div>

<!--PRIORIDADE-->

    <p class="form-required-hint"><span class="req">*</span> Campos obrigatórios</p>
    <button type="submit"><i class="fa-solid fa-paper-plane"></i> Enviar requisição</button>
</form>
