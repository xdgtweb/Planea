<?php
// Asegurarse de que el usuario está logueado para acceder a los modos
if (!isset($_SESSION['usuario_id'])) {
    json_response(['error' => 'Acceso no autorizado'], 401);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // No se necesita el id de usuario para obtener los modos, son genéricos
    $stmt = $mysqli->prepare("SELECT id, nombre FROM modos");
    $stmt->execute();
    $result = $stmt->get_result();

    $modos = [];
    while ($row = $result->fetch_assoc()) {
        $modos[] = $row;
    }

    // CORRECCIÓN: Se cambia 'jsonResponse' por 'json_response'
    json_response($modos);
    
    $stmt->close();
} else {
    // CORRECCIÓN: Se cambia 'jsonResponse' por 'json_response'
    json_response(['error' => 'Método no permitido'], 405);
}
?>