// script_modules/ui.js

// --- IMPORTACIONES DE OTROS MÓDULOS ---
// Estas funciones se importan porque son llamadas por las acciones en showActionsPanel
import { fetchData } from './utils.js';
import { 
    mostrarFormularioEditarObjetivo, 
    mostrarFormularioEditarSubObjetivo, 
    mostrarFormularioAddSubObjetivo, 
    mostrarFormularioEditarTarea, 
    mostrarFormularioAddSubTarea 
} from './modals.js'; 
import { 
    cargarObjetivos, 
    eliminarTareaDiaria, 
    restaurarTarea 
} from './views.js'; 
// modoActivo se necesita para el contexto en algunas acciones
import { modoActivo } from './app.js'; 


// --- MANEJO DE FORMULARIOS MODALES (con pie de página de acciones fijo) ---
let formularioDinamicoContenedorCache = null;
let formModalCache = null;

function getFormularioDinamicoContenedor() {
    if (!formularioDinamicoContenedorCache) {
        formularioDinamicoContenedorCache = document.getElementById('formulario-dinamico-contenedor');
    }
    return formularioDinamicoContenedorCache;
}

function getFormModal() { // Este es el div .form-modal interno
    if (!formModalCache) {
        const fdc = getFormularioDinamicoContenedor();
        formModalCache = fdc ? fdc.querySelector('.form-modal') : null;
    }
    return formModalCache;
}

/**
 * Muestra un formulario modal dinámico con estructura para contenido desplazable y pie de acciones fijo.
 * @param {string} contenidoCamposHTML - El HTML para los campos del formulario (sin incluir botones de acción principales).
 * @param {Function|null} onGuardarCallback - Función a ejecutar al hacer clic en Guardar. Si es null, no se muestra el botón Guardar.
 * @param {string} formTitle - Título del modal.
 * @param {string} guardarBtnText - Texto para el botón de guardar (ej. "Guardar", "Confirmar").
 * @param {string} cancelarBtnText - Texto para el botón de cancelar.
 */
export function mostrarFormulario(
    contenidoCamposHTML, 
    onGuardarCallback, 
    formTitle = "Formulario",
    guardarBtnText = "Guardar",
    cancelarBtnText = "Cancelar"
) {
    const formularioDinamicoContenedor = getFormularioDinamicoContenedor();
    const formModal = getFormModal(); 

    if (!formularioDinamicoContenedor || !formModal) {
        console.error("mostrarFormulario: Elementos del modal principal no encontrados en el DOM.");
        return;
    }

    console.log("mostrarFormulario: Mostrando formulario con título:", formTitle);

    // Nueva estructura interna del modal para soportar pie de página fijo
    formModal.innerHTML = `
        <div class="form-modal-header">
            <h3 id="form-modal-titulo">${formTitle}</h3>
        </div>
        <div class="form-modal-content">
            ${contenidoCamposHTML}
        </div>
        <div class="form-modal-actions">
            <button type="button" id="modalCancelarBtn" class="cancel-btn">${cancelarBtnText}</button>
            ${onGuardarCallback ? `<button type="button" id="modalGuardarBtn">${guardarBtnText}</button>` : ''}
        </div>
    `;
    
    formularioDinamicoContenedor.classList.remove('hidden');
    formularioDinamicoContenedor.removeAttribute('inert'); 
    formularioDinamicoContenedor.setAttribute('aria-hidden', 'false'); 
    
    const guardarBtn = formModal.querySelector('#modalGuardarBtn');
    if (guardarBtn && onGuardarCallback) { 
        console.log("[mostrarFormulario] Asignando evento 'onclick' al botón Guardar.");
        guardarBtn.onclick = async () => { 
            console.log("[Guardar Botón] Clic detectado. Ejecutando callback.");
            try { 
                await onGuardarCallback(); 
                // La función de callback es ahora responsable de llamar a ocultarFormulario si tiene éxito.
            } catch (error) { 
                console.error("Error en callback de guardarFormulario:", error);
                // No ocultar modal en error para que el usuario pueda reintentar.
                alert(`Error al procesar el formulario: ${error.message}`); 
            }
        }; 
    } else if (!onGuardarCallback) {
        console.warn("[mostrarFormulario] No se asignó evento 'onclick' porque no se proporcionó onGuardarCallback.");
    }

    const cancelarBtn = formModal.querySelector('#modalCancelarBtn');
    if (cancelarBtn) { 
        cancelarBtn.onclick = ocultarFormulario; 
    }
    
    const formContent = formModal.querySelector('.form-modal-content');
    const firstInput = formContent ? formContent.querySelector('input:not([type="hidden"]):not([type="button"]), textarea, select') : null;
    if (firstInput) { 
        setTimeout(() => firstInput.focus(), 50); // Pequeño retraso para asegurar que el modal es visible y no inerte
    } else {
        // Si no hay input, enfocar un botón para accesibilidad
        (cancelarBtn || guardarBtn || formModal).focus();
    }
}

