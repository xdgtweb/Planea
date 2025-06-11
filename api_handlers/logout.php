<?php
// CORRECCIÓN: Asegurarse de que la sesión se inicie antes de manipularla.
// Aunque en api.php ya se inicia, es una buena práctica verificarlo.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 1. Vaciar el array de la sesión
$_SESSION = [];

// 2. Si se usan cookies de sesión, eliminarlas.
// Esto destruirá la sesión, y no solo los datos de la sesión.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Finalmente, destruir la sesión.
session_destroy();

// Enviar una respuesta de éxito al cliente
json_response(['success' => true, 'message' => 'Sesión cerrada exitosamente.']);
?>