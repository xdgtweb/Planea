// script_modules/views.js
import { EMOJIS_PREDEFINIDOS } from './config.js';
import { fetchData, obtenerClaseDeEstado, lanzarAnimacionCelebracion, obtenerValorPrioridad } from './utils.js';
import { mostrarFormulario, ocultarFormulario, createActionToggleButton } from './ui.js';
import { 
    abrirModalParaNuevoElemento, 
    mostrarFormularioEditarTarea, 
    mostrarFormularioAddSubTarea,
    mostrarFormularioEditarObjetivo,
    mostrarFormularioAddSubObjetivo,
    mostrarFormularioEditarSubObjetivo 
} from './modals.js'; 

let modoContenido = null;
export let appFechaCalendarioActual = new Date(); 
export let appSetFechaCalendarioActual = (newDate) => { console.warn("setFechaCalendarioActual no inicializada en views.js"); appFechaCalendarioActual = newDate; }; 
export let modosDisponibles = []; 
export let myConfettiInstance = null; 
export let currentUser = {}; // Importar el objeto currentUser desde app.js para acceder a sus propiedades

export function initViewsModule(appState) {
    modoContenido = appState.modoContenido;
    appFechaCalendarioActual = appState.appFechaCalendarioActual;
    appSetFechaCalendarioActual = appState.appSetFechaCalendarioActual;
    modosDisponibles = appState.modosDisponibles;
    myConfettiInstance = appState.myConfettiInstance;
    currentUser = appState.currentUser; // Asignar el objeto currentUser
}

export async function renderizarModoDiaADia() {
    if (!modoContenido) { 
        console.error("renderizarModoDiaADia: Contenedor #modo-contenido NO está disponible."); 
        return; 
    }
    
    // Se elimina el contenedor de tareas inactivas antiguo, ahora se gestionará con un modal.
    modoContenido.innerHTML = `
        <h2>Día a Día</h2>
        <p class="descripcion-pagina">Clic derecho en un día del calendario para añadir tareas programadas. Clic izquierdo para ver detalles y anotaciones del día.</p>
        <div id="calendario-contenedor"></div>
        <div id="add-daily-item-container" style="text-align: center; margin: 15px 0;"></div>
        <div id="tareasDiariasLista"></div>
        <div id="tareasDetalleDia" class="form-overlay hidden" role="dialog" aria-modal="true" aria-hidden="true" inert>
            <div class="form-modal"></div>
        </div>`;
        
    const addDailyItemContainer = document.getElementById('add-daily-item-container');
    
    // Botón para añadir nuevas tareas/títulos
    const btnAddDaily = document.createElement('button');
    btnAddDaily.id = 'add-daily-item-btn';
    btnAddDaily.className = 'add-elemento-diario-btn';
    btnAddDaily.textContent = '+ Añadir Título y Subtareas (Hoy)';
    btnAddDaily.onclick = () => {
        if (!currentUser.is_admin && !currentUser.email_verified) {
            alert("Su correo electrónico no está verificado. Por favor, verifique su correo para crear o modificar elementos.");
            return;
        }
        abrirModalParaNuevoElemento('dia-a-dia', new Date().toISOString().split('T')[0]); 
    }; 
    addDailyItemContainer.appendChild(btnAddDaily);
    
    // Nuevo botón para mostrar el modal de tareas archivadas
    const btnShowArchived = document.createElement('button');
    btnShowArchived.id = 'show-archived-tasks-btn';
    btnShowArchived.className = 'add-elemento-diario-btn'; // Reutilizamos el estilo del botón añadir
    btnShowArchived.textContent = 'Ver Tareas Archivadas';
    btnShowArchived.style.marginLeft = '10px'; // Añade un pequeño espacio
    btnShowArchived.onclick = mostrarModalTareasInactivas; // Llama a la nueva función
    addDailyItemContainer.appendChild(btnShowArchived);

    // Se eliminan los event listeners y referencias a los elementos antiguos de tareas inactivas
    // const toggleInactivasBtn = document.getElementById('toggle-tareas-inactivas');
    // const listaInactivasDiv = document.getElementById('lista-tareas-inactivas');
    // ...

    await renderizarCalendario(appFechaCalendarioActual.getFullYear(), appFechaCalendarioActual.getMonth(), appSetFechaCalendarioActual); 
    await cargarTareasDiaADia(new Date(appFechaCalendarioActual.getTime())); 
}

