<form class="form_request" novalidate>
    <input type="hidden" name="type" value="service">

    <p class="form-intro">Use para pedir <strong>consertos e serviços de manutenção</strong>: elétrica, hidráulica, móveis, pintura, limpeza etc.</p>

    <div class="form-group">
        <label for="title">Título <span class="req">*</span></label>
        <input type="text" name="title" id="title" required maxlength="80" placeholder="Ex.: Lâmpada queimada na sala 12">
        <span>Uma frase curta que resuma o pedido.</span>
    </div>
    <div class="form-group">
        <label for="end">Para quando você precisa? <span class="req">*</span></label>
        <input type="date" name="end" id="end" required>
    </div>
    <div class="form-group">
        <label for="details">Explique o que você precisa <span class="req">*</span></label>
        <textarea name="details" id="details" required rows="5" placeholder="Onde é, o que aconteceu e o que precisa ser feito."></textarea>
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