export function ocultarFormulario() {
    const formularioDinamicoContenedor = getFormularioDinamicoContenedor();
    const formModal = getFormModal();

    if (!formularioDinamicoContenedor || !formModal) {
        console.error("ocultarFormulario: Elementos del modal principal no encontrados.");
        return;
    }
    console.log("ocultarFormulario: Ocultando formulario.");

    formularioDinamicoContenedor.classList.add('hidden');
    formularioDinamicoContenedor.setAttribute('inert', 'true'); 
    formularioDinamicoContenedor.setAttribute('aria-hidden', 'true'); 

    formModal.innerHTML = ''; // Limpiar contenido para la próxima vez
    
    // La función closeAllActionPanels se definirá en la siguiente parte de ui.js
    if (typeof closeAllActionPanels === 'function') {
        closeAllActionPanels(); 
    }
}
export let globalActionsPanel = null; // Referencia al único panel flotante

export function createGlobalActionsPanel() { 
    if (!globalActionsPanel) { 
        globalActionsPanel = document.createElement('div'); 
        globalActionsPanel.className = 'actions-panel-floating'; 
        document.body.appendChild(globalActionsPanel); 
        console.log("Panel de acciones global creado y añadido al body.");
    } 
    return globalActionsPanel; 
}

export function closeAllActionPanels() { 
    // Esta función se puede llamar desde cualquier parte para cerrar el panel
    if (globalActionsPanel && globalActionsPanel.classList.contains('actions-visible')) {
        globalActionsPanel.classList.remove('actions-visible');
        // La transición CSS se encarga de la opacidad y el transform.
        // Limpiar el contenido después de la animación para evitar saltos.
        setTimeout(() => {
            if (globalActionsPanel && !globalActionsPanel.classList.contains('actions-visible')) {
                globalActionsPanel.innerHTML = ''; 
            }
        }, 200); // Debe ser igual o mayor que la duración de la transición CSS

        document.querySelectorAll('.action-toggle-btn[aria-expanded="true"]').forEach(b => b.setAttribute('aria-expanded', 'false'));
        console.log("Panel de acciones global cerrado.");
    }
}

