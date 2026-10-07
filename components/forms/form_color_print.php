<form class="form_request" novalidate>
    <input type="hidden" name="type" value="colorPrint">

    <p class="form-intro">Use para pedir <strong>impressões coloridas</strong> na reprografia.</p>

    <div class="form-group">
        <label for="title">Título <span class="req">*</span></label>
        <input type="text" name="title" id="title" required maxlength="80" placeholder="Ex.: Certificados da formatura">
        <span>Uma frase curta que resuma o pedido.</span>
    </div>
    <div class="form-group">
        <label for="end">Para quando você precisa? <span class="req">*</span></label>
        <input type="date" name="end" id="end" required>
    </div>
    <div class="form-group">
        <label for="file">Qual arquivo imprimir? <span class="req">*</span></label>
        <input type="text" name="file" id="file" required maxlength="255" placeholder="Nome do arquivo ou onde ele está (e-mail, pasta, link)">
    </div>

    <div class="form-group">
        <label for="qtd">Quantas cópias? <span class="req">*</span></label>
        <input type="number" name="qtd" id="qtd" required min="1" max="100000" inputmode="numeric" placeholder="Ex.: 30">
    </div>

    <fieldset class="form-group">
        <legend>Tamanho do papel <span class="req">*</span></legend>
        <div class="aplication_select">
            <label><input type="radio" name="model" value="A4" required> A4 (folha comum)</label>
            <label><input type="radio" name="model" value="A4/Cartolina"> A4 em papel cartão</label>
            <label><input type="radio" name="model" value="A3/Cartolina"> A3 (folha grande)</label>
        </div>
    </fieldset>
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
