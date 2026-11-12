<form class="form_request" novalidate>
    <input type="hidden" name="type" value="service">

    <div class="form-step" data-form-step="2">
        <h2 class="form-step__title">2. O que você precisa?</h2>
<!--AREA-->
        <div class="form-group">
            <label for="title">Resumo em uma frase <span class="req">*</span></label>
            <input type="text" name="title" id="title" required maxlength="80" placeholder="Ex.: Lâmpada queimada na sala 12">
        </div>
        <div class="form-group">
            <label for="details">Detalhes <span class="req">*</span></label>
            <textarea name="details" id="details" required rows="4" maxlength="5000" placeholder="Onde é, o que aconteceu e o que precisa ser feito."></textarea>
        </div>
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
