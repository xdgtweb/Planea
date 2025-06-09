<?php
// api_handlers/session_status.php

if (isset($_SESSION['usuario_id']) && isset($_SESSION['nombre_usuario'])) {
    jsonResponse([
        "loggedIn" => true,
        "user" => [
            "id" => $_SESSION['usuario_id'],
            "username" => $_SESSION['nombre_usuario']
        ]
    ]);
} else {
    jsonResponse(["loggedIn" => false]);
}
?>