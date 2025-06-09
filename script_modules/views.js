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
let myConfettiInstance = null;

// Función para inicializar las variables/referencias desde app.js
export function initViewsModule(appState) {
    modoContenido = appState.modoContenido;
    appFechaCalendarioActual = appState.appFechaCalendarioActual;
    appSetFechaCalendarioActual = appState.appSetFechaCalendarioActual;
    modosDisponibles = appState.modosDisponibles;
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
            // Si se expande y está vacío, intenta cargar las tareas
            if (isExpanded && listaInactivasDiv.innerHTML.trim() === '') { 
                cargarTareasDiaADia(new Date(appFechaCalendarioActual.getTime())); 
            }
        };
        toggleInactivasBtn.onkeydown = (e) => { if (e.key === 'Enter' || e.key === ' ') toggleInactivasBtn.click(); };
    }

    await renderizarCalendario(appFechaCalendarioActual.getFullYear(), appFechaCalendarioActual.getMonth(), appSetFechaCalendarioActual); 
    await cargarTareasDiaADia(new Date(appFechaCalendarioActual.getTime())); 
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
    
    try {
        const todasLasTareas = await fetchData(urlEndpoint); 
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
        console.error("Error en cargarTareasDiaADia:", error); 
        tareasDiariasListaDiv.innerHTML = `<p class="error-mensaje">Error al cargar tareas: ${error.message}</p>`; 
        
        const toggleBtn = document.getElementById('toggle-tareas-inactivas');
        if (listaTareasInactivasDiv.classList.contains('expandido')) {
             listaTareasInactivasDiv.innerHTML = `<p class="error-mensaje">Error al cargar tareas inactivas.</p>`;
        }
    }
}

export function renderizarTareasInactivas(tareasInactivas, fechaObj) {
    const listaTareasInactivasDiv = document.getElementById('lista-tareas-inactivas');
    const toggleBtn = document.getElementById('toggle-tareas-inactivas');

    if (!listaTareasInactivasDiv || !toggleBtn) return;
    
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

    const toggleBtn = createActionToggleButton(tarea, fechaObj, 'dia-a-dia'); 
    liTitulo.appendChild(toggleBtn);
    return liTitulo;
}

export function crearElementoSubTareaDiaria(tarea, fechaObj, esSuelta = false) {
    const li = document.createElement('li');
    li.className = esSuelta ? 'tarea-item tarea-suelta' : 'tarea-item';
    if (!tarea.activo) { li.classList.add('tarea-inactiva'); }

    const checkboxId = `tarea-${tarea.id}-${fechaObj.toISOString().split('T')[0].replace(/-/g, '')}`;
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.id = checkboxId;
    checkbox.dataset.idTareaDb = tarea.id;
    checkbox.checked = tarea.completado;
    
    const label = document.createElement('label');
    label.htmlFor = checkboxId;
    label.textContent = tarea.texto;
    
    if(tarea.completado && tarea.activo) {
        label.classList.add('sub-objetivo-completado');
    } else if (!tarea.activo) { 
        label.classList.add('sub-objetivo-completado');
    }

    li.appendChild(checkbox);
    li.appendChild(label);

    const toggleBtn = createActionToggleButton(tarea, fechaObj, 'dia-a-dia');
    li.appendChild(toggleBtn);
    
    const hoy = new Date(); 
    hoy.setHours(0,0,0,0);
    const fechaDeLaTareaVisualizada = new Date(fechaObj); 
    fechaDeLaTareaVisualizada.setHours(0,0,0,0);
    const esPasado = fechaDeLaTareaVisualizada.getTime() < hoy.getTime();

    checkbox.disabled = esPasado || !tarea.activo;
    
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
            fecha_actualizacion: fechaObj.toISOString().split('T')[0]
        };
        const options = { 
            method: 'POST', 
            headers: { 'Content-Type': 'application/json' }, 
            body: JSON.stringify(payload) 
        };
        try {
            await fetchData(`tareas-dia-a-dia`, options); 
            if (tarea.activo) { 
                label.classList.toggle('sub-objetivo-completado', esMarcado); 
            }
            await renderizarCalendario(appFechaCalendarioActual.getFullYear(), appFechaCalendarioActual.getMonth(), appSetFechaCalendarioActual); 
        } catch (error) {
            e.target.checked = !esMarcado;
            if (tarea.activo) { 
                label.classList.toggle('sub-objetivo-completado', !esMarcado); 
            }
            alert(`Error al actualizar tarea: ${error.message}`);
        }
    };
    return li;
}

