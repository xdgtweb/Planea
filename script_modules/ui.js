import { fetchData } from './utils.js';
import { 
    mostrarFormularioEditarObjetivo, 
    mostrarFormularioAddSubObjetivo, 
    mostrarFormularioEditarSubObjetivo,
    mostrarFormularioEditarTarea,
    mostrarFormularioAddSubTarea
} from './modals.js';
import { restaurarTarea, eliminarTareaDiaria } from './views.js';

let miniCalCurrentMonth, miniCalCurrentYear;
export let miniCalSelectedDates = [];

// CORRECCIÓN: Se añade la palabra 'export' para que la función sea pública.
export function setupEventListeners() {
    // Listener para el botón de logout (si existe)
    const logoutButton = document.getElementById('logout-button');
    if (logoutButton) {
        logoutButton.addEventListener('click', handleLogout);
    }

    // Listener para el botón de cancelar del formulario modal
    const cancelBtn = document.getElementById('form-cancel-btn');
    if(cancelBtn) {
        cancelBtn.addEventListener('click', ocultarFormulario);
    }
}

export function updateUsernameInUI() {
    const username = sessionStorage.getItem('username');
    const displayElement = document.getElementById('username-display');
    if (username && displayElement) {
        displayElement.textContent = `Hola, ${username}`;
    }
}

export function createActionToggleButton(item, fecha, contexto) {
    const btn = document.createElement('button');
    btn.className = 'action-toggle-btn';
    btn.innerHTML = '&#x22EE;'; // Icono de tres puntos verticales
    btn.setAttribute('aria-label', 'Más acciones');
    btn.onclick = (e) => {
        e.stopPropagation();
        toggleActionPanel(e.target, item, fecha, contexto);
    };
    return btn;
}

function toggleActionPanel(button, item, fecha, contexto) {
    closeAllActionPanels(button);
    let panel = button.nextElementSibling;
    if (!panel || !panel.classList.contains('actions-panel-floating')) {
        panel = document.createElement('div');
        panel.className = 'actions-panel-floating';
        button.parentNode.insertBefore(panel, button.nextSibling);
    }
    
    if (panel.classList.contains('actions-visible')) {
        panel.classList.remove('actions-visible');
    } else {
        populateAndShowPanel(panel, item, fecha, contexto);
    }
}

function populateAndShowPanel(panel, item, fecha, contexto) {
    panel.innerHTML = ''; 
    const actions = getActionsForItem(item, fecha, contexto);
    actions.forEach(action => {
        const actionBtn = document.createElement('button');
        actionBtn.textContent = action.label;
        actionBtn.onclick = (e) => {
            e.stopPropagation();
            action.handler();
            closeAllActionPanels();
        };
        panel.appendChild(actionBtn);
    });
    panel.classList.add('actions-visible');
}

export function closeAllActionPanels(exceptButton = null) {
    document.querySelectorAll('.actions-panel-floating.actions-visible').forEach(panel => {
        if (!exceptButton || panel.previousElementSibling !== exceptButton) {
            panel.classList.remove('actions-visible');
        }
    });
}

function getActionsForItem(item, fecha, contexto) {
    const esObjetivo = contexto === 'corto-medio-plazo' || contexto === 'largo-plazo';
    const esTareaDiaria = contexto === 'dia-a-dia';
    const esActivo = item.activo;

    let actions = [];

    if (esActivo) {
        // Acciones para elementos activos
        if (esObjetivo) {
            if (item.sub_objetivos !== undefined) { // Es un objetivo principal
                actions.push({ label: "Editar Objetivo", handler: () => mostrarFormularioEditarObjetivo(item, contexto) });
                actions.push({ label: "Añadir Sub-objetivo", handler: () => mostrarFormularioAddSubObjetivo(item.id, contexto) });
            } else { // Es un sub-objetivo
                actions.push({ label: "Editar Sub-objetivo", handler: () => mostrarFormularioEditarSubObjetivo(item, contexto) });
            }
        } else if (esTareaDiaria) {
            if (item.tipo === 'titulo') {
                actions.push({ label: "Editar Título", handler: () => mostrarFormularioEditarTarea(item, fecha, 'titulo') });
                actions.push({ label: "Añadir Subtarea", handler: () => mostrarFormularioAddSubTarea(item.id, fecha) });
            } else {
                actions.push({ label: "Editar Subtarea", handler: () => mostrarFormularioEditarTarea(item, fecha, 'subtarea') });
            }
        }
        actions.push({ label: "Archivar", handler: () => eliminarTareaDiaria(item, fecha, true) });
    } else {
        // Acciones para elementos inactivos
        actions.push({ label: "Restaurar", handler: () => restaurarTarea(item, fecha) });
        actions.push({ label: "Eliminar Permanentemente", handler: () => eliminarTareaDiaria(item, fecha, false) });
    }

    return actions;
}

