<form class="form_request">
    <input type="hidden" name="type" value="colorPrint">

    <p style="border-left-color: #06b6d4; color: #0e7490; background: #ecfeff;">Requisições de impressão colorida são utilizadas para realizar impressões coloridas no setor de reprografia.</p>

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
        <label for="file">Nome do arquivo/Informações: </label>
        <input type="text" name="file" id="file" required>
    </div>

    <div class="form-group">
        <label for="qtd">Quantidade: </label>
        <input type="number" name="qtd" id="qtd" required>
    </div>

    <div class="form-group">
        <label>Formato:</label>
        <div class="aplication_select">
            <label><input type="radio" name="model" value="A4"> A4</label>
            <label><input type="radio" name="model" value="A4/Cartolina"> A4/Cartolina</label>
            <label><input type="radio" name="model" value="A3/Cartolina"> A3</label>
        </div>
    </div>

    <div class="form-group">
        <label for="obs">Observações ou objetivo: </label>
        <input type="text" name="obs" id="obs">
        <span>Não obrigatório, porém interessante para o comitê de aprovação!</span>
    </div>

    <button type="submit">Enviar Requisição</button>

</form>