export async function mostrarModalTareasInactivas() {
    const title = "Tareas Archivadas/Inactivas";
    // El botón de "Guardar" del modal genérico se usará como botón de "Cerrar"
    const saveButtonText = "Cerrar"; 

    // Contenido inicial del modal mientras se cargan las tareas
    let formHtmlContent = `<div id="form-fields"><p>Cargando tareas archivadas...</p></div>`;

    // Muestra el modal con el mensaje de carga
    mostrarFormulario(formHtmlContent, ocultarFormulario, title, saveButtonText); 

    // Personaliza los botones del modal para este caso
    const formSaveBtn = document.getElementById('form-save-btn');
    const formCancelBtn = document.getElementById('form-cancel-btn');

    if (formSaveBtn) {
        formSaveBtn.textContent = 'Cerrar';
        formSaveBtn.classList.remove('save-btn'); // Quita el estilo de botón de guardar (verde)
        formSaveBtn.classList.add('cancel-btn');  // Añade el estilo de botón de cancelar (gris)
        formSaveBtn.onclick = ocultarFormulario;
    }
    if (formCancelBtn) {
        formCancelBtn.style.display = 'none'; // Oculta el botón de cancelar duplicado
    }

    // Obtiene la fecha actual para la API
    const fechaParaAPI = appFechaCalendarioActual.toISOString().split('T')[0];
    try {
        // Obtenemos todas las tareas para el día y luego filtramos las inactivas.
        // Esto asume que la API /tareas-dia-a-dia?fecha=... devuelve todas las tareas.
        const todasLasTareas = await fetchData(`/tareas-dia-a-dia?fecha=${fechaParaAPI}`);
        const tareasInactivas = todasLasTareas.filter(t => !t.activo);

        const formFieldsDiv = document.getElementById('form-fields');
        if (!formFieldsDiv) {
            console.error("No se encontró el contenedor 'form-fields' dentro del modal.");
            return;
        }

        if (tareasInactivas.length === 0) {
            formFieldsDiv.innerHTML = `<p class="mensaje-vacio">No hay tareas archivadas para este día.</p>`;
        } else {
            formFieldsDiv.innerHTML = ''; // Limpia el mensaje de carga
            const ulInactivas = document.createElement('ul');
            ulInactivas.className = 'tareas-listado';
            tareasInactivas.forEach(item => {
                // Reutilizamos las funciones de renderizado de tareas
                const elementoTarea = item.tipo === 'titulo'
                    ? crearElementoTituloTareaDiaria(item, appFechaCalendarioActual)
                    : (item.parent_id === null ? crearElementoSubTareaDiaria(item, appFechaCalendarioActual, true) : null);

                if (elementoTarea) {
                    ulInactivas.appendChild(elementoTarea);
                    // Si es un título con subtareas, renderizamos también las subtareas inactivas
                    if (item.tipo === 'titulo' && item.subtareas && item.subtareas.length > 0) {
                        const ulSubInactivas = document.createElement('ul');
                        ulSubInactivas.className = 'subtareas-listado';
                        item.subtareas.forEach(subtarea => {
                            ulSubInactivas.appendChild(crearElementoSubTareaDiaria(subtarea, appFechaCalendarioActual, false));
                        });
                        ulInactivas.appendChild(ulSubInactivas);
                    }
                }
            });
            formFieldsDiv.appendChild(ulInactivas);
        }

    } catch (error) {
        console.error("Error al cargar tareas archivadas:", error);
        const formFieldsDiv = document.getElementById('form-fields');
        if (formFieldsDiv) {
            formFieldsDiv.innerHTML = `<p class="error-mensaje">Error al cargar tareas archivadas: ${error.message}</p>`;
        }
    }
}


export async function cargarTareasDiaADia(fechaObj) {
    const tareasDiariasListaDiv = document.getElementById('tareasDiariasLista');
    // Se elimina la referencia a listaTareasInactivasDiv ya que no se gestionará en esta función.
    // const listaTareasInactivasDiv = document.getElementById('lista-tareas-inactivas');
    
    if (!tareasDiariasListaDiv) return;

    tareasDiariasListaDiv.innerHTML = '<p>Cargando tareas activas...</p>';
    // Se elimina la lógica de carga para tareas inactivas del modo principal.
    // if (listaTareasInactivasDiv.classList.contains('expandido')) {
    //     listaTareasInactivasDiv.innerHTML = '<p>Cargando tareas inactivas...</p>';
    // }

    const fechaParaAPI = fechaObj.toISOString().split('T')[0];
    try {
        const todasLasTareas = await fetchData(`/tareas-dia-a-dia?fecha=${fechaParaAPI}`);
        const tareasActivas = todasLasTareas.filter(t => t.activo);
        // Se elimina el filtro para tareas inactivas ya que se cargarán en el modal.
        // const tareasInactivas = todasLasTareas.filter(t => !t.activo); 

        tareasDiariasListaDiv.innerHTML = '';
        if (tareasActivas.length > 0) {
            const ulActivas = document.createElement('ul');
            ulActivas.className = 'tareas-listado';
            tareasActivas.forEach(item => {
                const elementoTarea = item.tipo === 'titulo' 
                    ? crearElementoTituloTareaDiaria(item, fechaObj) 
                    : (item.parent_id === null ? crearElementoSubTareaDiaria(item, fechaObj, true) : null);
                
                if (elementoTarea) {
                    ulActivas.appendChild(elementoTarea);
                    if (item.tipo === 'titulo' && item.subtareas && item.subtareas.length > 0) {
                        const ulSub = document.createElement('ul');
                        ulSub.className = 'subtareas-listado';
                        item.subtareas.forEach(subtarea => { 
                            if(subtarea.activo) ulSub.appendChild(crearElementoSubTareaDiaria(subtarea, fechaObj, false));
                        });
                        if (ulSub.hasChildNodes()) ulActivas.appendChild(ulSub);
                    }
                }
            });
            tareasDiariasListaDiv.appendChild(ulActivas);
        } else {
            tareasDiariasListaDiv.innerHTML = '<p class="mensaje-vacio">No hay tareas activas para este día.</p>';
        }
        // Se elimina la llamada a renderizarTareasInactivas, ahora lo hace el modal.
        // renderizarTareasInactivas(tareasInactivas, fechaObj); 
    } catch (error) { 
        console.error("Error en cargarTareasDiaADia:", error); 
        tareasDiariasListaDiv.innerHTML = `<p class="error-mensaje">Error al cargar tareas: ${error.message}</p>`; 
    }
}

