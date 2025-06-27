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

        if (!$fecha_filtro) {
            json_response(['error' => 'Parámetro de fecha requerido para GET'], 400);
            return;
        }

        // Obtener tareas propias del usuario
        // Incluimos activo = 1, pero si quieres ver inactivas en el detalle, ajusta esto.
        // Se añade `submission_group_id` para agrupamiento.
        $query_own_tasks = "
            SELECT id, texto, completado, tipo, parent_id, regla_recurrencia, fecha_inicio, activo, emoji_anotacion, descripcion_anotacion, submission_group_id, usuario_id # <--- ¡AÑADIDA usuario_id AQUÍ!
            FROM tareas_diarias
            WHERE usuario_id = ? AND fecha_inicio = ?
        ";
        $params_own_tasks = [$usuario_id, $fecha_filtro];
        $types_own_tasks = "is";

        if ($activo_filtro !== null) { 
            $query_own_tasks .= " AND activo = ?";
            $params_own_tasks[] = (int)$activo_filtro;
            $types_own_tasks .= "i";
        }
        $query_own_tasks .= " ORDER BY id ASC";

        $stmt_own_tasks = $mysqli->prepare($query_own_tasks);
        if (!$stmt_own_tasks) {
            json_response(['error' => 'Error al preparar la consulta GET de tareas propias: ' . $mysqli->error], 500);
            return;
        }
        $stmt_own_tasks->bind_param($types_own_tasks, ...$params_own_tasks);
        $stmt_own_tasks->execute();
        $result_own_tasks = $stmt_own_tasks->get_result();
        
        $tasks_by_id = [];
        while ($task = $result_own_tasks->fetch_assoc()) {
            $task['completado'] = (bool)$task['completado'];
            $task['activo'] = (bool)$task['activo'];
            $task['subtareas'] = [];
            $task['is_shared'] = false; // Por defecto no es compartida
            $tasks_by_id[$task['id']] = $task;
        }
        $stmt_own_tasks->close();

        // Obtener tareas compartidas con el usuario actual (solo si tienen cuenta)
        $query_shared_tasks = "
            SELECT td.id, td.texto, td.completado, td.tipo, td.parent_id, td.regla_recurrencia, td.fecha_inicio, td.activo, td.emoji_anotacion, td.descripcion_anotacion, td.submission_group_id,
                   st.owner_user_id, u.username as owner_username, u.email as owner_email
            FROM shared_tasks st
            JOIN tareas_diarias td ON st.task_id = td.id
            JOIN usuarios u ON st.owner_user_id = u.id
            WHERE st.shared_with_user_id = ? AND td.fecha_inicio = ?
        ";
        $params_shared_tasks = [$usuario_id, $fecha_filtro];
        $types_shared_tasks = "is";

        // Si se filtra por activo, aplicar también a las compartidas
        if ($activo_filtro !== null) {
            $query_shared_tasks .= " AND td.activo = ?";
            $params_shared_tasks[] = (int)$activo_filtro;
            $types_shared_tasks .= "i";
        }
        $query_shared_tasks .= " ORDER BY td.id ASC";

        $stmt_shared_tasks = $mysqli->prepare($query_shared_tasks);
        if (!$stmt_shared_tasks) {
            json_response(['error' => 'Error al preparar la consulta GET de tareas compartidas: ' . $mysqli->error], 500);
            return;
        }
        $stmt_shared_tasks->bind_param($types_shared_tasks, ...$params_shared_tasks);
        $stmt_shared_tasks->execute();
        $result_shared_tasks = $stmt_shared_tasks->get_result();

        while ($task = $result_shared_tasks->fetch_assoc()) {
            // Solo añadir si la tarea no es ya del propio usuario (priorizar propiedad)
            if (!isset($tasks_by_id[$task['id']])) {
                $task['completado'] = (bool)$task['completado'];
                $task['activo'] = (bool)$task['activo'];
                $task['subtareas'] = [];
                $task['is_shared'] = true; // Indicar que es una tarea compartida
                $task['shared_owner_info'] = ['id' => $task['owner_user_id'], 'username' => $task['owner_username'], 'email' => $task['owner_email']];
                unset($task['owner_user_id'], $task['owner_username'], $task['owner_email']); // Limpiar datos no necesarios
                $tasks_by_id[$task['id']] = $task;
            }
        }
        $stmt_shared_tasks->close();

        $root_tasks = [];
        // Primera pasada: Organizar todas las tareas por ID y separar las tareas raíz
        foreach ($tasks_by_id as $id => &$task) {
            // Inicializar subtareas y el array temporal para control de duplicados
            if (!isset($task['subtareas'])) {
                $task['subtareas'] = [];
            }
            if (!isset($task['subtareas_indexed'])) { // <-- INICIO DE LA MODIFICACIÓN
                $task['subtareas_indexed'] = []; // Array temporal para controlar IDs ya añadidos
            } // <-- FIN DE LA MODIFICACIÓN

            if ($task['parent_id'] === null) {
                $root_tasks[$task['id']] = &$tasks_by_id[$task['id']]; // Mantener referencia a la tarea raíz
            }
        }

        // Recuperar las horas de recordatorio para cada tarea principal (solo si es propia del usuario logueado)
        $own_task_ids = [];
        foreach ($tasks_by_id as $id => $task) {
            // Solo si la tarea es del usuario, no si es compartida de otro
            if (isset($task['usuario_id']) && $task['usuario_id'] == $usuario_id) {
                $own_task_ids[] = $id;
            }
        }

        if (!empty($own_task_ids)) {
            $placeholders = implode(',', array_fill(0, count($own_task_ids), '?'));
            $stmt_reminder_times = $mysqli->prepare("
                SELECT r.tarea_id, rt.time_of_day 
                FROM reminders r
                JOIN reminder_times rt ON r.id = rt.reminder_id
                WHERE r.tarea_id IN ($placeholders)
            ");
            if ($stmt_reminder_times) {
                $types_str = str_repeat('i', count($own_task_ids));
                $stmt_reminder_times->bind_param($types_str, ...$own_task_ids);
                $stmt_reminder_times->execute();
                $times_result = $stmt_reminder_times->get_result();
                
                $times_by_task_id = [];
                while($row = $times_result->fetch_assoc()) {
                    $times_by_task_id[$row['tarea_id']][] = $row['time_of_day'];
                }
                $stmt_reminder_times->close();

                foreach($tasks_by_id as $id => &$task) {
                    // Asegurarse de asignar reminder_times solo a tareas propias
                    if (isset($task['usuario_id']) && $task['usuario_id'] == $usuario_id) { // Added isset check
                        if (isset($times_by_task_id[$id])) {
                            $task['reminder_times'] = $times_by_task_id[$id];
                        } else {
                            $task['reminder_times'] = [];
                        }
                    } else {
                        $task['reminder_times'] = []; // Las tareas compartidas no tienen recordatorios asociados directamente al usuario
                    }
                }
            }
        }


        // Segunda pasada: Anidar las subtareas bajo sus padres
        foreach ($tasks_by_id as $id => &$task) {
            if ($task['parent_id'] !== null) {
                $parent_id = $task['parent_id'];
                if (isset($tasks_by_id[$parent_id])) {
                    // INICIO DE LA MODIFICACIÓN para evitar duplicados de subtareas en el array JSON
                    // Solo añadir la subtarea si su ID no ha sido añadido ya a este padre
                    if (!isset($tasks_by_id[$parent_id]['subtareas_indexed'][$id])) {
                        $tasks_by_id[$parent_id]['subtareas'][] = &$task; // Añadir al array secuencial para el orden de salida JSON
                        $tasks_by_id[$parent_id]['subtareas_indexed'][$id] = true; // Marcar como añadida para evitar volver a añadir
                    }
                    // FIN DE LA MODIFICACIÓN
                    
                    // Si una subtarea está anidada, ya no debe ser una tarea raíz independiente
                    if (isset($root_tasks[$id])) {
                        unset($root_tasks[$id]);
                    }
                }
            }
        }

        // INICIO DE LA MODIFICACIÓN: Limpiar el array temporal 'subtareas_indexed' antes de la salida JSON
        foreach ($tasks_by_id as $id => &$task) {
            if (isset($task['subtareas_indexed'])) {
                unset($task['subtareas_indexed']);
            }
        }
        // FIN DE LA MODIFICACIÓN

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
        break;


    case 'POST':
        $raw_input = file_get_contents("php://input");
        $data = json_decode($raw_input, true);

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

        $_method_override = $data['_method'] ?? $method; // Obtener el método real, por defecto es POST si no se especifica _method

        // Validar que el usuario tiene el correo verificado para crear/modificar tareas (excepto si es admin)
        // Solo aplica si el usuario no es admin y su email no está verificado
        if (!($_SESSION['is_admin'] ?? false) && !($_SESSION['email_verified'] ?? false)) {
            json_response(['error' => 'Debe verificar su correo electrónico para crear o modificar tareas.'], 403);
            return;
        }

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

            // Nuevo: Validación de fecha para no actualizar a días pasados
            if ($fecha_inicio_update !== null) {
                $fecha_obj_update = new DateTime($fecha_inicio_update);
                $hoy_update = new DateTime(date('Y-m-d')); // Fecha actual sin hora
                // Corregido: getTime() a getTimestamp()
                if ($fecha_obj_update->getTimestamp() < $hoy_update->getTimestamp()) {
                    json_response(['error' => 'No se pueden actualizar tareas a días anteriores al actual.'], 403);
                    return;
                }
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
                $stmt->bind_param($types, ...$params);

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
            $fecha_tarea_eliminar = $data['fecha_inicio'] ?? null; // Obtener la fecha de la tarea para validación

            if ($id <= 0) {
                json_response(['error' => 'ID de tarea inválido'], 400);
                return;
            }
            if (empty($tipo_tarea)) { 
                json_response(['error' => 'El tipo de tarea es obligatorio para la eliminación'], 400);
                return;
            }

            // Nuevo: Validación para no eliminar/archivar tareas de días pasados
            if ($fecha_tarea_eliminar !== null) {
                $fecha_obj_delete = new DateTime($fecha_tarea_eliminar);
                $hoy_delete = new DateTime(date('Y-m-d')); // Fecha actual sin hora
                // Corregido: getTime() a getTimestamp()
                if ($fecha_obj_delete->getTimestamp() < $hoy_delete->getTimestamp()) {
                    json_response(['error' => 'No se pueden eliminar o archivar tareas de días anteriores al actual.'], 403);
                    return;
                }
            }

            if ($_method_override === 'HARD_DELETE') {
                $mysqli->begin_transaction();
                try {
                    // Eliminar registros de shared_tasks relacionados con esta tarea
                    $stmt_delete_shared = $mysqli->prepare("DELETE FROM shared_tasks WHERE task_id = ? AND owner_user_id = ?");
                    if (!$stmt_delete_shared) {
                        throw new Exception("Error al preparar eliminación de tareas compartidas: " . $mysqli->error);
                    }
                    $stmt_delete_shared->bind_param("ii", $id, $usuario_id);
                    $stmt_delete_shared->execute();
                    $stmt_delete_shared->close();

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
            } else { // DELETE (archivar)
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

                            // Desactivar también los compartidos de esta tarea principal y sus subtareas
                            $stmt_deactivate_shared = $mysqli->prepare("UPDATE shared_tasks SET access_token = NULL WHERE task_id = ? AND owner_user_id = ?");
                            if (!$stmt_deactivate_shared) {
                                throw new Exception("Error al preparar desactivación de compartidos: " . $mysqli->error);
                            }
                            $stmt_deactivate_shared->bind_param("ii", $id, $usuario_id);
                            $stmt_deactivate_shared->execute();
                            $stmt_deactivate_shared->close();

                            // Si fuera necesario desactivar las subtareas compartidas
                            // $stmt_subtask_ids = $mysqli->prepare("SELECT id FROM tareas_diarias WHERE parent_id = ? AND usuario_id = ?");
                            // $stmt_subtask_ids->bind_param("ii", $id, $usuario_id);
                            // $stmt_subtask_ids->execute();
                            // $sub_task_ids = $stmt_subtask_ids->get_result()->fetch_all(MYSQLI_ASSOC);
                            // $stmt_subtask_ids->close();

                            // foreach ($sub_task_ids as $sub_task_id_row) {
                            //     $stmt_deactivate_shared_sub = $mysqli->prepare("UPDATE shared_tasks SET access_token = NULL WHERE task_id = ? AND owner_user_id = ?");
                            //     $stmt_deactivate_shared_sub->bind_param("ii", $sub_task_id_row['id'], $usuario_id);
                            //     $stmt_deactivate_shared_sub->execute();
                            //     $stmt_deactivate_shared_sub->close();
                            // }

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

        } else { // POST (Creación de Tareas)
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

            // Nuevo: Validación de fecha para no crear tareas en días pasados
            if ($fecha_inicio !== null) {
                $fecha_obj_create = new DateTime($fecha_inicio);
                $hoy_create = new DateTime(date('Y-m-d')); // Fecha actual sin hora
                // Corregido: getTime() a getTimestamp()
                if ($fecha_obj_create->getTimestamp() < $hoy_create->getTimestamp()) {
                    json_response(['error' => 'No se pueden crear tareas para días anteriores al actual.'], 403);
                    return;
                }
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
                    $subtask_errors = []; // Array para recolectar errores de subtareas individuales
                    foreach ($data['subtareas_textos'] as $subtarea_texto) { 
                        if (!empty($subtarea_texto)) {
                            $stmt_sub->bind_param("isissi", $usuario_id, $subtarea_texto, $new_parent_id, $fecha_inicio, $regla_recurrencia, $next_submission_group_id); 
                            if (!$stmt_sub->execute()) { // Se agrega el control de errores de inserción de subtareas
                                $subtask_errors[] = "Fallo al insertar subtarea '" . htmlspecialchars($subtarea_texto) . "': " . $stmt_sub->error;
                            }
                        }
                    }
                    $stmt_sub->close();

                    if (!empty($subtask_errors)) { // Si hubo errores en alguna subtarea, se hace rollback y se retorna el error
                        $mysqli->rollback();
                        json_response(['error' => 'Errores al insertar subtareas: ' . implode('; ', $subtask_errors)], 500);
                        return;
                    }

                    $mysqli->commit();
                    json_response(['success' => true, 'id' => $new_parent_id, 'submission_group_id' => $next_submission_group_id], 201); 
                } catch (Exception $e) {
                    $mysqli->rollback();
                    json_response(['error' => 'Error en la transacción: ' . $e->getMessage()], 500);
                }
            } else { // Crear tarea simple
                $stmt = $mysqli->prepare("INSERT INTO tareas_diarias (usuario_id, texto, tipo, parent_id, regla_recurrencia, submission_group_id) VALUES (?, ?, ?, ?, ?, ?, ?)"); 
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
?>