// script_modules/modals.js

import { API_BASE_URL, EMOJIS_PREDEFINIDOS } from './config.js';
import { fetchData } from './utils.js';
// Importar la NUEVA versión de mostrarFormulario que maneja sus propios botones.
// También uiMiniCalSelectedDates para que este módulo pueda inicializarlo.
import { 
    mostrarFormulario, 
    ocultarFormulario, 
    renderMiniCalendar, 
    miniCalSelectedDates as uiMiniCalSelectedDates
} from './ui.js'; 
// Funciones de recarga de vistas y estado de la app (se definirán/importarán en sus respectivos módulos)
import { cargarTareasDiaADia, renderizarCalendario as appRenderizarCalendario, cargarObjetivos } from './views.js'; 
// Importar estado y funciones de app.js
import { 
    fechaCalendarioActual, // Importar directamente el nombre exportado desde app.js
    setFechaCalendarioActual,
    modoActivo,
    modosDisponibles 
} from './app.js'; 


export function abrirModalParaNuevoElemento(contexto = null, fechaPreseleccionada = null) {
    console.log("abrirModalParaNuevoElemento (modals.js):", contexto, "fechaPreseleccionada:", fechaPreseleccionada);
    let formHtmlCampos = '', formTitle = "Añadir Elemento";
    let guardarBtnText = "Guardar"; // Texto por defecto para el botón de guardar
    
    let emojiSelectorHTML = `<div class="emoji-selector-container" id="emojiSelectorAddTask">${EMOJIS_PREDEFINIDOS.map(e => `<span class="emoji-option" data-emoji="${e}" role="button" tabindex="0" aria-label="Seleccionar ${e}">${e}</span>`).join('')}</div>`;

    if (contexto === 'dia-a-dia') {
        formTitle = "Añadir Título, Subtareas y Programación";
        guardarBtnText = "Guardar Programación";
        
        // Limpiar y preparar el array de fechas seleccionadas del módulo ui.js
        uiMiniCalSelectedDates.length = 0; 
        if (fechaPreseleccionada) {
            uiMiniCalSelectedDates.push(fechaPreseleccionada);
        }
        
        // El HTML ahora solo contiene los campos. Los botones de acción se añaden por mostrarFormulario.
        formHtmlCampos = `
            <div id="camposTituloDiario">
                <label for="nuevoTituloTexto">Texto del Título Diario:</label>
                <input type="text" id="nuevoTituloTexto" placeholder="Ej. Rutina de mañana">
            </div>

            <fieldset id="programacionFechas">
                <legend>Programación de Fechas</legend>
                <label for="fechaInicioInputDisplay">Fecha(s) (clic en calendario):</label>
                <input type="text" id="fechaInicioInputDisplay" value="${uiMiniCalSelectedDates.join(', ')}" readonly placeholder="Seleccionar del calendario...">
                <div id="mini-calendar-container-dynamic"></div>
                <div id="selected-dates-display">Fechas: ${uiMiniCalSelectedDates.length > 0 ? uiMiniCalSelectedDates.join(', ') : 'Ninguna'}</div>
                
                <label for="tipoRecurrencia">Opciones Periodo/Repetición:</label>
                <select id="tipoRecurrencia">
                    <option value="SPECIFIC_DATES">Días específicos (del calendario)</option>
                    <option value="NONE">Solo una vez (primera/única fecha)</option>
                    <option value="DAILY">Diariamente (desde primera/única fecha)</option>
                    <option value="WEEKLY">Semanalmente (desde primera/única fecha)</option>
                    <option value="MONTHLY_DAY">Mensualmente (mismo día, desde primera/única fecha)</option>
                    <option value="PERIOD_CURRENT_MONTH">Todo el mes (de primera/única fecha)</option>
                    <option value="PERIOD_1_MONTH">Durante 1 mes (desde primera/única fecha)</option>
                    <option value="PERIOD_2_MONTHS">Durante 2 meses (desde primera/única fecha)</option>
                    <option value="PERIOD_3_MONTHS">Durante 3 meses (desde primera/única fecha)</option>
                </select>
                <div id="days-of-week-selector" style="display:none;">
                    <small>Selecciona días de la semana:</small><br>
                    ${['MON','TUE','WED','THU','FRI','SAT','SUN'].map(d=>`<label><input type="checkbox" name="recurrenciaDia" value="${d}"> ${d.charAt(0).toUpperCase() + d.slice(1).toLowerCase().substring(0,2)}</label>`).join('')}
                </div>
            </fieldset>

            <fieldset id="anotacionProgramada">
                <legend>Anotación para Fecha(s) de Inicio (Opcional)</legend>
                <div>
                    <label for="emojiAnotacionTareaInput">Emoji(s) (máx. 3): <span id="currentEmojiAnotacionDisplay" class="current-emoji-display"></span></label>
                    ${emojiSelectorHTML}
                    <input type="hidden" id="emojiAnotacionTareaInput" value="">
                </div>
                <div>
                    <label for="descripcionAnotacionTarea">Descripción del emoji:</label>
                    <input type="text" id="descripcionAnotacionTarea" placeholder="Ej. Importante!">
                </div>
            </fieldset>

            <div id="subtasks-inputs-container">
                <label>Subtareas (al menos una obligatoria):</label>
                <div class="subtask-entry">
                    <input type="text" class="subtask-text-input" placeholder="Subtarea 1">
                </div>
            </div>
            <button type="button" id="addMoreSubtasksBtn">+ Subtarea</button>
            `; 
    } else if (contexto === 'corto-medio-plazo') {
        formTitle = "Añadir Objetivo a Corto/Medio Plazo";
        guardarBtnText = "Guardar Objetivo";
        formHtmlCampos = `
            <div id="camposObjetivo">
                <label for="nuevoObjetivoTitulo">Título del Objetivo:</label>
                <input type="text" id="nuevoObjetivoTitulo" placeholder="Ej. Terminar curso online">
                <label for="nuevoObjetivoFecha">Fecha Estimada:</label>
                <input type="text" id="nuevoObjetivoFecha" placeholder="Ej. Próximos 3 meses, junio 2025">
                <label for="nuevoObjetivoDescripcion">Descripción:</label>
                <textarea id="nuevoObjetivoDescripcion" placeholder="Pasos, recursos..."></textarea>
            </div>`;
    } else if (contexto === 'largo-plazo') {
        formTitle = "Añadir Objetivo a Largo Plazo";
        guardarBtnText = "Guardar Objetivo";
        formHtmlCampos = `
            <div id="camposObjetivo">
                <label for="nuevoObjetivoTitulo">Título del Objetivo:</label>
                <input type="text" id="nuevoObjetivoTitulo" placeholder="Ej. Comprar una casa">
                <label for="nuevoObjetivoFecha">Fecha Estimada:</label>
                <input type="text" id="nuevoObjetivoFecha" placeholder="Ej. 5 años, 2028">
                <label for="nuevoObjetivoDescripcion">Descripción:</label>
                <textarea id="nuevoObjetivoDescripcion" placeholder="Plan de ahorro, investigación..."></textarea>
            </div>`;
    } else { 
        console.error("abrirModalParaNuevoElemento: Contexto desconocido o nulo:", contexto);
        alert("Error: No se puede determinar qué tipo de elemento añadir.");
        return; 
    }
    
    // Definir el callback de guardado
    const onGuardarCallback = async () => {
        let urlEndpoint, options, payload = {}, successMessage, reloadFunction;
        const tipoOp = contexto;
        
        if (tipoOp === 'dia-a-dia') {
            const tituloTexto = document.getElementById('nuevoTituloTexto').value.trim();
            if (!tituloTexto) { alert('El texto del título es obligatorio.'); throw new Error("Título vacío"); }
            payload = { 
                texto: tituloTexto, 
                tipo: 'titulo', 
                subtareas_textos: Array.from(document.querySelectorAll('#subtasks-inputs-container .subtask-text-input')).map(i=>i.value.trim()).filter(t=>t) 
            };
            if (payload.subtareas_textos.length === 0) { alert('Al menos una subtarea es obligatoria.'); throw new Error("Subtarea vacía"); }
            const tipoRecurrencia = document.getElementById('tipoRecurrencia').value;
            payload.regla_recurrencia = tipoRecurrencia;
            const primeraFechaDeReferencia = uiMiniCalSelectedDates.length > 0 ? uiMiniCalSelectedDates[0] : (fechaPreseleccionada || fechaCalendarioActual.toISOString().split('T')[0]);

            if (tipoRecurrencia === 'SPECIFIC_DATES') {
                if (uiMiniCalSelectedDates.length === 0) { alert('Debe seleccionar al menos una fecha específica.'); throw new Error("Fechas específicas no seleccionadas"); }
                payload.fechas_seleccionadas = [...uiMiniCalSelectedDates]; 
                payload.regla_recurrencia = 'NONE'; 
                payload.fecha_inicio = uiMiniCalSelectedDates[0]; 
            } else { 
                payload.fecha_inicio = primeraFechaDeReferencia;
                if (!payload.fecha_inicio) { alert('Debe seleccionar una fecha de inicio.'); throw new Error("Fecha de inicio no seleccionada"); }
                if (tipoRecurrencia.startsWith('PERIOD_')) {
                    if(tipoRecurrencia === 'PERIOD_CURRENT_MONTH') payload.periodo_meses = "CURRENT_MONTH";
                    else { payload.periodo_meses = parseInt(tipoRecurrencia.split('_')[1]); }
                } else if (tipoRecurrencia === "WEEKLY") {
                    const dias = Array.from(document.querySelectorAll('#days-of-week-selector input[name="recurrenciaDia"]:checked')).map(cb => cb.value);
                    if (dias.length === 0) { alert("Seleccione días para repetición semanal."); throw new Error("Días semanales no seleccionados"); }
                    payload.regla_recurrencia = `WEEKLY:${dias.join(',')}`;
                }
            }
            payload.emoji_anotacion = document.getElementById('emojiAnotacionTareaInput').value; 
            payload.descripcion_anotacion = document.getElementById('descripcionAnotacionTarea').value.trim();
            
            urlEndpoint = `tareas-dia-a-dia`;
            options = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
            successMessage = 'Programación guardada.'; 
            reloadFunction = async () => { 
                const refDate = payload.fecha_inicio || (payload.fechas_seleccionadas && payload.fechas_seleccionadas[0]) || fechaCalendarioActual.toISOString().split('T')[0];
                let newBaseDate = new Date(refDate + 'T00:00:00Z'); 
                setFechaCalendarioActual(newBaseDate); 
            };
            await fetchData(urlEndpoint, options); 
        
        } else if (tipoOp === 'corto-medio-plazo' || tipoOp === 'largo-plazo') {
            const titulo = document.getElementById('nuevoObjetivoTitulo').value.trim();
            const fecha = document.getElementById('nuevoObjetivoFecha').value.trim();
            const descripcion = document.getElementById('nuevoObjetivoDescripcion').value.trim();
            if (!titulo) { alert('El título del objetivo no puede estar vacío.'); throw new Error("Título vacío"); }
            urlEndpoint = `objetivos`;
            payload = { titulo, fecha_estimada: fecha, descripcion, mode_id: tipoOp };
            options = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
            successMessage = 'Objetivo añadido.';
            reloadFunction = async () => { await cargarObjetivos(tipoOp); };
            await fetchData(urlEndpoint, options);
        } else { 
            throw new Error("Operación de guardado no reconocida."); 
        }
        
        alert(successMessage);
        ocultarFormulario(); 
        if (reloadFunction) await reloadFunction();
    };
    
    mostrarFormulario(formHtmlCampos, onGuardarCallback, formTitle, guardarBtnText);

    // Lógica específica post-renderizado del modal para 'dia-a-dia'
    if (contexto === 'dia-a-dia') {
        const fechaInputDisplayEl = document.getElementById('fechaInicioInputDisplay');
        const initialDateForMiniCal = fechaPreseleccionada ? new Date(fechaPreseleccionada + "T00:00:00Z") : new Date();
        renderMiniCalendar(initialDateForMiniCal.getUTCFullYear(), initialDateForMiniCal.getUTCMonth(), fechaInputDisplayEl, true); 
        
        const tipoRecSelect = document.getElementById('tipoRecurrencia');
        const daysOfWeekSel = document.getElementById('days-of-week-selector');
        const miniCalCont = document.getElementById('mini-calendar-container-dynamic');

        if(tipoRecSelect) {
            tipoRecSelect.onchange = function() {
                daysOfWeekSel.style.display = (this.value === 'WEEKLY') ? 'block' : 'none';
                const isSpecific = this.value === 'SPECIFIC_DATES';
                if (miniCalCont) miniCalCont.style.display = isSpecific ? 'block' : 'none';
                
                if(!isSpecific) { 
                    const primeraFecha = uiMiniCalSelectedDates.length > 0 ? uiMiniCalSelectedDates[0] : (fechaPreseleccionada || new Date().toISOString().split('T')[0]);
                    const tempDate = primeraFecha ? new Date(primeraFecha+"T00:00:00Z") : new Date();
                    renderMiniCalendar(tempDate.getUTCFullYear(), tempDate.getUTCMonth(), fechaInputDisplayEl, false); 
                    if (miniCalCont) miniCalCont.style.display = 'none'; 
                } else {
                    const tempDate = uiMiniCalSelectedDates.length > 0 ? new Date(uiMiniCalSelectedDates[0]+"T00:00:00Z") : initialDateForMiniCal;
                    renderMiniCalendar(tempDate.getUTCFullYear(), tempDate.getUTCMonth(), fechaInputDisplayEl, true);
                }
            };
            tipoRecSelect.onchange(); 
        }
        const addMoreSubtasksBtn = document.getElementById('addMoreSubtasksBtn');
        const subtasksContainer = document.getElementById('subtasks-inputs-container');
        if (addMoreSubtasksBtn && subtasksContainer) {
            addMoreSubtasksBtn.onclick = () => { /* ... (lógica añadir subtarea de la respuesta #3) ... */ };
        }
        const emojiOptsAddTask = document.querySelectorAll('#emojiSelectorAddTask .emoji-option');
        if(emojiOptsAddTask.length) {
            let selectedEmojisModalArray = []; 
            emojiOptsAddTask.forEach(opt => { 
                opt.onclick = () => { /* ... (lógica rolling emoji selector de la respuesta #3) ... */ }; 
                opt.onkeydown = e => { if(e.key==='Enter'||e.key===' ') opt.click();};
            });
        }
    }
} // Fin de abrirModalParaNuevoElemento
export function mostrarFormularioAddSubTarea(parentId, fechaObjRecarga) {
    const formHtmlCampos = `
        <label for="nuevaSubTareaTexto">Texto de la Sub-Tarea:</label>
        <input type="text" id="nuevaSubTareaTexto" placeholder="Ej. Comprar leche">
    `; // Botones se añadirán por mostrarFormulario de ui.js
    
    const onGuardarSubTarea = async () => { 
        const texto = document.getElementById('nuevaSubTareaTexto').value.trim();
        if (!texto) { 
            alert('El texto no puede estar vacío.'); 
            throw new Error("Texto vacío para subtarea"); 
        }
        
        const urlEndpoint = `tareas-dia-a-dia`; 
        const payload = { 
            texto, 
            tipo: 'subtarea', 
            parent_id: parentId,
            // Las subtareas añadidas así son para el día específico del título padre
            // y no son recurrentes por sí mismas. Heredan la fecha_inicio de su instancia de título.
            fecha_inicio: fechaObjRecarga.toISOString().split('T')[0], 
            regla_recurrencia: 'NONE' 
        };
        const options = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
        
        try {
            // fetchData usa API_BASE_URL internamente
            await fetchData(urlEndpoint, options); 
            alert('Sub-tarea añadida.'); 
            ocultarFormulario(); // ocultarFormulario se importa desde ui.js
            
            // cargarTareasDiaADia y appRenderizarCalendario se importan desde views.js
            // fechaCalendarioActual y setFechaCalendarioActual se importan desde app.js
            const fechaParaRecargar = new Date(fechaCalendarioActual.getTime() || fechaObjRecarga.getTime());
            await cargarTareasDiaADia(fechaParaRecargar); 
            await appRenderizarCalendario(fechaParaRecargar.getFullYear(), fechaParaRecargar.getMonth(), setFechaCalendarioActual);
        } catch (error) {
            console.error("Error al añadir subtarea:", error);
            alert(`Error al añadir subtarea: ${error.message}`);
            // No ocultar el formulario aquí para que el usuario pueda reintentar.
            throw error; // Re-lanzar para que el catch de mostrarFormulario lo maneje si es necesario.
        }
    };
    
    // mostrarFormulario ahora maneja sus propios botones
    mostrarFormulario(formHtmlCampos, onGuardarSubTarea, "Añadir Sub-Tarea", "Guardar Sub-Tarea");
}

