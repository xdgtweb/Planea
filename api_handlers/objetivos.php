<?php
// Incluir configuración de la base de datos y funciones de respuesta JSON
require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../api.php'; // Para json_response

if (!isset($_SESSION['usuario_id'])) {
    json_response(['error' => 'Acceso no autorizado'], 401);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $fecha = $_GET['fecha'] ?? null;
        if (!$fecha) {
            json_response(['error' => 'Falta el parámetro fecha'], 400);
            return;
        }

        // Obtener tareas propias del usuario
        $query_own_tasks = "
            SELECT id, texto, completado, tipo, parent_id, regla_recurrencia, fecha_inicio, activo, emoji_anotacion, descripcion_anotacion, submission_group_id, usuario_id
            FROM tareas_diarias
            WHERE usuario_id = ? AND fecha_inicio = ?
        ";
        $stmt_own_tasks = $mysqli->prepare($query_own_tasks);
        $stmt_own_tasks->bind_param("is", $usuario_id, $fecha);
        $stmt_own_tasks->execute();
        $result_own_tasks = $stmt_own_tasks->get_result();

        $tasks_by_id = [];
        while ($task = $result_own_tasks->fetch_assoc()) {
            $task['subtareas'] = []; // Inicializar array de subtareas
            $task['is_shared'] = false; // Flag para tareas compartidas
            $task['reminder_times'] = []; // Placeholder para tiempos de recordatorio
            $tasks_by_id[$task['id']] = $task;
        }
        $stmt_own_tasks->close();

        // Obtener tareas compartidas con el usuario actual
        // Un JOIN con usuarios es necesario para obtener owner_username y owner_email
        $query_shared_tasks = "
            SELECT td.id, td.texto, td.completado, td.tipo, td.parent_id, td.regla_recurrencia, td.fecha_inicio, td.activo, td.emoji_anotacion, td.descripcion_anotacion, td.submission_group_id,
                   st.owner_user_id, u.username as owner_username, u.email as owner_email
            FROM shared_tasks st
            JOIN tareas_diarias td ON st.task_id = td.id
            JOIN usuarios u ON st.owner_user_id = u.id
            WHERE st.shared_with_user_id = ? AND td.fecha_inicio = ?
        ";
        $stmt_shared_tasks = $mysqli->prepare($query_shared_tasks);
        $stmt_shared_tasks->bind_param("is", $usuario_id, $fecha);
        $stmt_shared_tasks->execute();
        $result_shared_tasks = $stmt_shared_tasks->get_result();

        while ($task = $result_shared_tasks->fetch_assoc()) {
            // Solo añadir si la tarea no es ya propiedad del usuario actual (priorizar propiedad)
            if (!isset($tasks_by_id[$task['id']])) {
                $task['subtareas'] = []; // Inicializar array de subtareas para tareas compartidas
                $task['is_shared'] = true; // Marcar como tarea compartida
                $task['shared_owner_info'] = [
                    'user_id' => $task['owner_user_id'],
                    'username' => $task['owner_username'],
                    'email' => $task['owner_email']
                ];
                unset($task['owner_user_id'], $task['owner_username'], $task['owner_email']); // Limpiar campos extra
                $tasks_by_id[$task['id']] = $task;
            }
        }
        $stmt_shared_tasks->close();

        // Obtener tiempos de recordatorio para todas las tareas (propias y compartidas)
        // Se puede hacer una única consulta para todos los task_ids relevantes
        if (!empty($tasks_by_id)) {
            $task_ids = implode(',', array_keys($tasks_by_id));
            $query_reminders = "
                SELECT reminder_id, time_of_day
                FROM reminder_times
                WHERE reminder_id IN (
                    SELECT id FROM reminders WHERE tarea_id IN (?)
                )
            ";
            // Nota: El IN con un array de IDs directamente es complicado con prepared statements en bind_param.
            // Para simplificar, si el número de IDs es limitado, se pueden crear '?' dinámicamente.
            // Para producción, se recomienda una solución más robusta para evitar inyección SQL si $task_ids no es seguro.
            $stmt_reminders = $mysqli->query("
                SELECT rt.reminder_id, rt.time_of_day, r.tarea_id
                FROM reminder_times rt
                JOIN reminders r ON rt.reminder_id = r.id
                WHERE r.tarea_id IN ($task_ids)
            ");
            if ($stmt_reminders) {
                while ($reminder_time = $stmt_reminders->fetch_assoc()) {
                    $tarea_id = $reminder_time['tarea_id'];
                    if (isset($tasks_by_id[$tarea_id])) {
                        $tasks_by_id[$tarea_id]['reminder_times'][] = $reminder_time['time_of_day'];
                    }
                }
            }
        }


        $root_tasks = [];
        // Primer paso: Organizar todas las tareas por ID y separar tareas raíz
        foreach ($tasks_by_id as $id => &$task) {
            // Asegurarse de que subtareas siempre se inicialice como array
            if (!isset($task['subtareas'])) {
                $task['subtareas'] = [];
            }
            // MODIFICACIÓN CRUCIAL: Añadir temporalmente un array para control de duplicados de subtareas
            if (!isset($task['subtareas_indexed'])) {
                $task['subtareas_indexed'] = [];
            }

            if ($task['parent_id'] === null) {
                $root_tasks[$task['id']] = &$tasks_by_id[$task['id']]; // Mantener referencia a la tarea raíz
            }
        }

        // Segundo paso: Anidar subtareas bajo sus padres
        foreach ($tasks_by_id as $id => &$task) {
            if ($task['parent_id'] !== null) {
                $parent_id = $task['parent_id'];
                if (isset($tasks_by_id[$parent_id])) {
                    // INICIO DE LA MODIFICACIÓN: Evitar duplicados al anidar subtareas
                    // Solo añadir la subtarea si su ID no ha sido añadido ya a este padre
                    if (!isset($tasks_by_id[$parent_id]['subtareas_indexed'][$id])) {
                        $tasks_by_id[$parent_id]['subtareas'][] = &$task; // Añadir al array secuencial para el orden de salida JSON
                        $tasks_by_id[$parent_id]['subtareas_indexed'][$id] = true; // Marcar como añadida para evitar volver a añadir
                    }
                    // FIN DE LA MODIFICACIÓN
                    
                    // Si una subtarea es anidada, ya no debe ser una tarea raíz independiente
                    if (isset($root_tasks[$id])) {
                        unset($root_tasks[$id]);
                    }
                }
            }
        }

        // Limpiar el array temporal 'subtareas_indexed' antes de la salida JSON
        foreach ($tasks_by_id as $id => &$task) {
            if (isset($task['subtareas_indexed'])) {
                unset($task['subtareas_indexed']);
            }
        }

        // Convertir las tareas raíz en un array indexado numéricamente para la salida JSON
        $final_output = array_values($root_tasks);

        json_response($final_output);
        break;

    case 'POST':
        $data = json_decode(file_get_contents("php://input"), true);
        $id = $data['id'] ?? null;
        $tipo = $data['tipo'] ?? null;
        $texto = $data['texto'] ?? null;
        $fecha_inicio = $data['fecha_inicio'] ?? null; // Fecha de inicio de la tarea para validación

        // Validar que la tarea no sea de un día pasado
        $today = new DateTime();
        $today->setTime(0, 0, 0); // Normalizar a medianoche
        $taskDate = new DateTime($fecha_inicio);
        $taskDate->setTime(0, 0, 0); // Normalizar a medianoche

        if ($taskDate < $today) {
            json_response(['error' => 'No se pueden modificar tareas de días anteriores al actual.'], 400);
            return;
        }
        
        // Manejar el "método" real (PUT/DELETE/HARD_DELETE)
        $method_override = $data['_method'] ?? 'POST';

        if ($method_override === 'PUT') {
            $completado = isset($data['completado']) ? (bool)$data['completado'] : null;
            $activo = isset($data['activo']) ? (bool)$data['activo'] : null;
            $parent_id = $data['parent_id'] ?? null;
            $regla_recurrencia = $data['regla_recurrencia'] ?? null;
            $emoji_anotacion = $data['emoji_anotacion'] ?? null;
            $descripcion_anotacion = $data['descripcion_anotacion'] ?? null;

            if ($id === null || ($completado === null && $activo === null && $texto === null && $parent_id === null && $regla_recurrencia === null && $emoji_anotacion === null && $descripcion_anotacion === null)) {
                json_response(['error' => 'ID o datos para actualizar incompletos'], 400);
                return;
            }

            $set_clauses = [];
            $params = [];
            $types = "";

            if ($texto !== null) { $set_clauses[] = "texto = ?"; $params[] = $texto; $types .= "s"; }
            if ($completado !== null) { $set_clauses[] = "completado = ?"; $params[] = $completado; $types .= "i"; }
            if ($activo !== null) { $set_clauses[] = "activo = ?"; $params[] = $activo; $types .= "i"; }
            if ($parent_id !== null) { $set_clauses[] = "parent_id = ?"; $params[] = $parent_id; $types .= "i"; }
            if ($regla_recurrencia !== null) { $set_clauses[] = "regla_recurrencia = ?"; $params[] = $regla_recurrencia; $types .= "s"; }
            if ($emoji_anotacion !== null) { $set_clauses[] = "emoji_anotacion = ?"; $params[] = $emoji_anotacion; $types .= "s"; }
            if ($descripcion_anotacion !== null) { $set_clauses[] = "descripcion_anotacion = ?"; $params[] = $descripcion_anotacion; $types .= "s"; }


            if (empty($set_clauses)) {
                json_response(['error' => 'No hay datos para actualizar'], 400);
                return;
            }
            
            // Si se está actualizando un título, y se marca como inactivo, marcar también sus subtareas como inactivas
            if ($tipo === 'titulo' && $activo === false) {
                $stmt_subtasks_inactive = $mysqli->prepare("UPDATE tareas_diarias SET activo = FALSE WHERE parent_id = ? AND usuario_id = ?");
                $stmt_subtasks_inactive->bind_param("ii", $id, $usuario_id);
                $stmt_subtasks_inactive->execute();
                $stmt_subtasks_inactive->close();
            }
            
            // Si se está restaurando un título, restaurar también sus subtareas
            if ($tipo === 'titulo' && $activo === true) {
                 $stmt_subtasks_active = $mysqli->prepare("UPDATE tareas_diarias SET activo = TRUE WHERE parent_id = ? AND usuario_id = ?");
                 $stmt_subtasks_active->bind_param("ii", $id, $usuario_id);
                 $stmt_subtasks_active->execute();
                 $stmt_subtasks_active->close();
            }

            $query = "UPDATE tareas_diarias SET " . implode(", ", $set_clauses) . " WHERE id = ? AND usuario_id = ?";
            $params[] = $id;
            $params[] = $usuario_id;
            $types .= "ii";

            $stmt = $mysqli->prepare($query);
            if (!$stmt) {
                json_response(['error' => 'Error al preparar la consulta de actualización: ' . $mysqli->error], 500);
                return;
            }
            $stmt->bind_param($types, ...$params);

            if ($stmt->execute()) {
                json_response(['success' => true]);
            } else {
                json_response(['error' => 'No se pudo actualizar la tarea: ' . $stmt->error], 500);
            }
            $stmt->close();
        } elseif ($method_override === 'DELETE') {
            // Marcar tarea como inactiva (archivado)
            if ($id === null) {
                json_response(['error' => 'ID de tarea incompleto para eliminar'], 400);
                return;
            }

            // Marcar la tarea principal como inactiva
            $stmt = $mysqli->prepare("UPDATE tareas_diarias SET activo = FALSE WHERE id = ? AND usuario_id = ?");
            $stmt->bind_param("ii", $id, $usuario_id);
            
            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    // Si es un título, marcar también sus subtareas como inactivas
                    if ($tipo === 'titulo') {
                        $stmt_subtasks = $mysqli->prepare("UPDATE tareas_diarias SET activo = FALSE WHERE parent_id = ? AND usuario_id = ?");
                        $stmt_subtasks->bind_param("ii", $id, $usuario_id);
                        $stmt_subtasks->execute();
                        $stmt_subtasks->close();
                    }
                    json_response(['success' => true, 'message' => 'Tarea marcada como inactiva']);
                } else {
                    json_response(['error' => 'Tarea no encontrada o no autorizada'], 404);
                }
            } else {
                json_response(['error' => 'No se pudo archivar la tarea: ' . $stmt->error], 500);
            }
            $stmt->close();

        } elseif ($method_override === 'HARD_DELETE') {
            // Eliminar tarea permanentemente
            if ($id === null) {
                json_response(['error' => 'ID de tarea incompleto para eliminación permanente'], 400);
                return;
            }

            // Eliminar la tarea principal (ON DELETE CASCADE se encargará de las subtareas)
            $stmt = $mysqli->prepare("DELETE FROM tareas_diarias WHERE id = ? AND usuario_id = ?");
            $stmt->bind_param("ii", $id, $usuario_id);

            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    json_response(['success' => true, 'message' => 'Tarea eliminada permanentemente']);
                } else {
                    json_response(['error' => 'Tarea no encontrada o no autorizada'], 404);
                }
            } else {
                json_response(['error' => 'No se pudo eliminar la tarea permanentemente: ' . $stmt->error], 500);
            }
            $stmt->close();
        } else {
            // Crear nueva tarea
            $tipo = $data['tipo'] ?? 'titulo'; // 'titulo' o 'subtarea'
            $parent_id = $data['parent_id'] ?? null;
            $regla_recurrencia = $data['regla_recurrencia'] ?? 'NONE';
            $submission_group_id = $data['submission_group_id'] ?? 0;
            
            if (empty($texto) || empty($fecha_inicio)) {
                json_response(['error' => 'Texto y fecha de inicio son requeridos para crear una tarea'], 400);
                return;
            }

            if ($tipo === 'subtarea' && $parent_id === null) {
                json_response(['error' => 'Una subtarea debe tener un parent_id'], 400);
                return;
            }
            if ($tipo === 'titulo' && $parent_id !== null) {
                 json_response(['error' => 'Un título no puede tener un parent_id'], 400);
                 return;
            }

            $stmt = $mysqli->prepare("INSERT INTO tareas_diarias (usuario_id, texto, tipo, parent_id, regla_recurrencia, fecha_inicio, submission_group_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssisi", $usuario_id, $texto, $tipo, $parent_id, $regla_recurrencia, $fecha_inicio, $submission_group_id);

            if ($stmt->execute()) {
                json_response(['success' => true, 'id' => $mysqli->insert_id], 201);
            } else {
                json_response(['error' => 'No se pudo crear la tarea: ' . $stmt->error], 500);
            }
            $stmt->close();
        }
        break;

    default:
        json_response(['error' => 'Método no permitido'], 405);
        break;
}
?>