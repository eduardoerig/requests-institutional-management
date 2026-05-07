let order = "DESC";
let table = "all";

// Função para atualizar a lista
async function updateFilter() {
    const campoPesquisa = document.getElementById("campoPesquisa");
    const searchText = campoPesquisa ? campoPesquisa.value : "";
    const path = window.location.pathname;
    
    // Tenta identificar a página atual (approve, approved, etc)
    const segments = path.split('/');
    const pageName = segments[segments.length - 1] || segments[segments.length - 2] || 'home';

    let formData = new FormData();
    formData.append('action', 'post');
    formData.append('value', order);
    formData.append('table', table);
    formData.append('text', searchText);
    formData.append('path', pageName);

    const data = await fetchAjax("config/filter.php", formData);
    if (data.success) {
        showToast(data.message, 'success');
        const container = document.querySelector('.card-wrapper-scroll') || document.querySelector('.card-wrapper');
        if (container) {
            container.innerHTML = data.data;
            bindPopUpRequests();
        }
    }
}

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

const campoPesquisa = document.getElementById("campoPesquisa");
if (campoPesquisa) {
    campoPesquisa.addEventListener('input', function (e) {
        updateFilter();
    });
}

const radioTypes = document.querySelectorAll(".radioType");
radioTypes.forEach(radio => {
    radio.addEventListener('change', function (e) {
        table = radio.value;
        updateFilter();
    });
});