export async function restaurarTarea(tareaARestaurar, fechaObjRecarga) {
    let payload = { _method: "PUT", id: parseInt(tareaARestaurar.id), activo: true, tipo: tareaARestaurar.tipo };
    const urlEndpointBase = `tareas-dia-a-dia`;

    if (tareaARestaurar.tipo === 'subtarea') { 
        try {
            const titulosActivos = await fetchData(`tareas-dia-a-dia?fecha=${fechaObjRecarga.toISOString().split('T')[0]}&solo_titulos_activos=true`);
            
            let optionsHtml = '<option value="">-- Restaurar como tarea suelta (sin título) --</option>';
            if (titulosActivos && titulosActivos.length > 0) {
                titulosActivos.forEach(titulo => {
                    optionsHtml += `<option value="${titulo.id}">${titulo.texto}</option>`;
                });
            }
            
            const reasignarHtml = `<p>La tarea "${tareaARestaurar.texto}" es una subtarea. ¿Dónde quieres restaurarla?</p><select id="selectNuevoParentId">${optionsHtml}</select>`;
            
            mostrarFormulario(reasignarHtml, async () => {
                const selectParent = document.getElementById('selectNuevoParentId');
                payload.parent_id = selectParent.value ? parseInt(selectParent.value) : null; 
                const optionsRestaurar = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
                try {
                    await fetchData(urlEndpointBase, optionsRestaurar);
                    ocultarFormulario();
                    await cargarTareasDiaADia(new Date(appFechaCalendarioActual.getTime() || fechaObjRecarga.getTime())); 
                    await renderizarCalendario(appFechaCalendarioActual.getFullYear(), appFechaCalendarioActual.getMonth(), appSetFechaCalendarioActual);
                } catch (error) { alert(`Error al restaurar subtarea: ${error.message}`); throw error; }
            }, "Reasignar Subtarea", "Confirmar", "Cancelar");
            return; 
        } catch (error) { 
            alert(`Error obteniendo títulos para reasignar: ${error.message}.`);
            return;
        }
    } else {
         if (!confirm("¿Quieres restaurar este elemento a la lista de tareas activas?")) return;
         if (payload.tipo === 'titulo') delete payload.parent_id;
    }

    const options = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };
    try {
        await fetchData(urlEndpointBase, options);
        alert('Elemento restaurado a activo.');
        await cargarTareasDiaADia(new Date(appFechaCalendarioActual.getTime() || fechaObjRecarga.getTime())); 
        await renderizarCalendario(appFechaCalendarioActual.getFullYear(), appFechaCalendarioActual.getMonth(), appSetFechaCalendarioActual);
    } catch (error) { alert(`Error al restaurar tarea: ${error.message}`); }
}

export async function eliminarTareaDiaria(tarea, fechaObjRecarga, esActivaActual) {
    let confirmMessage = esActivaActual 
        ? `¿Marcar este elemento como inactivo? ${tarea.tipo === 'titulo' ? 'Sus subtareas también se marcarán como inactivas.' : ''}`
        : "Esto eliminará la tarea PERMANENTEMENTE. ¿Continuar?";
    
    if (!confirm(confirmMessage)) return;

    const operationMethod = esActivaActual ? "DELETE" : "HARD_DELETE";
    const payload = { _method: operationMethod, id: parseInt(tarea.id), tipo: tarea.tipo };
    const options = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) };

    try {
        await fetchData('tareas-dia-a-dia', options);
        alert(esActivaActual ? 'Elemento marcado como inactivo.' : 'Elemento eliminado permanentemente.');
        await cargarTareasDiaADia(new Date(appFechaCalendarioActual.getTime() || fechaObjRecarga.getTime()));
        await renderizarCalendario(appFechaCalendarioActual.getFullYear(), appFechaCalendarioActual.getMonth(), appSetFechaCalendarioActual);
    } catch (error) { alert(`Error al procesar la eliminación: ${error.message}`); }
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
    if (addObjetivoContainer) {
        const btnAddObjetivo = document.createElement('button');
        btnAddObjetivo.id = `add-objetivo-btn-${mode_id}`;
        btnAddObjetivo.className = 'add-elemento-diario-btn';
        btnAddObjetivo.textContent = '+ Añadir Nuevo Objetivo';
        btnAddObjetivo.onclick = () => abrirModalParaNuevoElemento(mode_id); 
        addObjetivoContainer.appendChild(btnAddObjetivo);
    }
    await cargarObjetivos(mode_id);
}

