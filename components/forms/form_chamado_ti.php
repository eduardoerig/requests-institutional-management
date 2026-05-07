<form class="form_request">

    <p style="border-left-color: #ef4444; color: #b91c1c; background: #fef2f2;">Requisições de TI são utilizadas para realizar a solicitação de atividades da manutenção e suporte técnico.</p>

    <input type="hidden" name="type" value="ti">

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

    <div class="checkbox_group">
        <input type="checkbox" name="urgent" id="urgent">
        <label for="urgent">Necessita ser adquirido com urgência (antes do prazo supracitado)</label>
    </div>

    <div class="form-group">
        <label for="obs">Observações ou objetivo: </label>
        <input type="text" name="obs" id="obs">
        <span>Não obrigatório, porém interessante para o comitê de aprovação!</span>
    </div>

    <button type="submit">Enviar Requisição</button>

</form>
