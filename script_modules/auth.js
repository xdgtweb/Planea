// script_modules/auth.js

import { fetchData } from './utils.js';
import { loadInitialApp, showLoginScreen } from './ui_auth.js';

let currentUser = null;

export function getCurrentUser() {
    return currentUser;
}

export async function checkLoginStatus() {
    try {
        const data = await fetchData('session-status');
        if (data.loggedIn) {
            currentUser = data.user;
            loadInitialApp(currentUser);
        } else {
            currentUser = null;
            showLoginScreen();
        }
    } catch (error) {
        console.error("Error checking login status:", error);
        showLoginScreen();
    }
}

export async function login(email, password) {
    await fetchData('login', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password })
    });
    await checkLoginStatus();
}

export async function register(username, email, password) {
    return fetchData('register', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, email, password })
    });
}

export async function logout() {
    try {
        await fetchData('logout', { method: 'POST' });
    } catch (e) {
        console.error("Error during logout, proceeding anyway:", e);
    } finally {
        currentUser = null;
        checkLoginStatus();
    }
}

export async function loginWithGoogle(googleToken) {
    await fetchData('google-signin', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ token: googleToken })
    });
    await checkLoginStatus();
}