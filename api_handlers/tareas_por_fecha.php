<?php
if (!isset($_SESSION['usuario_id'])) {
    json_response(['error' => 'Acceso no autorizado'], 401);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $fecha = $_GET['fecha'] ?? null;

    if (!$fecha) {
        json_response(['error' => 'Parámetro de fecha requerido'], 400);
        return;
    }

    // Consulta para obtener todas las tareas y subtareas programadas para una fecha específica
    // Incluimos activo = 1, pero si quieres ver inactivas en el detalle, ajusta esto.
    $stmt = $mysqli->prepare("
        SELECT id, texto, completado, tipo, parent_id 
        FROM tareas_diarias 
        WHERE usuario_id = ? AND fecha_inicio = ? AND activo = 1
        ORDER BY id ASC -- Ordenamos por ID para ayudar a la reconstrucción y mantener el orden de creación
    ");
    if (!$stmt) {
        json_response(['error' => 'Error al preparar la consulta: ' . $mysqli->error], 500);
        return;
    }
    $stmt->bind_param("is", $usuario_id, $fecha);
    $stmt->execute();
    $result = $stmt->get_result();

    $tasks_by_id = [];
    while ($row = $result->fetch_assoc()) {
        $row['completado'] = (bool)$row['completado']; // Convertir a booleano para JS
        $row['subtareas'] = []; // Inicializar un array vacío para subtareas anidadas
        $tasks_by_id[$row['id']] = $row; // Almacenar tareas por su ID
    }

    $final_output = []; // Array final con la estructura anidada

    // Primera pasada: construir la jerarquía
    foreach ($tasks_by_id as $id => &$task) { // Iterar por referencia para poder modificar el array original
        if ($task['parent_id'] === null) {
            // Es una tarea de nivel superior (título o tarea suelta)
            $final_output[] = &$task; // Añadirla al resultado final por referencia
        } else {
            // Es una subtarea, adjuntarla a su padre
            $parent_id = $task['parent_id'];
            if (isset($tasks_by_id[$parent_id])) {
                $tasks_by_id[$parent_id]['subtareas'][] = &$task; // Adjuntar la subtarea al padre por referencia
            }
            // Si la subtarea no tiene un padre válido en este conjunto de datos,
            // no se añade a final_output como tarea raíz.
        }
    }

    // Opcional: Asegurarse de que las subtareas dentro de cada tarea principal estén ordenadas
    foreach ($final_output as &$task) {
        if (isset($task['subtareas']) && count($task['subtareas']) > 0) {
            usort($task['subtareas'], function($a, $b) {
                return $a['id'] <=> $b['id']; // Ordenar subtareas por ID
            });
        }
    }
    
    json_response($final_output);

    $stmt->close();
} else {
    json_response(['error' => 'Método no permitido'], 405);
}

?>