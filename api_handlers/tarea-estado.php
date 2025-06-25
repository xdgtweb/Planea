<?php
// api_handlers/tarea-estado.php
// Este gestor se dedica exclusivamente a cambiar el estado 'completado' de una tarea.

// Reanudamos la sesión existente para acceder a las variables de sesión.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Incluimos la configuración de la base de datos y las funciones de utilidad.
require_once '../db_config.php';

// Verificamos que el usuario haya iniciado sesión.
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Acceso no autorizado']);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$method = $_SERVER['REQUEST_METHOD'];

// Este endpoint solo acepta peticiones que simulan ser POST.
if ($method === 'POST') {
    // Leemos el cuerpo de la petición, que debería ser un JSON.
    // Usamos $_POST porque el enrutador principal ya ha procesado el JSON y lo ha puesto ahí.
    $data = $_POST;

    // Obtenemos los datos del payload.
    $id_tarea = $data['id'] ?? 0;
    $completado = isset($data['completado']) ? ($data['completado'] ? 1 : 0) : 0;

    // Validamos que el ID de la tarea sea un número válido y positivo.
    if ($id_tarea <= 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'ID de tarea inválido']);
        exit;
    }

    // Preparamos la consulta SQL para actualizar el estado 'completado' de la tarea.
    // Para mayor seguridad, nos aseguramos de que la tarea pertenezca al usuario autenticado.
    $stmt = $mysqli->prepare("
        UPDATE tareas_diarias
        SET completado = ?
        WHERE id = ? AND usuario_id = ?
    ");
    
    // Vinculamos los parámetros a la consulta para prevenir inyección SQL.
    $stmt->bind_param("iii", $completado, $id_tarea, $usuario_id);

    // Ejecutamos la consulta.
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $response = ['success' => true, 'message' => 'Estado de la tarea actualizado.'];
        } else {
            $response = ['success' => true, 'message' => 'Tarea no encontrada o sin cambios necesarios.'];
        }
    } else {
        http_response_code(500);
        $response = ['error' => 'No se pudo actualizar el estado de la tarea.', 'details' => $stmt->error];
    }
    // Cerramos el statement.
    $stmt->close();

} else {
    http_response_code(405);
    $response = ['error' => 'Método no permitido'];
}

header('Content-Type: application/json');
echo json_encode($response);
exit;

?>