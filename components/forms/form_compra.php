<form class="form_request">
    <input type="hidden" name="type" value="shopping">

    <p style="border-left-color: #10b981; color: #047857; background: #ecfdf5;">Requisições de compras são utilizadas para fazer o orçamento ou a compra/contratação de algo externo.</p>

    <div class="form-group">
        <label for="title">Evento/atividade/título: </label>
        <input type="text" name="title" id="title" required maxlength="60">
        <span>Seja breve no título!</span>
    </div>

    <div class="form-group">
        <label for="end">Data de entrega: </label>
        <input type="date" name="end" id="end" required>
    </div>

    <div class="form-group">
        <label for="details">Informações da requisição: </label>
        <textarea name="details" id="details" required></textarea>
    </div>

    <div class="form-group">
        <label for="obs">Observações ou objetivo: </label>
        <input type="text" name="obs" id="obs">
        <span>Não obrigatório, porém interessante para o comitê de aprovação!</span>
    </div>

    <button type="submit">Enviar Requisição</button>
</form>