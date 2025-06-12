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
        $fecha_filtro = $_GET['fecha'] ?? null; 
        $activo_filtro = $_GET['activo'] ?? null; 

        // Asegúrate de seleccionar la nueva columna submission_group_id aquí
        $query = "SELECT id, texto, completado, tipo, parent_id, regla_recurrencia, fecha_inicio, activo, emoji_anotacion, descripcion_anotacion, submission_group_id FROM tareas_diarias WHERE usuario_id = ?"; 
        $params = [$usuario_id];
        $types = "i";

        if ($fecha_filtro) {
            $query .= " AND fecha_inicio = ?";
            $params[] = $fecha_filtro;
            $types .= "s";
        }
        if ($activo_filtro !== null) { 
            $query .= " AND activo = ?";
            $params[] = (int)$activo_filtro;
            $types .= "i";
        }
        
        // Ordenar para ayudar en la reconstrucción de la jerarquía
        $query .= " ORDER BY id ASC"; // Ordenar solo por ID para mantener el orden de creación original

        $stmt_tasks = $mysqli->prepare($query);
        if (!$stmt_tasks) {
            json_response(['error' => 'Error al preparar la consulta GET: ' . $mysqli->error], 500);
            return;
        }

        $stmt_tasks->bind_param($types, ...$params);
        $stmt_tasks->execute();
        $result = $stmt_tasks->get_result();
        
        $tasks_by_id = [];
        $root_tasks = [];

        // Primera pasada: Organizar todas las tareas por ID y separar las tareas raíz
        while ($task = $result->fetch_assoc()) {
            $task['completado'] = (bool)$task['completado'];
            $task['activo'] = (bool)$task['activo'];
            $task['subtareas'] = []; // Inicializar array de subtareas
            $tasks_by_id[$task['id']] = $task;

            if ($task['parent_id'] === null) {
                $root_tasks[$task['id']] = &$tasks_by_id[$task['id']]; // Mantener referencia a la tarea raíz
            }
        }

        // Segunda pasada: Anidar las subtareas bajo sus padres
        foreach ($tasks_by_id as $id => &$task) {
            if ($task['parent_id'] !== null) {
                $parent_id = $task['parent_id'];
                if (isset($tasks_by_id[$parent_id])) {
                    $tasks_by_id[$parent_id]['subtareas'][] = &$task; // Anidar por referencia
                    // Si una subtarea está anidada, ya no debe ser una tarea raíz independiente
                    if (isset($root_tasks[$id])) {
                        unset($root_tasks[$id]);
                    }
                }
            }
        }

        // Convertir el array de tareas raíz a un array indexado para la respuesta JSON
        $final_output = array_values($root_tasks);

        // Opcional: Asegurarse de que las subtareas dentro de cada título estén ordenadas
        foreach ($final_output as &$task) {
            if (isset($task['subtareas']) && count($task['subtareas']) > 0) {
                usort($task['subtareas'], function($a, $b) {
                    return $a['id'] <=> $b['id']; // Ordenar subtareas por ID
                });
            }
        }
        
        json_response($final_output);
        $stmt_tasks->close();
        break;


    case 'POST':
        $data = json_decode(file_get_contents("php://input"), true); // Usar 'true' para obtener un array asociativo
        $_method_override = $data['_method'] ?? $method; // Obtener el método real, por defecto es POST si no se especifica _method

        // --- MANEJO DE OPERACIONES DE ACTUALIZACIÓN (PUT SIMULADO) ---
        if ($_method_override === 'PUT') {
            $id = $data['id'] ?? 0;
            $texto_update = $data['texto'] ?? null;
            $fecha_inicio_update = $data['fecha_inicio'] ?? null;
            $regla_recurrencia_update = $data['regla_recurrencia'] ?? null;
            $activo_update = isset($data['activo']) ? ($data['activo'] ? 1 : 0) : null;
            $tipo_update = $data['tipo'] ?? null; // Asegurarse de obtener el tipo aquí para validaciones/lógicas
            $completado_update = isset($data['completado']) ? ($data['completado'] ? 1 : 0) : null;

            if ($id <= 0) {
                json_response(['error' => 'ID de tarea inválido para actualizar'], 400);
                return;
            }

            // Validar que texto y tipo no estén vacíos, ya que son obligatorios
            if (empty($texto_update) || empty($tipo_update)) {
                json_response(['error' => 'El texto y el tipo de tarea son obligatorios para la actualización'], 400);
                return;
            }

            $set_clauses = [];
            $bind_params = [];
            $bind_types = "";

            // Añadir campos al UPDATE solo si están presentes en los datos
            if ($texto_update !== null) {
                $set_clauses[] = "texto = ?";
                $bind_params[] = $texto_update;
                $bind_types .= "s";
            }
            if ($fecha_inicio_update !== null) {
                $set_clauses[] = "fecha_inicio = ?";
                $bind_params[] = $fecha_inicio_update;
                $bind_types .= "s";
            }
            if ($regla_recurrencia_update !== null) {
                $set_clauses[] = "regla_recurrencia = ?";
                $bind_params[] = $regla_recurrencia_update;
                $bind_types .= "s";
            }
            if ($activo_update !== null) {
                $set_clauses[] = "activo = ?";
                $bind_params[] = $activo_update;
                $bind_types .= "i";
                // Lógica para desactivar subtareas si un título se desactiva
                if ($activo_update == 0 && $tipo_update === 'titulo') {
                     $stmt_deactivate_subtasks = $mysqli->prepare("UPDATE tareas_diarias SET activo = 0 WHERE parent_id = ? AND usuario_id = ?");
                     $stmt_deactivate_subtasks->bind_param("ii", $id, $usuario_id);
                     $stmt_deactivate_subtasks->execute();
                     $stmt_deactivate_subtasks->close();
                }
            }
            // Manejar la actualización de 'completado' si se envió
            if ($completado_update !== null) {
                $set_clauses[] = "completado = ?";
                $bind_params[] = $completado_update;
                $bind_types .= "i";
            }

            if (empty($set_clauses)) {
                json_response(['error' => 'No hay campos para actualizar'], 400);
                return;
            }

            $query_update = "UPDATE tareas_diarias SET " . implode(", ", $set_clauses) . " WHERE id = ? AND usuario_id = ?";
            $bind_params[] = $id;
            $bind_params[] = $usuario_id;
            $bind_types .= "ii"; // El último 'ii' es para 'id' y 'usuario_id' en la cláusula WHERE

            $stmt = $mysqli->prepare($query_update);
            if (!$stmt) {
                json_response(['error' => 'Error al preparar la actualización general de tarea: ' . $mysqli->error], 500);
                return;
            }
            $stmt->bind_param($bind_types, ...$bind_params);

            if ($stmt->execute()) {
                if ($stmt->affected_rows > 0) {
                    json_response(['success' => true]);
                } else {
                    json_response(['error' => 'Tarea no encontrada o sin cambios necesarios'], 404);
                }
            } else {
                json_response(['error' => 'No se pudo actualizar la tarea: ' . $stmt->error], 500);
            }
            $stmt->close();
            break; // Terminar el caso POST después de manejar el PUT


        // --- MANEJO DE OPERACIONES DE ELIMINACIÓN (DELETE SIMULADO) ---
        } elseif ($_method_override === 'DELETE' || $_method_override === 'HARD_DELETE') {
            $id = $data['id'] ?? 0;
            $tipo_tarea = $data['tipo'] ?? null; 

            if ($id <= 0) {
                json_response(['error' => 'ID de tarea inválido'], 400);
                return;
            }
            // Validar que tipo no esté vacío, ya que es obligatorio (para consistencia)
            if (empty($tipo_tarea)) { 
                json_response(['error' => 'El tipo de tarea es obligatorio para la eliminación'], 400);
                return;
            }

            if ($_method_override === 'HARD_DELETE') {
                $mysqli->begin_transaction();
                try {
                    if ($tipo_tarea === 'titulo') {
                        $stmt_delete_subtasks = $mysqli->prepare("DELETE FROM tareas_diarias WHERE parent_id = ? AND usuario_id = ?");
                        if (!$stmt_delete_subtasks) {
                             throw new Exception("Error al preparar eliminación de subtareas: " . $mysqli->error);
                        }
                        $stmt_delete_subtasks->bind_param("ii", $id, $usuario_id);
                        $stmt_delete_subtasks->execute();
                        $stmt_delete_subtasks->close();
                    }

                    $stmt_delete_main = $mysqli->prepare("DELETE FROM tareas_diarias WHERE id = ? AND usuario_id = ?");
                    if (!$stmt_delete_main) {
                         throw new Exception("Error al preparar eliminación de tarea principal: " . $mysqli->error);
                    }
                    $stmt_delete_main->bind_param("ii", $id, $usuario_id);
                    $stmt_delete_main->execute();
                    $stmt_delete_main->close();

                    $mysqli->commit();
                    json_response(['success' => true, 'message' => 'Elemento eliminado permanentemente.']);
                } catch (Exception $e) {
                    $mysqli->rollback();
                    error_log("Error al eliminar permanentemente: " . $e->getMessage()); 
                    json_response(['error' => 'Error al eliminar permanentemente: ' . $e->getMessage()], 500);
                }
            } else { // 'DELETE' o método por defecto, marca como inactivo
                $mysqli->begin_transaction();
                try {
                    $stmt_deactivate_main = $mysqli->prepare("UPDATE tareas_diarias SET activo = 0 WHERE id = ? AND usuario_id = ?");
                    if (!$stmt_deactivate_main) {
                        throw new Exception("Error al preparar desactivación de tarea principal: " . $mysqli->error);
                    }
                    $stmt_deactivate_main->bind_param("ii", $id, $usuario_id);
                    $stmt_deactivate_main->execute();
                    
                    if ($stmt_deactivate_main->affected_rows > 0) {
                        if ($tipo_tarea === 'titulo') {
                            $stmt_deactivate_subtasks = $mysqli->prepare("UPDATE tareas_diarias SET activo = 0 WHERE parent_id = ? AND usuario_id = ?");
                            if (!$stmt_deactivate_subtasks) {
                                throw new Exception("Error al preparar desactivación de subtareas: " . $mysqli->error);
                            }
                            $stmt_deactivate_subtasks->bind_param("ii", $id, $usuario_id);
                            $stmt_deactivate_subtasks->execute();
                            $stmt_deactivate_subtasks->close();
                        }
                        $mysqli->commit();
                        json_response(['success' => true, 'message' => 'Elemento marcado como inactivo.']);
                    } else {
                        $mysqli->rollback(); 
                        json_response(['error' => 'Tarea no encontrada o ya estaba inactiva.'], 404);
                    }

                    $stmt_deactivate_main->close();

                } catch (Exception $e) {
                    $mysqli->rollback();
                    error_log("Error al marcar como inactivo: " . $e->getMessage()); 
                    json_response(['error' => 'Error al marcar como inactivo: ' . $e->getMessage()], 500);
                }
            }
            break; // Terminar el caso POST después de manejar el DELETE

        // --- CREACIÓN DE NUEVAS TAREAS (INSERT) ---
        } else { 
            // Los campos texto y tipo ya se validaron al principio de POST.
            // Si llega aquí, significa que es una creación nueva y no PUT/DELETE simulado.
            // Asegúrate de que el texto y el tipo realmente sean obligatorios para INSERT aquí.
            if (empty($texto) || empty($tipo)) { // Esta validación es redundante si está al inicio, pero por seguridad
                json_response(['error' => 'El texto y el tipo de tarea son obligatorios para la creación'], 400);
                return;
            }

            if ($tipo === 'titulo' && isset($data['subtareas_textos']) && is_array($data['subtareas_textos'])) {
                $mysqli->begin_transaction();
                try {
                    $stmt_get_next_group_id = $mysqli->prepare("SELECT COALESCE(MAX(submission_group_id), 0) + 1 FROM tareas_diarias WHERE usuario_id = ?"); 
                    if (!$stmt_get_next_group_id) {
                        throw new Exception("Error al preparar la obtención del siguiente grupo ID: " . $mysqli->error);
                    }
                    $stmt_get_next_group_id->bind_param("i", $usuario_id);
                    $stmt_get_next_group_id->execute();
                    $result_get_next_group_id = $stmt_get_next_group_id->get_result();
                    $next_submission_group_id = $result_get_next_group_id->fetch_row()[0];
                    $stmt_get_next_group_id->close();

                    $stmt_parent = $mysqli->prepare("INSERT INTO tareas_diarias (usuario_id, texto, tipo, regla_recurrencia, fecha_inicio, emoji_anotacion, descripcion_anotacion, submission_group_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"); 
                    if (!$stmt_parent) {
                        throw new Exception("Error al preparar la inserción del título: " . $mysqli->error);
                    }
                    $stmt_parent->bind_param("issssssi", $usuario_id, $texto, $tipo, $regla_recurrencia, $fecha_inicio, $emoji_anotacion, $descripcion_anotacion, $next_submission_group_id); 
                    $stmt_parent->execute();
                    $new_parent_id = $mysqli->insert_id;
                    $stmt_parent->close();

                    if (!empty($emoji_anotacion) || !empty($descripcion_anotacion)) {
                        $stmt_check_anotacion = $mysqli->prepare("SELECT id FROM anotaciones WHERE usuario_id = ? AND fecha = ?");
                        if (!$stmt_check_anotacion) {
                            throw new Exception("Error al preparar la verificación de anotación: " . $mysqli->error);
                        }
                        $stmt_check_anotacion->bind_param("is", $usuario_id, $fecha_inicio);
                        $stmt_check_anotacion->execute();
                        $result_check_anotacion = $stmt_check_anotacion->get_result();

                        if ($result_check_anotacion->num_rows > 0) {
                            $stmt_update_anotacion = $mysqli->prepare("UPDATE anotaciones SET emoji = ?, descripcion = ? WHERE usuario_id = ? AND fecha = ?");
                            if (!$stmt_update_anotacion) {
                                throw new Exception("Error al preparar la actualización de anotación: " . $mysqli->error);
                            }
                            $stmt_update_anotacion->bind_param("ssis", $emoji_anotacion, $descripcion_anotacion, $usuario_id, $fecha_inicio);
                            $stmt_update_anotacion->execute();
                            $stmt_update_anotacion->close();
                        } else {
                            $stmt_insert_anotacion = $mysqli->prepare("INSERT INTO anotaciones (usuario_id, fecha, emoji, descripcion) VALUES (?, ?, ?, ?)");
                            if (!$stmt_insert_anotacion) {
                                throw new Exception("Error al preparar la inserción de anotación: " . $mysqli->error);
                            }
                            $stmt_insert_anotacion->bind_param("isss", $usuario_id, $fecha_inicio, $emoji_anotacion, $descripcion_anotacion);
                            $stmt_insert_anotacion->execute();
                            $stmt_insert_anotacion->close();
                        }
                        $stmt_check_anotacion->close();
                    }

                    $stmt_sub = $mysqli->prepare("INSERT INTO tareas_diarias (usuario_id, texto, tipo, parent_id, fecha_inicio, regla_recurrencia, submission_group_id) VALUES (?, ?, 'subtarea', ?, ?, ?, ?)"); 
                    if (!$stmt_sub) {
                        throw new Exception("Error al preparar la inserción de subtareas: " . $mysqli->error);
                    }
                    foreach ($data['subtareas_textos'] as $subtarea_texto) { 
                        if (!empty($subtarea_texto)) {
                            $stmt_sub->bind_param("isissi", $usuario_id, $subtarea_texto, $new_parent_id, $fecha_inicio, $regla_recurrencia, $next_submission_group_id); 
                            $stmt_sub->execute();
                        }
                    }
                    $stmt_sub->close();
                    $mysqli->commit();
                    json_response(['success' => true, 'id' => $new_parent_id, 'submission_group_id' => $next_submission_group_id], 201); 
                } catch (Exception $e) {
                    $mysqli->rollback();
                    json_response(['error' => 'Error en la transacción: ' . $e->getMessage()], 500);
                }
            } else { // Crear una tarea o subtarea simple
                $stmt = $mysqli->prepare("INSERT INTO tareas_diarias (usuario_id, texto, tipo, parent_id, regla_recurrencia, fecha_inicio, submission_group_id) VALUES (?, ?, ?, ?, ?, ?, ?)"); 
                if (!$stmt) {
                     json_response(['error' => 'Error al preparar la inserción de tarea simple: ' . $mysqli->error], 500);
                     return;
                }
                $default_submission_group_id = 0; 
                $stmt->bind_param("ississi", $usuario_id, $texto, $tipo, $parent_id, $regla_recurrencia, $fecha_inicio, $default_submission_group_id); 
                if ($stmt->execute()) {
                    json_response(['success' => true, 'id' => $mysqli->insert_id], 201);
                } else {
                    json_response(['error' => 'No se pudo crear la tarea o subtarea simple: ' . $stmt->error], 500);
                }
                $stmt->close();
            }
        }
        break; // Terminar el caso POST

    case 'PUT': // Este caso ya no debería ser alcanzado directamente por el cliente si POST maneja _method=PUT
    case 'DELETE': // Este caso ya no debería ser alcanzado directamente por el cliente si POST maneja _method=DELETE
        json_response(['error' => 'Método no permitido directamente. Usa POST con _method en el cuerpo.'], 405);
        break;

    default:
        json_response(['error' => 'Método no permitido'], 405);
        break;
}
?>