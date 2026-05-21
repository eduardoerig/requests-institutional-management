let order = "DESC";
let table = "all";
let status = "all";

// Função para atualizar a lista
async function updateFilter() {
    const campoPesquisa = document.getElementById("campoPesquisa");
    const dataFiltro = document.getElementById("dataFiltro");
    const searchText = campoPesquisa ? campoPesquisa.value : "";
    const searchDate = dataFiltro ? dataFiltro.value : "";
    const path = window.location.pathname + window.location.search;
    
    // Identifica o contexto da página
    let pageName = 'home';
    if (path.includes('approve') && !path.includes('approved')) pageName = 'approve';
    else if (path.includes('approved')) pageName = 'approved';
    else if (path.includes('inWork')) pageName = 'inWork';
    else if (path.includes('myRequests')) pageName = 'myRequests';

    let formData = new FormData();
    formData.append('action', 'post');
    formData.append('value', order);
    formData.append('table', table);
    formData.append('status_filter', status);
    formData.append('text', searchText);
    formData.append('date', searchDate);
    formData.append('path', pageName);

    const data = await fetchAjax("config/filter.php", formData);
    if (data.success) {
        // showToast(data.message, 'success'); // Removido para ser menos intrusivo
        const container = document.querySelector('.card-wrapper-scroll') || document.querySelector('.card-wrapper');
        if (container) {
            container.innerHTML = data.data;
            bindPopUpRequests();
        }
    }
}

// Event listener para ordenação
const icon = document.getElementById("orderIcon");
if (icon) {
    icon.onclick = function (e) {
        if (this.dataset.order === "desc") {
            this.dataset.order = "asc";
            order = "ASC";
            this.innerHTML = '<i class="fas fa-sort-amount-up"></i>';
        } else {
            this.dataset.order = "desc";
            order = "DESC";
            this.innerHTML = '<i class="fas fa-sort-amount-down"></i>';
        }
        updateFilter();
    };
}

// Event listener para busca
const campoPesquisa = document.getElementById("campoPesquisa");
if (campoPesquisa) {
    campoPesquisa.addEventListener('input', function (e) {
        updateFilter();
    });
}

// Event listener para data
const dataFiltro = document.getElementById("dataFiltro");
if (dataFiltro) {
    dataFiltro.addEventListener('change', function (e) {
        updateFilter();
    });
}

// Event listener para os rádios de SETOR (bolinhas)
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('radioType')) {
        table = e.target.value;
        updateFilter();
    }
    
    // Event listener para os rádios de STATUS (bolinhas)
    if (e.target.classList.contains('statusRadio')) {
        status = e.target.value;
        updateFilter();
    }
});