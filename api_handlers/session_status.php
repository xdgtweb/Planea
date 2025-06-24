<?php

// CORRECCIÓN: Nos aseguramos de que la sesión esté iniciada, aunque api.php ya lo hace.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['usuario_id'])) {
    // CORRECCIÓN: Cambiado 'jsonResponse' a 'json_response' para que coincida con la definición en api.php
    json_response([
        'loggedIn' => true, 
        'username' => $_SESSION['username'], 
        'usuario_id' => $_SESSION['usuario_id'],
        'email_verified' => $_SESSION['email_verified'] ?? false, // Nuevo: estado de verificación del email
        'is_admin' => $_SESSION['is_admin'] ?? false // Nuevo: rol de administrador
    ]);
} else {
    // CORRECCIÓN: Cambiado 'jsonResponse' a 'json_response'
    json_response(['loggedIn' => false]);
}
?>