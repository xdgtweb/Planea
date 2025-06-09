// script_modules/views.js

// --- IMPORTACIONES DE OTROS MÓDULOS ---
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

// Variables de módulo que se inicializarán desde app.js mediante initViewsModule
let modoContenido = null;
let appFechaCalendarioActual = new Date(); 
let appSetFechaCalendarioActual = (newDate) => { console.warn("setFechaCalendarioActual no inicializada en views.js"); fechaCalendarioActual = newDate; };
let modosDisponibles = [];
// let appModoActivo = 'dia-a-dia'; // No se necesita directamente aquí
let myConfettiInstance = null;

// Función para inicializar las variables/referencias desde app.js
export function initViewsModule(appState) {
    modoContenido = appState.modoContenido;
    appFechaCalendarioActual = appState.appFechaCalendarioActual;
    appSetFechaCalendarioActual = appState.appSetFechaCalendarioActual;
    modosDisponibles = appState.modosDisponibles;
    // appModoActivo = appState.appModoActivo; 
    myConfettiInstance = appState.myConfettiInstance;
    console.log("views.js: Módulo inicializado con referencias de app.js");
}


// --- RENDERIZADO DE MODOS Y CONTENIDO ESPECÍFICO ---

export async function renderizarModoDiaADia() {
    console.log("renderizarModoDiaADia (views.js): Iniciando.");
    if (!modoContenido) { 
        console.error("renderizarModoDiaADia: Contenedor #modo-contenido NO está disponible."); 
        return; 
    }
    
    let fechaActualVista = new Date(appFechaCalendarioActual.getTime());

    modoContenido.innerHTML = `
        <h2>Día a Día</h2>
        <p class="descripcion-pagina">Clic derecho en un día del calendario para añadir tareas programadas. Clic izquierdo para ver detalles y anotaciones del día.</p>
        <div id="calendario-contenedor"></div>
        <div id="add-daily-item-container" style="text-align: center; margin: 15px 0;"></div>
        <div id="tareasDiariasLista"></div>
        <div id="tareas-inactivas-contenedor">
            <h3 id="toggle-tareas-inactivas" aria-expanded="false" role="button" tabindex="0">Mostrar Tareas Archivadas/Inactivas</h3>
            <div id="lista-tareas-inactivas" class="tareas-listado"></div>
        </div>
        <div id="tareasDetalleDia" class="form-overlay hidden" role="dialog" aria-modal="true" aria-hidden="true">
            <div class="form-modal">
                {/* Contenido para el modal de detalle del día se inyectará aquí */}
            </div>
        </div>`;
    const addDailyItemContainer = document.getElementById('add-daily-item-container');
    if (addDailyItemContainer) {
        const btnAddDaily = document.createElement('button');
        btnAddDaily.id = 'add-daily-item-btn';
        btnAddDaily.className = 'add-elemento-diario-btn';
        btnAddDaily.textContent = '+ Añadir Título y Subtareas (Hoy)';
        btnAddDaily.type = 'button';
        btnAddDaily.onclick = () => abrirModalParaNuevoElemento('dia-a-dia', new Date().toISOString().split('T')[0]); 
        addDailyItemContainer.appendChild(btnAddDaily);
    }
    
    const toggleInactivasBtn = document.getElementById('toggle-tareas-inactivas');
    const listaInactivasDiv = document.getElementById('lista-tareas-inactivas');
    if (toggleInactivasBtn && listaInactivasDiv) {
        toggleInactivasBtn.onclick = () => {
            const isExpanded = listaInactivasDiv.classList.toggle('expandido');
            toggleInactivasBtn.classList.toggle('expandido', isExpanded);
            toggleInactivasBtn.setAttribute('aria-expanded', isExpanded.toString());
            if (isExpanded && listaTareasInactivasDiv.innerHTML.trim() === '') { 
                cargarTareasDiaADia(new Date(appFechaCalendarioActual.getTime())); 
            }
        };
        toggleInactivasBtn.onkeydown = (e) => { if (e.key === 'Enter' || e.key === ' ') toggleInactivasBtn.click(); };
    }

    console.log("renderizarModoDiaADia (views.js): HTML base y botón de añadir inyectados.");
    // renderizarCalendario (se definirá en Parte 4) llamará a appSetFechaCalendarioActual como callback
    await renderizarCalendario(appFechaCalendarioActual.getFullYear(), appFechaCalendarioActual.getMonth(), appSetFechaCalendarioActual); 
    await cargarTareasDiaADia(new Date(appFechaCalendarioActual.getTime())); 
    // Los listeners para #tareasDetalleDia se añadirán en verDetalleDia()
    console.log("renderizarModoDiaADia (views.js): Finalizado.");
}

