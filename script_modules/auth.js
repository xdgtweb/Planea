import { fetchData } from './utils.js';
import { showLoginScreen } from './ui_auth.js';
import { cargarModos } from './app.js';

export async function login(email, password) {
    try {
        const data = await fetchData('/login', 'POST', { email, password });
        return data;
    } catch (error) {
        console.error('Error en el login:', error);
        return { success: false, message: error.message || 'Error de conexión.' };
    }
}

export async function register(username, email, password) {
    try {
        const data = await fetchData('/register', 'POST', { username, email, password });
        return data;
    } catch (error) {
        console.error('Error en el registro:', error);
        return { success: false, message: error.message || 'Error de conexión.' };
    }
}

export async function logout() {
    try {
        const data = await fetchData('/logout', 'POST');
        return data;
    } catch (error) {
        console.error('Error al cerrar sesión:', error);
        return { success: false, message: 'Error de conexión.' };
    }
}

export async function checkLoginStatus() {
    console.log("[auth.js] 6. Entrando en checkLoginStatus...");
    try {
        const data = await fetchData('/session-status', 'GET');
        // DEBUG: ¿Qué datos recibe esta función de fetchData?
        console.log("[auth.js] 7. checkLoginStatus recibió de fetchData:", data);
        return data;
    } catch (error) {
        console.error('[auth.js] ERROR en checkLoginStatus:', error);
        return { loggedIn: false };
    }
}

export async function loginWithGoogle(token) {
    try {
        const data = await fetchData('/google-signin', 'POST', { token });
        return data;
    } catch (error) {
        console.error('Error en el login con Google:', error);
        return { success: false, message: error.message || 'Error de conexión.' };
    }
}