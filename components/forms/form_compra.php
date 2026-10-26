<form class="form_request" novalidate>
    <input type="hidden" name="type" value="shopping">

    <div class="form-step" data-form-step="2">
        <h2 class="form-step__title">2. O que você precisa?</h2>
        <div class="form-group">
            <label for="title">Resumo em uma frase <span class="req">*</span></label>
            <input type="text" name="title" id="title" required maxlength="80" placeholder="Ex.: 10 resmas de papel A4">
        </div>
        <div class="form-group">
            <label for="details">Detalhes <span class="req">*</span></label>
            <textarea name="details" id="details" required rows="4" placeholder="O que comprar, quantidade, modelo ou marca de preferência e link de referência."></textarea>
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