// La función renderizarTareasInactivas ya no es necesaria como exportable para la vista principal,
// su lógica se ha integrado directamente en mostrarModalTareasInactivas.
// Podría ser una función auxiliar interna si se necesitara reutilizar el HTML de una lista de tareas inactivas.
/*
export function renderizarTareasInactivas(tareasInactivas, fechaObj) {
    const listaTareasInactivasDiv = document.getElementById('lista-tareas-inactivas');
    const toggleBtn = document.getElementById('toggle-tareas-inactivas');

    if (!listaTareasInactivasDiv || !toggleBtn) return;
    
    const numInactivas = tareasInactivas ? tareasInactivas.length : 0; 
    toggleBtn.textContent = `Mostrar Tareas Archivadas/Inactivas (${numInactivas})`;

    
    if (numInactivas === 0) {
        listaTareasInactivasDiv.innerHTML = '<p class="mensaje-vacio">No hay tareas archivadas.</p>';
    } else {
        listaTareasInactivasDiv.innerHTML = ''; 
        const ulInactivas = document.createElement('ul');
        ulInactivas.className = 'tareas-listado';
        tareasInactivas.forEach(item => {
            const elementoTarea = item.tipo === 'titulo' 
                ? crearElementoTituloTareaDiaria(item, fechaObj)
                : (item.parent_id === null ? crearElementoSubTareaDiaria(item, fechaObj, true) : null);
            
            if (elementoTarea) {
                ulInactivas.appendChild(elementoTarea);
                if (item.tipo === 'titulo' && item.subtareas && item.subtareas.length > 0) {
                    const ulSubInactivas = document.createElement('ul');
                    ulSubInactivas.className = 'subtareas-listado';
                    item.subtareas.forEach(subtarea => {
                        ulSubInactivas.appendChild(crearElementoSubTareaDiaria(subtarea, fechaObj, false));
                    });
                    ulInactivas.appendChild(ulSubInactivas);
                }
            }
        });
        listaTareasInactivasDiv.appendChild(ulInactivas);
    }
}
*/

export function crearElementoTituloTareaDiaria(tarea, fechaObj) {
    const liTitulo = document.createElement('li');
    liTitulo.className = 'tarea-titulo-item';
    if (!tarea.activo) liTitulo.classList.add('tarea-inactiva');

    const textoSpan = document.createElement('span');
    textoSpan.className = 'texto-titulo';
    textoSpan.textContent = tarea.texto;
    liTitulo.appendChild(textoSpan);

    // Nuevo: Indicador de tarea compartida
    if (tarea.is_shared) {
        const sharedIcon = document.createElement('i');
        sharedIcon.className = 'fas fa-share-alt shared-icon';
        sharedIcon.title = `Compartido por: ${tarea.shared_owner_info?.username || tarea.shared_owner_info?.email || 'Desconocido'}`;
        textoSpan.appendChild(sharedIcon);
    }

    const toggleBtn = createActionToggleButton(tarea, fechaObj, 'dia-a-dia'); 
    liTitulo.appendChild(toggleBtn);
    return liTitulo;
}

export function crearElementoSubTareaDiaria(tarea, fechaObj, esSuelta = false) {
    const li = document.createElement('li');
    li.className = esSuelta ? 'tarea-item tarea-suelta' : 'tarea-item';
    if (!tarea.activo) li.classList.add('tarea-inactiva');

    const checkboxId = `tarea-${tarea.id}-${fechaObj.toISOString().split('T')[0].replace(/-/g, '')}`;
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.id = checkboxId;
    checkbox.dataset.idTareaDb = tarea.id;
    checkbox.checked = tarea.completado;
    
    const label = document.createElement('label');
    label.htmlFor = checkboxId;
    label.textContent = tarea.texto;
    
    if (tarea.completado || !tarea.activo) {
        label.classList.add('sub-objetivo-completado');
    }

    li.appendChild(checkbox);
    li.appendChild(label);

    // Nuevo: Indicador de tarea compartida para subtareas
    if (tarea.is_shared) {
        const sharedIcon = document.createElement('i');
        sharedIcon.className = 'fas fa-share-alt shared-icon';
        sharedIcon.title = `Compartido por: ${tarea.shared_owner_info?.username || tarea.shared_owner_info?.email || 'Desconocido'}`;
        label.appendChild(sharedIcon); // Añadir el icono dentro del label o al final de la línea
    }


    const toggleBtn = createActionToggleButton(tarea, fechaObj, 'dia-a-dia');
    li.appendChild(toggleBtn);
    
    const hoy = new Date(); 
    hoy.setHours(0,0,0,0);
    const fechaDeLaTareaVisualizada = new Date(fechaObj); 
    fechaDeLaTareaVisualizada.setHours(0,0,0,0);
    const esPasado = fechaDeLaTareaVisualizada.getTime() < hoy.getTime();

    // Nuevo: Deshabilitar checkbox si el email no está verificado (a menos que sea admin)
    // El checkbox se deshabilita si es pasado, inactivo O si el usuario no es admin y su email no está verificado.
    checkbox.disabled = esPasado || !tarea.activo || (!currentUser.is_admin && !currentUser.email_verified);
    
    if (checkbox.disabled) { 
        checkbox.style.opacity = '0.6'; 
        checkbox.style.cursor = 'not-allowed'; 
    }

    checkbox.onchange = async (e) => {
        const esMarcado = e.target.checked; 
        const idTareaDB = e.target.dataset.idTareaDb;
        if (esMarcado && myConfettiInstance) lanzarAnimacionCelebracion(myConfettiInstance, e); 

        const payload = { 
            _method: "PUT", 
            id: parseInt(idTareaDB), 
            completado: esMarcado, 
            fecha_actualizacion: fechaObj.toISOString().split('T')[0],
            texto: tarea.texto || '', 
            tipo: tarea.tipo    
        };
        try {
            const response = await fetchData(`/tareas-dia-a-dia`, 'POST', payload); 
            if (response.success) {
                if (tarea.activo) label.classList.toggle('sub-objetivo-completado', esMarcado);
                await renderizarCalendario(appFechaCalendarioActual.getFullYear(), appFechaCalendarioActual.getMonth(), appSetFechaCalendarioActual); 
            } else {
                e.target.checked = !esMarcado;
                if (tarea.activo) label.classList.toggle('sub-objetivo-completado', !esMarcado);
                alert(`Error al actualizar tarea: ${response.message || 'Error desconocido'}`);
            }
        } catch (error) {
            e.target.checked = !esMarcado;
            if (tarea.activo) label.classList.toggle('sub-objetivo-completado', !esMarcado);
            alert(`Error al actualizar tarea: ${error.message}`);
        }
    };
    return li;
}