export function showActionsPanel(toggleButtonElement, actionsHTML, itemData, fechaObj, modeIdContext = null) {
    console.log("[showActionsPanel] Iniciando para item ID:", itemData.id);
    const panel = createGlobalActionsPanel(); // Crea o obtiene el panel global
    panel.innerHTML = actionsHTML; 
    panel.dataset.itemIdFor = String(itemData.id); 
    
    if (!actionsHTML.trim()) {
        console.error("[showActionsPanel] actionsHTML está vacío. No se mostrará contenido.");
        return; 
    }
    console.log("[showActionsPanel] HTML del panel:", actionsHTML);

    // Adjuntar listeners dinámicamente a los botones DENTRO del panel flotante
    const setupAction = (selector, handler) => {
        const btn = panel.querySelector(selector);
        if (btn) {
            console.log(`[showActionsPanel] Adjuntando listener a '${selector}' para item ID: ${itemData.id}`);
            if (typeof handler === 'function') {
                btn.onclick = e => { e.stopPropagation(); handler(); closeAllActionPanels(); };
            } else {
                console.warn(`[showActionsPanel] El handler para '${selector}' no es una función (probablemente porque el módulo que lo contiene aún no está cargado/importado).`);
            }
        } else if (actionsHTML.includes(selector.substring(1).split(' ')[0].split('.')[0])) { 
             console.warn(`[showActionsPanel] HTML para '${selector}' pudo estar, pero querySelector '${selector}' falló.`);
        }
    };
    
    const canEditItem = itemData.activo !== false || itemData.mode_id || itemData.objetivo_id;

    // Las funciones llamadas aquí (mostrarFormularioEditarObjetivo, etc.)
    // se importarán desde modals.js y views.js
    if (itemData.mode_id) { // Es un objetivo principal
        if (canEditItem) setupAction('.edit-btn', () => mostrarFormularioEditarObjetivo(itemData, itemData.mode_id));
        setupAction('.delete-btn', () => { 
            if (confirm('¿Seguro de eliminar este objetivo y todos sus sub-objetivos?')) {
                fetchData(`objetivos`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ _method: "DELETE", id: itemData.id }) })
                .then(() => { alert('Objetivo eliminado.'); cargarObjetivos(itemData.mode_id); }) 
                .catch(err => alert(`Error al eliminar objetivo: ${err.message}`));
            }
        });
        setupAction('.add-sub-objetivo-btn-panel', () => mostrarFormularioAddSubObjetivo(itemData.id, itemData.mode_id));
    } else if (itemData.objetivo_id) { // Es un sub-objetivo
        if (canEditItem) setupAction('.edit-btn', () => mostrarFormularioEditarSubObjetivo(itemData.objetivo_id, itemData, modeIdContext || modoActivo)); // modoActivo se importa de app.js
        setupAction('.delete-btn', () => {
            if (confirm('¿Seguro de eliminar este sub-objetivo?')) {
                fetchData(`sub_objetivos`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ _method: "DELETE", id: parseInt(itemData.id) }) })
                .then(() => { alert('Sub-objetivo eliminado.'); cargarObjetivos(modeIdContext || modoActivo); })
                .catch(err => alert(`Error al eliminar sub-objetivo: ${err.message}`));
            }
        });
    } else { // Es una tarea diaria (título o subtarea)
        if (itemData.activo) {
            if (canEditItem) setupAction('.edit-btn', () => mostrarFormularioEditarTarea(itemData, fechaObj, itemData.tipo));
            setupAction('.delete-btn', () => eliminarTareaDiaria(itemData, fechaObj, true)); 
            if (itemData.tipo === 'titulo') {
                setupAction('.add-sub-btn', () => mostrarFormularioAddSubTarea(itemData.id, fechaObj));
            }
        } else { // Tarea Inactiva
            setupAction('.restore-btn', () => restaurarTarea(itemData, fechaObj));
            setupAction('.delete-permanente-btn', () => eliminarTareaDiaria(itemData, fechaObj, false)); 
        }
    }
    
    // --- LÓGICA DE POSICIONAMIENTO "AL LADO" REVISADA CON MÁS DEPURACIÓN ---
    const rect = toggleButtonElement.getBoundingClientRect(); 
    const panelSpacing = 10; 
    const viewportPadding = 10; 

    console.log("[showActionsPanel] Rect del botón '⋮':", `top: ${rect.top.toFixed(1)}`, `left: ${rect.left.toFixed(1)}`, `width: ${rect.width.toFixed(1)}`);
    
    panel.style.position = 'fixed'; 
    panel.style.visibility = 'hidden'; 
    panel.style.opacity = '0'; 
    panel.style.top = '-9999px'; 
    panel.style.left = '-9999px';
    
    const panelRect = panel.getBoundingClientRect(); 
    console.log("[showActionsPanel] Rect del panel (medido oculto):", `width: ${panelRect.width.toFixed(1)}`, `height: ${panelRect.height.toFixed(1)}`);
    if (panelRect.width === 0 || panelRect.height === 0) { console.error("[showActionsPanel] ¡El panel tiene dimensiones cero!"); return; }

    let topPosition, leftPosition;
    let placementStrategy = "izquierda";

    leftPosition = rect.left + window.scrollX - panelRect.width - panelSpacing;
    topPosition = rect.top + window.scrollY + (rect.height / 2) - (panelRect.height / 2);
    console.log(`[showActionsPanel] Intento Izquierda: left=${leftPosition.toFixed(1)}`);

    if (leftPosition < (viewportPadding + window.scrollX)) {
        console.log("[showActionsPanel] No cabe a la izquierda, intentando derecha.");
        leftPosition = rect.right + window.scrollX + panelSpacing;
        placementStrategy = "derecha";
        console.log(`[showActionsPanel] Intento Derecha: left=${leftPosition.toFixed(1)}`);
    }
    
    if (leftPosition + panelRect.width > window.innerWidth - viewportPadding + window.scrollX) {
        console.log("[showActionsPanel] La posición derecha se sale, re-intentando izquierda y ajustando.");
        leftPosition = rect.left + window.scrollX - panelRect.width - panelSpacing;
        placementStrategy = "izquierda"; // Volver a la estrategia preferida
    }
    
    if (leftPosition < viewportPadding + window.scrollX) {
        leftPosition = viewportPadding + window.scrollX;
        console.log(`[showActionsPanel] Ajuste final FORZADO izquierda: left=${leftPosition.toFixed(1)}`);
    }
    if (topPosition < viewportPadding + window.scrollY) {
        topPosition = viewportPadding + window.scrollY;
    }
    if (topPosition + panelRect.height > window.innerHeight - viewportPadding + window.scrollY) {
        topPosition = window.innerHeight - panelRect.height - viewportPadding + window.scrollY;
    }
    
    panel.style.top = `${topPosition}px`;
    panel.style.left = `${leftPosition}px`;
    console.log(`[showActionsPanel] Posición final calculada: top=${panel.style.top}, left=${panel.style.left}`);
    
    // Configurar para animación de entrada
    panel.classList.remove('animate-from-left', 'animate-from-right'); 
    let initialTransform = 'scale(0.95)';
    if (parseFloat(panel.style.left) < rect.left) {
        initialTransform += ' translateX(10px)'; 
        panel.classList.add('animate-from-right');
    } else { 
        initialTransform += ' translateX(-10px)';
        panel.classList.add('animate-from-left');
    }
    
    panel.style.transform = initialTransform;
    panel.style.opacity = '0'; 
    panel.style.visibility = 'hidden'; 
    
    requestAnimationFrame(() => { 
        panel.classList.add('actions-visible'); 
        console.log("[showActionsPanel] Panel activado con .actions-visible.");
    });
    toggleButtonElement.setAttribute('aria-expanded', 'true');
}

