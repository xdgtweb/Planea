// script_modules/modals.js

// >>>>> INICIO DE LOG DE DEPURACIÓN <<<<<
console.log("modals.js loaded!"); 
// >>>>> FIN DE LOG DE DEPURACIÓN <<<<<

import { EMOJIS_PREDEFINIDOS } from './config.js';
import { fetchData } from './utils.js';
import { 
    mostrarFormulario, 
    ocultarFormulario, 
    renderMiniCalendar, 
    miniCalSelectedDates as uiMiniCalSelectedDates
} from './ui.js'; 
import { cargarTareasDiaADia, renderizarCalendario as appRenderizarCalendario, cargarObjetivos } from './views.js'; 
import { 
    fechaCalendarioActual,
    setFechaCalendarioActual,
    modoActivo
} from './app.js'; 


export function abrirModalParaNuevoElemento(contexto = null, fechaPreseleccionada = null) {
    let formHtmlCampos = '', formTitle = "Añadir Elemento";
    let guardarBtnText = "Guardar";
    
    let emojiSelectorHTML = `<div class="emoji-selector-container" id="emojiSelectorAddTask">${EMOJIS_PREDEFINIDOS.map(e => `<span class="emoji-option" data-emoji="${e}" role="button" tabindex="0" aria-label="Seleccionar ${e}">${e}</span>`).join('')}</div>`;

    if (contexto === 'dia-a-dia') {
        formTitle = "Añadir Título, Subtareas y Programación";
        guardarBtnText = "Guardar Programación";
        
        uiMiniCalSelectedDates.length = 0; 
        if (fechaPreseleccionada) {
            uiMiniCalSelectedDates.push(fechaPreseleccionada);
        }
        
        formHtmlCampos = `
            <div id="camposTituloDiario">
                <label for="nuevoTituloTexto">Texto del Título Diario:</label>
                <input type="text" id="nuevoTituloTexto" placeholder="Ej. Rutina de mañana">
            </div>

            <fieldset id="programacionFechas">
                <legend>Programación de Fechas</legend>
                <div id="mini-calendar-container-dynamic"></div>
                <div id="selected-dates-display">Fechas: ${uiMiniCalSelectedDates.length > 0 ? uiMiniCalSelectedDates.join(', ') : 'Ninguna'}</div>
                
                <label for="tipoRecurrencia">Opciones de Repetición:</label>
                <select id="tipoRecurrencia">
                    <option value="NONE">Solo una vez (en las fechas seleccionadas)</option>
                    <option value="DAILY">Diariamente (desde la primera fecha)</option>
                    <option value="WEEKLY">Semanalmente (en los días de la semana de las fechas)</option>
                    <option value="MONTHLY_DAY">Mensualmente (mismo día del mes)</option>
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
    } else if (contexto === 'corto-medio-plazo' || contexto === 'largo-plazo') {
        formTitle = `Añadir Objetivo a ${contexto === 'corto-medio-plazo' ? 'Corto/Medio' : 'Largo'} Plazo`;
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
    } else { 
        console.error("Contexto desconocido:", contexto);
        return; 
    }
    
    const onGuardarCallback = async () => {
        const tipoOp = contexto;
        
        if (tipoOp === 'dia-a-dia') {
            const tituloTexto = document.getElementById('nuevoTituloTexto').value.trim();
            if (!tituloTexto) { alert('El texto del título es obligatorio.'); throw new Error("Título vacío"); }
            
            const subtareas = Array.from(document.querySelectorAll('#subtasks-inputs-container .subtask-text-input')).map(i=>i.value.trim()).filter(t=>t);
            if (subtareas.length === 0) { alert('Al menos una subtarea es obligatoria.'); throw new Error("Subtarea vacía"); }
            
            const tipoRecurrencia = document.getElementById('tipoRecurrencia').value;
            const fechasParaGuardar = uiMiniCalSelectedDates; 

            if (fechasParaGuardar.length === 0) { alert('Debe seleccionar al menos una fecha del calendario.'); throw new Error("Fechas no seleccionadas"); }

            let regla_recurrencia_final = tipoRecurrencia;
            if (tipoRecurrencia === 'WEEKLY') {
                const dias = Array.from(document.querySelectorAll('#days-of-week-selector input[name="recurrenciaDia"]:checked')).map(cb => cb.value);
                if (dias.length === 0) { alert("Seleccione días para repetición semanal."); throw new Error("Días semanales no seleccionados"); }
                regla_recurrencia_final = `WEEKLY:${dias.join(',')}`;
            }

            let successfulSaves = 0;
            let failedSaves = 0;
            let errors = [];

            for (const fecha of fechasParaGuardar) {
                const payload = { 
                    texto: tituloTexto, 
                    tipo: 'titulo', 
                    subtareas_textos: subtareas,
                    regla_recurrencia: regla_recurrencia_final,
                    fecha_inicio: fecha, 
                    emoji_anotacion: document.getElementById('emojiAnotacionTareaInput').value, 
                    descripcion_anotacion: document.getElementById('descripcionAnotacionTarea').value.trim(),
                };

                try {
                    await fetchData('tareas-dia-a-dia', 'POST', payload);
                    successfulSaves++;
                } catch (error) {
                    failedSaves++;
                    errors.push(`Error al guardar para la fecha ${fecha}: ${error.message}`);
                    console.error(`Error al guardar para la fecha ${fecha}:`, error);
                }
            }
            
            if (successfulSaves > 0) {
                alert(`Programación guardada exitosamente para ${successfulSaves} día(s).`);
            }
            if (failedSaves > 0) {
                alert(`Atención: Fallo al guardar para ${failedSaves} día(s). Errores: \n${errors.join('\n')}`);
            }

            ocultarFormulario();
            
            // REFRESH UI: Recargar las tareas y el calendario para la fecha actual de visualización
            // Esto asegura que los cambios se reflejen en el mes/día que el usuario está viendo.
            await cargarTareasDiaADia(fechaCalendarioActual); 
            await appRenderizarCalendario(fechaCalendarioActual.getFullYear(), fechaCalendarioActual.getMonth(), setFechaCalendarioActual);

        } else if (tipoOp === 'corto-medio-plazo' || tipoOp === 'largo-plazo') {
            const titulo = document.getElementById('nuevoObjetivoTitulo').value.trim();
            if (!titulo) { alert('El título del objetivo no puede estar vacío.'); throw new Error("Título vacío"); }
            
            const payload = { 
                titulo, 
                fecha_estimada: document.getElementById('nuevoObjetivoFecha').value.trim(), 
                descripcion: document.getElementById('nuevoObjetivoDescripcion').value.trim(), 
                mode_id: tipoOp 
            };

            await fetchData('objetivos', 'POST', payload);
            
            alert('Objetivo añadido.');
            ocultarFormulario();
            await cargarObjetivos(tipoOp);
        }
    };
    
    mostrarFormulario(formHtmlCampos, onGuardarCallback, formTitle, guardarBtnText);

    if (contexto === 'dia-a-dia') {
        const initialDateForMiniCal = fechaPreseleccionada ? new Date(fechaPreseleccionada + "T00:00:00Z") : new Date();
        renderMiniCalendar(initialDateForMiniCal.getUTCFullYear(), initialDateForMiniCal.getUTCMonth(), true); 
        
        const tipoRecSelect = document.getElementById('tipoRecurrencia');
        const daysOfWeekSel = document.getElementById('days-of-week-selector');
        tipoRecSelect.onchange = () => {
            daysOfWeekSel.style.display = (tipoRecSelect.value === 'WEEKLY') ? 'block' : 'none';
        };
        
        document.getElementById('addMoreSubtasksBtn').onclick = () => {
            const container = document.getElementById('subtasks-inputs-container');
            const newEntry = document.createElement('div');
            newEntry.className = 'subtask-entry';
            newEntry.innerHTML = `<input type="text" class="subtask-text-input" placeholder="Siguiente subtarea..."><button type="button" class="quitar-subtarea-btn" title="Quitar">&times;</button>`;
            container.appendChild(newEntry);
            newEntry.querySelector('.quitar-subtarea-btn').onclick = () => newEntry.remove();
            newEntry.querySelector('.subtask-text-input').focus();
        };

        const emojiDisplay = document.getElementById('currentEmojiAnotacionDisplay');
        const emojiInput = document.getElementById('emojiAnotacionTareaInput');
        let selectedEmojisModalArray = []; 
        document.querySelectorAll('#emojiSelectorAddTask .emoji-option').forEach(opt => { 
            opt.onclick = () => {
                const emoji = opt.dataset.emoji;
                const index = selectedEmojisModalArray.indexOf(emoji);
                if (index > -1) {
                    selectedEmojisModalArray.splice(index, 1);
                    opt.classList.remove('selected');
                } else if (selectedEmojisModalArray.length < 3) {
                    selectedEmojis.push(emoji);
                    opt.classList.add('selected');
                }
                emojiDisplay.textContent = selectedEmojisModalArray.join(' ');
                emojiInput.value = selectedEmojisModalArray.join('');
            }; 
        });
    }
}

// Esta función estaba faltando en el archivo modals.js que me pegaste previamente
export function mostrarFormularioEditarTarea(tarea, fechaObjRecarga, tipoOriginal) {
    if (!tarea.activo) {
        alert("No se pueden editar elementos inactivos. Restaure primero el elemento si desea editarlo.");
        return;
    }
    let formHtmlCampos = `<label for="editTareaTexto">Texto:</label>
                          <input type="text" id="editTareaTexto" value="${tarea.texto || ''}">`;
    
    if (tipoOriginal === 'titulo') {
        const fechaInicioActual = tarea.fecha_inicio || new Date().toISOString().split('T')[0];
        const reglaRecurrenciaActual = tarea.regla_recurrencia || 'NONE';
        const diasSemanaActuales = reglaRecurrenciaActual.startsWith('WEEKLY:') ? reglaRecurrenciaActual.split(':')[1].split(',') : [];

        formHtmlCampos += `
            <fieldset>
                <legend>Programación</legend>
                <label for="editFechaInicioTarea">Fecha de inicio:</label>
                <input type="date" id="editFechaInicioTarea" value="${fechaInicioActual}">
                <label for="editTipoRecurrencia">Repetir:</label>
                <select id="editTipoRecurrencia">
                    <option value="NONE" ${reglaRecurrenciaActual === 'NONE' ? 'selected' : ''}>Solo una vez</option>
                    <option value="DAILY" ${reglaRecurrenciaActual === 'DAILY' ? 'selected' : ''}>Diariamente</option>
                    <option value="WEEKLY" ${reglaRecurrenciaActual.startsWith('WEEKLY:') ? 'selected' : ''}>Semanalmente</option>
                    <option value="MONTHLY_DAY" ${reglaRecurrenciaActual === 'MONTHLY_DAY' ? 'selected' : ''}>Mensualmente</option>
                </select>
                <div id="edit-days-of-week-selector" style="display:${reglaRecurrenciaActual.startsWith('WEEKLY:') ? 'block' : 'none'};">
                    <small>Selecciona días:</small><br>
                    ${['MON','TUE','WED','THU','FRI','SAT','SUN'].map(d => `<label><input type="checkbox" name="editRecurrenciaDia" value="${d}" ${diasSemanaActuales.includes(d) ? 'checked' : ''}> ${d.substring(0,3)}</label>`).join('')}
                </div>
            </fieldset>
        `;
    }
    
    const onGuardarEditarTarea = async () => { 
        const texto = document.getElementById('editTareaTexto').value.trim();
        if (!texto) { alert('El texto no puede estar vacío.'); throw new Error("Texto vacío"); }
        const payload = { _method: "PUT", id: tarea.id, texto, tipo: tarea.tipo }; // Added tipo

        if (tipoOriginal === 'titulo') {
            payload.fecha_inicio = document.getElementById('editFechaInicioTarea').value;
            let tipoRecurrenciaVal = document.getElementById('editTipoRecurrencia').value;
            if (tipoRecurrenciaVal === "WEEKLY") {
                const dias = Array.from(document.querySelectorAll('#edit-days-of-week-selector input:checked')).map(cb => cb.value);
                if (dias.length === 0) { alert("Debe seleccionar al menos un día para la repetición semanal."); throw new Error("Días no seleccionados"); }
                tipoRecurrenciaVal = `WEEKLY:${dias.join(',')}`;
            }
            payload.regla_recurrencia = tipoRecurrenciaVal;
        }

        await fetchData('tareas-dia-a-dia', 'POST', payload);
        alert('Tarea actualizada.'); 
        ocultarFormulario(); 
        await cargarTareasDiaADia(fechaObjRecarga); 
        await appRenderizarCalendario(fechaObjRecarga.getFullYear(), fechaObjRecarga.getMonth(), setFechaCalendarioActual);
    };

    mostrarFormulario(formHtmlCampos, onGuardarEditarTarea, `Editar ${tipoOriginal === 'titulo' ? 'Título' : 'Subtarea'}`);

    if (tipoOriginal === 'titulo') {
        const select = document.getElementById('editTipoRecurrencia');
        select.onchange = () => {
            document.getElementById('edit-days-of-week-selector').style.display = select.value === 'WEEKLY' ? 'block' : 'none';
        }
    }
}


export function mostrarFormularioAddSubTarea(parentId, fechaObjRecarga) {
    const formHtmlCampos = `<label for="nuevaSubTareaTexto">Texto de la Sub-Tarea:</label><input type="text" id="nuevaSubTareaTexto" placeholder="Ej. Comprar leche">`;
    
    const onGuardarSubTarea = async () => { 
        const texto = document.getElementById('nuevaSubTareaTexto').value.trim();
        if (!texto) { alert('El texto no puede estar vacío.'); throw new Error("Texto vacío"); }
        
        const payload = { texto, tipo: 'subtarea', parent_id: parentId, fecha_inicio: fechaObjRecarga.toISOString().split('T')[0], regla_recurrencia: 'NONE' };
        await fetchData('tareas-dia-a-dia', 'POST', payload);
        
        alert('Sub-tarea añadida.'); 
        ocultarFormulario();
        
        await cargarTareasDiaADia(fechaObjRecarga); 
        await appRenderizarCalendario(fechaObjRecarga.getFullYear(), fechaObjRecarga.getMonth(), setFechaCalendarioActual);
    };
    
    mostrarFormulario(formHtmlCampos, onGuardarSubTarea, "Añadir Sub-Tarea", "Guardar");
}

export function mostrarFormularioAddSubObjetivo(objetivoPrincipalId, mode_id_recarga) {
    const formHtmlCampos = `<label for="nuevoSubObjetivoModalTexto">Texto del Sub-Objetivo:</label><input type="text" id="nuevoSubObjetivoModalTexto" placeholder="Ej. Investigar opciones">`;
    
    const onGuardarSubObjetivo = async () => {
        const texto = document.getElementById('nuevoSubObjetivoModalTexto').value.trim();
        if (!texto) { alert('El texto no puede estar vacío.'); throw new Error("Texto vacío"); }
        
        await fetchData('sub_objetivos', 'POST', { objetivo_id: objetivoPrincipalId, texto });
        
        alert('Sub-objetivo añadido.');
        ocultarFormulario();
        await cargarObjetivos(mode_id_recarga);
    };

    mostrarFormulario(formHtmlCampos, onGuardarSubObjetivo, "Añadir Sub-Objetivo", "Guardar");
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
        if (!titulo) { alert('El título no puede estar vacío.'); throw new Error("Título vacío"); }
        
        const payload = { 
            _method: "PUT", 
            id: objetivo.id, 
            titulo, 
            fecha_estimada: document.getElementById('editObjetivoFecha').value.trim(), 
            descripcion: document.getElementById('editObjetivoDescripcion').value.trim() 
        };
        await fetchData('objetivos', 'POST', payload);
        alert('Objetivo actualizado.'); 
        ocultarFormulario();
        await cargarObjetivos(mode_id_recarga); 
    };
    mostrarFormulario(formHtmlCampos, onGuardarEditarObjetivo, "Editar Objetivo");
}

export function mostrarFormularioEditarSubObjetivo(subObjetivo, mode_id_recarga) {
    const formHtmlCampos = `
        <label for="editSubObjetivoTexto">Texto:</label>
        <input type="text" id="editSubObjetivoTexto" value="${subObjetivo.texto || ''}">
    `;
    
    const onGuardarEditarSubObjetivo = async () => {
        const texto = document.getElementById('editSubObjetivoTexto').trim();
        if (!texto) { alert('El texto no puede estar vacío.'); throw new Error("Texto vacío"); }
        
        const payload = { _method: "PUT", id: subObjetivo.id, texto }; 
        await fetchData('sub_objetivos', 'POST', payload);
        alert('Sub-objetivo actualizado.'); 
        ocultarFormulario();
        await cargarObjetivos(mode_id_recarga); 
    };
    mostrarFormulario(formHtmlCampos, onGuardarEditarSubObjetivo, "Editar Sub-Objetivo");
}