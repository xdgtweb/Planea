<?php
if (!isset($_SESSION['usuario_id'])) {
    // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
    json_response(['error' => 'Acceso no autorizado'], 401);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"));

    $idSubObjetivo = $data->idSubObjetivoDB ?? 0;
    $completado = isset($data->completado) ? ($data->completado ? 1 : 0) : 0;

    if ($idSubObjetivo <= 0) {
        // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
        json_response(['error' => 'ID de sub-objetivo inválido'], 400);
        return;
    }

    // Para seguridad, verificamos que el sub-objetivo que se quiere actualizar
    // pertenece a un objetivo del usuario que ha iniciado sesión.
    $stmt = $mysqli->prepare("
        UPDATE sub_objetivos so
        JOIN objetivos o ON so.objetivo_id = o.id
        SET so.completado = ?
        WHERE so.id = ? AND o.usuario_id = ?
    ");
    $stmt->bind_param("iii", $completado, $idSubObjetivo, $usuario_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
            json_response(['success' => true]);
        } else {
            // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
            json_response(['error' => 'Sub-objetivo no encontrado o sin cambios necesarios'], 404);
        }
    } else {
        // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
        json_response(['error' => 'No se pudo actualizar el estado del sub-objetivo'], 500);
    }
    $stmt->close();

} else {
    // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
    json_response(['error' => 'Método no permitido'], 405);
}
?>