export async function restaurarTarea(tareaARestaurar, fechaObjRecarga) {
    // Nuevo: Comprobación de verificación de email antes de restaurar
    if (!currentUser.is_admin && !currentUser.email_verified) {
        alert("Su correo electrónico no está verificado. Por favor, verifique su correo para restaurar elementos.");
        return;
    }

    // Nuevo: Validación para no restaurar tareas de días pasados
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const taskDateObj = new Date(tareaARestaurar.fecha_inicio + 'T00:00:00Z'); // Usar fecha_inicio de la tarea
    
    if (taskDateObj.getTime() < today.getTime()) {
        alert("No se pueden restaurar tareas de días anteriores al actual.");
        return;
    }

    const payload = { 
        _method: "PUT", 
        id: parseInt(tareaARestaurar.id), 
        activo: true, 
        tipo: tareaARestaurar.tipo,
        texto: tareaARestaurar.texto || '' ,
        fecha_inicio: tareaARestaurar.fecha_inicio // Incluir fecha_inicio para la validación del backend
    };
    if (tareaARestaurar.tipo === 'subtarea') {
        alert("Para restaurar una subtarea, primero restaura su título principal desde la lista de archivados.");
        return;
    }
    if (!confirm("¿Quieres restaurar este elemento a la lista de tareas activas?")) return;
    try {
        await fetchData('/tareas-dia-a-dia', 'POST', payload); 
        alert('Elemento restaurado a activo.');
        await cargarTareasDiaADia(new Date(appFechaCalendarioActual.getTime() || fechaObjRecarga.getTime())); 
        await renderizarCalendario(appFechaCalendarioActual.getFullYear(), appFechaCalendarioActual.getMonth(), appSetFechaCalendarioActual);
        
        // Nuevo: Si el modal de tareas archivadas está abierto, recargar su contenido
        const formContainer = document.getElementById('form-container'); // Este es el overlay genérico del modal
        const formTitle = document.getElementById('form-title'); // Título del modal
        if (formContainer && formContainer.getAttribute('aria-hidden') === 'false' && formTitle.textContent === 'Tareas Archivadas/Inactivas') {
            await mostrarModalTareasInactivas(); // Re-renderizar el contenido del modal
        }

    } catch (error) { alert(`Error al restaurar tarea: ${error.message}`); }
}

export async function eliminarTareaDiaria(tarea, fechaObjRecarga, esActivaActual) {
    console.log("-> eliminarTareaDiaria llamada para ID:", tarea.id); 
    // Nuevo: Comprobación de verificación de email antes de eliminar
    if (!currentUser.is_admin && !currentUser.email_verified) {
        alert("Su correo electrónico no está verificado. Por favor, verifique su correo para eliminar elementos.");
        return;
    }
    
    // Nuevo: Validación para no eliminar tareas de días pasados
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const taskDateObj = new Date(tarea.fecha_inicio + 'T00:00:00Z'); // Usar fecha_inicio de la tarea
    
    if (taskDateObj.getTime() < today.getTime()) {
        alert("No se pueden eliminar o archivar tareas de días anteriores al actual.");
        return;
    }

    const confirmMessage = esActivaActual 
        ? `¿Marcar este elemento como inactivo? ${tarea.tipo === 'titulo' ? 'Sus subtareas también se marcarán como inactivas.' : ''}`
        : "Esto eliminará la tarea PERMANENTEMENTE. ¿Continuar?";
    
    console.log("-> Mostrando diálogo de confirmación..."); 
    if (!confirm(confirmMessage)) {
        console.log("-> Usuario canceló la eliminación."); 
        return;
    }
    console.log("-> Usuario confirmó la eliminación. Preparando payload..."); 

    const operationMethod = esActivaActual ? "DELETE" : "HARD_DELETE";
    const payload = { 
        _method: operationMethod, 
        id: parseInt(tarea.id), 
        tipo: tarea.tipo || '', 
        texto: tarea.texto || '',
        fecha_inicio: tarea.fecha_inicio // Incluir fecha_inicio para la validación del backend
    };
    console.log("-> Payload listo:", payload); 
    try {
        await fetchData('/tareas-dia-a-dia', 'POST', payload); 
        console.log("-> fetchData para eliminación completado."); 
        alert(esActivaActual ? 'Elemento marcado como inactivo.' : 'Elemento eliminado permanentemente.');
        
        await cargarTareasDiaADia(new Date(appFechaCalendarioActual.getTime() || fechaObjRecarga.getTime()));
        await renderizarCalendario(appFechaCalendarioActual.getFullYear(), appFechaCalendarioActual.getMonth(), appSetFechaCalendarioActual);

        // Nuevo: Si el modal de tareas archivadas está abierto, recargar su contenido
        const formContainer = document.getElementById('form-container'); // Este es el overlay genérico del modal
        const formTitle = document.getElementById('form-title'); // Título del modal
        if (formContainer && formContainer.getAttribute('aria-hidden') === 'false' && formTitle.textContent === 'Tareas Archivadas/Inactivas') {
            await mostrarModalTareasInactivas(); // Re-renderizar el contenido del modal
        }

    } catch (error) { 
        console.error("-> Error durante fetchData para eliminación:", error); 
        alert(`Error al procesar la eliminación: ${error.message}`); 
    }
}

