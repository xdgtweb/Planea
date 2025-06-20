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

        // Recuperar las horas de recordatorio para cada tarea principal
        $task_ids = array_keys($tasks_by_id);
        if (!empty($task_ids)) {
            $placeholders = implode(',', array_fill(0, count($task_ids), '?'));
            $stmt_reminder_times = $mysqli->prepare("
                SELECT r.tarea_id, rt.time_of_day 
                FROM reminders r
                JOIN reminder_times rt ON r.id = rt.reminder_id
                WHERE r.tarea_id IN ($placeholders)
            ");
            if ($stmt_reminder_times) {
                $types_str = str_repeat('i', count($task_ids));
                $stmt_reminder_times->bind_param($types_str, ...$task_ids);
                $stmt_reminder_times->execute();
                $times_result = $stmt_reminder_times->get_result();
                
                $times_by_task_id = [];
                while($row = $times_result->fetch_assoc()) {
                    $times_by_task_id[$row['tarea_id']][] = $row['time_of_day'];
                }
                $stmt_reminder_times->close();

                foreach($tasks_by_id as $id => &$task) {
                    if (isset($times_by_task_id[$id])) {
                        $task['reminder_times'] = $times_by_task_id[$id];
                    } else {
                        $task['reminder_times'] = [];
                    }
                }
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
        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true);

        // --- INICIO DE LA CORRECCIÓN ---
        // Verificar si la decodificación JSON falló
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            json_response(['error' => 'Entrada JSON inválida: ' . json_last_error_msg()], 400);
            return;
        }
        // Verificar si $data no es un array (en caso de que la entrada sea vacía o un valor no JSON)
        if (!is_array($data)) {
            json_response(['error' => 'Formato de datos inválido, se esperaba un objeto/array JSON.'], 400);
            return;
        }
        // --- FIN DE LA CORRECCIÓN ---

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

            // CAMPOS DE RECORDATORIO (nuevos)
            $send_reminder = isset($data['send_reminder']) ? ($data['send_reminder'] ? 1 : 0) : null;
            $reminder_type = $data['reminder_type'] ?? null;
            $selected_reminder_times = $data['selected_reminder_times'] ?? []; // NUEVO: Horas específicas

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
                     if ($stmt_deactivate_subtasks) { // Añadida comprobación
                        $stmt_deactivate_subtasks->bind_param("ii", $id, $usuario_id);
                        $stmt_deactivate_subtasks->execute();
                        $stmt_deactivate_subtasks->close();
                     }
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

            $mysqli->begin_transaction(); // Iniciar transacción para recordatorios
            try {
                $stmt = $mysqli->prepare($query_update);
                if (!$stmt) {
                    throw new Exception('Error al preparar la actualización general de tarea: ' . $mysqli->error);
                }
                $stmt->bind_param($bind_types, ...$bind_params);

                if (!$stmt->execute()) {
                    throw new Exception('No se pudo actualizar la tarea: ' . $stmt->error);
                }
                $stmt->close();

                // Lógica para actualizar/eliminar recordatorios si la tarea es de tipo 'titulo'
                if ($tipo_update === 'titulo' && $send_reminder !== null && $fecha_inicio_update !== null) {
                    // Obtener el ID del recordatorio existente para esta tarea
                    $existing_reminder_id = null;
                    $stmt_get_reminder_id = $mysqli->prepare("SELECT id FROM reminders WHERE tarea_id = ? AND usuario_id = ? LIMIT 1");
                    $stmt_get_reminder_id->bind_param("ii", $id, $usuario_id);
                    $stmt_get_reminder_id->execute();
                    $result_reminder_id = $stmt_get_reminder_id->get_result();
                    if ($result_reminder_id->num_rows > 0) {
                        $existing_reminder_id = $result_reminder_id->fetch_assoc()['id'];
                    }
                    $stmt_get_reminder_id->close();

                    if ($send_reminder == 1 && $reminder_type !== 'none') {
                        // Calcular reminder_datetime
                        $reminder_datetime = new DateTime($fecha_inicio_update);
                        if ($reminder_type === 'hours_before') {
                            // Por defecto, 4 horas antes, se podría hacer más flexible con otro campo en el modal
                            $reminder_datetime->modify('-4 hours'); 
                        } elseif ($reminder_type === 'day_before') {
                            $reminder_datetime->modify('-1 day');
                        } elseif ($reminder_type === 'week_before') {
                            $reminder_datetime->modify('-1 week');
                        } elseif ($reminder_type === 'month_before') {
                            $reminder_datetime->modify('-1 month');
                        }

                        if ($existing_reminder_id) {
                            // Actualizar recordatorio existente
                            $stmt_update_reminder = $mysqli->prepare("UPDATE reminders SET reminder_datetime = ?, type = ?, status = 'pending' WHERE id = ?");
                            if ($stmt_update_reminder) { // Añadida comprobación
                                $stmt_update_reminder->bind_param("ssi", $reminder_datetime->format('Y-m-d H:i:s'), $reminder_type, $existing_reminder_id);
                                $stmt_update_reminder->execute();
                                $stmt_update_reminder->close();
                            }
                        } else {
                            // Insertar nuevo recordatorio
                            $stmt_insert_reminder = $mysqli->prepare("INSERT INTO reminders (usuario_id, tarea_id, reminder_datetime, type) VALUES (?, ?, ?, ?)");
                            if ($stmt_insert_reminder) { // Añadida comprobación
                                $stmt_insert_reminder->bind_param("iiss", $usuario_id, $id, $reminder_datetime->format('Y-m-d H:i:s'), $reminder_type);
                                $stmt_insert_reminder->execute();
                                $existing_reminder_id = $mysqli->insert_id; // Obtener el ID del nuevo recordatorio
                                $stmt_insert_reminder->close();
                            }
                        }

                        // NUEVO: Actualizar las horas de recordatorio específicas
                        // Primero, eliminar las horas existentes para este recordatorio
                        $stmt_delete_times = $mysqli->prepare("DELETE FROM reminder_times WHERE reminder_id = ?");
                        if (!$stmt_delete_times) {
                            throw new Exception("Error al preparar la eliminación de horas de recordatorio: " . $mysqli->error);
                        }
                        $stmt_delete_times->bind_param("i", $existing_reminder_id);
                        $stmt_delete_times->execute();
                        $stmt_delete_times->close();

                        // Luego, insertar las nuevas horas seleccionadas
                        if (!empty($selected_reminder_times) && is_array($selected_reminder_times)) {
                            $stmt_insert_times = $mysqli->prepare("INSERT INTO reminder_times (reminder_id, time_of_day) VALUES (?, ?)");
                            if (!$stmt_insert_times) {
                                throw new Exception("Error al preparar la inserción de horas de recordatorio (PUT): " . $mysqli->error);
                            }
                            foreach ($selected_reminder_times as $time_str) {
                                $stmt_insert_times->bind_param("is", $existing_reminder_id, $time_str);
                                $stmt_insert_times->execute();
                            }
                            $stmt_insert_times->close();
                        }
                    } else {
                        // Si send_reminder es 0 o type es 'none', eliminar recordatorios existentes para esta tarea
                        // y también las horas asociadas.
                        if ($existing_reminder_id) {
                            $stmt_delete_reminder = $mysqli->prepare("DELETE FROM reminders WHERE id = ?");
                            if ($stmt_delete_reminder) { // Añadida comprobación
                                $stmt_delete_reminder->bind_param("i", $existing_reminder_id);
                                $stmt_delete_reminder->execute();
                                $stmt_delete_reminder->close();
                            }
                            // Las horas se borrarán automáticamente debido a ON DELETE CASCADE si usas la Opción A.
                        }
                    }
                }

                $mysqli->commit();
                if ($mysqli->affected_rows > 0) {
                    json_response(['success' => true, 'message' => 'Tarea actualizada.']);
                } else {
                    json_response(['success' => true, 'message' => 'Tarea encontrada, pero sin cambios necesarios.'], 200);
                }
            } catch (Exception $e) {
                $mysqli->rollback();
                json_response(['error' => 'Error en la transacción al actualizar tarea: ' . $e->getMessage()], 500);
                return;
            }
            break;


        } elseif ($_method_override === 'DELETE' || $_method_override === 'HARD_DELETE') {
            $id = $data['id'] ?? 0;
            $tipo_tarea = $data['tipo'] ?? null; 

            if ($id <= 0) {
                json_response(['error' => 'ID de tarea inválido'], 400);
                return;
            }
            if (empty($tipo_tarea)) { 
                json_response(['error' => 'El tipo de tarea es obligatorio para la eliminación'], 400);
                return;
            }

            if ($_method_override === 'HARD_DELETE') {
                $mysqli->begin_transaction();
                try {
                    if ($tipo_tarea === 'titulo') {
                        $stmt_delete_reminders = $mysqli->prepare("DELETE FROM reminders WHERE tarea_id = ? AND usuario_id = ?");
                        if (!$stmt_delete_reminders) {
                            throw new Exception("Error al preparar eliminación de recordatorios: " . $mysqli->error);
                        }
                        $stmt_delete_reminders->bind_param("ii", $id, $usuario_id);
                        $stmt_delete_reminders->execute();
                        $stmt_delete_reminders->close();

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
            } else {
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

                            $stmt_deactivate_reminders = $mysqli->prepare("UPDATE reminders SET status = 'disabled' WHERE tarea_id = ? AND usuario_id = ?");
                            if (!$stmt_deactivate_reminders) {
                                throw new Exception("Error al preparar desactivación de recordatorios: " . $mysqli->error);
                            }
                            $stmt_deactivate_reminders->bind_param("ii", $id, $usuario_id);
                            $stmt_deactivate_reminders->execute();
                            $stmt_deactivate_reminders->close();
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
            break;

        } else { 
            $texto = $data['texto'] ?? null;
            $tipo = $data['tipo'] ?? null;
            $parent_id = $data['parent_id'] ?? null;
            $regla_recurrencia = $data['regla_recurrencia'] ?? null;
            $fecha_inicio = $data['fecha_inicio'] ?? null;
            $emoji_anotacion = $data['emoji_anotacion'] ?? null;
            $descripcion_anotacion = $data['descripcion_anotacion'] ?? null;
            
            $send_reminder = isset($data['send_reminder']) ? ($data['send_reminder'] ? 1 : 0) : 0;
            $reminder_type = $data['reminder_type'] ?? 'none';
            $selected_reminder_times = $data['selected_reminder_times'] ?? [];


            if (empty($texto) || empty($tipo)) {
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

                    if ($send_reminder == 1 && $reminder_type !== 'none' && $fecha_inicio) {
                        $reminder_datetime = new DateTime($fecha_inicio);
                        if ($reminder_type === 'hours_before') {
                            $reminder_datetime->modify('-4 hours'); 
                        } elseif ($reminder_type === 'day_before') {
                            $reminder_datetime->modify('-1 day');
                        } elseif ($reminder_type === 'week_before') {
                            $reminder_datetime->modify('-1 week');
                        } elseif ($reminder_type === 'month_before') {
                            $reminder_datetime->modify('-1 month');
                        }
                        
                        $stmt_insert_reminder = $mysqli->prepare("INSERT INTO reminders (usuario_id, tarea_id, reminder_datetime, type) VALUES (?, ?, ?, ?)");
                        if (!$stmt_insert_reminder) {
                            throw new Exception("Error al preparar la inserción de recordatorio: " . $mysqli->error);
                        }
                        $stmt_insert_reminder->bind_param("iiss", $usuario_id, $new_parent_id, $reminder_datetime->format('Y-m-d H:i:s'), $reminder_type);
                        $stmt_insert_reminder->execute();
                        $new_reminder_id = $mysqli->insert_id;
                        $stmt_insert_reminder->close();

                        if (!empty($selected_reminder_times) && is_array($selected_reminder_times)) {
                            $stmt_insert_times = $mysqli->prepare("INSERT INTO reminder_times (reminder_id, time_of_day) VALUES (?, ?)");
                            if (!$stmt_insert_times) {
                                throw new Exception("Error al preparar la inserción de horas de recordatorio: " . $mysqli->error);
                            }
                            foreach ($selected_reminder_times as $time_str) {
                                $stmt_insert_times->bind_param("is", $new_reminder_id, $time_str);
                                $stmt_insert_times->execute();
                            }
                            $stmt_insert_times->close();
                        }
                    }


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
            } else {
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
        break;

    default:
        json_response(['error' => 'Método no permitido'], 405);
        break;
}