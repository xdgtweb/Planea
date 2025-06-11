<?php
if (!isset($_SESSION['usuario_id'])) {
    // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
    json_response(['error' => 'Acceso no autorizado'], 401);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $fecha = $_GET['fecha'] ?? null;

    if (!$fecha) {
        // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
        json_response(['error' => 'Parámetro de fecha requerido'], 400);
        return;
    }

    // Consulta para obtener todas las tareas y subtareas programadas para una fecha específica
    // Se asume que una tarea "pertenece" a un día si su fecha de inicio es ese día.
    $stmt = $mysqli->prepare("
        SELECT id, texto, completado, tipo, parent_id 
        FROM tareas_diarias 
        WHERE usuario_id = ? AND fecha_inicio = ? AND activo = 1
        ORDER BY parent_id ASC, id ASC
    ");
    $stmt->bind_param("is", $usuario_id, $fecha);
    $stmt->execute();
    $result = $stmt->get_result();

    $tareas = [];
    while ($row = $result->fetch_assoc()) {
        $tareas[$row['id']] = $row;
    }

    // Organizar las subtareas dentro de sus títulos
    $tareas_del_dia = [];
    foreach ($tareas as $id => &$tarea) {
        if ($tarea['parent_id'] !== null && isset($tareas[$tarea['parent_id']])) {
            // Si es una subtarea, la añadimos a su padre
            if (!isset($tareas[$tarea['parent_id']]['subtareas'])) {
                $tareas[$tarea['parent_id']]['subtareas'] = [];
            }
            $tareas[$tarea['parent_id']]['subtareas'][] = $tarea;
        } else {
            // Si es un título o una subtarea huérfana, la añadimos al nivel principal
            $tareas_del_dia[] = $tarea;
        }
    }
    
    // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
    json_response($tareas_del_dia);

    $stmt->close();
} else {
    // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
    json_response(['error' => 'Método no permitido'], 405);
}

?>