export async function renderizarModoObjetivos(mode_id) {
    if (!modoContenido) return;
    const modoData = modosDisponibles.find(m => m.id === mode_id);
    const modoNombreDisplay = modoData ? modoData.nombre : 'Objetivos';
    modoContenido.innerHTML = `
        <h2>${modoNombreDisplay}</h2>
        <p class="descripcion-pagina">Nuestros objetivos de ${mode_id === 'corto-medio-plazo' ? 'corto y medio plazo' : 'largo plazo'}.</p>
        <div id="add-objetivo-container-${mode_id}" style="text-align: center; margin: 15px 0;"></div>
        <div id="lineaDeTiempo-${mode_id}" class="objetivos-listado"></div>`;
    const addObjetivoContainer = document.getElementById(`add-objetivo-container-${mode_id}`);
    const btnAddObjetivo = document.createElement('button');
    btnAddObjetivo.className = 'add-elemento-diario-btn';
    btnAddObjetivo.textContent = '+ Añadir Nuevo Objetivo';
    btnAddObjetivo.onclick = () => {
        // Nuevo: Comprobar si el email está verificado antes de añadir objetivos
        if (!currentUser.is_admin && !currentUser.email_verified) {
            alert("Su correo electrónico no está verificado. Por favor, verifique su correo para crear o modificar elementos.");
            return;
        }
        abrirModalParaNuevoElemento(mode_id); 
    }; 
    addObjetivoContainer.appendChild(btnAddObjetivo);
    await cargarObjetivos(mode_id);
}

export async function cargarObjetivos(mode_id) {
    const contenedorObjetivos = document.getElementById(`lineaDeTiempo-${mode_id}`);
    if (!contenedorObjetivos) return;
    contenedorObjetivos.innerHTML = '<p>Cargando objetivos...</p>';
    try {
        let objetivosData = await fetchData(`/objetivos?mode=${mode_id}`); 
        objetivosData.sort((a, b) => obtenerValorPrioridad(a.fecha_estimada) - obtenerValorPrioridad(b.fecha_estimada) || a.titulo.localeCompare(b.titulo));

        if (objetivosData.length === 0) {
            contenedorObjetivos.innerHTML = `<p class="mensaje-vacio">No hay objetivos. Usa el botón '+' para añadir nuevos.</p>`;
            return;
        }
        contenedorObjetivos.innerHTML = '';
        objetivosData.forEach(objetivo => {
            objetivo.activo = true; 
            const divObjetivo = document.createElement('div');
            divObjetivo.classList.add('seccion-objetivo');
            const totalSubObjetivos = objetivo.sub_objetivos?.length || 0;
            const subObjetivosCompletados = objetivo.sub_objetivos?.filter(sub => sub.completado).length || 0;
            const porcentajeCompletado = totalSubObjetivos > 0 ? Math.round((subObjetivosCompletados / totalSubObjetivos) * 100) : 0; 
            divObjetivo.classList.add(obtenerClaseDeEstado(porcentajeCompletado)); 

            const cabeceraObjetivo = document.createElement('div');
            cabeceraObjetivo.classList.add('cabecera-objetivo');
            cabeceraObjetivo.innerHTML = `
                <div class="cabecera-objetivo-izquierda">
                    <span class="titulo-objetivo">${objetivo.titulo}</span>
                    <span class="porcentaje-progreso">(${porcentajeCompletado}%)</span>
                </div>
                <span class="fecha-estimada">${objetivo.fecha_estimada || ''}</span>`;
            cabeceraObjetivo.onclick = () => {
                cabeceraObjetivo.parentElement.querySelector('.contenido-objetivo')?.classList.toggle('expandido');
                cabeceraObjetivo.classList.toggle('expandido');
            };
            divObjetivo.appendChild(cabeceraObjetivo);

            const barraProgreso = document.createElement('div');
            barraProgreso.classList.add('barra-progreso');
            barraProgreso.style.width = `${porcentajeCompletado}%`;
            divObjetivo.appendChild(barraProgreso);

            const contenidoObjetivo = document.createElement('div');
            contenidoObjetivo.classList.add('contenido-objetivo');
            contenidoObjetivo.innerHTML = `<p>${objetivo.descripcion || ''}</p>`;
            let ulSubObjetivos = document.createElement('ul');
            if (objetivo.sub_objetivos?.length > 0) {
                objetivo.sub_objetivos.sort((a,b) => (a.id || 0) - (b.id || 0)); 
                objetivo.sub_objetivos.forEach(sub => {
                    sub.activo = true; 
                    const liSub = document.createElement('li');
                    liSub.className = sub.completado ? 'sub-objetivo-completado' : '';
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox'; 
                    checkbox.id = `sub-${objetivo.id}-${sub.id}`; 
                    checkbox.dataset.idSubDb = sub.id; 
                    checkbox.checked = sub.completado;
                    const label = document.createElement('label');
                    label.htmlFor = checkbox.id; 
                    label.textContent = sub.texto;
                    liSub.append(checkbox, label, createActionToggleButton(sub, null, mode_id));

                    // Nuevo: Deshabilitar checkbox si el email no está verificado (a menos que sea admin)
                    checkbox.disabled = (!currentUser.is_admin && !currentUser.email_verified);
                    if (checkbox.disabled) { 
                        checkbox.style.opacity = '0.6'; 
                        checkbox.style.cursor = 'not-allowed'; 
                    }

                    checkbox.onchange = async (e) => {
                        const esMarcado = e.target.checked; 
                        if(esMarcado && myConfettiInstance) lanzarAnimacionCelebracion(myConfettiInstance, e);
                        try {
                            await fetchData(`/sub-objetivos-estado`, 'POST', { idSubObjetivoDB: parseInt(sub.id), completado: esMarcado }); // Modificado para pasar como POST data
                            await cargarObjetivos(mode_id);
                        }
                        catch (error) {
                            alert(`Error al actualizar: ${error.message}`);
                            e.target.checked = !esMarcado;
                        }
                    };
                    ulSubObjetivos.appendChild(liSub);
                });
            }
            contenidoObjetivo.appendChild(ulSubObjetivos); 
            divObjetivo.appendChild(contenidoObjetivo);
            divObjetivo.appendChild(createActionToggleButton(objetivo, null, mode_id));
            contenedorObjetivos.appendChild(divObjetivo);
        });
    } catch (error) {
        contenedorObjetivos.innerHTML = `<p class="error-mensaje">Error al cargar objetivos: ${error.message}</p>`;
    }
}