export function createActionToggleButton(item, dateObj, modeCtx = null) {
    const btn = document.createElement('button'); btn.type = 'button'; btn.className = 'action-toggle-btn'; btn.innerHTML = '&#8942;'; btn.setAttribute('aria-label', 'Más opciones'); btn.setAttribute('aria-expanded', 'false');
    btn.onclick = e => {
        e.stopPropagation(); 
        const p = document.querySelector('.actions-panel-floating.actions-visible');
        if (p && p.dataset.itemIdFor === String(item.id)) { 
            closeAllActionPanels(); 
            return; 
        }
        closeAllActionPanels(); 
        let html = '';
        const canEditItem = item.activo !== false || item.mode_id || item.objetivo_id;

        if (item.mode_id) { 
            html = `${canEditItem ? '<button type="button" class="edit-btn">Editar</button>' : ''}<button type="button" class="delete-btn">Eliminar</button><button type="button" class="add-sub-objetivo-btn-panel">Añadir Sub-objetivo</button>`;
        } else if (item.objetivo_id) { 
            html = `${canEditItem ? '<button type="button" class="edit-btn">Editar</button>' : ''}<button type="button" class="delete-btn">Eliminar</button>`;
        } else { 
            if (item.activo) {
                html = `<button type="button" class="edit-btn">Editar</button><button type="button" class="delete-btn">Desactivar</button>`;
                if (item.tipo === 'titulo') {
                    html += `<button type="button" class="add-sub-btn">Añadir Subtarea</button>`;
                }
            } else { 
                html = `<button type="button" class="restore-btn">Restaurar</button><button type="button" class="delete-btn delete-permanente-btn">Eliminar PERM.</button>`;
            }
        }
        if (html) {
            showActionsPanel(btn, html, item, dateObj, modeCtx);
        } else {
            console.warn("createActionToggleButton: No se generaron acciones HTML para el item:", item);
        }
    }; 
    return btn;
}
// --- MINI CALENDARIO PARA MODALES ---
// Estas variables mantienen el estado del mini-calendario que se está mostrando.
let miniCalCurrentViewDate = new Date(); // Fecha del mes/año que el mini-calendario está mostrando
export let miniCalSelectedDates = [];   // Array de fechas "YYYY-MM-DD" actualmente seleccionadas. Exportada.
let miniCalTargetInputDisplay = null;   // El input <input type="text" readonly> que mostrará las fechas. Se setea en renderMiniCalendar.

