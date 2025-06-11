<?php
// Primero, nos aseguramos de que solo los usuarios logueados puedan acceder.
if (!isset($_SESSION['usuario_id'])) {
    json_response(['error' => 'Acceso no autorizado'], 401);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$method = $_SERVER['REQUEST_METHOD'];

// Manejar la petición según el método HTTP
switch ($method) {
    case 'GET':
        // --- OBTENER TAREAS DIARIAS ---
        $stmt_tasks = $mysqli->prepare("SELECT id, texto, completado, tipo FROM tareas_diarias WHERE usuario_id = ? AND parent_id IS NULL ORDER BY id");
        $stmt_tasks->bind_param("i", $usuario_id);
        $stmt_tasks->execute();
        $result_tasks = $stmt_tasks->get_result();
        
        $tareas = [];
        while ($task = $result_tasks->fetch_assoc()) {
            if ($task['tipo'] === 'titulo') {
                $stmt_subtasks = $mysqli->prepare("SELECT id, texto, completado FROM tareas_diarias WHERE parent_id = ? ORDER BY id");
                $stmt_subtasks->bind_param("i", $task['id']);
                $stmt_subtasks->execute();
                $result_subtasks = $stmt_subtasks->get_result();
                $subtareas = [];
                while ($subtask = $result_subtasks->fetch_assoc()) {
                    $subtareas[] = $subtask;
                }
                $task['subtareas'] = $subtareas;
                $stmt_subtasks->close();
            }
            $tareas[] = $task;
        }
        
        json_response($tareas);
        $stmt_tasks->close();
        break;

    case 'POST':
        // --- CREAR NUEVA TAREA O SUBTAREA ---
        $data = json_decode(file_get_contents("php://input"));
        $texto = $data->texto ?? '';
        $tipo = $data->tipo ?? 'subtarea'; // 'titulo' o 'subtarea'
        $parent_id = $data->parent_id ?? null;

        if (empty($texto)) {
            json_response(['error' => 'El texto no puede estar vacío'], 400);
            return;
        }

        // Si es un título, puede tener subtareas para crear en una sola transacción
        if ($tipo === 'titulo' && isset($data->subtareas) && is_array($data->subtareas)) {
            $mysqli->begin_transaction();
            try {
                $stmt_parent = $mysqli->prepare("INSERT INTO tareas_diarias (usuario_id, texto, tipo) VALUES (?, ?, ?)");
                $stmt_parent->bind_param("iss", $usuario_id, $texto, $tipo);
                $stmt_parent->execute();
                $new_parent_id = $mysqli->insert_id;
                $stmt_parent->close();

                $stmt_sub = $mysqli->prepare("INSERT INTO tareas_diarias (usuario_id, texto, tipo, parent_id) VALUES (?, ?, 'subtarea', ?)");
                foreach ($data->subtareas as $subtarea_texto) {
                    if (!empty($subtarea_texto)) {
                        $stmt_sub->bind_param("isi", $usuario_id, $subtarea_texto, $new_parent_id);
                        $stmt_sub->execute();
                    }
                }
                $stmt_sub->close();
                $mysqli->commit();
                json_response(['success' => true, 'id' => $new_parent_id], 201);
            } catch (Exception $e) {
                $mysqli->rollback();
                json_response(['error' => 'Error en la transacción: ' . $e->getMessage()], 500);
            }
        } else {
            // Crear una tarea o subtarea simple
            $stmt = $mysqli->prepare("INSERT INTO tareas_diarias (usuario_id, texto, tipo, parent_id) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("issi", $usuario_id, $texto, $tipo, $parent_id);
            if ($stmt->execute()) {
                json_response(['success' => true, 'id' => $mysqli->insert_id], 201);
            } else {
                json_response(['error' => 'No se pudo crear la tarea'], 500);
            }
            $stmt->close();
        }
        break;

    case 'PUT':
        // --- ACTUALIZAR UNA TAREA (MARCAR COMO COMPLETADA) ---
        $data = json_decode(file_get_contents("php://input"));
        $id = $data->id ?? 0;
        $completado = isset($data->completado) ? ($data->completado ? 1 : 0) : 0;

        if ($id <= 0) {
            json_response(['error' => 'ID de tarea inválido'], 400);
            return;
        }

        $stmt = $mysqli->prepare("UPDATE tareas_diarias SET completado = ? WHERE id = ? AND usuario_id = ?");
        $stmt->bind_param("iii", $completado, $id, $usuario_id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                json_response(['success' => true]);
            } else {
                json_response(['error' => 'Tarea no encontrada o sin cambios'], 404);
            }
        } else {
            json_response(['error' => 'No se pudo actualizar la tarea'], 500);
        }
        $stmt->close();
        break;

    case 'DELETE':
        // --- ELIMINAR UNA TAREA ---
        $data = json_decode(file_get_contents("php://input"));
        $id = $data->id ?? 0;

        if ($id <= 0) {
            json_response(['error' => 'ID de tarea inválido'], 400);
            return;
        }
        
        // Antes de borrar, averiguar si es un 'titulo' para borrar sus subtareas
        $stmt_check = $mysqli->prepare("SELECT tipo FROM tareas_diarias WHERE id = ? AND usuario_id = ?");
        $stmt_check->bind_param("ii", $id, $usuario_id);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        if ($row = $result_check->fetch_assoc()) {
            if ($row['tipo'] === 'titulo') {
                // Es un título, borrar subtareas primero
                $stmt_delete_sub = $mysqli->prepare("DELETE FROM tareas_diarias WHERE parent_id = ? AND usuario_id = ?");
                $stmt_delete_sub->bind_param("ii", $id, $usuario_id);
                $stmt_delete_sub->execute();
                $stmt_delete_sub->close();
            }
        }
        $stmt_check->close();

        // Borrar la tarea principal (o la subtarea)
        $stmt = $mysqli->prepare("DELETE FROM tareas_diarias WHERE id = ? AND usuario_id = ?");
        $stmt->bind_param("ii", $id, $usuario_id);

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                json_response(['success' => true]);
            } else {
                json_response(['error' => 'Tarea no encontrada'], 404);
            }
        } else {
            json_response(['error' => 'No se pudo eliminar la tarea'], 500);
        }
        $stmt->close();
        break;

    default:
        json_response(['error' => 'Método no permitido'], 405);
        break;
}
?>