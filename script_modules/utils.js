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
    // Loguear los valores recibidos y el importado API_BASE_URL
    console.log("[fetchData] Inicio. API_BASE_URL importado:", API_BASE_URL, "(tipo:", typeof API_BASE_URL, ")");
    console.log("[fetchData] Recibido endpoint_con_params:", endpoint_con_params, "(tipo:", typeof endpoint_con_params, ")");

    let endpoint_base = String(endpoint_con_params || ''); 
    let query_string_original = '';

    if (endpoint_base.includes('?')) {
        const parts = endpoint_base.split('?', 2);
        endpoint_base = parts[0];
        query_string_original = parts[1];
    }
    
    const cleanApiBaseUrl = String(API_BASE_URL || '').trim(); 

    if (!cleanApiBaseUrl) {
        console.error("[fetchData] ERROR: API_BASE_URL está vacío o no definido después de importar y limpiar.");
        throw new Error("Configuración de API_BASE_URL incorrecta.");
    }
    if (!endpoint_base) {
        console.error("[fetchData] ERROR: endpoint_base está vacío después de procesar.");
        throw new Error("Endpoint no especificado para fetchData.");
    }
    
    console.log("[fetchData] Valores limpios -> cleanApiBaseUrl:", cleanApiBaseUrl, "| endpoint_base:", endpoint_base, "| query_string_original:", query_string_original);

    let url = `${cleanApiBaseUrl}?endpoint=${endpoint_base}`;
    if (query_string_original) {
        url += `&${query_string_original}`;
    }

    const logOptions = { ...options, body: options.body && typeof options.body !== 'string' ? '[Cuerpo no string]' : options.body };
    console.log("fetchData URL FINAL:", url, "CON OPCIONES:", logOptions);
    
    try {
        const response = await fetch(url, options);
        
        if (response.type === 'opaque') { // Capturar redirecciones CORS bloqueadas (como a la página 404 de InfinityFree)
             console.error(`fetchData: Respuesta opaca para ${url}. Indica un problema de CORS o una redirección a un recurso no permitido (posible 404 no manejado por tu API).`);
             throw new Error(`Fallo al obtener recurso (respuesta opaca): ${url}. Verifica la ruta del endpoint y la configuración del servidor.`);
        }

        if (!response.ok) { // Capturar errores HTTP (4xx, 5xx)
            let errorText = `Error HTTP ${response.status} (${response.statusText}) al obtener ${url}.`;
            let responseBodyText = '';
            try {
                responseBodyText = await response.text(); 
                console.error("fetchData: Respuesta de error del servidor (status no OK):", responseBodyText);
                errorText += ` Respuesta del servidor: ${responseBodyText.substring(0, 200)}...`;
            } catch (e) {
                console.error("fetchData: No se pudo leer el cuerpo de la respuesta de error.");
            }
            throw new Error(errorText);
        }

        // Si response.ok es true, intentar parsear como JSON
        const responseData = await response.json().catch(jsonError => {
            console.error(`fetchData: Error al parsear JSON desde ${url}. Status: ${response.status}`, jsonError);
            // En este punto, response.ok era true, pero el cuerpo no es JSON.
            // Esto indica que el servidor PHP envió un 200 OK pero con contenido incorrecto (ej. un error PHP mostrado como HTML).
            // No podemos re-leer response.text() aquí si .json() ya consumió el stream y falló.
            // El error original jsonError es lo más útil aquí.
            throw new Error(`Respuesta del servidor no es JSON válido desde ${url} (Status: ${response.status}), aunque el status fue OK. Error de parseo: ${jsonError.message}`);
        });
        return responseData;

    } catch (error) {
        console.error(`Error en fetchData para '${url || endpoint_con_params}':`, error.message);
        // Si el error ya es uno de los que hemos formateado arriba, simplemente lo re-lanzamos.
        // Si es un error genérico de "Failed to fetch", lo envolvemos.
        if (error.message.toLowerCase().includes("failed to fetch") && 
            !error.message.includes("HTTP") && 
            !error.message.includes("JSON válido") &&
            !error.message.includes("respuesta opaca")) {
             throw new Error(`Fallo de red al obtener recurso: ${url || endpoint_con_params}. Verifica la conexión y la URL.`);
        }
        throw error; // Re-lanzar el error si ya tiene un mensaje descriptivo o es uno de nuestros errores personalizados.
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