export async function cargarTareasDiaADia(fechaObj) {
    const tareasDiariasListaDiv = document.getElementById('tareasDiariasLista');
    const listaTareasInactivasDiv = document.getElementById('lista-tareas-inactivas');
    
    if (!tareasDiariasListaDiv || !listaTareasInactivasDiv) { 
        console.warn("cargarTareasDiaADia: Contenedores de tareas no encontrados en el DOM."); 
        return; 
    }

    tareasDiariasListaDiv.innerHTML = '<p>Cargando tareas activas...</p>';
    if (listaTareasInactivasDiv.classList.contains('expandido')) {
        listaTareasInactivasDiv.innerHTML = '<p>Cargando tareas inactivas...</p>';
    } else {
         listaTareasInactivasDiv.innerHTML = ''; 
    }

    const fechaParaAPI = fechaObj.toISOString().split('T')[0];
    const urlEndpoint = `tareas-dia-a-dia?fecha=${fechaParaAPI}`;
    console.log("cargarTareasDiaADia (views.js): Iniciando carga para fecha:", fechaParaAPI);
    let todasLasTareas; 
    try {
        todasLasTareas = await fetchData(urlEndpoint); 
        console.log("cargarTareasDiaADia (views.js): Tareas recibidas para " + fechaParaAPI + ":", todasLasTareas);
        if (!Array.isArray(todasLasTareas)) {
            throw new Error("Datos de tareas no válidos (no es un array).");
        }

        const tareasActivas = todasLasTareas.filter(t => t.activo);
        const tareasInactivas = todasLasTareas.filter(t => !t.activo); 

        if (tareasActivas.length === 0) {
            tareasDiariasListaDiv.innerHTML = '<p class="mensaje-vacio">No hay tareas activas para este día.</p>';
        } else {
            tareasDiariasListaDiv.innerHTML = '';
            const ulActivas = document.createElement('ul');
            ulActivas.className = 'tareas-listado';
            tareasActivas.forEach(item => {
                const elementoTarea = item.tipo === 'titulo' ? 
                    crearElementoTituloTareaDiaria(item, fechaObj) :
                    (item.tipo === 'subtarea' && (item.parent_id === null || item.parent_id === undefined) ? 
                        crearElementoSubTareaDiaria(item, fechaObj, true) : null);
                
                if (elementoTarea) {
                    ulActivas.appendChild(elementoTarea);
                    if (item.tipo === 'titulo' && item.subtareas && item.subtareas.length > 0) {
                        const ulSub = document.createElement('ul');
                        ulSub.className = 'subtareas-listado';
                        item.subtareas.forEach(subtarea => { 
                            if(subtarea.activo) { 
                                ulSub.appendChild(crearElementoSubTareaDiaria(subtarea, fechaObj, false));
                            }
                        });
                        if (ulSub.hasChildNodes()) ulActivas.appendChild(ulSub);
                    }
                }
            });
            tareasDiariasListaDiv.appendChild(ulActivas);
        }
        renderizarTareasInactivas(tareasInactivas, fechaObj); 
    } catch (error) { 
        console.error("Error en cargarTareasDiaADia dentro del try:", error); 
        tareasDiariasListaDiv.innerHTML = `<p class="error-mensaje">Error al cargar tareas: ${error.message}</p>`; 
        
        const toggleBtn = document.getElementById('toggle-tareas-inactivas');
        if (listaTareasInactivasDiv.classList.contains('expandido')) {
             listaTareasInactivasDiv.innerHTML = `<p class="error-mensaje">Error al cargar tareas inactivas.</p>`;
        }
        if(toggleBtn) { 
             let numInactivasError = '?'; 
             if (typeof todasLasTareas !== 'undefined' && Array.isArray(todasLasTareas)) {
                numInactivasError = todasLasTareas.filter(t => !t.activo).length;
             }
             toggleBtn.textContent = `Mostrar Tareas Archivadas/Inactivas (${numInactivasError})`;
        }
    }
}

