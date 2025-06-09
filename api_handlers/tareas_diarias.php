<?php
// api_handlers/tareas_diarias.php

if (!isset($_SESSION['usuario_id'])) { jsonResponse(["error" => "No autorizado."], 401); exit; }
$usuario_id = $_SESSION['usuario_id'];

if (!isset($mysqli)) { jsonResponse(["error" => "Error crítico: Conexión a base de datos no disponible."], 500); exit; }
if (!isset($handler_http_method)) { jsonResponse(["error" => "Error crítico: Método HTTP no determinado."], 500); exit; }

switch ($handler_http_method) {
    case 'GET':
        try {
            $fecha_solicitada = $_GET['fecha'] ?? getTodayDate();
            $solo_titulos_activos = isset($_GET['solo_titulos_activos']) && $_GET['solo_titulos_activos'] === 'true';

            if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $fecha_solicitada)) { jsonResponse(["error" => "Formato de fecha inválido."], 400); }

            if ($solo_titulos_activos) {
                $sql_titulos = "SELECT id, texto, fecha_inicio, regla_recurrencia, creado_en FROM tareas_dia_a_dia WHERE tipo = 'titulo' AND activo = TRUE AND usuario_id = ? ORDER BY orden, id";
                $stmt_titulos = $mysqli->prepare($sql_titulos);
                $stmt_titulos->bind_param("i", $usuario_id);
                $stmt_titulos->execute();
                $result_titulos = $stmt_titulos->get_result();
                $titulos_activos_del_dia = [];
                $current_date_obj_param = new DateTimeImmutable($fecha_solicitada, new DateTimeZone('UTC'));

                while ($row = $result_titulos->fetch_assoc()) {
                    $mostrar_titulo = false;
                    $fecha_base_str = $row['fecha_inicio'] ?: $row['creado_en'];
                    if (empty($fecha_base_str) || strpos($fecha_base_str, '0000-00-00') === 0) continue;
                    $fecha_inicio_titulo_obj = new DateTimeImmutable($fecha_base_str, new DateTimeZone('UTC'));

                    if ($current_date_obj_param < $fecha_inicio_titulo_obj && (empty($row['regla_recurrencia']) || $row['regla_recurrencia'] === 'NONE')) continue;
                    
                    $regla = $row['regla_recurrencia'];
                    if ($regla === 'NONE') {
                        if ($fecha_inicio_titulo_obj->format('Y-m-d') == $current_date_obj_param->format('Y-m-d')) $mostrar_titulo = true;
                    } elseif ($regla === 'DAILY') {
                        if ($current_date_obj_param >= $fecha_inicio_titulo_obj) $mostrar_titulo = true;
                    } elseif (strpos($regla, 'WEEKLY:') === 0) {
                        if ($current_date_obj_param >= $fecha_inicio_titulo_obj) {
                            $parts = explode(':', $regla);
                            if (count($parts) === 2) {
                                $days_of_week_abbr = explode(',', $parts[1]);
                                $day_map_php_to_abbr = [1=>'MON', 2=>'TUE', 3=>'WED', 4=>'THU', 5=>'FRI', 6=>'SAT', 7=>'SUN'];
                                $current_day_abbr_php = $day_map_php_to_abbr[$current_date_obj_param->format('N')] ?? '';
                                if (in_array($current_day_abbr_php, $days_of_week_abbr)) $mostrar_titulo = true;
                            }
                        }
                    } elseif ($regla === 'MONTHLY_DAY') {
                        if ($current_date_obj_param >= $fecha_inicio_titulo_obj && $current_date_obj_param->format('d') == $fecha_inicio_titulo_obj->format('d')) $mostrar_titulo = true;
                    } else {
                        if ($fecha_inicio_titulo_obj->format('Y-m-d') == $current_date_obj_param->format('Y-m-d')) $mostrar_titulo = true;
                    }

                    if ($mostrar_titulo) { $titulos_activos_del_dia[] = ['id' => $row['id'], 'texto' => $row['texto']]; }
                }
                $stmt_titulos->close();
                jsonResponse($titulos_activos_del_dia);
            } else { 
                $sql_maestra = "SELECT id, texto, tipo, parent_id, orden, activo, fecha_inicio, regla_recurrencia, creado_en FROM tareas_dia_a_dia WHERE usuario_id = ? ORDER BY activo DESC, orden ASC, id ASC";
                $stmt_maestra = $mysqli->prepare($sql_maestra);
                $stmt_maestra->bind_param("i", $usuario_id);
                $stmt_maestra->execute();
                $result_maestra = $stmt_maestra->get_result();
                
                $all_tasks_from_db_temp = []; 
                $current_date_obj_param = new DateTimeImmutable($fecha_solicitada, new DateTimeZone('UTC'));
                while ($row = $result_maestra->fetch_assoc()) {
                    try {
                        $fecha_base_str = $row['fecha_inicio'] ?: $row['creado_en'];
                        if (empty($fecha_base_str) || strpos($fecha_base_str, '0000-00-00') === 0) continue;
                        $fecha_inicio_tarea_obj = new DateTimeImmutable($fecha_base_str, new DateTimeZone('UTC'));
                    } catch(Exception $e) {
                        error_log("Saltando tarea ID {$row['id']} por fecha inválida: '{$fecha_base_str}'");
                        continue;
                    }
                    
                    $mostrar_tarea = false;
                    if ($current_date_obj_param < $fecha_inicio_tarea_obj && (empty($row['regla_recurrencia']) || $row['regla_recurrencia'] === 'NONE')) continue; 
                    
                    $regla = $row['regla_recurrencia'];
                    if ($regla === 'NONE') {
                        if ($fecha_inicio_tarea_obj->format('Y-m-d') == $current_date_obj_param->format('Y-m-d')) $mostrar_tarea = true;
                    } elseif ($regla === 'DAILY') {
                        if ($current_date_obj_param >= $fecha_inicio_tarea_obj) $mostrar_tarea = true;
                    } elseif (strpos($regla, 'WEEKLY:') === 0) {
                        if ($current_date_obj_param >= $fecha_inicio_tarea_obj) {
                            $parts = explode(':', $regla);
                            if (count($parts) === 2) {
                                $days_of_week_abbr = explode(',', $parts[1]);
                                $day_map_php_to_abbr = [1=>'MON', 2=>'TUE', 3=>'WED', 4=>'THU', 5=>'FRI', 6=>'SAT', 7=>'SUN'];
                                $current_day_abbr_php = $day_map_php_to_abbr[$current_date_obj_param->format('N')] ?? '';
                                if (in_array($current_day_abbr_php, $days_of_week_abbr)) $mostrar_tarea = true;
                            }
                        }
                    } elseif ($regla === 'MONTHLY_DAY') {
                        if ($current_date_obj_param >= $fecha_inicio_tarea_obj && $current_date_obj_param->format('d') == $fecha_inicio_tarea_obj->format('d')) $mostrar_tarea = true;
                    } else {
                        if ($fecha_inicio_tarea_obj->format('Y-m-d') == $current_date_obj_param->format('Y-m-d')) $mostrar_tarea = true;
                    }
                    
                    if ($mostrar_tarea) {
                        $row['activo'] = (bool)$row['activo']; $all_tasks_from_db_temp[$row['id']] = $row;
                        $all_tasks_from_db_temp[$row['id']]['subtareas'] = []; $all_tasks_from_db_temp[$row['id']]['completado'] = false;
                    }
                }
                $stmt_maestra->close();
                
                if (!empty($all_tasks_from_db_temp)) {
                    $task_ids_for_completion_check = array_keys($all_tasks_from_db_temp);
                    $placeholders_comp = implode(',', array_fill(0, count($task_ids_for_completion_check), '?'));
                    $types_comp = str_repeat('i', count($task_ids_for_completion_check)) . 's'; 
                    $params_comp = $task_ids_for_completion_check; $params_comp[] = $fecha_solicitada;
                    $sql_completadas = "SELECT tarea_id, completado FROM tareas_dia_a_dia_completadas WHERE tarea_id IN ($placeholders_comp) AND fecha = ?";
                    $stmt_completadas = $mysqli->prepare($sql_completadas);
                    $stmt_completadas->bind_param($types_comp, ...$params_comp); 
                    $stmt_completadas->execute();
                    $result_completadas = $stmt_completadas->get_result();
                    while ($row_comp = $result_completadas->fetch_assoc()) {
                        if (isset($all_tasks_from_db_temp[$row_comp['tarea_id']])) {
                            $all_tasks_from_db_temp[$row_comp['tarea_id']]['completado'] = (bool)$row_comp['completado'];
                        }
                    } $stmt_completadas->close();
                }

                $tareas_final_output = [];
                foreach ($all_tasks_from_db_temp as $tarea_id => $tarea) { if ($tarea['parent_id'] !== null && isset($all_tasks_from_db_temp[$tarea['parent_id']])) { $all_tasks_from_db_temp[$tarea['parent_id']]['subtareas'][]=$tarea;}}
                foreach ($all_tasks_from_db_temp as $tarea_id => $tarea) { if ($tarea['parent_id'] === null) { $tareas_final_output[]=$tarea;}}
                usort($tareas_final_output, function($a, $b) { if ($a['activo'] !== $b['activo']) return $a['activo'] ? -1 : 1; return ($a['orden']??$a['id'])<=>($b['orden']??$b['id']); });
                jsonResponse($tareas_final_output);
            }
        } catch (Exception $e) {
            error_log("Error en GET /tareas-dia-a-dia (user $usuario_id): " . $e->getMessage());
            jsonResponse(["error" => "No se pudieron obtener las tareas."], 500);
        }
        break;

    case 'POST':
        try {
            if (empty($data_for_handler)) { jsonResponse(["error" => "No se recibió payload."], 400); }
            $texto = $data_for_handler['texto'] ?? ''; 
            $tipo = $data_for_handler['tipo'] ?? 'subtarea';
            $subtareas_textos = $data_for_handler['subtareas_textos'] ?? [];
            $regla_recurrencia = $data_for_handler['regla_recurrencia'] ?? 'NONE';
            $fecha_inicio = $data_for_handler['fecha_inicio'] ?? getTodayDate();
            $parent_id = $data_for_handler['parent_id'] ?? null;
            $creado_en_ts = date('Y-m-d H:i:s');
            
            $mysqli->begin_transaction();

            if ($tipo === 'titulo' && empty($subtareas_textos)) { jsonResponse(["error" => "Un título debe tener al menos una subtarea."], 400); }

            $sql_main = "INSERT INTO tareas_dia_a_dia (texto, tipo, parent_id, activo, creado_en, regla_recurrencia, fecha_inicio, usuario_id) 
                         VALUES (?, ?, ?, TRUE, ?, ?, ?, ?)";
            $stmt_main = $mysqli->prepare($sql_main);
            $stmt_main->bind_param("ssisssi", $texto, $tipo, $parent_id, $creado_en_ts, $regla_recurrencia, $fecha_inicio, $usuario_id);
            if (!$stmt_main->execute()) { throw new Exception("Error al añadir tarea principal: " . $stmt_main->error); }
            $new_id = $mysqli->insert_id;
            $stmt_main->close();

            if ($tipo === 'titulo' && !empty($subtareas_textos)) {
                $sql_sub = "INSERT INTO tareas_dia_a_dia (texto, tipo, parent_id, activo, creado_en, regla_recurrencia, fecha_inicio, usuario_id) 
                            VALUES (?, 'subtarea', ?, TRUE, ?, ?, ?, ?)";
                $stmt_sub = $mysqli->prepare($sql_sub);
                foreach($subtareas_textos as $sub_texto) {
                    if(empty(trim($sub_texto))) continue;
                    $stmt_sub->bind_param("sisssi", $sub_texto, $new_id, $creado_en_ts, $regla_recurrencia, $fecha_inicio, $usuario_id);
                    if(!$stmt_sub->execute()) { throw new Exception("Error al añadir subtarea: " . $stmt_sub->error); }
                } 
                $stmt_sub->close();
            }

            $mysqli->commit();
            jsonResponse(["success" => true, "message" => "Elemento creado.", "id" => $new_id], 201);
        } catch (Exception $e) { 
            $mysqli->rollback(); 
            error_log("Error en POST /tareas-dia-a-dia (user $usuario_id): " . $e->getMessage());
            jsonResponse(["error" => "Error al crear tarea: " . $e->getMessage()], 500); 
        }
        break;

    case 'PUT':
        try {
            $id = $data_for_handler['id'] ?? null; 
            if ($id === null) { jsonResponse(["error" => "ID de tarea es requerido para actualizar."], 400); }

            $sql_check_owner = "SELECT id FROM tareas_dia_a_dia WHERE id = ? AND usuario_id = ?";
            $stmt_check = $mysqli->prepare($sql_check_owner);
            $stmt_check->bind_param("i", $id);
            $stmt_check->execute();
            if ($stmt_check->get_result()->num_rows === 0) {
                jsonResponse(["error" => "Tarea no encontrada o sin permiso."], 404);
            }
            $stmt_check->close();

            $mysqli->begin_transaction();
            
            if (isset($data_for_handler['texto'])) {
                $sql_update = "UPDATE tareas_dia_a_dia SET texto = ? WHERE id = ?";
                $stmt = $mysqli->prepare($sql_update);
                $stmt->bind_param("si", $data_for_handler['texto'], $id);
                $stmt->execute();
                $stmt->close();
            }

            if (isset($data_for_handler['fecha_inicio']) || isset($data_for_handler['regla_recurrencia'])) {
                $fields = []; $params = []; $types = "";
                if (isset($data_for_handler['fecha_inicio'])) { $fields[] = "fecha_inicio = ?"; $params[] = $data_for_handler['fecha_inicio']; $types .= "s"; }
                if (isset($data_for_handler['regla_recurrencia'])) { $fields[] = "regla_recurrencia = ?"; $params[] = $data_for_handler['regla_recurrencia']; $types .= "s"; }
                $params[] = $id; $types .= "i";
                $sql_update = "UPDATE tareas_dia_a_dia SET " . implode(", ", $fields) . " WHERE id = ?";
                $stmt = $mysqli->prepare($sql_update);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $stmt->close();
            }

            if (isset($data_for_handler['completado'])) {
                $fecha = $data_for_handler['fecha_actualizacion'] ?? getTodayDate();
                $completado = filter_var($data_for_handler['completado'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                $sql_completed = "INSERT INTO tareas_dia_a_dia_completadas (tarea_id, fecha, completado) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE completado = VALUES(completado)";
                $stmt = $mysqli->prepare($sql_completed);
                $stmt->bind_param("isi", $id, $fecha, $completado);
                $stmt->execute();
                $stmt->close();
            }

            if (isset($data_for_handler['activo'])) {
                $activo = filter_var($data_for_handler['activo'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
                $sql_active = "UPDATE tareas_dia_a_dia SET activo = ? WHERE id = ?";
                $stmt = $mysqli->prepare($sql_active);
                $stmt->bind_param("ii", $activo, $id);
                $stmt->execute();
                $stmt->close();
            }

            if (array_key_exists('parent_id', $data_for_handler)) {
                $parent_id = $data_for_handler['parent_id'];
                $sql_parent = "UPDATE tareas_dia_a_dia SET parent_id = ? WHERE id = ?";
                $stmt_parent = $mysqli->prepare($sql_parent);
                if ($parent_id === null) {
                    $stmt_parent->bind_param("si", $parent_id, $id);
                } else {
                    $stmt_parent->bind_param("ii", $parent_id, $id);
                }
                $stmt_parent->execute();
                $stmt_parent->close();
            }

            $mysqli->commit(); 
            jsonResponse(["success" => true, "message" => "Tarea actualizada."]);
        } catch (Exception $e) { 
            $mysqli->rollback(); 
            error_log("Error en PUT /tareas-dia-a-dia (user $usuario_id): " . $e->getMessage());
            jsonResponse(["error" => "Error al actualizar tarea: " . $e->getMessage()], 500); 
        }
        break;

    case 'DELETE':
    case 'HARD_DELETE':
        try {
            $id = $data_for_handler['id'] ?? null;
            if ($id === null) { jsonResponse(["error" => "ID es requerido para eliminar."], 400); }

            $mysqli->begin_transaction();
            
            $sql_check_owner = "SELECT tipo FROM tareas_dia_a_dia WHERE id = ? AND usuario_id = ?";
            $stmt_check = $mysqli->prepare($sql_check_owner);
            $stmt_check->bind_param("i", $id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            if ($result_check->num_rows === 0) { $mysqli->rollback(); jsonResponse(["error" => "Tarea no encontrada o sin permiso."], 404); }
            $tarea_info = $result_check->fetch_assoc();
            $stmt_check->close();

            if ($handler_http_method === 'DELETE') {
                 $sql = "UPDATE tareas_dia_a_dia SET activo = FALSE WHERE id = ?";
                 $stmt = $mysqli->prepare($sql);
                 $stmt->bind_param("i", $id);
                 $stmt->execute();
                 $stmt->close();
                 if ($tarea_info['tipo'] === 'titulo') {
                    $sql_subs = "UPDATE tareas_dia_a_dia SET activo = FALSE WHERE parent_id = ?";
                    $stmt_subs = $mysqli->prepare($sql_subs);
                    $stmt_subs->bind_param("i", $id);
                    $stmt_subs->execute();
                    $stmt_subs->close();
                 }
            } else {
                if ($tarea_info['tipo'] === 'titulo') {
                    $sql_del_subs = "DELETE FROM tareas_dia_a_dia WHERE parent_id = ?";
                    $stmt_del_subs = $mysqli->prepare($sql_del_subs);
                    $stmt_del_subs->bind_param("i", $id);
                    $stmt_del_subs->execute();
                    $stmt_del_subs->close();
                }
                $sql_del_comp = "DELETE FROM tareas_dia_a_dia_completadas WHERE tarea_id = ?";
                $stmt_del_comp = $mysqli->prepare($sql_del_comp);
                $stmt_del_comp->bind_param("i", $id);
                $stmt_del_comp->execute();
                $stmt_del_comp->close();

                $sql_del_main = "DELETE FROM tareas_dia_a_dia WHERE id = ?";
                $stmt_del_main = $mysqli->prepare($sql_del_main);
                $stmt_del_main->bind_param("i", $id);
                $stmt_del_main->execute();
                $stmt_del_main->close();
            }

            $mysqli->commit();
            jsonResponse(["success" => true, "message" => "Operación completada."]);
        } catch (Exception $e) {
            $mysqli->rollback();
            error_log("Error en DELETE/HARD_DELETE /tareas-dia-a-dia (user $usuario_id): " . $e->getMessage());
            jsonResponse(["error" => "Error durante la eliminación: " . $e->getMessage()], 500);
        }
        break;

    default:
        jsonResponse(["error" => "Método no soportado."], 405);
        break;
}
?>