export async function renderizarCalendario(currentYear, currentMonth, updateGlobalDateCallback) {
    const calendarioContenedor = document.getElementById('calendario-contenedor');
    if (!calendarioContenedor) return;
    
    const fechaVistaActual = new Date(Date.UTC(currentYear, currentMonth, 1)); 
    const nombreMes = fechaVistaActual.toLocaleString('es-ES', { month: 'long', timeZone: 'UTC' });
    const anioVista = fechaVistaActual.getUTCFullYear();
    const mesVista = fechaVistaActual.getUTCMonth(); 
    
    let estadosDias = {};
    let anotacionesMes = {}; 

    try {
        [estadosDias, anotacionesMes] = await Promise.all([
            fetchData(`/calendario-dia-a-dia?mes=${mesVista + 1}&anio=${anioVista}`).then(d => d.reduce((acc, dia) => ({...acc, [dia.fecha]: dia.porcentaje}), {})),
            fetchData(`/anotaciones?mes=${mesVista + 1}&anio=${anioVista}`)
        ]);
    } catch (error) {
        calendarioContenedor.innerHTML = `<p class="error-mensaje">No se pudo cargar info del calendario: ${error.message}</p>`;
        return;
    }

    const nombresDiasSemana = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    calendarioContenedor.innerHTML = `
        <div class="calendario-nav">
            <button type="button" id="prevMonth" aria-label="Mes anterior">←</button>
            <h3 id="mes-anio">${nombreMes.charAt(0).toUpperCase() + nombreMes.slice(1)} ${anioVista}</h3>
            <button type="button" id="nextMonth" aria-label="Mes siguiente">→</button>
        </div>
        <div class="dias-semana">${nombresDiasSemana.map(dia => `<span>${dia}</span>`).join('')}</div>
        <div class="dias-mes"></div>`;
    
    const diasMesContenedor = calendarioContenedor.querySelector('.dias-mes');
    if (!diasMesContenedor) return;
    
    const primerDiaDelMes = new Date(Date.UTC(anioVista, mesVista, 1));
    let diaDeSemanaPrimerDia = primerDiaDelMes.getUTCDay(); 
    if (diaDeSemanaPrimerDia === 0) diaDeSemanaPrimerDia = 7; 
    for (let i = 1; i < diaDeSemanaPrimerDia; i++) diasMesContenedor.appendChild(document.createElement('span'));
    
    const numDiasEnMes = new Date(Date.UTC(anioVista, mesVista + 1, 0)).getUTCDate();
    const hoy = new Date(); 
    hoy.setUTCHours(0,0,0,0); 

    for (let dia = 1; dia <= numDiasEnMes; dia++) {
        const fechaStr = `${anioVista}-${String(mesVista + 1).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
        const fechaActualDia = new Date(Date.UTC(anioVista, mesVista, dia));
        const porcentaje = estadosDias[fechaStr] ?? -1;
        const anotacionDia = anotacionesMes[fechaStr];

        const diaSpan = document.createElement('span');
        diaSpan.className = 'dia-calendario';
        diaSpan.dataset.fecha = fechaStr;
        diaSpan.setAttribute('role', 'button');
        diaSpan.setAttribute('tabindex', '0');
        
        diaSpan.innerHTML = `<span class="dia-numero">${dia}</span><span class="emoji-anotacion"></span>`;
        if (anotacionDia?.emoji) {
            diaSpan.querySelector('.emoji-anotacion').textContent = anotacionDia.emoji; 
            if (anotacionDia.descripcion) diaSpan.title = anotacionDia.descripcion;
        }

        if (porcentaje >= 0) diaSpan.classList.add(obtenerClaseDeEstado(porcentaje)); 
        if (fechaActualDia.getTime() === hoy.getTime()) diaSpan.classList.add('dia-actual'); 
        
        // Nuevo: Deshabilitar días pasados en el calendario visual
        const esDiaPasado = fechaActualDia.getTime() < hoy.getTime();
        if (esDiaPasado) {
            diaSpan.classList.add('dia-pasado');
            diaSpan.setAttribute('aria-disabled', 'true'); // Indicador de accesibilidad
            diaSpan.removeAttribute('tabindex'); // Remover del foco de navegación
            // Eliminar listeners o reemplazarlos por una alerta
            diaSpan.onclick = () => alert("No se pueden interactuar con días anteriores al actual.");
            diaSpan.oncontextmenu = (e) => { 
                e.preventDefault(); 
                alert("No se pueden añadir tareas o anotaciones a días anteriores al actual.");
            };
            diaSpan.onkeydown = (e) => { 
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    alert("No se pueden interactuar con días anteriores al actual.");
                }
            };
        } else {
            diaSpan.onclick = () => verDetalleDia(fechaStr); 
            diaSpan.oncontextmenu = (e) => { 
                e.preventDefault(); 
                // Nuevo: Comprobar email verificado antes de abrir modal de añadir
                if (!currentUser.is_admin && !currentUser.email_verified) {
                    alert("Su correo electrónico no está verificado. Por favor, verifique su correo para crear elementos.");
                    return;
                }
                abrirModalParaNuevoElemento('dia-a-dia', fechaStr); 
            };
            diaSpan.onkeydown = (e) => { if (e.key === 'Enter' || e.key === ' ') e.target.click(); };
        }
        
        diasMesContenedor.appendChild(diaSpan);
    }
    
    calendarioContenedor.querySelector('#prevMonth').onclick = () => updateGlobalDateCallback(new Date(appFechaCalendarioActual.setUTCMonth(appFechaCalendarioActual.getUTCMonth() - 1)));
    calendarioContenedor.querySelector('#nextMonth').onclick = () => updateGlobalDateCallback(new Date(appFechaCalendarioActual.setUTCMonth(appFechaCalendarioActual.getUTCMonth() + 1)));
}

export async function verDetalleDia(fechaStr) {
    const tareasDetalleDiaOverlay = document.getElementById('tareasDetalleDia');
    if (!tareasDetalleDiaOverlay) return;
    const modalInterno = tareasDetalleDiaOverlay.querySelector('.form-modal');
    if (!modalInterno) return;

    // CONSOLIDADO: Un único div.form-modal-actions para todos los botones del footer
    // Orden de los botones: Cerrar (primero), Quitar Anotación, Guardar Anotación (al final)
    // El flex-grow: 1; entre Cerrar y los otros empujará "Quitar Anotación" y "Guardar Anotación" a la derecha.
    modalInterno.innerHTML = `
        <div class="form-modal-header"><h3 id="detalle-dia-titulo">Detalles de <span id="detalleDiaFecha"></span></h3></div>
        <div id="listaDetalleTareasScroll" class="form-modal-content"><p>Cargando...</p></div>
        <div class="form-modal-actions">
            <button type="button" id="cerrarDetalleDia" class="cancel-btn">Cerrar</button>
            <div style="flex-grow: 1;"></div> <button type="button" id="quitarAnotacionBtnModal" class="cancel-btn" style="display:none;">Quitar Anotación</button>
            <button type="button" id="guardarAnotacionBtnModal" class="save-btn">Guardar Anotación</button>
        </div>
    `;

    const detalleDiaFechaSpan = modalInterno.querySelector('#detalleDiaFecha');
    const listaDetalleTareasScrollDiv = modalInterno.querySelector('#listaDetalleTareasScroll');
    // const formModalActionsDiv = modalInterno.querySelector('.form-modal-actions'); // Referencia al único contenedor de acciones
    const fechaConsultadaObj = new Date(fechaStr + 'T00:00:00Z');
    detalleDiaFechaSpan.textContent = fechaConsultadaObj.toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC' });

    tareasDetalleDiaOverlay.classList.remove('hidden');
    tareasDetalleDiaOverlay.removeAttribute('inert');
    const cerrarBtn = modalInterno.querySelector('#cerrarDetalleDia');
    cerrarBtn.onclick = () => {
        tareasDetalleDiaOverlay.classList.add('hidden');
        tareasDetalleDiaOverlay.setAttribute('inert', 'true');
    };

    const [tareasResult, anotacionResult] = await Promise.allSettled([
        fetchData(`/tareas-por-fecha?fecha=${fechaStr}`),
        fetchData(`/anotaciones?fecha=${fechaStr}`)
    ]);

    let tareasHtml = '<p class="mensaje-vacio">No hay tareas para este día.</p>';
    if (tareasResult.status === 'fulfilled' && tareasResult.value.length > 0) {
        const tareas = tareasResult.value; 
        tareasHtml = '<ul>' + tareas.map(tarea => {
            let subtareasHtml = '';
            if (tarea.tipo === 'titulo' && tarea.subtareas?.length > 0) { 
                subtareasHtml = '<ul style="padding-left: 15px;">' + tarea.subtareas.map(sub => {
                    const completadoClass = sub.completado ? 'text-decoration: line-through; opacity:0.7;' : '';
                    return `<li><span style="${completadoClass}">${sub.texto}</span></li>`;
                }).join('') + '</ul>';
            }
            const tituloStyle = tarea.tipo === 'titulo' ? 'font-weight: bold; margin-top: 10px;' : '';
            return `<li style="${tituloStyle}">${tarea.texto}</li>` + subtareasHtml;
        }).join('') + '</ul>';
    } else if (tareasResult.status === 'rejected') {
        tareasHtml = `<p class="error-mensaje">Error: ${tareasResult.reason.message}</p>`;
    }

    let currentEmojisString = anotacionResult.status === 'fulfilled' ? (anotacionResult.value?.emoji || '') : '';
    let currentDesc = anotacionResult.status === 'fulfilled' ? (anotacionResult.value?.descripcion || '') : '';
    
    const hoy = new Date(); 
    hoy.setUTCHours(0,0,0,0);
    const esDiaPasado = fechaConsultadaObj.getTime() < hoy.getTime();
    
    // Nuevo: El selector de emojis solo se muestra si el día no es pasado y si el usuario está verificado o es admin
    const canEditAnnotations = (!esDiaPasado && (currentUser.is_admin || currentUser.email_verified));
    const emojiOptionsHTML = EMOJIS_PREDEFINIDOS.map(e => `<span class="emoji-option" data-emoji="${e}" role="button" tabindex="0" aria-label="Seleccionar ${e}">${e}</span>`).join('');
    
    const anotacionEditorHtml = `
        <div class="anotacion-editor">
            <h4>Anotación del Día:</h4>
            <div>
                <label for="emojiDiaModalInput" class="form-label">Emoji(s) (máx. 3): <span id="emojiDiaModalDisplay" class="current-emoji-display"></span></label>
                ${canEditAnnotations ? `<div id="emojiSelectorModal" class="emoji-selector-container">${emojiOptionsHTML}</div>` : ''}
                <input type="hidden" id="emojiDiaModalInput" value="${currentEmojisString}">
            </div>
            <div class="form-group">
                <label for="descripcionEmojiDiaModal" class="form-label">Descripción:</label>
                <input type="text" id="descripcionEmojiDiaModal" value="${currentDesc}" class="form-control" ${canEditAnnotations ? '' : 'disabled'}>
            </div>
        </div>
    `;
    listaDetalleTareasScrollDiv.innerHTML = `<div id="tareas-del-dia-container">${tareasHtml}</div><hr>${anotacionEditorHtml}`;

    // Rellenar el display de emojis inicial después de renderizar el HTML
    modalInterno.querySelector('#emojiDiaModalDisplay').textContent = currentEmojisString;

    if (canEditAnnotations) {
        const guardarBtn = modalInterno.querySelector('#guardarAnotacionBtnModal');
        const quitarBtn = modalInterno.querySelector('#quitarAnotacionBtnModal'); 

        if (currentEmojisString || currentDesc) {
            quitarBtn.style.display = 'inline-block'; 
        } else {
            quitarBtn.style.display = 'none'; 
        }
        
        quitarBtn.onclick = async () => { 
            if (!confirm("¿Quitar anotación?")) return;
            try {
                // Nuevo: Incluir fecha en payload DELETE
                await fetchData('/anotaciones', 'POST', { _method: 'DELETE', fecha: fechaStr }); 
                cerrarBtn.click();
                await renderizarCalendario(appFechaCalendarioActual.getFullYear(), appFechaCalendarioActual.getMonth(), appSetFechaCalendarioActual);
            } catch (error) {
                alert(`Error al quitar: ${error.message}`);
            }
        };

        guardarBtn.onclick = async () => {
            try {
                await fetchData('/anotaciones', 'POST', {
                    fecha: fechaStr,
                    emoji: modalInterno.querySelector('#emojiDiaModalInput').value,
                    descripcion: modalInterno.querySelector('#descripcionEmojiDiaModal').value
                });
                cerrarBtn.click();
                await renderizarCalendario(appFechaCalendarioActual.getFullYear(), appFechaCalendarioActual.getMonth(), appSetFechaCalendarioActual);
            } catch (error) {
                alert(`Error al guardar: ${error.message}`);
            }
        };

        const emojiOptions = modalInterno.querySelectorAll('#emojiSelectorModal .emoji-option');
        const emojiInputHidden = modalInterno.querySelector('#emojiDiaModalInput');
        const emojiDisplay = modalInterno.querySelector('#emojiDiaModalDisplay'); // Asegúrate de tener esta referencia

        let selectedEmojis = emojiInputHidden.value.match(/./gu) || [];
        emojiOptions.forEach(option => {
            if (selectedEmojis.includes(option.dataset.emoji)) option.classList.add('selected');
            option.onclick = () => {
                const emoji = option.dataset.emoji;
                const index = selectedEmojis.indexOf(emoji);
                if (index > -1) {
                    selectedEmojis.splice(index, 1);
                    option.classList.remove('selected');
                } else if (selectedEmojis.length < 3) {
                    selectedEmojis.push(emoji);
                    option.classList.add('selected');
                }
                emojiDisplay.textContent = selectedEmojis.join(' ');
                emojiInputHidden.value = selectedEmojis.join('');
            };
        });
    } else {
        // Si es un día pasado o el usuario no puede editar, ocultamos los botones
        const guardarBtn = modalInterno.querySelector('#guardarAnotacionBtnModal');
        const quitarBtn = modalInterno.querySelector('#quitarAnotacionBtnModal'); 
        if (guardarBtn) guardarBtn.style.display = 'none';
        if (quitarBtn) quitarBtn.style.display = 'none'; 
    }
}