export function mostrarFormulario(camposHTML, onSaveCallback, titulo = "Formulario", btnGuardarTexto = "Guardar") {
    const formContainer = document.getElementById('form-container');
    const formTitle = document.getElementById('form-title');
    const formFields = document.getElementById('form-fields');
    const saveBtn = document.getElementById('form-save-btn');
    
    formTitle.textContent = titulo;
    formFields.innerHTML = camposHTML;
    saveBtn.textContent = btnGuardarTexto;
    
    saveBtn.onclick = null; // Limpiar listener anterior
    saveBtn.onclick = onSaveCallback;

    formContainer.classList.remove('hidden');
    formContainer.removeAttribute('inert');
}

export function ocultarFormulario() {
    const formContainer = document.getElementById('form-container');
    formContainer.classList.add('hidden');
    formContainer.setAttribute('inert', 'true');
}

export function renderMiniCalendar(year, month, allowMultiple = false) {
    miniCalCurrentYear = year;
    miniCalCurrentMonth = month;
    const container = document.getElementById('mini-calendar-container-dynamic');
    if (!container) return;
    
    const fechaBase = new Date(Date.UTC(year, month, 1));
    const nombreMes = fechaBase.toLocaleString('es-ES', { month: 'long', timeZone: 'UTC' });
    
    container.innerHTML = `
        <div class="calendario-nav">
            <button type="button" id="mini-prevMonth">←</button>
            <h4 id="mini-mes-anio">${nombreMes.charAt(0).toUpperCase() + nombreMes.slice(1)} ${year}</h4>
            <button type="button" id="mini-nextMonth">→</button>
        </div>
        <div class="dias-semana"><span>Lun</span><span>Mar</span><span>Mié</span><span>Jue</span><span>Vie</span><span>Sáb</span><span>Dom</span></div>
        <div class="dias-mes"></div>
    `;

    const diasMesContenedor = container.querySelector('.dias-mes');
    const primerDiaDelMes = new Date(Date.UTC(year, month, 1));
    let diaDeSemanaPrimerDia = primerDiaDelMes.getUTCDay();
    if (diaDeSemanaPrimerDia === 0) diaDeSemanaPrimerDia = 7;
    for (let i = 1; i < diaDeSemanaPrimerDia; i++) diasMesContenedor.appendChild(document.createElement('span'));

    const numDiasEnMes = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
    for (let dia = 1; dia <= numDiasEnMes; dia++) {
        const fechaStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
        const diaSpan = document.createElement('span');
        diaSpan.className = 'dia-calendario';
        diaSpan.dataset.fecha = fechaStr;
        diaSpan.textContent = dia;
        diaSpan.setAttribute('role', 'button');
        diaSpan.setAttribute('tabindex', '0');

        if (miniCalSelectedDates.includes(fechaStr)) {
            diaSpan.classList.add('selected');
        }

        diaSpan.onclick = () => {
            const index = miniCalSelectedDates.indexOf(fechaStr);
            if (index > -1) {
                miniCalSelectedDates.splice(index, 1);
                diaSpan.classList.remove('selected');
            } else {
                if (!allowMultiple) {
                    miniCalSelectedDates.length = 0;
                    container.querySelectorAll('.dia-calendario.selected').forEach(el => el.classList.remove('selected'));
                }
                miniCalSelectedDates.push(fechaStr);
                diaSpan.classList.add('selected');
            }
            document.getElementById('selected-dates-display').textContent = `Fechas: ${miniCalSelectedDates.join(', ')}`;
        };
        diasMesContenedor.appendChild(diaSpan);
    }

    container.querySelector('#mini-prevMonth').onclick = () => renderMiniCalendar(month === 0 ? year - 1 : year, month === 0 ? 11 : month - 1, allowMultiple);
    container.querySelector('#mini-nextMonth').onclick = () => renderMiniCalendar(month === 11 ? year + 1 : year, month === 11 ? 0 : month + 1, allowMultiple);
}

// Función de utilidad para manejar el logout, si se decide centralizar aquí
async function handleLogout() {
    const { logout } = await import('./auth.js'); // Carga dinámica para evitar ciclos
    const { showLoginScreen } = await import('./ui_auth.js');
    const result = await logout();
    if (result.success) {
        showLoginScreen();
    } else {
        alert(result.message || "Error al cerrar sesión.");
    }
}