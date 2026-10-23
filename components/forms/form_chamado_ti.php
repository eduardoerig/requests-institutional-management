<form class="form_request" novalidate>
    <input type="hidden" name="type" value="ti">

    <div class="form-group">
        <label for="title">O que você precisa? <span class="req">*</span></label>
        <input type="text" name="title" id="title" required maxlength="80" placeholder="Ex.: Computador da recepção não liga">
    </div>
    <div class="form-group">
        <label for="details">Detalhes <span class="req">*</span></label>
        <textarea name="details" id="details" required rows="4" placeholder="Qual equipamento, onde fica e o que está acontecendo."></textarea>
    </div>
    <div class="form-group">
        <label for="end">Para quando? <span class="req">*</span></label>
        <div class="date-field">
            <input type="date" name="end" id="end" required>
            <span class="date-quick" data-date-quick></span>
        </div>
    </div>

<!--PRIORIDADE-->

    <details class="form-more">
        <summary>Mais alguma coisa? <small>(opcional)</small></summary>
        <div class="form-group">
            <label for="obs" class="visually-hidden">Observações</label>
            <textarea name="obs" id="obs" rows="2" maxlength="2000" placeholder="Sala, horário, contato ou algo que ajude quem vai aprovar ou atender"></textarea>
        </div>
    </details>

    <div class="form-submit-bar">
        <button type="submit"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Enviar pedido</button>
    </div>
</form>