export function mostrarFormularioAddSubObjetivo(objetivoPrincipalId, mode_id_recarga) {
    const formHtmlCampos = `
        <label for="nuevoSubObjetivoModalTexto">Texto del Sub-Objetivo:</label>
        <input type="text" id="nuevoSubObjetivoModalTexto" placeholder="Ej. Investigar opciones">
    `; // Botones se añadirán por mostrarFormulario de ui.js
    
    const onGuardarSubObjetivo = async () => {
        const texto = document.getElementById('nuevoSubObjetivoModalTexto').value.trim();
        if (!texto) { 
            alert('El texto no puede estar vacío.'); 
            throw new Error("Texto vacío para sub-objetivo"); 
        }
        
        const urlEndpoint = `sub_objetivos`; 
        const payload = { objetivo_id: objetivoPrincipalId, texto: texto };
        const options = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
        
        try {
            // fetchData usa API_BASE_URL internamente
            await fetchData(urlEndpoint, options);
            alert('Sub-objetivo añadido.');
            ocultarFormulario(); // ocultarFormulario se importa desde ui.js
            await cargarObjetivos(mode_id_recarga); // cargarObjetivos se importa desde views.js
        } catch (error) {
            console.error("Error al añadir sub-objetivo:", error);
            alert(`Error al añadir sub-objetivo: ${error.message}`);
            throw error; 
        }
    };

    mostrarFormulario(formHtmlCampos, onGuardarSubObjetivo, "Añadir Nuevo Sub-Objetivo", "Guardar Sub-Objetivo");
}
export function mostrarFormularioEditarTarea(tarea, fechaObjRecarga, tipoOriginal = 'subtarea') {
    if (!tarea.activo && (tipoOriginal === 'titulo' || tipoOriginal === 'subtarea')) {
        alert("No se pueden editar elementos inactivos. Restaure primero el elemento si desea editarlo.");
        return;
    }
    let formHtmlCampos = `<label for="editTareaTexto">Texto:</label>
                          <input type="text" id="editTareaTexto" value="${tarea.texto || ''}">`;
    
    let fechaInicioActual = '';
    let reglaRecurrenciaActual = 'NONE';
    let diasSemanaActuales = [];

    if (tipoOriginal === 'titulo') {
        fechaInicioActual = tarea.fecha_inicio || tarea.fecha_creacion || fechaCalendarioActual.toISOString().split('T')[0];
        reglaRecurrenciaActual = tarea.regla_recurrencia || 'NONE';
        if (reglaRecurrenciaActual.startsWith('WEEKLY:')) {
            diasSemanaActuales = reglaRecurrenciaActual.split(':')[1].split(',');
        }
        formHtmlCampos += `
            <fieldset style="margin-top:15px; border: 1px solid #ccc; padding: 10px; border-radius: 5px;">
                <legend style="font-weight: bold; padding: 0 5px;">Programación</legend>
                <label for="editFechaInicioTarea">Fecha de inicio:</label>
                <input type="date" id="editFechaInicioTarea" value="${fechaInicioActual}" style="margin-bottom:10px;">
                <label for="editTipoRecurrencia">Repetir:</label>
                <select id="editTipoRecurrencia" style="margin-bottom:5px;">
                    <option value="NONE" ${reglaRecurrenciaActual === 'NONE' ? 'selected' : ''}>Solo una vez</option>
                    <option value="DAILY" ${reglaRecurrenciaActual === 'DAILY' ? 'selected' : ''}>Diariamente</option>
                    <option value="WEEKLY" ${reglaRecurrenciaActual.startsWith('WEEKLY:') ? 'selected' : ''}>Semanalmente</option>
                    <option value="MONTHLY_DAY" ${reglaRecurrenciaActual === 'MONTHLY_DAY' ? 'selected' : ''}>Mensualmente (mismo día)</option>
                </select>
                <div id="edit-days-of-week-selector" style="display:${reglaRecurrenciaActual.startsWith('WEEKLY:') ? 'block' : 'none'}; margin-top:5px; margin-bottom:10px; padding:5px; background:#f9f9f9; border-radius:3px;">
                    <small>Selecciona días:</small><br>
                    ${['MON','TUE','WED','THU','FRI','SAT','SUN'].map(d => `<label style="margin-right:5px;"><input type="checkbox" name="editRecurrenciaDia" value="${d}" ${diasSemanaActuales.includes(d) ? 'checked' : ''}> ${d.charAt(0).toUpperCase() + d.slice(1).toLowerCase().substring(0,2)}</label>`).join('')}
                </div>
            </fieldset>
        `;
    }
    // Los botones se añadirán por mostrarFormulario
    
    const onGuardarEditarTarea = async () => { 
        const texto = document.getElementById('editTareaTexto').value.trim();
        if (!texto) { alert('El texto no puede estar vacío.'); throw new Error("Texto vacío"); }
        const payload = { _method: "PUT", id: tarea.id, texto };

        if (tipoOriginal === 'titulo') {
            payload.fecha_inicio = document.getElementById('editFechaInicioTarea').value;
            const tipoRecurrenciaVal = document.getElementById('editTipoRecurrencia').value;
            payload.regla_recurrencia = tipoRecurrenciaVal;
            if (tipoRecurrenciaVal === "WEEKLY") {
                const diasSeleccionados = Array.from(document.querySelectorAll('#edit-days-of-week-selector input[name="editRecurrenciaDia"]:checked'))
                                            .map(cb => cb.value);
                if (diasSeleccionados.length === 0) { alert("Si elige repetición semanal, debe seleccionar al menos un día."); throw new Error("Días semanales no seleccionados"); }
                payload.regla_recurrencia = `WEEKLY:${diasSeleccionados.join(',')}`;
            }
        }

        const urlEndpoint = `tareas-dia-a-dia`;
        const options = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
        
        try {
            await fetchData(urlEndpoint, options); 
            alert('Tarea actualizada.'); 
            ocultarFormulario(); 
            
            let calendarioPrincipalNecesitaRecargaCompleta = false;
            if (tipoOriginal === 'titulo' && payload.fecha_inicio) {
                const nuevaFechaInicioTarea = new Date(payload.fecha_inicio + 'T00:00:00Z'); 
                if (fechaCalendarioActual.getUTCFullYear() !== nuevaFechaInicioTarea.getUTCFullYear() || 
                    fechaCalendarioActual.getUTCMonth() !== nuevaFechaInicioTarea.getUTCMonth()) {
                    setFechaCalendarioActual(nuevaFechaInicioTarea); 
                    calendarioPrincipalNecesitaRecargaCompleta = true; 
                }
            }

            if (!calendarioPrincipalNecesitaRecargaCompleta) {
                await cargarTareasDiaADia(new Date(fechaCalendarioActual)); 
                await appRenderizarCalendario(fechaCalendarioActual.getFullYear(), fechaCalendarioActual.getMonth(), setFechaCalendarioActual);
            }

        } catch (error) {
            console.error("Error al editar tarea:", error);
            alert(`Error al editar tarea: ${error.message}`);
            throw error;
        }
    };

    mostrarFormulario(formHtmlCampos, onGuardarEditarTarea, `Editar ${tipoOriginal === 'titulo' ? 'Título y Programación' : 'Subtarea'}`);

    if (tipoOriginal === 'titulo') {
        const editTipoRecurrenciaSelect = document.getElementById('editTipoRecurrencia');
        const editDaysOfWeekSelector = document.getElementById('edit-days-of-week-selector');
        if(editTipoRecurrenciaSelect && editDaysOfWeekSelector) {
            editTipoRecurrenciaSelect.onchange = function() {
                editDaysOfWeekSelector.style.display = (this.value === 'WEEKLY') ? 'block' : 'none';
            }
            editDaysOfWeekSelector.style.display = (editTipoRecurrenciaSelect.value === 'WEEKLY') ? 'block' : 'none';
        }
    }
}

