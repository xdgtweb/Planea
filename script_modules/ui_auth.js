import { login, register, logout, loginWithGoogle } from './auth.js';
import { cargarModos } from './app.js'; // CORRECCIÓN: Se importa 'cargarModos' en lugar de 'loadInitialApp'.

let googleInitialized = false;

// Función para mostrar la pantalla de login/registro
export function showLoginScreen() {
    const mainContent = document.getElementById('main-content');
    
    const initialLoader = document.getElementById('initial-loader');
    if (initialLoader) {
        initialLoader.style.display = 'none';
    }
    
    mainContent.innerHTML = `
        <div class="auth-container">
            <div class="auth-form-container">
                <div id="auth-message" class="auth-message"></div>
                <div id="login-form">
                    <h2>Iniciar Sesión</h2>
                    <form id="loginForm">
                        <input type="email" id="login-email" placeholder="Correo Electrónico" required>
                        <input type="password" id="login-password" placeholder="Contraseña" required>
                        <button type="submit">Entrar</button>
                    </form>
                     <div id="google-btn-container" class="google-btn-container"></div>
                    <p>¿No tienes cuenta? <a href="#" id="show-register">Regístrate</a></p>
                </div>
                <div id="register-form" style="display: none;">
                    <h2>Registro</h2>
                    <form id="registerForm">
                        <input type="text" id="register-username" placeholder="Nombre de usuario" required>
                        <input type="email" id="register-email" placeholder="Correo Electrónico" required>
                        <input type="password" id="register-password" placeholder="Contraseña (mín. 8 caracteres)" required minlength="8">
                        <button type="submit">Registrarse</button>
                    </form>
                    <p>¿Ya tienes cuenta? <a href="#" id="show-login">Inicia sesión</a></p>
                </div>
            </div>
        </div>
    `;

    setupAuthEventListeners();
    initializeGoogleSignIn();
}

// Función para inicializar Google Sign-In
function initializeGoogleSignIn() {
    if (googleInitialized || typeof google === 'undefined') {
        return;
    }
    try {
        google.accounts.id.initialize({
            // ¡ATENCIÓN! Recuerda poner tu ID de cliente real aquí.
            client_id: '356486997376-1ctts9tjobjh4bl2b70lcms4dq98se3l.apps.googleusercontent.com',
            callback: handleGoogleSignIn
        });
        const googleBtnContainer = document.getElementById('google-btn-container');
        if (googleBtnContainer) {
             google.accounts.id.renderButton(
                googleBtnContainer,
                { theme: "outline", size: "large", type: "standard", text: "signin_with", shape: "rectangular" }
            );
        }
       
        googleInitialized = true;
    } catch (error) {
        console.error("Error inicializando Google Sign-In:", error);
        const googleBtnContainer = document.getElementById('google-btn-container');
        if(googleBtnContainer) {
            googleBtnContainer.innerHTML = 'El inicio de sesión con Google no está disponible.';
        }
    }
}

// Callback para manejar la respuesta de Google
async function handleGoogleSignIn(response) {
    const messageDiv = document.getElementById('auth-message');
    try {
        const result = await loginWithGoogle(response.credential);
        if (result.success) {
            cargarModos(); // CORRECCIÓN: Se llama a la función correcta.
        } else {
            messageDiv.textContent = result.message || 'Error en el inicio de sesión con Google.';
            messageDiv.className = 'auth-message error';
        }
    } catch (error) {
        messageDiv.textContent = 'Ocurrió un error inesperado. Inténtalo de nuevo.';
        messageDiv.className = 'auth-message error';
    }
}


// Configurar los event listeners para los formularios
function setupAuthEventListeners() {
    const loginForm = document.getElementById('loginForm');
    const registerForm = document.getElementById('registerForm');
    const messageDiv = document.getElementById('auth-message');

    loginForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = document.getElementById('login-email').value;
        const password = document.getElementById('login-password').value;
        const result = await login(email, password);
        if (result.success) {
            cargarModos(); // CORRECCIÓN: Se llama a la función correcta.
        } else {
            messageDiv.textContent = result.message;
            messageDiv.className = 'auth-message error';
        }
    });

    registerForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const username = document.getElementById('register-username').value;
        const email = document.getElementById('register-email').value;
        const password = document.getElementById('register-password').value;
        const result = await register(username, email, password);
        if (result.success) {
            messageDiv.textContent = result.message;
            messageDiv.className = 'auth-message success';
            showLogin();
        } else {
            messageDiv.textContent = result.message;
            messageDiv.className = 'auth-message error';
        }
    });

    document.getElementById('show-register').addEventListener('click', (e) => {
        e.preventDefault();
        showRegister();
    });

    document.getElementById('show-login').addEventListener('click', (e) => {
        e.preventDefault();
        showLogin();
    });
}

function showLogin() {
    document.getElementById('login-form').style.display = 'block';
    document.getElementById('register-form').style.display = 'none';
    document.getElementById('auth-message').textContent = '';
    document.getElementById('auth-message').className = 'auth-message';
}

function showRegister() {
    document.getElementById('login-form').style.display = 'none';
    document.getElementById('register-form').style.display = 'block';
    document.getElementById('auth-message').textContent = '';
    document.getElementById('auth-message').className = 'auth-message';
}