/**
 * Renderiza un mini-calendario interactivo.
 * @param {number} year - El año a mostrar.
 * @param {number} month - El mes a mostrar (0-11, como en Date.getMonth()).
 * @param {HTMLInputElement} targetInputEl - El elemento input (readonly) donde se reflejarán las fechas seleccionadas.
 * @param {boolean} multiSelect - Si es true, permite seleccionar múltiples fechas. Si es false, solo una.
 */
export function renderMiniCalendar(year, month, targetInputEl, multiSelect = false) {
    const container = document.getElementById('mini-calendar-container-dynamic'); 
    if (!container) { 
        console.error("Contenedor #mini-calendar-container-dynamic no encontrado en el DOM del modal."); 
        return; 
    }
    
    miniCalCurrentViewDate.setFullYear(year, month, 1); 
    miniCalTargetInputDisplay = targetInputEl; 

    // Sincronización inicial de miniCalSelectedDates con el valor del input,
    // útil si el modal se reabre o si cambia el modo de selección.
    if (miniCalTargetInputDisplay && miniCalTargetInputDisplay.value) {
        const datesFromInputRaw = miniCalTargetInputDisplay.value.split(',').map(d => d.trim());
        const validDatesFromInput = datesFromInputRaw.filter(d => d && d.match(/^\d{4}-\d{2}-\d{2}$/));

        if (multiSelect) {
            if (validDatesFromInput.length > 0) {
                miniCalSelectedDates = validDatesFromInput;
            }
        } else { // Selección única
            if (validDatesFromInput.length > 0) {
                miniCalSelectedDates = [validDatesFromInput[0]]; 
            } else if (miniCalSelectedDates.length > 1) {
                miniCalSelectedDates = [miniCalSelectedDates[0]];
            }
        }
    } else if (!multiSelect && miniCalSelectedDates.length > 1) {
        // Si el input está vacío, no es multiselect, pero miniCalSelectedDates tenía varias, tomar solo la primera.
        miniCalSelectedDates = [miniCalSelectedDates[0]];
    }

    // Actualizar el input y el display con el estado actual de miniCalSelectedDates
    if(miniCalTargetInputDisplay) miniCalTargetInputDisplay.value = miniCalSelectedDates.join(', ');
    const displaySelGlobal = document.getElementById('selected-dates-display'); 
    if(displaySelGlobal) displaySelGlobal.textContent = `Fechas: ${miniCalSelectedDates.length ? miniCalSelectedDates.join(', ') : 'Ninguna'}`;


    const monthName = miniCalCurrentViewDate.toLocaleString('es-ES', { month: 'long' });
    const currentY = miniCalCurrentViewDate.getFullYear();
    const currentM = miniCalCurrentViewDate.getMonth(); // 0-11

    let calHTML = `
        <div class="mini-calendario-nav">
            <button type="button" id="mini-cal-prev-month" aria-label="Mes anterior">‹</button>
            <span id="mini-cal-mes-anio">${monthName.charAt(0).toUpperCase() + monthName.slice(1)} ${currentY}</span>
            <button type="button" id="mini-cal-next-month" aria-label="Mes siguiente">›</button>
        </div>
        <div class="mini-cal-dias-semana">
            ${['L','M','X','J','V','S','D'].map(d=>`<span>${d}</span>`).join('')}
        </div>
        <div class="mini-cal-dias-mes">`;

    const firstDayOfMonth = new Date(currentY, currentM, 1);
    const daysInMonth = new Date(currentY, currentM + 1, 0).getDate();
    let dayOfWeekFirstDay = firstDayOfMonth.getDay(); 
    if (dayOfWeekFirstDay === 0) dayOfWeekFirstDay = 7; 
    for (let i = 1; i < dayOfWeekFirstDay; i++) { calHTML += `<span></span>`; } 

    const today = new Date(); 
    today.setHours(0,0,0,0); 

    for (let day = 1; day <= daysInMonth; day++) {
        const dateStr = `${currentY}-${String(currentM + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const currentDateLoop = new Date(Date.UTC(currentY, currentM, day)); 
        let cls = 'mini-cal-dia'; 
        const todayUTC = new Date(Date.UTC(today.getFullYear(), today.getMonth(), today.getDate()));
        if (currentDateLoop.getTime() === todayUTC.getTime()) cls += ' hoy'; 
        if (miniCalSelectedDates.includes(dateStr)) cls += ' seleccionado';
        
        calHTML += `<span class="${cls}" data-date="${dateStr}" role="button" tabindex="0">${day}</span>`;
    }
    calHTML += `</div>`; 
    container.innerHTML = calHTML;

    document.getElementById('mini-cal-prev-month').onclick = () => renderMiniCalendar(currentY, currentM - 1, targetInputEl, multiSelect);
    document.getElementById('mini-cal-next-month').onclick = () => renderMiniCalendar(currentY, currentM + 1, targetInputEl, multiSelect);
    
    container.querySelectorAll('.mini-cal-dia:not(.otro-mes)').forEach(cell => {
        cell.onclick = () => {
            const selDate = cell.dataset.date;
            if (multiSelect) { 
                const idx = miniCalSelectedDates.indexOf(selDate); 
                if (idx > -1) { 
                    miniCalSelectedDates.splice(idx, 1); 
                    cell.classList.remove('seleccionado'); 
                } else { 
                    miniCalSelectedDates.push(selDate); 
                    cell.classList.add('seleccionado'); 
                }
                miniCalSelectedDates.sort(); 
            } else { // Selección única
                if (miniCalSelectedDates.length === 1 && miniCalSelectedDates[0] === selDate) {
                    miniCalSelectedDates = []; // Deseleccionar si se hace clic en la misma fecha
                    cell.classList.remove('seleccionado');
                } else {
                    miniCalSelectedDates = [selDate]; // Seleccionar/Reemplazar selección
                    container.querySelectorAll('.mini-cal-dia.seleccionado').forEach(sc => sc.classList.remove('seleccionado'));
                    cell.classList.add('seleccionado');
                }
            } 
            
            if (miniCalTargetInputDisplay) miniCalTargetInputDisplay.value = miniCalSelectedDates.join(', ');
            
            const displaySel = document.getElementById('selected-dates-display'); 
            if(displaySel) displaySel.textContent = `Fechas: ${miniCalSelectedDates.length ? miniCalSelectedDates.join(', ') : 'Ninguna'}`;
        };
        cell.onkeydown = e => { if(e.key === 'Enter' || e.key === ' ') e.target.click(); };
    });
}
// Fin del módulo ui.js