export async function cargarObjetivos(mode_id) {
    const contenedorObjetivos = document.getElementById(`lineaDeTiempo-${mode_id}`);
    if (!contenedorObjetivos) return;
    
    contenedorObjetivos.innerHTML = '<p>Cargando objetivos...</p>';
    
    try {
        let objetivosData = await fetchData(`objetivos?mode=${mode_id}`); 
        
        objetivosData.sort((a, b) => { 
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
                this.parentElement.querySelector('.contenido-objetivo')?.classList.toggle('expandido');
                this.classList.toggle('expandido');
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
                    
                    const toggleSubBtn = createActionToggleButton(sub, null, mode_id); 
                    liSub.appendChild(toggleSubBtn);
                    
                    checkbox.onchange = async (evento) => {
                        const esMarcado = evento.target.checked; 
                        const idSubObjetivoDB = evento.target.dataset.idSubDb;
                        if(esMarcado && myConfettiInstance) lanzarAnimacionCelebracion(myConfettiInstance, evento); 

                        const updateOptions = { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ idSubObjetivoDB: parseInt(idSubObjetivoDB), completado: esMarcado }) };
                        try {
                            await fetchData(`sub-objetivos-estado`, updateOptions);
                            evento.target.closest('li').classList.toggle('sub-objetivo-completado', esMarcado);
                            
                            let completadosActuales = 0; 
                            const checkboxesDelObjetivo = divObjetivo.querySelectorAll('.contenido-objetivo ul li input[type="checkbox"]');
                            checkboxesDelObjetivo.forEach(cb => { if(cb.checked) completadosActuales++; });
                            const totalActual = checkboxesDelObjetivo.length;
                            const nuevoPorcentaje = totalActual > 0 ? Math.round((completadosActuales / totalActual) * 100) : 0;
                            
                            divObjetivo.querySelector('.porcentaje-progreso').textContent = `(${nuevoPorcentaje}%)`;
                            divObjetivo.querySelector('.barra-progreso').style.width = `${nuevoPorcentaje}%`;
                            
                            divObjetivo.className = 'seccion-objetivo'; // Reset classes
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
    const calendarioContenedor = document.getElementById('calendario-contenedor');
    if (!calendarioContenedor) return;
    
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
    if (!diasMesContenedor) return;
    
    const primerDiaDelMes = new Date(Date.UTC(anioVista, mesVista, 1));
    let diaDeSemanaPrimerDia = primerDiaDelMes.getUTCDay(); 
    if (diaDeSemanaPrimerDia === 0) diaDeSemanaPrimerDia = 7; 
    for (let i = 1; i < diaDeSemanaPrimerDia; i++) { diasMesContenedor.appendChild(document.createElement('span')); }
    
    const numDiasEnMes = new Date(Date.UTC(anioVista, mesVista + 1, 0)).getUTCDate();
    const hoy = new Date(); 
    hoy.setUTCHours(0,0,0,0); 

    for (let dia = 1; dia <= numDiasEnMes; dia++) {
        const fechaStr = `${anioVista}-${String(mesVista + 1).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
        const porcentaje = estadosDias[fechaStr] !== undefined ? estadosDias[fechaStr] : -1;
        const anotacionDia = anotacionesMes[fechaStr];

        const diaSpan = document.createElement('span');
        diaSpan.className = 'dia-calendario';
        diaSpan.dataset.fecha = fechaStr;
        diaSpan.setAttribute('role', 'button');
        diaSpan.setAttribute('tabindex', '0');
        
        diaSpan.innerHTML = `<span class="dia-numero">${dia}</span><span class="emoji-anotacion"></span>`;
        if (anotacionDia && anotacionDia.emoji) {
            diaSpan.querySelector('.emoji-anotacion').textContent = anotacionDia.emoji; 
            if (anotacionDia.descripcion) diaSpan.title = anotacionDia.descripcion;
        }

        if (porcentaje >= 0) { diaSpan.classList.add(obtenerClaseDeEstado(porcentaje)); } 
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
    
    calendarioContenedor.querySelector('#prevMonth').onclick = () => {
        const nuevaFecha = new Date(appFechaCalendarioActual); 
        nuevaFecha.setUTCMonth(nuevaFecha.getUTCMonth() - 1); 
        updateGlobalDateCallback(nuevaFecha); 
    };
    calendarioContenedor.querySelector('#nextMonth').onclick = () => {
        const nuevaFecha = new Date(appFechaCalendarioActual);
        nuevaFecha.setUTCMonth(nuevaFecha.getUTCMonth() + 1);
        updateGlobalDateCallback(nuevaFecha);
    };
}

export async function verDetalleDia(fechaStr) {
    const tareasDetalleDiaOverlay = document.getElementById('tareasDetalleDia'); 
    if (!tareasDetalleDiaOverlay) return;
    const modalInterno = tareasDetalleDiaOverlay.querySelector('.form-modal');
    if (!modalInterno) return;

    modalInterno.innerHTML = `
        <div class="form-modal-header">
            <h3 id="detalle-dia-titulo">Tareas para <span id="detalleDiaFecha"></span></h3>
        </div>
        <div id="listaDetalleTareasScroll" class="form-modal-content"><p>Cargando detalles...</p></div>
        <div id="anotacionActionsFooter" class="form-modal-actions"></div>
        <div class="form-modal-actions main-close-action">
            <button type="button" id="cerrarDetalleDia" class="cancel-btn">Cerrar</button>
        </div>
    `;
    
    const detalleDiaFechaSpan = modalInterno.querySelector('#detalleDiaFecha');
    const listaDetalleTareasScrollDiv = modalInterno.querySelector('#listaDetalleTareasScroll');
    const anotacionActionsFooter = modalInterno.querySelector('#anotacionActionsFooter');
    
    const fechaConsultadaObj = new Date(fechaStr + 'T00:00:00Z'); 
    if (detalleDiaFechaSpan) detalleDiaFechaSpan.textContent = fechaConsultadaObj.toLocaleDateString('es-ES', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC' });
    
    tareasDetalleDiaOverlay.classList.remove('hidden');
    const cerrarBtn = modalInterno.querySelector('#cerrarDetalleDia');
    if (cerrarBtn) cerrarBtn.onclick = () => tareasDetalleDiaOverlay.classList.add('hidden');

    let tareasHtml = '<p class="mensaje-vacio">No hay tareas para este día.</p>';
    try {
        const tareas = await fetchData(`tareas-por-fecha?fecha=${fechaStr}`);
        if (Array.isArray(tareas) && tareas.length > 0) {
            tareasHtml = '<ul>' + tareas.map(tarea => {
                let subtareasHtml = '';
                if (tarea.tipo === 'titulo' && tarea.subtareas && tarea.subtareas.length > 0) {
                    subtareasHtml = '<ul style="padding-left: 15px;">' + tarea.subtareas.map(sub => `<li>...</li>`).join('') + '</ul>';
                }
                return `<li>...</li>` + subtareasHtml;
            }).join('') + '</ul>';
        }
    } catch (error) {
        tareasHtml = `<p class="error-mensaje">Error al cargar tareas: ${error.message}</p>`;
    }

    let currentEmojisString = '', currentDesc = '';
    const hoy = new Date();
    hoy.setUTCHours(0,0,0,0);
    const esDiaPasado = new Date(fechaStr + 'T00:00:00Z').getTime() < hoy.getTime();

    try {
        const anotacion = await fetchData(`anotaciones?fecha=${fechaStr}`);
        if (anotacion) {
            currentEmojisString = anotacion.emoji || '';
            currentDesc = anotacion.descripcion || '';
        }
    } catch (e) { console.warn("No se pudo cargar anotación para", fechaStr); }

    const anotacionEditorHtml = `...`; // El HTML para el editor
    listaDetalleTareasScrollDiv.innerHTML = `<div id="tareas-del-dia-container">${tareasHtml}</div>` + anotacionEditorHtml;

    if (anotacionActionsFooter && !esDiaPasado) {
        anotacionActionsFooter.innerHTML = `<button type="button" id="guardarAnotacionBtnModal">Guardar</button>`;
        if (currentEmojisString || currentDesc) {
            anotacionActionsFooter.innerHTML += `<button type="button" id="quitarAnotacionBtnModal" class="cancel-btn">Quitar</button>`;
        }
        
        const guardarBtn = anotacionActionsFooter.querySelector('#guardarAnotacionBtnModal');
        const quitarBtn = anotacionActionsFooter.querySelector('#quitarAnotacionBtnModal');

        const emojiOptions = listaDetalleTareasScrollDiv.querySelectorAll('#emojiSelectorModal .emoji-option:not(.disabled)');
        const emojiDisplay = listaDetalleTareasScrollDiv.querySelector('#emojiDiaModalDisplay');
        const emojiInputHidden = listaDetalleTareasScrollDiv.querySelector('#emojiDiaModalInput');
        const descInput = listaDetalleTareasScrollDiv.querySelector('#descripcionEmojiDiaModal');

        if (emojiOptions.length > 0) {
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
        }

        if (guardarBtn) {
            guardarBtn.onclick = async () => {
                const payload = { fecha: fechaStr, emoji: emojiInputHidden.value, descripcion: descInput.value.trim() };
                try {
                    await fetchData('anotaciones', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
                    cerrarBtn.click();
                    await renderizarCalendario(appFechaCalendarioActual.getFullYear(), appFechaCalendarioActual.getMonth(), appSetFechaCalendarioActual);
                } catch (error) { alert(`Error al guardar: ${error.message}`); }
            };
        }
        
        if (quitarBtn) {
            quitarBtn.onclick = async () => {
                 if (!confirm("¿Quitar anotación?")) return;
                 try {
                    await fetchData('anotaciones', { method: 'DELETE', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ fecha: fechaStr }) });
                    cerrarBtn.click();
                    await renderizarCalendario(appFechaCalendarioActual.getFullYear(), appFechaCalendarioActual.getMonth(), appSetFechaCalendarioActual);
                } catch (error) { alert(`Error al quitar: ${error.message}`); }
            };
        }
    }
}