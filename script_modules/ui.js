import { fetchData } from './utils.js';
import { 
    mostrarFormularioEditarObjetivo, 
    mostrarFormularioAddSubObjetivo, 
    mostrarFormularioEditarSubObjetivo,
    mostrarFormularioEditarTarea,
    mostrarFormularioAddSubTarea
} from './modals.js';
import { restaurarTarea, eliminarTareaDiaria } from './views.js';
// Se elimina la importación directa de currentUser desde app.js para evitar problemas de sincronización.
// import { currentUser } from './app.js'; 

let miniCalCurrentMonth, miniCalCurrentYear;
export let miniCalSelectedDates = [];

// Variable local para almacenar el estado de currentUser pasado desde app.js
let appCurrentUser = {};

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

// Inicializa el módulo UI con el estado de la aplicación, incluyendo currentUser
export function initUIModule(appState) {
    appCurrentUser = appState.currentUser; // Almacena el currentUser pasado
    console.log("UI.JS: appCurrentUser establecido en initUIModule:", appCurrentUser); // Añade esta línea
    setupEventListeners();
    updateUsernameInUI(); 
}

export function updateUsernameInUI() {
    // Usar el username del objeto appCurrentUser
    const displayElement = document.getElementById('username-display');
    if (appCurrentUser.username && displayElement) {
        displayElement.textContent = `Hola, ${appCurrentUser.username}`;
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
    const actions = getActionsForItem(item, fecha, contexto); // Obtiene las acciones.
    console.log("UI.JS: Populating panel. Acciones encontradas:", actions.length, actions); // Añade esta línea
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
    console.log("UI.JS: getActionsForItem llamado. appCurrentUser:", appCurrentUser); // Añade esta línea
    const esObjetivo = contexto === 'corto-medio-plazo' || contexto === 'largo-plazo';
    const esTareaDiaria = contexto === 'dia-a-dia';
    const esActivo = item.activo;
    
    // Se utiliza la variable local appCurrentUser para asegurar que los datos del usuario estén sincronizados
    const canModify = appCurrentUser.is_admin || appCurrentUser.email_verified; 
    console.log("UI.JS: canModify calculado como:", canModify); // Añade esta línea

    let actions = [];

    if (esActivo) {
        // Acciones para elementos activos
        if (esObjetivo) {
            if (item.sub_objetivos !== undefined) { // Es un objetivo principal
                if (canModify) {
                    actions.push({ label: "Editar Objetivo", handler: () => mostrarFormularioEditarObjetivo(item, contexto) });
                    actions.push({ label: "Añadir Sub-objetivo", handler: () => mostrarFormularioAddSubObjetivo(item.id, contexto) });
                }
            } else { // Es un sub-objetivo
                if (canModify) {
                    actions.push({ label: "Editar Sub-objetivo", handler: () => mostrarFormularioEditarSubObjetivo(item, contexto) });
                }
            }
        } else if (esTareaDiaria) {
            if (item.tipo === 'titulo') {
                // Solo el propietario de la tarea puede editar/compartir/eliminar
                if (item.usuario_id === appCurrentUser.id && canModify) { 
                    actions.push({ label: "Editar Título", handler: () => mostrarFormularioEditarTarea(item, fecha, 'titulo') });
                    actions.push({ label: "Añadir Subtarea", handler: () => mostrarFormularioAddSubTarea(item.id, fecha) });
                    // No hay "compartir" directo aquí, se hace desde el modal de edición
                } else if (item.is_shared) { // Si es compartida y no eres el propietario
                    // Podrías añadir una acción para "Ver detalles de compartido"
                    // actions.push({ label: "Ver detalles compartido", handler: () => alert("Tarea compartida por otro usuario.") });
                }
            } else { // Es una subtarea
                if (item.usuario_id === appCurrentUser.id && canModify) {
                    actions.push({ label: "Editar Subtarea", handler: () => mostrarFormularioEditarTarea(item, fecha, 'subtarea') });
                }
            }
        }
        
        // Acciones de archivar/eliminar (solo para el propietario y si tiene permiso)
        if (item.usuario_id === appCurrentUser.id && canModify) {
             actions.push({ label: "Archivar", handler: () => eliminarTareaDiaria(item, fecha, true) });
        }
    } else { // Acciones para elementos inactivos
        // Solo el propietario de la tarea inactiva puede restaurarla o eliminarla permanentemente
        if (item.usuario_id === appCurrentUser.id && canModify) {
            actions.push({ label: "Restaurar", handler: () => restaurarTarea(item, fecha) });
            actions.push({ label: "Eliminar Permanentemente", handler: () => eliminarTareaDiaria(item, fecha, false) });
        }
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
    formContainer.setAttribute('aria-hidden', 'false'); // Nuevo: Mostrar el modal para lectores de pantalla
}

export function ocultarFormulario() {
    const formContainer = document.getElementById('form-container');
    // CORRECCIÓN ACCESIBILIDAD: Mover el foco a otro lugar o hacer blur del elemento enfocado
    if (document.activeElement && formContainer.contains(document.activeElement)) {
        document.activeElement.blur(); // Quita el foco del elemento dentro del modal
    }
    formContainer.classList.add('hidden');
    formContainer.setAttribute('inert', 'true');
    formContainer.setAttribute('aria-hidden', 'true'); // Nuevo: Ocultar el modal para lectores de pantalla
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
    const hoy = new Date();
    hoy.setUTCHours(0,0,0,0); // Normalizar a medianoche UTC
    
    for (let dia = 1; dia <= numDiasEnMes; dia++) {
        const fechaStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
        const fechaActualDia = new Date(Date.UTC(year, month, dia)); // Crear fecha para comparación
        const diaSpan = document.createElement('span');
        diaSpan.className = 'dia-calendario';
        diaSpan.dataset.fecha = fechaStr;
        diaSpan.textContent = dia;
        diaSpan.setAttribute('role', 'button');
        diaSpan.setAttribute('tabindex', '0');

        if (miniCalSelectedDates.includes(fechaStr)) {
            diaSpan.classList.add('selected');
        }

        // Nuevo: Deshabilitar días pasados en el mini calendario
        if (fechaActualDia.getTime() < hoy.getTime()) {
            diaSpan.classList.add('dia-pasado');
            diaSpan.setAttribute('aria-disabled', 'true');
            diaSpan.removeAttribute('tabindex'); // Eliminar del orden de tabulación
            diaSpan.onclick = () => alert("No se pueden seleccionar días anteriores al actual.");
        } else {
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
        }
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