export function renderizarTareasInactivas(tareasInactivas, fechaObj) {
    const listaTareasInactivasDiv = document.getElementById('lista-tareas-inactivas');
    const toggleBtn = document.getElementById('toggle-tareas-inactivas');

    if (!listaTareasInactivasDiv || !toggleBtn) {
        console.error("renderizarTareasInactivas: Contenedores para inactivas no encontrados.");
        return;
    }
    
    const numInactivas = tareasInactivas ? tareasInactivas.length : 0; 
    const estaExpandido = listaTareasInactivasDiv.classList.contains('expandido');

    toggleBtn.textContent = estaExpandido ? 
        `Ocultar Tareas Archivadas/Inactivas (${numInactivas})` : 
        `Mostrar Tareas Archivadas/Inactivas (${numInactivas})`;

    if (!estaExpandido) { 
        listaTareasInactivasDiv.innerHTML = ''; 
        return;
    }

    if (numInactivas === 0) {
        listaTareasInactivasDiv.innerHTML = '<p class="mensaje-vacio" style="padding:10px;">No hay tareas archivadas.</p>';
        return;
    }
    
    listaTareasInactivasDiv.innerHTML = '';
    const ulInactivas = document.createElement('ul');
    ulInactivas.className = 'tareas-listado';

    tareasInactivas.forEach(item => {
        const elementoTarea = item.tipo === 'titulo' ? 
            crearElementoTituloTareaDiaria(item, fechaObj) :
            (item.tipo === 'subtarea' && (item.parent_id === null || item.parent_id === undefined) ? 
                crearElementoSubTareaDiaria(item, fechaObj, true) : null); 
        
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
export function crearElementoTituloTareaDiaria(tarea, fechaObj) {
    const liTitulo = document.createElement('li');
    liTitulo.className = 'tarea-titulo-item';
    if (!tarea.activo) { liTitulo.classList.add('tarea-inactiva'); }

    const textoSpan = document.createElement('span');
    textoSpan.className = 'texto-titulo';
    textoSpan.textContent = tarea.texto;
    liTitulo.appendChild(textoSpan);

    // createActionToggleButton se importa desde ui.js
    const toggleBtn = createActionToggleButton(tarea, fechaObj, 'dia-a-dia'); 
    liTitulo.appendChild(toggleBtn);
    return liTitulo;
}

export function crearElementoSubTareaDiaria(tarea, fechaObj, esSuelta = false) {
    const li = document.createElement('li');
    li.className = esSuelta ? 'tarea-item tarea-suelta' : 'tarea-item';
    if (!tarea.activo) { li.classList.add('tarea-inactiva'); }

    const checkboxId = `tarea-${tarea.id}-${fechaObj.toISOString().split('T')[0].replace(/-/g, '')}`;
    let labelText = tarea.texto; 
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox'; checkbox.id = checkboxId; checkbox.dataset.idTareaDb = tarea.id; checkbox.checked = tarea.completado;
    const label = document.createElement('label');
    label.htmlFor = checkboxId; label.textContent = labelText;
    
    if(tarea.completado && tarea.activo) {
        label.classList.add('sub-objetivo-completado'); // Reutilizamos clase para el tachado
    } else if (!tarea.activo) { 
        label.classList.add('sub-objetivo-completado'); // Las inactivas también se tachan
    }

    li.appendChild(checkbox);
    li.appendChild(label);

    const toggleBtn = createActionToggleButton(tarea, fechaObj, 'dia-a-dia');
    li.appendChild(toggleBtn);
    
    const hoy = new Date(); 
    hoy.setHours(0,0,0,0);
    // fechaObj es la fecha del día para el cual se está renderizando esta lista de tareas
    const fechaDeLaTareaVisualizada = new Date(fechaObj); 
    fechaDeLaTareaVisualizada.setHours(0,0,0,0);

    const esHoy = fechaDeLaTareaVisualizada.getTime() === hoy.getTime();
    const esPasado = fechaDeLaTareaVisualizada.getTime() < hoy.getTime();

    // Deshabilitar checkbox si:
    // 1. La tarea es de un día pasado.
    // 2. No es hoy Y es un día futuro (solo se pueden completar tareas del día actual).
    // 3. La tarea misma está inactiva.
    checkbox.disabled = esPasado || (!esHoy && fechaDeLaTareaVisualizada.getTime() > hoy.getTime()) || !tarea.activo;
    
    if (checkbox.disabled) { 
        checkbox.style.opacity = '0.6'; 
        checkbox.style.cursor = 'not-allowed'; 
    }

    checkbox.onchange = async (e) => {
        const esMarcado = e.target.checked; 
        const idTareaDB = e.target.dataset.idTareaDb;
        // myConfettiInstance se inicializa en app.js y se accede a través de initViewsModule
        if (esMarcado && myConfettiInstance) lanzarAnimacionCelebracion(myConfettiInstance, e); 

        const urlEndpoint = `tareas-dia-a-dia`; 
        const payload = { 
            _method: "PUT", 
            id: parseInt(idTareaDB), 
            completado: esMarcado, 
            fecha_actualizacion: fechaObj.toISOString().split('T')[0] // Fecha del día donde se marcó
        };
        const options = { 
            method: 'POST', 
            headers: { 'Content-Type': 'application/json' }, 
            body: JSON.stringify(payload) 
        };
        try {
            await fetchData(urlEndpoint, options); 
            if (tarea.activo) { 
                label.classList.toggle('sub-objetivo-completado', esMarcado); 
            }
            // renderizarCalendario es de este mismo módulo (views.js) y se definirá en la Parte 4
            await renderizarCalendario(fechaCalendarioActual.getFullYear(), fechaCalendarioActual.getMonth(), setFechaCalendarioActual); 
        } catch (error) {
            e.target.checked = !esMarcado; // Revertir el checkbox si hay error
            if (tarea.activo) { 
                label.classList.toggle('sub-objetivo-completado', !esMarcado); 
            }
            alert(`Error al actualizar tarea: ${error.message}`);
        }
    };
    return li;
}

export async function restaurarTarea(tareaARestaurar, fechaObjRecarga) {
    console.log("Intentando restaurar tarea:", tareaARestaurar);
    let payload = { _method: "PUT", id: parseInt(tareaARestaurar.id), activo: true, tipo: tareaARestaurar.tipo };
    const urlEndpointBase = `tareas-dia-a-dia`;

    if (tareaARestaurar.tipo === 'subtarea') { 
        try {
            const urlTitulosEndpoint = `tareas-dia-a-dia?fecha=${fechaObjRecarga.toISOString().split('T')[0]}&solo_titulos_activos=true`;
            const titulosActivos = await fetchData(urlTitulosEndpoint); 
            console.log("Títulos activos para reasignar:", titulosActivos);
    
            let optionsHtml = '<option value="">-- Restaurar como tarea suelta (sin título) --</option>';
            if (titulosActivos && titulosActivos.length > 0) {
                titulosActivos.forEach(titulo => {
                    optionsHtml += `<option value="${titulo.id}">${titulo.texto}</option>`;
                });
            }
            
            const reasignarHtml = `
                <p>La tarea "${tareaARestaurar.texto}" es una subtarea. ¿Dónde quieres restaurarla?</p>
                <label for="selectNuevoParentId" style="display:block; margin-bottom:5px;">Asignar al título (o dejar como suelta):</label>
                <select id="selectNuevoParentId" style="width:100%; padding:8px; margin-bottom:15px;">${optionsHtml}</select>
            `;
            
            mostrarFormulario(reasignarHtml, async () => {
                const selectParent = document.getElementById('selectNuevoParentId');
                const nuevoParentId = selectParent.value ? parseInt(selectParent.value) : null; 
                payload.parent_id = nuevoParentId; 
                console.log("Restaurando subtarea con payload:", payload);
                const optionsRestaurar = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
                try {
                    await fetchData(urlEndpointBase, optionsRestaurar);
                    alert('Subtarea restaurada.'); 
                    ocultarFormulario(); 
                    
                    const fechaParaRecarga = new Date(fechaCalendarioActual.getTime() || fechaObjRecarga.getTime());
                    await cargarTareasDiaADia(fechaParaRecarga); 
                    await renderizarCalendario(fechaParaRecarga.getFullYear(), fechaParaRecarga.getMonth(), setFechaCalendarioActual);
                } catch (error) { alert(`Error al restaurar subtarea: ${error.message}`); throw error; }
            }, "Reasignar Subtarea", "Confirmar", "Cancelar");
            return; 
        } catch (error) { 
            alert(`Error obteniendo títulos para reasignar: ${error.message}. Se ofrecerá restaurar como tarea suelta.`);
            if (!confirm("No se pudieron cargar los títulos. ¿Restaurar como tarea suelta (sin título) de todas formas?")) return;
            payload.parent_id = null; 
        }
    } else { // Es un título
         if (!confirm("¿Quieres restaurar este elemento a la lista de tareas activas?")) return;
         if (payload.tipo === 'titulo') delete payload.parent_id;
    }

    const options = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
    try {
        await fetchData(urlEndpointBase, options);
        alert('Elemento restaurado a activo.');
        const fechaParaRecarga = new Date(fechaCalendarioActual.getTime() || fechaObjRecarga.getTime());
        await cargarTareasDiaADia(fechaParaRecarga); 
        await renderizarCalendario(fechaParaRecarga.getFullYear(), fechaParaRecarga.getMonth(), setFechaCalendarioActual);
    } catch (error) { alert(`Error al restaurar tarea: ${error.message}`); }
}

export async function eliminarTareaDiaria(tarea, fechaObjRecarga, esActivaActual) {
    let confirmMessage = ""; let operationMethod = "";
    if (esActivaActual) {
        confirmMessage = `¿Marcar este elemento como inactivo? ${tarea.tipo === 'titulo' ? 'Sus subtareas también se marcarán como inactivas.' : (tarea.tipo === 'subtarea' ? 'La subtarea se marcará como inactiva y suelta (sin título asociado).' : '')}`;
        operationMethod = "DELETE"; 
    } else {
        confirmMessage = "Esto eliminará la tarea PERMANENTEMENTE y no se podrá recuperar. ¿Continuar?";
        operationMethod = "HARD_DELETE"; 
    }
    if (!confirm(confirmMessage)) return;
    const urlEndpoint = `tareas-dia-a-dia`;
    const payload = { _method: operationMethod, id: parseInt(tarea.id), tipo: tarea.tipo };
    const options = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
    try {
        await fetchData(urlEndpoint, options);
        alert(esActivaActual ? 'Elemento marcado como inactivo.' : 'Elemento eliminado permanentemente.');
        const fechaParaRecarga = new Date(fechaCalendarioActual.getTime() || fechaObjRecarga.getTime());
        await cargarTareasDiaADia(fechaParaRecarga);
        await renderizarCalendario(fechaParaRecarga.getFullYear(), fechaParaRecarga.getMonth(), setFechaCalendarioActual);
    } catch (error) { alert(`Error al procesar la eliminación: ${error.message}`); }
}
export async function renderizarModoObjetivos(mode_id) {
    console.log("renderizarModoObjetivos (views.js): Iniciando para modo:", mode_id);
    // modoContenido y modosDisponibles se inicializan en app.js y se acceden mediante initViewsModule
    if (!modoContenido) { 
        console.error("renderizarModoObjetivos: Contenedor #modo-contenido NO está disponible."); 
        return; 
    }
    
    const modoData = modosDisponibles.find(m => m.id === mode_id);
    const modoNombreDisplay = modoData ? modoData.nombre : (mode_id === 'corto-medio-plazo' ? 'Corto/Medio Plazo' : 'Largo Plazo');

    modoContenido.innerHTML = `
        <h2>${modoNombreDisplay}</h2>
        <p class="descripcion-pagina">Nuestros objetivos de ${mode_id === 'corto-medio-plazo' ? 'corto y medio plazo' : 'largo plazo'}.</p>
        <div id="add-objetivo-container-${mode_id}" style="text-align: center; margin: 15px 0;"></div>
        <div id="lineaDeTiempo-${mode_id}" class="objetivos-listado"></div>`;
    
    const addObjetivoContainer = document.getElementById(`add-objetivo-container-${mode_id}`);
    if (addObjetivoContainer) {
        const btnAddObjetivo = document.createElement('button');
        btnAddObjetivo.id = `add-objetivo-btn-${mode_id}`;
        btnAddObjetivo.className = 'add-elemento-diario-btn'; // Reutilizar clase de estilo
        btnAddObjetivo.textContent = '+ Añadir Nuevo Objetivo';
        btnAddObjetivo.type = 'button';
        // abrirModalParaNuevoElemento se importa de modals.js
        btnAddObjetivo.onclick = () => abrirModalParaNuevoElemento(mode_id); 
        addObjetivoContainer.appendChild(btnAddObjetivo);
    }
    console.log("renderizarModoObjetivos (views.js): HTML base y botón de añadir inyectados. Llamando a cargarObjetivos.");
    await cargarObjetivos(mode_id);
    console.log("renderizarModoObjetivos (views.js): Finalizado para modo:", mode_id);
}

export async function cargarObjetivos(mode_id) {
    const contenedorObjetivos = document.getElementById(`lineaDeTiempo-${mode_id}`);
    if (!contenedorObjetivos) { 
        console.error("cargarObjetivos (views.js): Contenedor #lineaDeTiempo-" + mode_id + " NO encontrado!"); 
        return;
    }
    contenedorObjetivos.innerHTML = '<p>Cargando objetivos...</p>';
    const urlEndpoint = `objetivos?mode=${mode_id}`; 
    console.log("cargarObjetivos (views.js): Iniciando carga para modo:", mode_id);
    try {
        // fetchData usa API_BASE_URL internamente
        let objetivosData = await fetchData(urlEndpoint); 
        console.log("cargarObjetivos (views.js): Objetivos recibidos para " + mode_id + ":", objetivosData);
        
        objetivosData.sort((a, b) => { 
            // obtenerValorPrioridad se importa desde utils.js
            const prioridadA = obtenerValorPrioridad(a.fecha_estimada); 
            const prioridadB = obtenerValorPrioridad(b.fecha_estimada);
            if (prioridadA !== prioridadB) return prioridadA - prioridadB;
            return (a.titulo || "").localeCompare(b.titulo || "");
        });

        if (objetivosData.length === 0) {
            contenedorObjetivos.innerHTML = `<p class="mensaje-vacio">No hay objetivos. Usa el botón '+' para añadir nuevos.</p>`;
            return;
        }
        contenedorObjetivos.innerHTML = '';
        objetivosData.forEach(objetivo => {
            objetivo.activo = true; 
            const divObjetivo = document.createElement('div');
            divObjetivo.classList.add('seccion-objetivo');
            let totalSubObjetivos = objetivo.sub_objetivos ? objetivo.sub_objetivos.length : 0;
            let subObjetivosCompletados = objetivo.sub_objetivos ? objetivo.sub_objetivos.filter(sub => sub.completado).length : 0;
            let porcentajeCompletado = totalSubObjetivos > 0 ? Math.round((subObjetivosCompletados / totalSubObjetivos) * 100) : 0;
            
            // obtenerClaseDeEstado (importado de utils.js) devuelve 'cal-estado-X'
            // El CSS para .seccion-objetivo (Parte 1 de estilos.css, respuesta #151) está preparado para estas clases.
            divObjetivo.classList.add(obtenerClaseDeEstado(porcentajeCompletado)); 

            const cabeceraObjetivo = document.createElement('div');
            cabeceraObjetivo.classList.add('cabecera-objetivo');
            cabeceraObjetivo.innerHTML = `
                <div class="cabecera-objetivo-izquierda">
                    <span class="titulo-objetivo">${objetivo.titulo}</span>
                    <span class="porcentaje-progreso">(${porcentajeCompletado}%)</span>
                </div>
                <span class="fecha-estimada">${objetivo.fecha_estimada || ''}</span>`;
            cabeceraObjetivo.onclick = function() {
                const contenido = this.parentElement.querySelector('.contenido-objetivo');
                this.classList.toggle('expandido');
                if (contenido) contenido.classList.toggle('expandido');
            };
            divObjetivo.appendChild(cabeceraObjetivo);

            const barraProgreso = document.createElement('div');
            barraProgreso.classList.add('barra-progreso');
            barraProgreso.style.width = `${porcentajeCompletado}%`;
            divObjetivo.appendChild(barraProgreso);

            const contenidoObjetivo = document.createElement('div');
            contenidoObjetivo.classList.add('contenido-objetivo');
            let ulSubObjetivos = document.createElement('ul');
            if (objetivo.sub_objetivos && objetivo.sub_objetivos.length > 0) {
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
                    
                    liSub.appendChild(checkbox); 
                    liSub.appendChild(label);
                    
                    // createActionToggleButton importado de ui.js
                    // mode_id es el contexto del objetivo padre.
                    const toggleSubBtn = createActionToggleButton(sub, null, mode_id); 
                    liSub.appendChild(toggleSubBtn);
                    
                    checkbox.onchange = async (evento) => {
                        const esMarcado = evento.target.checked; 
                        const idSubObjetivoDB = evento.target.dataset.idSubDb;
                        // myConfettiInstance se inicializa en app.js y se accede mediante initViewsModule
                        if(esMarcado && myConfettiInstance) lanzarAnimacionCelebracion(myConfettiInstance, evento); 

                        const updateUrlEndpoint = `sub-objetivos-estado`; 
                        const updateOptions = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ idSubObjetivoDB: parseInt(idSubObjetivoDB), completado: esMarcado }) };
                        try {
                            await fetchData(updateUrlEndpoint, updateOptions);
                            evento.target.closest('li').classList.toggle('sub-objetivo-completado', esMarcado);
                            
                            let completadosActuales = 0; 
                            const checkboxesDelObjetivo = divObjetivo.querySelectorAll('.contenido-objetivo ul li input[type="checkbox"]');
                            checkboxesDelObjetivo.forEach(cb => { if(cb.checked) completadosActuales++; });
                            const totalActual = checkboxesDelObjetivo.length;
                            const nuevoPorcentaje = totalActual > 0 ? Math.round((completadosActuales / totalActual) * 100) : 0;
                            
                            divObjetivo.querySelector('.porcentaje-progreso').textContent = `(${nuevoPorcentaje}%)`;
                            divObjetivo.querySelector('.barra-progreso').style.width = `${nuevoPorcentaje}%`;
                            
                            // Actualizar clase de estado del objetivo principal
                            divObjetivo.classList.remove('cal-estado-0', 'cal-estado-1', 'cal-estado-2', 'cal-estado-3', 'cal-estado-4', 'cal-estado-5');
                            divObjetivo.classList.add(obtenerClaseDeEstado(nuevoPorcentaje));

                        } catch (error) {
                            alert(`Error al actualizar estado del sub-objetivo: ${error.message}`);
                            evento.target.checked = !esMarcado; 
                            evento.target.closest('li').classList.toggle('sub-objetivo-completado', !esMarcado);
                        }
                    };
                    ulSubObjetivos.appendChild(liSub);
                });
            }
            const descP = document.createElement('p');
            descP.textContent = objetivo.descripcion || '';
            contenidoObjetivo.appendChild(descP);
            contenidoObjetivo.appendChild(ulSubObjetivos); 
            divObjetivo.appendChild(contenidoObjetivo);
            
            const toggleBtnPrincipal = createActionToggleButton(objetivo, null, mode_id);
            divObjetivo.appendChild(toggleBtnPrincipal);
            
            contenedorObjetivos.appendChild(divObjetivo);
        });
    } catch (error) {
        contenedorObjetivos.innerHTML = `<p class="error-mensaje">Error al cargar objetivos: ${error.message}</p>`;
    }
}
export async function renderizarCalendario(currentYear, currentMonth, updateGlobalDateCallback) {
    console.log("renderizarCalendario (views.js): Iniciando para", currentYear, currentMonth + 1);
    const calendarioContenedor = document.getElementById('calendario-contenedor');
    if (!calendarioContenedor) { 
        console.error("renderizarCalendario: Contenedor #calendario-contenedor NO encontrado!"); 
        return; 
    }
    
    const fechaVistaActual = new Date(Date.UTC(currentYear, currentMonth, 1)); 
    const nombreMes = fechaVistaActual.toLocaleString('es-ES', { month: 'long', timeZone: 'UTC' });
    const anioVista = fechaVistaActual.getUTCFullYear();
    const mesVista = fechaVistaActual.getUTCMonth(); 
    
    let estadosDias = {};
    let anotacionesMes = {}; 

    try {
        const [dataEstados, dataAnotaciones] = await Promise.all([
            fetchData(`calendario-dia-a-dia?mes=${mesVista + 1}&anio=${anioVista}`),
            fetchData(`anotaciones?mes=${mesVista + 1}&anio=${anioVista}`)
        ]);
        if (Array.isArray(dataEstados)) { dataEstados.forEach(dia => { estadosDias[dia.fecha] = dia.porcentaje; }); }
        if (typeof dataAnotaciones === 'object' && dataAnotaciones !== null) { anotacionesMes = dataAnotaciones; }
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
    if (!diasMesContenedor) { console.error("renderizarCalendario: Contenedor .dias-mes NO encontrado."); return; }
    
    const primerDiaDelMes = new Date(Date.UTC(anioVista, mesVista, 1));
    const ultimoDiaDelMes = new Date(Date.UTC(anioVista, mesVista + 1, 0)); 
    const numDiasEnMes = ultimoDiaDelMes.getUTCDate(); 
    let diaDeSemanaPrimerDia = primerDiaDelMes.getUTCDay(); 
    if (diaDeSemanaPrimerDia === 0) diaDeSemanaPrimerDia = 7; 
    for (let i = 1; i < diaDeSemanaPrimerDia; i++) { diasMesContenedor.appendChild(document.createElement('span')); }
    
    const hoy = new Date(); 
    hoy.setUTCHours(0,0,0,0); 

    for (let dia = 1; dia <= numDiasEnMes; dia++) {
        const fechaStr = `${anioVista}-${String(mesVista + 1).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
        const porcentaje = estadosDias[fechaStr] !== undefined ? estadosDias[fechaStr] : -1;
        const anotacionDia = anotacionesMes[fechaStr];

        const diaSpan = document.createElement('span');
        diaSpan.className = 'dia-calendario';
        diaSpan.dataset.fecha = fechaStr;
        diaSpan.setAttribute('role', 'button'); diaSpan.setAttribute('tabindex', '0');
        
        const numeroDiaSpan = document.createElement('span'); 
        numeroDiaSpan.className = 'dia-numero';
        numeroDiaSpan.textContent = dia;
        diaSpan.appendChild(numeroDiaSpan);

        const emojiSpan = document.createElement('span'); 
        emojiSpan.className = 'emoji-anotacion';
        if (anotacionDia && anotacionDia.emoji) {
            emojiSpan.textContent = anotacionDia.emoji; 
            if (anotacionDia.descripcion) diaSpan.title = anotacionDia.descripcion;
        }
        diaSpan.appendChild(emojiSpan);

        if (porcentaje >= 0) { diaSpan.classList.add(obtenerClaseDeEstado(porcentaje)); } 
        else { 
            const fechaCelda = new Date(Date.UTC(anioVista, mesVista, dia)); 
            if (fechaCelda.getTime() < hoy.getTime() ) { diaSpan.classList.add('cal-estado-sin-datos'); }
        }
        const fechaCeldaActual = new Date(Date.UTC(anioVista, mesVista, dia));
        if (fechaCeldaActual.getTime() === hoy.getTime()) { 
            diaSpan.classList.add('dia-actual'); 
            diaSpan.setAttribute('aria-current', 'date');
        }
        
        diaSpan.onclick = () => verDetalleDia(fechaStr); 
        diaSpan.oncontextmenu = (e) => { 
            e.preventDefault();
            abrirModalParaNuevoElemento('dia-a-dia', fechaStr); 
        };
        diaSpan.onkeydown = (e) => { if (e.key === 'Enter' || e.key === ' ') e.target.click(); };
        diasMesContenedor.appendChild(diaSpan);
    }
    const prevBtn = calendarioContenedor.querySelector('#prevMonth');
    if (prevBtn) {
        prevBtn.onclick = () => {
            const nuevaFecha = new Date(appFechaCalendarioActual); 
            nuevaFecha.setUTCMonth(nuevaFecha.getUTCMonth() - 1); 
            updateGlobalDateCallback(nuevaFecha); 
        };
    }
    const nextBtn = calendarioContenedor.querySelector('#nextMonth');
    if (nextBtn) {
        nextBtn.onclick = () => {
            const nuevaFecha = new Date(appFechaCalendarioActual);
            nuevaFecha.setUTCMonth(nuevaFecha.getUTCMonth() + 1);
            updateGlobalDateCallback(nuevaFecha);
        };
    }
    console.log("renderizarCalendario (views.js): Finalizado.");
}

export async function verDetalleDia(fechaStr) {
    const tareasDetalleDiaOverlay = document.getElementById('tareasDetalleDia'); 
    if (!tareasDetalleDiaOverlay) { console.error("Contenedor de modal #tareasDetalleDia no encontrado."); return; }
    const modalInterno = tareasDetalleDiaOverlay.querySelector('.form-modal');
    if (!modalInterno) { console.error(".form-modal dentro de #tareasDetalleDia no encontrado."); return; }

    // Estructura HTML interna del modal con áreas para header, contenido desplazable y acciones fijas
    modalInterno.innerHTML = `
        <div class="form-modal-header">
            <h3 id="detalle-dia-titulo">Tareas para <span id="detalleDiaFecha"></span></h3>
        </div>
        <div id="listaDetalleTareasScroll" class="form-modal-content">
            <p>Cargando detalles...</p>
        </div>
        <div id="anotacionActionsFooter" class="form-modal-actions" style="flex-direction: column; gap: var(--space-s); padding-bottom: var(--space-s); justify-content: center; text-align:center;">
            {/* Botones de Guardar/Quitar Anotación irán aquí si es día actual/futuro */}
        </div>
        <div class="form-modal-actions main-close-action" style="border-top:none; padding-top: var(--space-s);">
            <button type="button" id="cerrarDetalleDia" class="cancel-btn">Cerrar</button>
        </div>
    `;
    
    const detalleDiaFechaSpan = modalInterno.querySelector('#detalleDiaFecha');
    const listaDetalleTareasScrollDiv = modalInterno.querySelector('#listaDetalleTareasScroll');
    const anotacionActionsFooter = modalInterno.querySelector('#anotacionActionsFooter');
    
    const fechaConsultadaObj = new Date(fechaStr + 'T00:00:00Z'); 
    if (detalleDiaFechaSpan) detalleDiaFechaSpan.textContent = fechaConsultadaObj.toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC' });
    
    // Mostrar el modal
    tareasDetalleDiaOverlay.classList.remove('hidden');
    tareasDetalleDiaOverlay.removeAttribute('inert');
    tareasDetalleDiaOverlay.setAttribute('aria-hidden', 'false');

    const cerrarBtn = modalInterno.querySelector('#cerrarDetalleDia');
    if (cerrarBtn) {
        cerrarBtn.onclick = () => {
            tareasDetalleDiaOverlay.classList.add('hidden');
            tareasDetalleDiaOverlay.setAttribute('inert', 'true');
            tareasDetalleDiaOverlay.setAttribute('aria-hidden', 'true');
        };
    }

    // Cargar y mostrar tareas y anotaciones en paralelo
    let tareasHtml = '<p class="mensaje-vacio">No hay tareas registradas para este día.</p>';
    try {
        const tareas = await fetchData(`tareas-por-fecha?fecha=${fechaStr}`);
        if (Array.isArray(tareas) && tareas.length > 0) {
            let html = '<ul>';
            tareas.forEach(tarea => {
                if (tarea.tipo === 'titulo') {
                    html += `<li style="font-weight: bold; margin-top: 10px; color: var(--color-fondo-principal);">${tarea.texto}${!tarea.activo ? ' (inactiva)' : ''}</li>`;
                    if (tarea.subtareas && tarea.subtareas.length > 0) {
                        html += '<ul style="padding-left: 15px;">';
                        tarea.subtareas.forEach(subtarea => {
                            const statusColor = subtarea.completado ? 'var(--color-exito)' : (subtarea.activo ? 'var(--color-error)' : 'var(--color-texto-secundario)');
                            html += `<li><span class="status-indicator" style="background-color: ${statusColor};"></span><span style="${subtarea.completado && subtarea.activo ? 'text-decoration: line-through; opacity:0.7;' : ''} ${!subtarea.activo ? 'font-style:italic; color:var(--color-texto-secundario); text-decoration: line-through;' : ''}">${subtarea.texto} ${!subtarea.activo ? '(inactiva)' : ''}</span></li>`;
                        });
                        html += '</ul>';
                    }
                } else if (tarea.tipo === 'subtarea' && (tarea.parent_id === null || tarea.parent_id === undefined) ) {
                    const statusColor = tarea.completado ? 'var(--color-exito)' : (tarea.activo ? 'var(--color-error)' : 'var(--color-texto-secundario)');
                    html += `<li><span class="status-indicator" style="background-color: ${statusColor};"></span><span style="${tarea.completado && tarea.activo ? 'text-decoration: line-through; opacity:0.7;' : ''} ${!tarea.activo ? 'font-style:italic; color:var(--color-texto-secundario); text-decoration: line-through;' : ''}">${tarea.texto} ${!tarea.activo ? '(inactiva)' : ''}</span></li>`;
                }
            });
            html += '</ul>';
            tareasHtml = html;
        }
    } catch (error) {
        tareasHtml = `<p class="error-mensaje">Error al cargar detalle de tareas: ${error.message}</p>`;
    }

    // Obtener y preparar HTML del editor de anotaciones
    let currentEmojisString = ''; let currentDesc = '';
    const hoy = new Date(); hoy.setUTCHours(0,0,0,0);
    const fechaConsultadaUTC = new Date(Date.UTC(parseInt(fechaStr.substring(0,4)), parseInt(fechaStr.substring(5,7)) - 1, parseInt(fechaStr.substring(8,10))));
    const esDiaPasado = fechaConsultadaUTC.getTime() < hoy.getTime();

    try {
        const anotacion = await fetchData(`anotaciones?fecha=${fechaStr}`);
        if (anotacion && (anotacion.emoji || anotacion.descripcion)) { currentEmojisString = anotacion.emoji || ''; currentDesc = anotacion.descripcion || ''; }
    } catch (e) { console.warn("No se pudo cargar anotación para", fechaStr); }

    let emojiOptionsHTML = EMOJIS_PREDEFINIDOS.map(emoji => `<span class="emoji-option ${esDiaPasado ? 'disabled' : ''}" data-emoji="${emoji}" role="button" ${esDiaPasado ? 'aria-disabled="true"' : 'tabindex="0"'} aria-label="Seleccionar ${emoji}">${emoji}</span>`).join('');
    
    const anotacionEditorHtml = `
        <div class="anotacion-editor">
            <h4>Anotación del Día:</h4>
            <div>
                <label for="emojiDiaModalDisplay">Emoji(s) (máx. 3): <span id="emojiDiaModalDisplay" class="current-emoji-display">${currentEmojisString}</span></label>
                ${!esDiaPasado ? `<div id="emojiSelectorModal" class="emoji-selector-container">${emojiOptionsHTML}</div>` : '<p style="font-size:0.9em; color:#777;">(No se pueden editar emojis de días pasados)</p>'}
                <input type="hidden" id="emojiDiaModalInput" value="${currentEmojisString}">
            </div>
            <div>
                <label for="descripcionEmojiDiaModal">Descripción (opcional):</label>
                <input type="text" id="descripcionEmojiDiaModal" value="${currentDesc}" placeholder="Ej. Cumpleaños" ${esDiaPasado ? 'disabled' : ''}>
            </div>
        </div>`;

    // Inyectar HTML de tareas y editor de anotaciones en el área de scroll
    if (listaDetalleTareasScrollDiv) listaDetalleTareasScrollDiv.innerHTML = `<div id="tareas-del-dia-container">${tareasHtml}</div>` + anotacionEditorHtml;

    // Poblar y configurar el footer de acciones de anotación
    if (anotacionActionsFooter && !esDiaPasado) {
        anotacionActionsFooter.innerHTML = `
            <button type="button" id="guardarAnotacionBtnModal">Guardar Anotación</button>
            ${(currentEmojisString || currentDesc) ? `<button type="button" id="quitarAnotacionBtnModal" class="cancel-btn">Quitar Anotación</button>` : ''}
        `;
        
        const guardarBtn = anotacionActionsFooter.querySelector('#guardarAnotacionBtnModal');
        const quitarBtn = anotacionActionsFooter.querySelector('#quitarAnotacionBtnModal');

        // Adjuntar lógica a los emojis y botones del editor (si no es día pasado)
        const emojiOptions = listaDetalleTareasScrollDiv.querySelectorAll('#emojiSelectorModal .emoji-option:not(.disabled)');
        const emojiDisplay = listaDetalleTareasScrollDiv.querySelector('#emojiDiaModalDisplay');
        const emojiInputHidden = listaDetalleTareasScrollDiv.querySelector('#emojiDiaModalInput');
        
        if (emojiOptions.length && emojiDisplay && emojiInputHidden) { /* ... Lógica rolling emoji selector (de respuesta #11) ... */ }

        if (guardarBtn) { guardarBtn.onclick = async () => { /* ... lógica guardar anotación (de respuesta #11) ... */ }; }
        if (quitarBtn) { quitarBtn.onclick = async () => { /* ... lógica quitar anotación (de respuesta #11) ... */ }; }
    }
}
// Fin del módulo views.js