export function mostrarFormularioEditarObjetivo(objetivo, mode_id_recarga) {
    const formHtmlCampos = `
        <label for="editObjetivoTitulo">Título:</label>
        <input type="text" id="editObjetivoTitulo" value="${objetivo.titulo || ''}">
        <label for="editObjetivoFecha">Fecha Estimada:</label>
        <input type="text" id="editObjetivoFecha" value="${objetivo.fecha_estimada || ''}">
        <label for="editObjetivoDescripcion">Descripción:</label>
        <textarea id="editObjetivoDescripcion">${objetivo.descripcion || ''}</textarea>
    `;
    
    const onGuardarEditarObjetivo = async () => {
        const titulo = document.getElementById('editObjetivoTitulo').value.trim();
        const fecha = document.getElementById('editObjetivoFecha').value.trim();
        const descripcion = document.getElementById('editObjetivoDescripcion').value.trim();
        if (!titulo) { alert('El título no puede estar vacío.'); throw new Error("Título vacío"); }
        
        const urlEndpoint = `objetivos`;
        const payload = { _method: "PUT", id: objetivo.id, titulo, fecha_estimada: fecha, descripcion };
        const options = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
        
        try {
            await fetchData(urlEndpoint, options); 
            alert('Objetivo actualizado.'); 
            ocultarFormulario();
            await cargarObjetivos(mode_id_recarga); 
        } catch (error) {
            console.error("Error al editar objetivo:", error);
            alert(`Error al editar objetivo: ${error.message}`);
            throw error;
        }
    };
    mostrarFormulario(formHtmlCampos, onGuardarEditarObjetivo, "Editar Objetivo");
}

export function mostrarFormularioEditarSubObjetivo(objetivoIdPadre, subObjetivo, mode_id_recarga) {
    const formHtmlCampos = `
        <label for="editSubObjetivoTexto">Texto:</label>
        <input type="text" id="editSubObjetivoTexto" value="${subObjetivo.texto || ''}">
    `;
    
    const onGuardarEditarSubObjetivo = async () => {
        const texto = document.getElementById('editSubObjetivoTexto').value.trim();
        if (!texto) { alert('El texto no puede estar vacío.'); throw new Error("Texto vacío"); }
        
        const urlEndpoint = `sub_objetivos`;
        const payload = { _method: "PUT", id: subObjetivo.id, texto }; 
        const options = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
        
        try {
            await fetchData(urlEndpoint, options); 
            alert('Sub-objetivo actualizado.'); 
            ocultarFormulario();
            await cargarObjetivos(mode_id_recarga); 
        } catch (error) {
            console.error("Error al editar sub-objetivo:", error);
            alert(`Error al editar sub-objetivo: ${error.message}`);
            throw error;
        }
    };
    mostrarFormulario(formHtmlCampos, onGuardarEditarSubObjetivo, "Editar Sub-Objetivo");
}
// Fin del módulo modals.js