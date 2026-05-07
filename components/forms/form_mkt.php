<form class="form_request">
    <input type="hidden" name="type" value="mkt">

    <p style="border-left-color: #54585a; color: #54585a; background: #f8fafc;">Requisições de marketing são utilizadas para solicitar a criação, adaptação ou execução de ações e materiais necessários às atividades de comunicação da empresa.</p>

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
        <textarea name="descp" id="details" required></textarea>
    </div>

    <div class="form-group">
        <label for="qtd">Aplicação: </label>
        <div class="aplication_select">
            <label><input type="radio" class="model_radius" name="model" value="Whatsapp"> Whatsapp</label>
            <label><input type="radio" class="model_radius" name="model" value="Ingressos"> Ingressos</label>
            <label><input type="radio" class="model_radius" name="model" value="Panfleto"> Panfleto</label>
            <label><input type="radio" class="model_radius" name="model" value="Instagram"> Instagram</label>
            <label><input type="radio" class="model_radius" name="model" value="Banner"> Banner</label>
            <label><input type="radio" class="model_radius" name="model" value="ClipEscola"> ClipEscola</label>
            <label><input type="radio" class="model_radius" name="model" value="Posts"> Posts</label>
            <label>
                <input type="radio" name="model" class="model_radius" value="" id="radio_outros"> Outros
                <input type="text" id="input_outros" name="outros_texto" placeholder="Descreva..." style="display:none; margin-top:5px;">
            </label>
        </div>
    </div>

    <div class="form-group">
        <label for="obs">Observações ou objetivo: </label>
        <input type="text" name="obs" id="obs">
        <span>Não obrigatório, porém interessante para o comitê de aprovação!</span>
    </div>

    <button type="submit">Enviar Requisição</button>
</form>