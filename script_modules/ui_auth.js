// script_modules/ui_auth.js

import { login, register, logout, loginWithGoogle } from './auth.js';
import { cargarModos, initAppModules } from './app.js';

const body = document.body;

function getAuthContainer() {
    let container = document.getElementById('auth-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'auth-container';
        container.className = 'contenedor-principal';
        body.appendChild(container);
    }
    return container;
}

export function showLoginScreen() {
    const appContainer = document.getElementById('app-container');
    if (appContainer) appContainer.style.display = 'none';

    const authContainer = getAuthContainer();
    authContainer.style.display = 'block';

    authContainer.innerHTML = `
        <div id="login-form">
            <h2 style="text-align:center;">Iniciar Sesión en Planea</h2>
            
            <div id="google-button-container" style="display: flex; justify-content: center; margin-bottom: 10px; min-height: 40px;"></div>
            
            <div class="auth-divider">O</div>

            <input type="email" id="login-email" placeholder="Email" required autocomplete="email">
            <input type="password" id="login-password" placeholder="Contraseña" required autocomplete="current-password">
            <button id="login-btn">Entrar</button>
            <p>¿No tienes cuenta? <a href="#" id="show-register">Regístrate</a></p>
        </div>
        <div id="register-form" style="display:none;">
            <h2 style="text-align:center;">Crear Cuenta</h2>
            <input type="text" id="register-username" placeholder="Nombre de usuario" required autocomplete="username">
            <input type="email" id="register-email" placeholder="Email" required autocomplete="email">
            <input type="password" id="register-password" placeholder="Contraseña (mín. 6 caracteres)" required autocomplete="new-password">
            <button id="register-btn">Registrar</button>
            <p>¿Ya tienes cuenta? <a href="#" id="show-login">Inicia sesión</a></p>
        </div>
        <div id="auth-message" style="color: red; text-align: center; margin-top: 10px; min-height: 20px;"></div>
    `;

    // Lógica para inicializar el botón de Google de forma segura
    const inicializarBotonGoogle = () => {
        if (typeof google === 'undefined' || !google.accounts || !google.accounts.id) {
            setTimeout(inicializarBotonGoogle, 100);
            return;
        }

        google.accounts.id.initialize({
            client_id: "356486997376-1ctts9tjobjh4bl2b70lcms4dq98se3l.apps.googleusercontent.com", // TU ID YA ESTÁ AQUÍ
            callback: async (response) => {
                const messageEl = document.getElementById('auth-message');
                if (messageEl) messageEl.textContent = 'Verificando con Google...';
                try {
                    await loginWithGoogle(response.credential);
                } catch (error) {
                    if (messageEl) messageEl.textContent = 'Error: ' + error.message;
                }
            }
        });

        google.accounts.id.renderButton(
            document.getElementById("google-button-container"),
            { theme: "outline", size: "large", text: "signin_with", shape: "rectangular", logo_alignment: "left" }
        );
    };
    
    inicializarBotonGoogle();

    // Listeners para los formularios de login/registro normal
    document.getElementById('show-register').addEventListener('click', (e) => {
        e.preventDefault();
        document.getElementById('login-form').style.display = 'none';
        document.getElementById('register-form').style.display = 'block';
    });
    document.getElementById('show-login').addEventListener('click', (e) => {
        e.preventDefault();
        document.getElementById('register-form').style.display = 'none';
        document.getElementById('login-form').style.display = 'block';
    });
    document.getElementById('login-btn').addEventListener('click', async () => {
        const email = document.getElementById('login-email').value;
        const password = document.getElementById('login-password').value;
        await login(email, password).catch(err => {
            document.getElementById('auth-message').textContent = 'Error: ' + err.message;
        });
    });
    document.getElementById('register-btn').addEventListener('click', async () => {
        const username = document.getElementById('register-username').value;
        const email = document.getElementById('register-email').value;
        const password = document.getElementById('register-password').value;
        try {
            await register(username, email, password);
            document.getElementById('auth-message').textContent = '¡Registro exitoso! Ahora puedes iniciar sesión.';
            document.getElementById('show-login').click();
        } catch (error) {
             document.getElementById('auth-message').textContent = 'Error: ' + error.message;
        }
    });
}

export function loadInitialApp(user) {
    const authContainer = document.getElementById('auth-container');
    if (authContainer) authContainer.style.display = 'none';
    let appContainer = document.getElementById('app-container');
    if (!appContainer) {
        appContainer = document.createElement('div');
        appContainer.id = 'app-container';
        body.appendChild(appContainer);
    }
    appContainer.style.display = 'block';
    appContainer.innerHTML = `
        <div class="contenedor-principal">
            <header>
                <h1 id="app-title">Nuestro Plan de Vida</h1>
                <p class="descripcion-pagina">Bienvenid@, ${user.username}! Un espacio para vuestras metas.</p>
                <button id="logout-btn" title="Cerrar Sesión">&#x2715;</button>
            </header>
            <nav id="nav-modes-container" class="bottom-nav"></nav>
            <main id="modo-contenido"><p>Cargando...</p></main>
            <div id="formulario-dinamico-contenedor" class="form-overlay hidden" role="dialog" aria-modal="true" aria-hidden="true">
                <div class="form-modal"></div>
            </div>
        </div>
    `;
    document.getElementById('logout-btn').addEventListener('click', logout);
    initAppModules();
    cargarModos(); 
}