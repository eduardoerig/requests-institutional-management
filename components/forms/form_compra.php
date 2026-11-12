<form class="form_request" novalidate>
    <input type="hidden" name="type" value="shopping">

    <div class="form-step" data-form-step="2">
        <h2 class="form-step__title">2. O que você precisa?</h2>
<!--AREA-->
        <div class="form-group">
            <label for="title">Resumo em uma frase <span class="req">*</span></label>
            <input type="text" name="title" id="title" required maxlength="80" placeholder="Ex.: 10 resmas de papel A4">
        </div>
        <div class="form-group">
            <label for="details">Detalhes <span class="req" data-details-req>*</span></label>
            <textarea name="details" id="details" required rows="4" maxlength="5000" data-message="Explique o que você precisa ou adicione os itens." placeholder="Modelo, marca de preferência ou qualquer explicação que ajude Compras."></textarea>
        </div>
        <div class="form-extras">
        <fieldset class="form-group repeater" data-repeater data-max="30" data-repeater-details="details">
            <legend>Itens</legend>
            <small class="form-hint">Liste o que comprar. O link ajuda Compras a achar o produto certo.</small>
            <div class="repeater__rows" data-repeater-rows></div>
            <button type="button" class="link-add repeater__add" data-repeater-add><i class="fa-solid fa-plus" aria-hidden="true"></i> Adicionar item</button>
            <template data-repeater-template>
                <div class="repeater__row" data-repeater-row>
                    <div class="repeater__fields repeater__fields--item">
                        <input type="text" data-field="name" data-key data-message="Escreva o nome do item (ou tire a linha)." maxlength="255" placeholder="Item (ex.: Papel A4)" aria-label="Item">
                        <input type="text" data-field="qty" maxlength="50" placeholder="Quantidade" aria-label="Quantidade">
                        <input type="url" data-field="url" maxlength="1000" inputmode="url" placeholder="Link do produto (opcional)" aria-label="Link do produto">
                    </div>
                    <button type="button" class="icon-x repeater__remove" data-repeater-remove aria-label="Tirar esta linha"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </div>
            </template>
        </fieldset>
<!--ANEXOS-->
        </div>
        <div class="form-group date-group">
            <label for="end">Para quando? <span class="req">*</span></label>
            <div class="date-field">
                <input type="date" name="end" id="end" required>
            </div>
            <p class="date-chosen" data-date-chosen aria-live="polite"></p>
<!--DIAS_COMPRA-->
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
