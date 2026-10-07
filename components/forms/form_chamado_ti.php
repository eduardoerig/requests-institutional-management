<form class="form_request" novalidate>
    <input type="hidden" name="type" value="ti">

    <p class="form-intro">Use para pedir <strong>ajuda com computador, internet, impressora, sistemas e acessos</strong>.</p>

    <div class="form-group">
        <label for="title">Título <span class="req">*</span></label>
        <input type="text" name="title" id="title" required maxlength="80" placeholder="Ex.: Computador da recepção não liga">
        <span>Uma frase curta que resuma o pedido.</span>
    </div>
    <div class="form-group">
        <label for="end">Para quando você precisa? <span class="req">*</span></label>
        <input type="date" name="end" id="end" required>
    </div>
    <div class="form-group">
        <label for="details">Explique o que você precisa <span class="req">*</span></label>
        <textarea name="details" id="details" required rows="5" placeholder="Qual equipamento, onde fica e o que está acontecendo."></textarea>
    </div>
    <div class="form-group">
        <label for="obs">Observações <small>(opcional)</small></label>
        <input type="text" name="obs" id="obs" maxlength="2000" placeholder="Algo mais que ajude quem vai aprovar ou atender">
    </div>

<!--PRIORIDADE-->

    <div class="form-submit-bar">
        <p class="form-required-hint"><span class="req">*</span> Campos obrigatórios</p>
        <button type="submit"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Enviar requisição</button>
    </div>
</form>
