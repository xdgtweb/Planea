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
            <div class="form-group">
                <label for="nuevoTituloTexto" class="form-label">Texto del Título Diario:</label>
                <input type="text" id="nuevoTituloTexto" placeholder="Ej. Rutina de mañana" class="form-control">
            </div>

            <fieldset id="programacionFechas">
                <legend>Programación de Fechas</legend>
                <div id="mini-calendar-container-dynamic"></div>
                <div id="selected-dates-display">Fechas: ${uiMiniCalSelectedDates.length > 0 ? uiMiniCalSelectedDates.join(', ') : 'Ninguna'}</div>
                
                <div class="form-group">
                    <label for="tipoRecurrencia" class="form-label">Opciones de Repetición:</label>
                    <select id="tipoRecurrencia" class="form-control">
                        <option value="NONE">Solo una vez (en las fechas seleccionadas)</option>
                        <option value="DAILY">Diariamente (desde la primera fecha)</option>
                        <option value="WEEKLY">Semanalmente (en los días de la semana de las fechas)</option>
                        <option value="MONTHLY_DAY">Mensualmente (mismo día del mes)</option>
                    </select>
                </div>
                <div id="days-of-week-selector" style="display:none;">
                    <small>Selecciona días de la semana:</small><br>
                    ${['MON','TUE','WED','THU','FRI','SAT','SUN'].map(d=>`<label><input type="checkbox" name="recurrenciaDia" value="${d}"> ${d.charAt(0).toUpperCase() + d.slice(1).toLowerCase().substring(0,2)}</label>`).join('')}
                </div>
            </fieldset>

            <fieldset id="reminderSettings">
                <legend>Recordatorio por Correo (Opcional)</legend>
                <div class="form-group">
                    <input type="checkbox" id="activarRecordatorio">
                    <label for="activarRecordatorio" class="form-label">Enviar recordatorio por correo electrónico</label>
                </div>
                <div id="reminderOptions" style="display:none; margin-top: var(--space-s);">
                    <label for="tipoRecordatorio" class="form-label">¿Cuándo enviar?</label>
                    <select id="tipoRecordatorio" class="form-control">
                        <option value="hours_before">Unas horas antes (ej. 4h antes de la fecha de inicio)</option>
                        <option value="day_before">Un día antes</option>
                        <option value="week_before">Una semana antes</option>
                        <option value="month_before">Un mes antes</option>
                    </select>
                </div>
            </fieldset>

            <fieldset id="reminderTimesSettings" style="display:none;">
                <legend>Horas de Recordatorio Específicas</legend>
                <div class="form-group">
                    <label class="form-label">Selecciona hora(s) (Opcional):</label>
                    <div id="reminderHoursContainer" class="time-slot-container">
                        ${Array.from({length: 24 * 2}, (_, i) => {
                            const hour = Math.floor(i / 2);
                            const minute = (i % 2) * 30;
                            const time = `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
                            return `<span class="time-slot" data-time="${time}:00">${time}</span>`; // Formato HH:MM:SS para la BD
                        }).join('')}
                    </div>
                    <input type="hidden" id="selectedReminderHoursInput" value="">
                </div>
            </fieldset>

            <fieldset id="anotacionProgramada">
                <legend>Anotación para Fecha(s) de Inicio (Opcional)</legend>
                <div class="form-group">
                    <label for="emojiAnotacionTareaInput" class="form-label">Emoji(s) (máx. 3): <span id="currentEmojiAnotacionDisplay" class="current-emoji-display"></span></label>
                    ${emojiSelectorHTML}
                    <input type="hidden" id="emojiAnotacionTareaInput" value="">
                </div>
                <div class="form-group">
                    <label for="descripcionAnotacionTarea" class="form-label">Descripción del emoji:</label>
                    <input type="text" id="descripcionAnotacionTarea" placeholder="Ej. Importante!" class="form-control">
                </div>
            </fieldset>

            <div id="subtasks-inputs-container">
                <label class="form-label">Subtareas (al menos una obligatoria):</label>
                <div class="subtask-entry form-group">
                    <input type="text" class="subtask-text-input form-control" placeholder="Subtarea 1">
                </div>
            </div>
            <button type="button" id="addMoreSubtasksBtn" class="btn btn-secondary">+ Subtarea</button>
            `; 
    } else if (contexto === 'corto-medio-plazo' || contexto === 'largo-plazo') {
        formTitle = `Añadir Objetivo a ${contexto === 'corto-medio-plazo' ? 'Corto/Medio' : 'Largo'} Plazo`;
        guardarBtnText = "Guardar Objetivo";
        formHtmlCampos = `
            <div id="camposObjetivo">
                <div class="form-group">
                    <label for="nuevoObjetivoTitulo" class="form-label">Título del Objetivo:</label>
                    <input type="text" id="nuevoObjetivoTitulo" placeholder="Ej. Terminar curso online" class="form-control">
                </div>
                <div class="form-group">
                    <label for="nuevoObjetivoFecha" class="form-label">Fecha Estimada:</label>
                    <input type="text" id="nuevoObjetivoFecha" placeholder="Ej. Próximos 3 meses, junio 2025" class="form-control">
                </div>
                <div class="form-group">
                    <label for="nuevoObjetivoDescripcion" class="form-label">Descripción:</label>
                    <textarea id="nuevoObjetivoDescripcion" placeholder="Pasos, recursos..." class="form-control"></textarea>
                </div>
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

            // CAMPOS DE RECORDATORIO
            const sendReminder = document.getElementById('activarRecordatorio').checked;
            const reminderType = document.getElementById('tipoRecordatorio').value;
            // NUEVO: Obtener las horas seleccionadas del input oculto
            const selectedReminderHours = document.getElementById('selectedReminderHoursInput').value.split(',').filter(time => time);


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
                    // Añadir datos de recordatorio al payload
                    send_reminder: sendReminder,
                    reminder_type: reminderType,
                    selected_reminder_times: selectedReminderHours // NUEVO: Añadir las horas seleccionadas
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
        // Inicializar el estado de days-of-week-selector
        daysOfWeekSel.style.display = (tipoRecSelect.value === 'WEEKLY') ? 'block' : 'none';


        // Lógica para los campos de recordatorio
        const activarRecordatorioCheckbox = document.getElementById('activarRecordatorio');
        const reminderOptionsDiv = document.getElementById('reminderOptions');
        const reminderTimesSettingsFieldset = document.getElementById('reminderTimesSettings');
        const tipoRecordatorioSelect = document.getElementById('tipoRecordatorio');

        // Función para actualizar la visibilidad de las horas según el tipo de recordatorio
        const updateReminderTimesVisibility = () => {
            reminderTimesSettingsFieldset.style.display = (tipoRecordatorioSelect.value === 'hours_before' && activarRecordatorioCheckbox.checked) ? 'block' : 'none';
        };

        // Controlar la visibilidad de todas las opciones de recordatorio y el valor por defecto del select
        const toggleReminderOptions = () => {
            const isChecked = activarRecordatorioCheckbox.checked;
            reminderOptionsDiv.style.display = isChecked ? 'block' : 'none';
            
            if (isChecked) {
                // Si se activa, por defecto a "Unas horas antes" si no hay otra selección
                if (tipoRecordatorioSelect.value === '') { // o un valor por defecto que no sea 'none'
                     tipoRecordatorioSelect.value = 'hours_before'; 
                }
            } else {
                // Si se desactiva, no se necesita cambiar el valor del select.
                // send_reminder: false gestiona la lógica.
            }
            updateReminderTimesVisibility(); // Actualizar visibilidad de horas
        };

        activarRecordatorioCheckbox.onchange = toggleReminderOptions;
        tipoRecordatorioSelect.onchange = updateReminderTimesVisibility; // Escuchar cambios en el tipo
        
        toggleReminderOptions(); // Ejecutar al cargar para establecer el estado inicial


        // Lógica para la selección de horas interactivas
        const reminderHoursContainer = document.getElementById('reminderHoursContainer');
        const selectedReminderHoursInput = document.getElementById('selectedReminderHoursInput');
        let currentSelectedTimes = []; // Almacena las horas seleccionadas internamente

        reminderHoursContainer.addEventListener('click', (e) => {
            if (e.target.classList.contains('time-slot')) {
                const time = e.target.dataset.time;
                e.target.classList.toggle('selected');

                if (e.target.classList.contains('selected')) {
                    currentSelectedTimes.push(time);
                } else {
                    currentSelectedTimes = currentSelectedTimes.filter(t => t !== time);
                }
                currentSelectedTimes.sort(); // Mantener las horas ordenadas
                selectedReminderHoursInput.value = currentSelectedTimes.join(',');
            }
        });


        document.getElementById('addMoreSubtasksBtn').onclick = () => {
            const container = document.getElementById('subtasks-inputs-container');
            const newEntry = document.createElement('div');
            newEntry.className = 'subtask-entry form-group'; // Añadido form-group para estilos
            newEntry.innerHTML = `<input type="text" class="subtask-text-input form-control" placeholder="Siguiente subtarea..."><button type="button" class="quitar-subtarea-btn" title="Quitar">&times;</button>`;
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
                    selectedEmojisModalArray.push(emoji); // Corrigido: Usar selectedEmojisModalArray
                    opt.classList.add('selected');
                }
                emojiDisplay.textContent = selectedEmojisModalArray.join(' ');
                emojiInput.value = selectedEmojisModalArray.join('');
            }; 
        });
    }
}

export function mostrarFormularioEditarTarea(tarea, fechaObjRecarga, tipoOriginal) {
    if (!tarea.activo) {
        alert("No se pueden editar elementos inactivos. Restaure primero el elemento si desea editarlo.");
        return;
    }

    // Asegurarse de que tarea.reminder_times sea un array, incluso si viene null
    let currentReminderTimes = Array.isArray(tarea.reminder_times) ? tarea.reminder_times : [];
    let currentReminderType = tarea.reminder_type || 'hours_before'; // Cambiar default si 'none' no existe
    let isReminderActive = tarea.send_reminder || false;


    let formHtmlCampos = `<div class="form-group">
                            <label for="editTareaTexto" class="form-label">Texto:</label>
                            <input type="text" id="editTareaTexto" value="${tarea.texto || ''}" class="form-control">
                        </div>`;
    
    if (tipoOriginal === 'titulo') {
        const fechaInicioActual = tarea.fecha_inicio || new Date().toISOString().split('T')[0];
        const reglaRecurrenciaActual = tarea.regla_recurrencia || 'NONE';
        const diasSemanaActuales = reglaRecurrenciaActual.startsWith('WEEKLY:') ? reglaRecurrenciaActual.split(':')[1].split(',') : [];

        formHtmlCampos += `
            <fieldset>
                <legend>Programación</legend>
                <div class="form-group">
                    <label for="editFechaInicioTarea" class="form-label">Fecha de inicio:</label>
                    <input type="date" id="editFechaInicioTarea" value="${fechaInicioActual}" class="form-control">
                </div>
                <div class="form-group">
                    <label for="editTipoRecurrencia" class="form-label">Repetir:</label>
                    <select id="editTipoRecurrencia" class="form-control">
                        <option value="NONE" ${reglaRecurrenciaActual === 'NONE' ? 'selected' : ''}>Solo una vez</option>
                        <option value="DAILY" ${reglaRecurrenciaActual === 'DAILY' ? 'selected' : ''}>Diariamente</option>
                        <option value="WEEKLY" ${reglaRecurrenciaActual.startsWith('WEEKLY:') ? 'selected' : ''}>Semanalmente</option>
                        <option value="MONTHLY_DAY" ${reglaRecurrenciaActual === 'MONTHLY_DAY' ? 'selected' : ''}>Mensualmente</option>
                    </select>
                </div>
                <div id="edit-days-of-week-selector" style="display:${reglaRecurrenciaActual.startsWith('WEEKLY:') ? 'block' : 'none'};">
                    <small>Selecciona días:</small><br>
                    ${['MON','TUE','WED','THU','FRI','SAT','SUN'].map(d => `<label><input type="checkbox" name="editRecurrenciaDia" value="${d}" ${diasSemanaActuales.includes(d) ? 'checked' : ''}> ${d.substring(0,3)}</label>`).join('')}
                </div>
            </fieldset>

            <fieldset id="editReminderSettings">
                <legend>Recordatorio por Correo (Opcional)</legend>
                <div class="form-group">
                    <input type="checkbox" id="editActivarRecordatorio" ${isReminderActive ? 'checked' : ''}>
                    <label for="editActivarRecordatorio" class="form-label">Enviar recordatorio por correo electrónico</label>
                </div>
                <div id="editReminderOptions" style="display:${isReminderActive ? 'block' : 'none'}; margin-top: var(--space-s);">
                    <label for="editTipoRecordatorio" class="form-label">¿Cuándo enviar?</label>
                    <select id="editTipoRecordatorio" class="form-control">
                        <option value="hours_before" ${currentReminderType === 'hours_before' ? 'selected' : ''}>Unas horas antes (ej. 4h)</option>
                        <option value="day_before" ${currentReminderType === 'day_before' ? 'selected' : ''}>Un día antes</option>
                        <option value="week_before" ${currentReminderType === 'week_before' ? 'selected' : ''}>Una semana antes</option>
                        <option value="month_before" ${currentReminderType === 'month_before' ? 'selected' : ''}>Un mes antes</option>
                    </select>
                </div>
            </fieldset>

            <fieldset id="editReminderTimesSettings" style="display:none;">
                <legend>Horas de Recordatorio Específicas</legend>
                <div class="form-group">
                    <label class="form-label">Selecciona hora(s) (Opcional):</label>
                    <div id="editReminderHoursContainer" class="time-slot-container">
                        ${Array.from({length: 24 * 2}, (_, i) => {
                            const hour = Math.floor(i / 2);
                            const minute = (i % 2) * 30;
                            const time = `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
                            const isSelected = currentReminderTimes.includes(`${time}:00`) ? 'selected' : '';
                            return `<span class="time-slot ${isSelected}" data-time="${time}:00">${time}</span>`;
                        }).join('')}
                    </div>
                    <input type="hidden" id="selectedEditReminderHoursInput" value="${currentReminderTimes.join(',')}">
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

            // CAMPOS DE RECORDATORIO
            payload.send_reminder = document.getElementById('editActivarRecordatorio').checked;
            payload.reminder_type = document.getElementById('editTipoRecordatorio').value;
            // NUEVO: Obtener las horas seleccionadas para la edición del input oculto
            const selectedEditReminderHours = document.getElementById('selectedEditReminderHoursInput').value.split(',').filter(time => time);
            payload.selected_reminder_times = selectedEditReminderHours;
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
        // Inicializar el estado de edit-days-of-week-selector
        document.getElementById('edit-days-of-week-selector').style.display = select.value === 'WEEKLY' ? 'block' : 'none';

        // Lógica para los campos de recordatorio en edición
        const editActivarRecordatorioCheckbox = document.getElementById('editActivarRecordatorio');
        const editReminderOptionsDiv = document.getElementById('editReminderOptions');
        const editReminderTimesSettingsFieldset = document.getElementById('editReminderTimesSettings');
        const editTipoRecordatorioSelect = document.getElementById('editTipoRecordatorio');

        // Función para actualizar la visibilidad de las horas según el tipo de recordatorio
        const updateEditReminderTimesVisibility = () => {
            editReminderTimesSettingsFieldset.style.display = (editTipoRecordatorioSelect.value === 'hours_before' && editActivarRecordatorioCheckbox.checked) ? 'block' : 'none';
        };

        // Controlar la visibilidad de todas las opciones de recordatorio en edición y el valor por defecto del select
        const toggleEditReminderOptions = () => {
            const isChecked = editActivarRecordatorioCheckbox.checked;
            editReminderOptionsDiv.style.display = isChecked ? 'block' : 'none';
            
            if (isChecked) {
                // Si se activa, por defecto a "Unas horas antes" si no hay otra selección
                if (editTipoRecordatorioSelect.value === '') { // o un valor por defecto que no sea 'none'
                    editTipoRecordatorioSelect.value = 'hours_before'; 
                }
            } else {
                // Si se desactiva, no se necesita cambiar el valor del select.
            }
            updateEditReminderTimesVisibility(); // Actualizar visibilidad de horas
        };

        editActivarRecordatorioCheckbox.onchange = toggleEditReminderOptions;
        editTipoRecordatorioSelect.onchange = updateEditReminderTimesVisibility; // Escuchar cambios en el tipo

        toggleEditReminderOptions(); // Ejecutar al cargar para establecer el estado inicial

        // Lógica para la selección de horas interactivas en edición
        const editReminderHoursContainer = document.getElementById('editReminderHoursContainer');
        const selectedEditReminderHoursInput = document.getElementById('selectedEditReminderHoursInput');
        // Asegúrate de que currentSelectedEditTimes se inicializa correctamente con las horas existentes
        let currentSelectedEditTimes = currentReminderTimes.slice(); // Usar slice para crear una copia

        editReminderHoursContainer.addEventListener('click', (e) => {
            if (e.target.classList.contains('time-slot')) {
                const time = e.target.dataset.time;
                e.target.classList.toggle('selected');

                if (e.target.classList.contains('selected')) {
                    currentSelectedEditTimes.push(time);
                } else {
                    currentSelectedEditTimes = currentSelectedEditTimes.filter(t => t !== time);
                }
                currentSelectedEditTimes.sort(); // Mantener las horas ordenadas
                selectedEditReminderHoursInput.value = currentSelectedEditTimes.join(',');
            }
        });
    }
}


export function mostrarFormularioAddSubTarea(parentId, fechaObjRecarga) {
    const formHtmlCampos = `<div class="form-group">
                                <label for="nuevaSubTareaTexto" class="form-label">Texto de la Sub-Tarea:</label>
                                <input type="text" id="nuevaSubTareaTexto" placeholder="Ej. Comprar leche" class="form-control">
                            </div>`;
    
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
    const formHtmlCampos = `<div class="form-group">
                                <label for="nuevoSubObjetivoModalTexto" class="form-label">Texto del Sub-Objetivo:</label>
                                <input type="text" id="nuevoSubObjetivoModalTexto" placeholder="Ej. Investigar opciones" class="form-control">
                            </div>`;
    
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
        <div class="form-group">
            <label for="editObjetivoTitulo" class="form-label">Título:</label>
            <input type="text" id="editObjetivoTitulo" value="${objetivo.titulo || ''}" class="form-control">
        </div>
        <div class="form-group">
            <label for="editObjetivoFecha" class="form-label">Fecha Estimada:</label>
            <input type="text" id="editObjetivoFecha" value="${objetivo.fecha_estimada || ''}" class="form-control">
        </div>
        <div class="form-group">
            <label for="editObjetivoDescripcion" class="form-label">Descripción:</label>
            <textarea id="editObjetivoDescripcion" class="form-control">${objetivo.descripcion || ''}</textarea>
        </div>
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
        <div class="form-group">
            <label for="editSubObjetivoTexto" class="form-label">Texto:</label>
            <input type="text" id="editSubObjetivoTexto" value="${subObjetivo.texto || ''}" class="form-control">
        </div>
    `;
    
    const onGuardarEditarSubObjetivo = async () => {
        const texto = document.getElementById('editSubObjetivoTexto').value.trim(); // Corrigido: .value.trim()
        if (!texto) { alert('El texto no puede estar vacío.'); throw new Error("Texto vacío"); }
        
        const payload = { _method: "PUT", id: subObjetivo.id, texto }; 
        await fetchData('sub_objetivos', 'POST', payload);
        alert('Sub-objetivo actualizado.'); 
        ocultarFormulario();
        await cargarObjetivos(mode_id_recarga); 
    };
    mostrarFormulario(formHtmlCampos, onGuardarEditarSubObjetivo, "Editar Sub-Objetivo");
}