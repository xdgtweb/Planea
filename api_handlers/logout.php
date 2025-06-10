<?php
// api_handlers/logout.php

// Asegurarse de que la sesión está iniciada para poder destruirla
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Vaciar todas las variables de sesión
$_SESSION = array();

// Destruir la cookie de sesión
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finalmente, destruir la sesión.
session_destroy();

jsonResponse(["success" => true, "message" => "Sesión cerrada correctamente."]);
?>