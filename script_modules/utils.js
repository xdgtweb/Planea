import { API_BASE_URL } from './config.js';

// --- FUNCIONES DE LÓGICA DE UI ---

export function obtenerClaseDeEstado(porcentaje) {
    if (porcentaje >= 100) return 'estado-completado';
    if (porcentaje > 0) return 'estado-en-progreso';
    return 'estado-pendiente';
}

export function lanzarAnimacionCelebracion(confettiInstance, event) {
    const rect = event.target.getBoundingClientRect();
    const x = (rect.left + rect.right) / 2 / window.innerWidth;
    const y = (rect.top + rect.bottom) / 2 / window.innerHeight;
    confettiInstance({
        particleCount: 100,
        spread: 70,
        origin: { x, y }
    });
}

export function obtenerValorPrioridad(fecha_estimada) {
    if (!fecha_estimada) return 3; // Sin fecha, baja prioridad
    const lower_fecha = fecha_estimada.toLowerCase();
    if (lower_fecha.includes('corto')) return 1; // Corto plazo, alta prioridad
    if (lower_fecha.includes('medio')) return 2; // Medio plazo, media prioridad
    if (lower_fecha.includes('largo')) return 3; // Largo plazo, baja prioridad
    return 3; // Default
}

// --- FUNCIÓN DE COMUNICACIÓN CON API ---

/**
 * Función centralizada para hacer peticiones a la API.
 * @param {string} endpoint_con_params - El endpoint de la API, ej. '/login' o '/tareas?id=1'
 * @param {string} method - El método HTTP (GET, POST, PUT, DELETE).
 * @param {object} body - El cuerpo de la petición para POST/PUT.
 * @returns {Promise<any>} Los datos JSON de la respuesta.
 */
export async function fetchData(endpoint_con_params, method = 'GET', body = null) {
    
    console.log(`[fetchData] Inicio. API_BASE_URL importado: ${API_BASE_URL}`);
    console.log(`[fetchData] Recibido endpoint_con_params: ${endpoint_con_params}`);
    
    // CORRECCIÓN: Se une la base de la URL con el endpoint directamente para que el enrutador de PHP lo reconozca.
    const url_final = `${API_BASE_URL}${endpoint_con_params}`;

    const options = {
        method,
        headers: {},
        credentials: 'include' // Importante para que las cookies de sesión se envíen
    };

    if (method === 'POST' || method === 'PUT' || method === 'DELETE') {
        if (body) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
    }
    
    console.log(`fetchData URL FINAL: ${url_final} CON OPCIONES:`, options);

    try {
        const response = await fetch(url_final, options);
        
        const contentType = response.headers.get("content-type");
        if (!response.ok) {
            let errorData;
            if (contentType && contentType.includes("application/json")) {
                errorData = await response.json();
            } else {
                const errorText = await response.text();
                errorData = { message: `El servidor devolvió un error no JSON (Estado: ${response.status}).`, details: errorText };
            }
            throw new Error(errorData.message || errorData.error || 'Error en la respuesta del servidor.');
        }

        if (response.status === 204 || !contentType || !contentType.includes("application/json")) {
            return { success: true };
        }

        return await response.json();

    } catch (error) {
        console.error(`Error en fetchData para '${url_final}':`, error.message);
        throw error;
    }
}