// script_modules/utils.js

import { API_BASE_URL } from './config.js'; // API_BASE_URL es 'api.php' desde config.js

// --- Constantes para Confetti (Exportadas para uso en app.js si es necesario inicializar) ---
export const confettiDefaults = { 
    spread: 360, 
    ticks: 50, 
    gravity: 0, 
    decay: 0.94, 
    startVelocity: 30, 
    colors: ['#a864fd', '#29cdff', '#78ff44', '#ff718d', '#fdff6a'] 
};

// --- Funciones de Confetti (ahora aceptan la instancia de confetti) ---
function shootConfetti(confettiInstance, x, y) { 
    if (!confettiInstance) return;
    confettiInstance({ ...confettiDefaults, particleCount: 80, scalar: 1.2, origin: { x, y } }); 
}

function shootFireworks(confettiInstance) {
    if (!confettiInstance) return;
    const duration = 5 * 1000; 
    const animationEnd = Date.now() + duration; 
    const fireworkDefaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 10000 };
    function randomInRange(min, max) { return Math.random() * (max - min) + min; }
    const interval = setInterval(function() {
        const timeLeft = animationEnd - Date.now(); 
        if (timeLeft <= 0) { return clearInterval(interval); }
        const x = randomInRange(0.1, 0.9); 
        const y = randomInRange(0.3, 0.7);
        confettiInstance({ ...fireworkDefaults, particleCount: 15, scalar: 0.8, origin: { x: x, y: y } });
        confettiInstance({ ...fireworkDefaults, particleCount: 10, scalar: 0.5, origin: { x: randomInRange(0.1, 0.9), y: randomInRange(0.3, 0.7) } });
    }, 200);
}

function shootBoom(confettiInstance) { 
    if (!confettiInstance) return;
    confettiInstance({ particleCount: 150, spread: 180, origin: { x: 0.5, y: 0.5 }, scalar: 1.5, colors: ['#ff0000', '#0000ff', '#ffffff'], zIndex: 10000 }); 
}

export function lanzarAnimacionCelebracion(confettiInstance, event) {
    if (!confettiInstance) {
        console.warn("Instancia de Confetti no proporcionada a lanzarAnimacionCelebracion");
        return;
    }
    if (!event || !event.target) {
        const animacionesSinPosicion = [() => shootFireworks(confettiInstance), () => shootBoom(confettiInstance)];
        animacionesSinPosicion[Math.floor(Math.random() * animacionesSinPosicion.length)](); 
        return;
    }
    const rect = event.target.getBoundingClientRect(); 
    const x = (rect.left + rect.width / 2) / window.innerWidth;
    const y = (rect.top + rect.height / 2) / window.innerHeight;
    const animacionesConPosicion = [() => shootConfetti(confettiInstance, x, y), () => shootFireworks(confettiInstance), () => shootBoom(confettiInstance)];
    animacionesConPosicion[Math.floor(Math.random() * animacionesConPosicion.length)]();
}

// --- UTILIDAD PARA LLAMADAS A LA API (CORREGIDA PARA ?endpoint=...) ---
export async function fetchData(endpoint_con_params, options = {}) {
    console.log("[fetchData] Inicio. API_BASE_URL importado:", API_BASE_URL);
    console.log("[fetchData] Recibido endpoint_con_params:", endpoint_con_params);

    let endpoint_base = String(endpoint_con_params || ''); 
    let query_string_original = '';

    if (endpoint_base.includes('?')) {
        const parts = endpoint_base.split('?', 2);
        endpoint_base = parts[0];
        query_string_original = parts[1];
    }
    
    const cleanApiBaseUrl = String(API_BASE_URL || '').trim(); 

    if (!cleanApiBaseUrl) {
        console.error("[fetchData] ERROR: API_BASE_URL está vacío o no definido.");
        throw new Error("Configuración de API_BASE_URL incorrecta.");
    }
    if (!endpoint_base) {
        console.error("[fetchData] ERROR: endpoint_base está vacío.");
        throw new Error("Endpoint no especificado para fetchData.");
    }
    
    let url = `${cleanApiBaseUrl}?endpoint=${endpoint_base}`;
    if (query_string_original) {
        url += `&${query_string_original}`;
    }

    // Para manejar sesiones con cookies, es crucial incluir 'credentials'
    const finalOptions = {
        ...options,
        credentials: 'include' // Envía cookies (como el ID de sesión) con la petición.
    };

    console.log("fetchData URL FINAL:", url, "CON OPCIONES:", finalOptions);
    
    try {
        const response = await fetch(url, finalOptions);
        
        if (response.type === 'opaque') {
             console.error(`fetchData: Respuesta opaca para ${url}.`);
             throw new Error(`Fallo al obtener recurso (respuesta opaca): ${url}.`);
        }
        
        const responseData = await response.json();

        if (!response.ok) {
            // Si la respuesta tiene un error en formato JSON, úsalo. Si no, usa el statusText.
            const error = new Error(responseData.error || `Error HTTP ${response.status} (${response.statusText})`);
            error.status = response.status;
            throw error;
        }

        return responseData;

    } catch (error) {
        console.error(`Error en fetchData para '${url || endpoint_con_params}':`, error.message);
        // Re-lanzar el error para que sea manejado por el código que llamó a fetchData
        throw error;
    }
}

// --- LÓGICA DE ORDENACIÓN Y ESTADOS ---
export function obtenerValorPrioridad(fechaEstimada) {
    if (!fechaEstimada) return 9999999999999; 
    fechaEstimada = String(fechaEstimada).toLowerCase().trim();
    const matchMesAno = fechaEstimada.match(/(\w+)\s+(\d{4})/i);
    if (matchMesAno) {
        const meses = { "enero":0, "febrero":1, "marzo":2, "abril":3, "mayo":4, "junio":5, "julio":6, "agosto":7, "septiembre":8, "octubre":9, "noviembre":10, "diciembre":11 };
        const mesNum = meses[matchMesAno[1]];
        if (mesNum !== undefined) return new Date(parseInt(matchMesAno[2]), mesNum, 1).getTime();
    }
    const matchYMD = fechaEstimada.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (matchYMD) {
        const parsedDate = new Date(parseInt(matchYMD[1]), parseInt(matchYMD[2]) - 1, parseInt(matchYMD[3]));
        if (!isNaN(parsedDate.getTime())) return parsedDate.getTime();
    }
    const currentYear = new Date().getFullYear();
    const prioridadTexto = { "primera quincena de junio": new Date(currentYear, 5, 1).getTime(), "en progreso": Date.now() - 100000000000, "continuo": Date.now() - 90000000000, "cuando convivamos": Date.now() + 30 * 24 * 60 * 60 * 1000, "1-2 años": Date.now() + (1.5 * 365.25 * 24 * 60 * 60 * 1000), "a medio y largo plazo": Date.now() + (3 * 365.25 * 24 * 60 * 60 * 1000), "a largo plazo": Date.now() + (5 * 365.25 * 24 * 60 * 60 * 1000) };
    return prioridadTexto[fechaEstimada] !== undefined ? prioridadTexto[fechaEstimada] : Date.now() + 9999999999999;
}

export function obtenerClaseDeEstado(p) { 
    if (p === 100 || p > 80) { return 'cal-estado-5'; }
    if (p > 60) { return 'cal-estado-4'; }
    if (p > 40) { return 'cal-estado-3'; }
    if (p > 20) { return 'cal-estado-2'; }
    if (p > 0) { return 'cal-estado-1'; }
    return 'cal-estado-0'; // Para 0% o si no hay tareas
}