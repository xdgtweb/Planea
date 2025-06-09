<?php
// api_handlers/tareas_diarias.php

// Este archivo es incluido por el api.php principal.
// Las variables $mysqli (de db_config.php, global), 
// $handler_http_method (el método HTTP efectivo: GET, POST, PUT, DELETE),
// $data_for_handler (el payload JSON decodificado para POST, PUT, DELETE),
// y las funciones jsonResponse() y getTodayDate() están disponibles desde el router principal.

if (!isset($mysqli)) { 
    jsonResponse(["error" => "Error crítico: Conexión a base de datos no disponible en tareas_diarias.php."], 500);
    exit;
}
if (!isset($handler_http_method)) { 
    jsonResponse(["error" => "Error crítico: Método HTTP no determinado en tareas_diarias.php."], 500);
    exit;
}

switch ($handler_http_method) { // Usar la variable del router que ya consideró _method
    case 'GET':
        try {
            $fecha_solicitada = $_GET['fecha'] ?? getTodayDate();
            $solo_titulos_activos = isset($_GET['solo_titulos_activos']) && $_GET['solo_titulos_activos'] === 'true';

            if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $fecha_solicitada)) {
                jsonResponse(["error" => "Formato de fecha inválido para 'fecha_solicitada'. Use YYYY-MM-DD."], 400);
            }

            if ($solo_titulos_activos) {
                $sql_titulos = "SELECT id, texto, fecha_inicio, regla_recurrencia, fecha_creacion FROM tareas_dia_a_dia WHERE tipo = 'titulo' AND activo = TRUE ORDER BY orden, id";
                $stmt_titulos = $mysqli->prepare($sql_titulos);
                if (!$stmt_titulos) { throw new Exception("DB Error (tdd_g_t_prep): " . $mysqli->error . " SQL: " . $sql_titulos); }
                if(!$stmt_titulos->execute()){ throw new Exception("DB Error (tdd_g_t_exec): " . $stmt_titulos->error . " SQL: " . $sql_titulos); }
                $result_titulos = $stmt_titulos->get_result();
                $titulos_activos_del_dia = [];
                $current_date_obj_param = new DateTimeImmutable($fecha_solicitada, new DateTimeZone('UTC'));

                while ($row = $result_titulos->fetch_assoc()) {
                    $mostrar_titulo = false;
                    $fecha_inicio_titulo_obj = $row['fecha_inicio'] ? new DateTimeImmutable($row['fecha_inicio'], new DateTimeZone('UTC')) : new DateTimeImmutable($row['fecha_creacion'], new DateTimeZone('UTC'));
                    if ($current_date_obj_param < $fecha_inicio_titulo_obj && ($row['regla_recurrencia'] === 'NONE' || !$row['regla_recurrencia'])) continue;
                    switch ($row['regla_recurrencia']) {
                        case 'NONE': if ($fecha_inicio_titulo_obj->format('Y-m-d') == $current_date_obj_param->format('Y-m-d')) $mostrar_titulo = true; break;
                        case 'DAILY': if ($current_date_obj_param >= $fecha_inicio_titulo_obj) $mostrar_titulo = true; break;
                        case (strpos($row['regla_recurrencia'], 'WEEKLY:') === 0):
                            if ($current_date_obj_param >= $fecha_inicio_titulo_obj) {
                                $parts = explode(':', $row['regla_recurrencia']); 
                                if(count($parts) === 2) { 
                                    $days_of_week_abbr = explode(',', $parts[1]); 
                                    $current_day_of_week_php = $current_date_obj_param->format('N'); // 1 (Mon) a 7 (Sun)
                                    $day_map_php_to_abbr = [1=>'MON', 2=>'TUE', 3=>'WED', 4=>'THU', 5=>'FRI', 6=>'SAT', 7=>'SUN'];
                                    $current_day_abbr_php = $day_map_php_to_abbr[$current_day_of_week_php] ?? '';
                                    if (!empty($current_day_abbr_php) && in_array($current_day_abbr_php, $days_of_week_abbr)) $mostrar_titulo = true;
                                }
                            } break;
                        case 'MONTHLY_DAY': if ($current_date_obj_param >= $fecha_inicio_titulo_obj && $current_date_obj_param->format('d') == $fecha_inicio_titulo_obj->format('d')) $mostrar_titulo = true; break;
                        default: if ($row['fecha_inicio'] == $current_date_obj_param->format('Y-m-d')) $mostrar_titulo = true; else if (!$row['fecha_inicio'] && $row['fecha_creacion'] == $current_date_obj_param->format('Y-m-d')) $mostrar_titulo = true; break;
                    }
                    if ($mostrar_titulo) { $titulos_activos_del_dia[] = ['id' => $row['id'], 'texto' => $row['texto']]; }
                }
                $stmt_titulos->close();
                jsonResponse($titulos_activos_del_dia);
            } else { 
                $sql_maestra = "SELECT id, texto, tipo, parent_id, fecha_creacion, orden, activo, fecha_inicio, regla_recurrencia FROM tareas_dia_a_dia ORDER BY activo DESC, orden ASC, id ASC"; 
                $stmt_maestra = $mysqli->prepare($sql_maestra);
                if (!$stmt_maestra) { throw new Exception("DB Error (tdd_g1_all_prep): " . $mysqli->error . " SQL: " . $sql_maestra); }
                if(!$stmt_maestra->execute()){ throw new Exception("DB Error (tdd_g1_all_exec): " . $stmt_maestra->error . " SQL: " . $sql_maestra); }
                $result_maestra = $stmt_maestra->get_result();
                $all_tasks_from_db_temp = []; 
                $current_date_obj_param = new DateTimeImmutable($fecha_solicitada, new DateTimeZone('UTC'));
                while ($row = $result_maestra->fetch_assoc()) {
                    $mostrar_tarea = false;
                    $fecha_inicio_tarea_obj = $row['fecha_inicio'] ? new DateTimeImmutable($row['fecha_inicio'], new DateTimeZone('UTC')) : new DateTimeImmutable($row['fecha_creacion'], new DateTimeZone('UTC'));
                    if ($current_date_obj_param < $fecha_inicio_tarea_obj && ($row['regla_recurrencia'] === 'NONE' || !$row['regla_recurrencia'])) continue; 
                    switch ($row['regla_recurrencia']) {
                        case 'NONE': if ($fecha_inicio_tarea_obj->format('Y-m-d') == $current_date_obj_param->format('Y-m-d')) $mostrar_tarea = true; break;
                        case 'DAILY': if ($current_date_obj_param >= $fecha_inicio_tarea_obj) $mostrar_tarea = true; break;
                        case (strpos($row['regla_recurrencia'], 'WEEKLY:') === 0):
                            if ($current_date_obj_param >= $fecha_inicio_tarea_obj) {
                                $parts = explode(':', $row['regla_recurrencia']); 
                                if(count($parts) === 2){ 
                                    $days_of_week_abbr = explode(',', $parts[1]); 
                                    $current_day_of_week_php = $current_date_obj_param->format('N');
                                    $day_map_php_to_abbr = [1=>'MON', 2=>'TUE', 3=>'WED', 4=>'THU', 5=>'FRI', 6=>'SAT', 7=>'SUN'];
                                    $current_day_abbr_php = $day_map_php_to_abbr[$current_day_of_week_php] ?? '';
                                    if (!empty($current_day_abbr_php) && in_array($current_day_abbr_php, $days_of_week_abbr)) $mostrar_tarea = true;
                                }
                            } break;
                        case 'MONTHLY_DAY': if ($current_date_obj_param >= $fecha_inicio_tarea_obj && $current_date_obj_param->format('d') == $fecha_inicio_tarea_obj->format('d')) $mostrar_tarea = true; break;
                        default: if ($row['fecha_inicio'] == $current_date_obj_param->format('Y-m-d')) $mostrar_tarea = true; else if (!$row['fecha_inicio'] && $row['fecha_creacion'] == $current_date_obj_param->format('Y-m-d')) $mostrar_tarea = true; break;
                    }
                    if ($mostrar_tarea) {
                        $row['activo'] = (bool)$row['activo']; $all_tasks_from_db_temp[$row['id']] = $row;
                        $all_tasks_from_db_temp[$row['id']]['subtareas'] = []; $all_tasks_from_db_temp[$row['id']]['completado'] = false;
                    }
                }
                $stmt_maestra->close();
                if (!empty($all_tasks_from_db_temp)) {
                    $task_ids_for_completion_check = array_keys($all_tasks_from_db_temp);
                    if (!empty($task_ids_for_completion_check)) {
                        $placeholders_comp = implode(',', array_fill(0, count($task_ids_for_completion_check), '?'));
                        $types_comp = str_repeat('i', count($task_ids_for_completion_check)) . 's'; 
                        $params_comp = $task_ids_for_completion_check; $params_comp[] = $fecha_solicitada;
                        $sql_completadas = "SELECT tarea_id, completado FROM tareas_dia_a_dia_completadas WHERE tarea_id IN ($placeholders_comp) AND fecha = ?";
                        $stmt_completadas = $mysqli->prepare($sql_completadas);
                        if (!$stmt_completadas) { throw new Exception("DB Error (tdd_g2_all_prep): " . $mysqli->error . " SQL: " . $sql_completadas); }
                        $stmt_completadas->bind_param($types_comp, ...$params_comp); 
                        if(!$stmt_completadas->execute()){ throw new Exception("DB Error (tdd_g2_all_exec): " . $stmt_completadas->error . " SQL: " . $sql_completadas); }
                        $result_completadas = $stmt_completadas->get_result();
                        while ($row_comp = $result_completadas->fetch_assoc()) {
                            if (isset($all_tasks_from_db_temp[$row_comp['tarea_id']]) && $all_tasks_from_db_temp[$row_comp['tarea_id']]['activo']) {
                                $all_tasks_from_db_temp[$row_comp['tarea_id']]['completado'] = (bool)$row_comp['completado'];
                            }
                        } $stmt_completadas->close();
                    }
                }
                $tareas_final_output = [];
                foreach ($all_tasks_from_db_temp as $tarea_id => $tarea) { if ($tarea['parent_id'] !== null && isset($all_tasks_from_db_temp[$tarea['parent_id']])) { $all_tasks_from_db_temp[$tarea['parent_id']]['subtareas'][]=$tarea;}}
                foreach ($all_tasks_from_db_temp as $tarea_id => $tarea) { if ($tarea['parent_id'] === null) { if(!empty($tarea['subtareas'])){ usort($tarea['subtareas'],function($a,$b){ if ($a['activo'] !== $b['activo']) return $a['activo'] ? -1 : 1; return ($a['orden']??$a['id'])<=>($b['orden']??$b['id']); });} $tareas_final_output[]=$tarea;}}
                usort($tareas_final_output, function($a, $b) { if ($a['activo'] !== $b['activo']) { return $a['activo'] ? -1 : 1; } return ($a['orden'] ?? $a['id']) <=> ($b['orden'] ?? $b['id']); });
                jsonResponse($tareas_final_output);
            }
        } catch (Exception $e) {
            error_log("Error en GET /tareas-dia-a-dia: " . $e->getMessage());
            jsonResponse(["error" => "No se pudieron obtener las tareas del día.", "details" => $e->getMessage()], 500);
        }
        break;
        case 'POST': // Acción: Crear nueva Tarea/Título
        try {
            // $data_for_handler ya contiene el cuerpo JSON decodificado por el router principal.
            if (empty($data_for_handler)) {
                jsonResponse(["error" => "No se recibió payload para la creación de la tarea."], 400);
            }

            $texto = $data_for_handler['texto'] ?? ''; 
            $tipo = $data_for_handler['tipo'] ?? 'subtarea';
            $subtareas_textos = $data_for_handler['subtareas_textos'] ?? [];
            
            $fechas_seleccionadas = $data_for_handler['fechas_seleccionadas'] ?? []; 
            $fecha_inicio_single = $data_for_handler['fecha_inicio'] ?? null;       
            $regla_recurrencia_payload = $data_for_handler['regla_recurrencia'] ?? 'NONE';
            $periodo_meses_str = $data_for_handler['periodo_meses'] ?? null;      
            
            $emoji_anotacion = $data_for_handler['emoji_anotacion'] ?? null;       
            $descripcion_anotacion = $data_for_handler['descripcion_anotacion'] ?? null;

            if (empty($texto)) { jsonResponse(["error" => "El texto principal no puede estar vacío."], 400); }
            if ($tipo === 'titulo' && empty($subtareas_textos)) { jsonResponse(["error" => "Un título debe tener al menos una subtarea al crearse."], 400); }
            
            $noFechasEspecificas = empty($fechas_seleccionadas);
            $noFechaInicioSingle = !$fecha_inicio_single;
            $esRecurrenciaQueNecesitaFechaInicio = ($regla_recurrencia_payload === 'DAILY' || 
                                                 strpos($regla_recurrencia_payload, 'WEEKLY:') === 0 || 
                                                 $regla_recurrencia_payload === 'MONTHLY_DAY' ||
                                                 $regla_recurrencia_payload === 'NONE');

            if ($regla_recurrencia_payload === 'SPECIFIC_DATES' && $noFechasEspecificas) {
                jsonResponse(["error" => "Para 'Días específicos', debe seleccionar al menos una fecha del calendario."], 400);
            } elseif ( $regla_recurrencia_payload !== 'SPECIFIC_DATES' && 
                       strpos($regla_recurrencia_payload, 'PERIOD_') !== 0 &&
                       $esRecurrenciaQueNecesitaFechaInicio && $noFechaInicioSingle
                     ) {
                 jsonResponse(["error" => "Debe proporcionar una fecha de inicio para este tipo de recurrencia ('".htmlspecialchars($regla_recurrencia_payload)."')."], 400);
            }

            $mysqli->begin_transaction();
            
            $dates_to_process = [];
            $final_regla_para_db = $regla_recurrencia_payload;
            $base_fecha_inicio_para_calculos = $fecha_inicio_single ?: (!empty($fechas_seleccionadas) ? $fechas_seleccionadas[0] : getTodayDate());

            if ($regla_recurrencia_payload === 'SPECIFIC_DATES' && !empty($fechas_seleccionadas)) {
                $dates_to_process = $fechas_seleccionadas;
                $final_regla_para_db = 'NONE'; 
            } elseif (strpos($regla_recurrencia_payload, 'PERIOD_') === 0 && $periodo_meses_str !== null) {
                $start_obj = new DateTimeImmutable($base_fecha_inicio_para_calculos, new DateTimeZone('UTC')); 
                $end_obj = new DateTimeImmutable($base_fecha_inicio_para_calculos, new DateTimeZone('UTC'));
                if($periodo_meses_str === "CURRENT_MONTH") {
                    $end_obj = $end_obj->modify('last day of this month');
                } else { 
                    $num_meses = intval($periodo_meses_str);
                    if ($num_meses > 0 && $num_meses <= 12) { 
                         $end_obj = $end_obj->modify("+" . $num_meses . " months -1 day");
                    } else {
                        throw new Exception("Número de meses para el periodo no válido: " . htmlspecialchars($periodo_meses_str));
                    }
                }
                $interval = new DateInterval('P1D');
                $period = new DatePeriod($start_obj, $interval, $end_obj->modify('+1 day')); 
                foreach($period as $dt) { $dates_to_process[] = $dt->format('Y-m-d'); }
                $final_regla_para_db = 'NONE'; 
            } else { 
                $dates_to_process[] = $base_fecha_inicio_para_calculos; 
            }
            
            $created_items_summary = []; 
            $fecha_creacion_real = getTodayDate(); 

            foreach ($dates_to_process as $task_date_for_loop) {
                $current_task_regla_final = (count($dates_to_process) > 1 && ($regla_recurrencia_payload === 'SPECIFIC_DATES' || strpos($regla_recurrencia_payload, 'PERIOD_') === 0)) ? 'NONE' : $final_regla_para_db;
                
                $sql_main = "INSERT INTO tareas_dia_a_dia (texto, tipo, parent_id, activo, fecha_creacion, orden, regla_recurrencia, fecha_inicio) 
                             VALUES (?, ?, NULL, TRUE, ?, (SELECT IFNULL(MAX(orden), -1) + 1 FROM tareas_dia_a_dia t2 WHERE t2.parent_id IS NULL AND t2.tipo = 'titulo'), ?, ?)";
                $stmt_main = $mysqli->prepare($sql_main); 
                if(!$stmt_main){throw new Exception("DB err tdd_c_main (prep): ".$mysqli->error. " SQL: ".$sql_main);}
                $stmt_main->bind_param("sssss", $texto, $tipo, $fecha_creacion_real, $current_task_regla_final, $task_date_for_loop);
                if (!$stmt_main->execute()) { throw new Exception("Error al añadir tarea/título para ".$task_date_for_loop.": " . $stmt_main->error); }
                $new_id = $mysqli->insert_id; 
                $stmt_main->close();
                $created_items_summary[] = ["id" => $new_id, "fecha_inicio" => $task_date_for_loop];

                if ($tipo === 'titulo' && !empty($subtareas_textos)) {
                    $sql_sub = "INSERT INTO tareas_dia_a_dia (texto, tipo, parent_id, activo, fecha_creacion, orden, regla_recurrencia, fecha_inicio) 
                                VALUES (?, 'subtarea', ?, TRUE, ?, (SELECT IFNULL(MAX(orden), -1) + 1 FROM tareas_dia_a_dia t2 WHERE t2.parent_id = ?), ?, ?)";
                    $stmt_sub = $mysqli->prepare($sql_sub); 
                    if(!$stmt_sub){throw new Exception("DB err tdd_cs_loop (prep): ".$mysqli->error. " SQL: ".$sql_sub);}
                    foreach($subtareas_textos as $sub_texto) {
                        if(empty(trim($sub_texto))) continue;
                        $stmt_sub->bind_param("sisiss", $sub_texto, $new_id, $fecha_creacion_real, $new_id, $current_task_regla_final, $task_date_for_loop);
                        if(!$stmt_sub->execute()) { throw new Exception("Error al añadir subtarea '".htmlspecialchars($sub_texto)."': ".$stmt_sub->error); }
                    } 
                    $stmt_sub->close();
                }
                
                // Guardar anotación para CADA fecha del bucle si se proporcionó
                if (!empty($emoji_anotacion) || !empty($descripcion_anotacion)) {
                     $sql_anot = "INSERT INTO dia_anotaciones (fecha, emoji, descripcion) VALUES (?, ?, ?) 
                                  ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), descripcion = VALUES(descripcion)";
                     $stmt_anot = $mysqli->prepare($sql_anot); 
                     if(!$stmt_anot){ 
                         error_log("DB err anot_c_loop (prepare) para fecha ".$task_date_for_loop.": ".$mysqli->error); 
                     } else {
                         $stmt_anot->bind_param("sss", $task_date_for_loop, $emoji_anotacion, $descripcion_anotacion);
                         if(!$stmt_anot->execute()) { 
                             error_log("Error al guardar anotación para fecha ".$task_date_for_loop.": ".$stmt_anot->error); 
                         }
                         $stmt_anot->close();
                     }
                }
            }
            $mysqli->commit();
            jsonResponse(["success" => true, "message" => "Tarea(s) y/o título(s) añadidos.", "items_creados" => count($created_items_summary)], 201);
        } catch (Exception $e) { 
            $mysqli->rollback(); 
            error_log("Error en POST /tareas-dia-a-dia (crear): " . $e->getMessage());
            jsonResponse(["error" => "Error al crear tarea: " . $e->getMessage()], 500); 
        }
        break; // Fin de POST (crear) para tareas-dia-a-dia
        case 'PUT': // Acción: Actualizar Tarea/Título existente
        try {
            // $data_for_handler ya contiene el cuerpo JSON decodificado por el router principal
            $id = $data_for_handler['id'] ?? null; 
            $texto = $data_for_handler['texto'] ?? null; 
            $completado = $data_for_handler['completado'] ?? null; 
            $activo_estado = $data_for_handler['activo'] ?? null; 
            $nuevo_parent_id = array_key_exists('parent_id', $data_for_handler) ? ($data_for_handler['parent_id'] === null || $data_for_handler['parent_id'] === "NULL" || $data_for_handler['parent_id'] === "" ? null : (int)$data_for_handler['parent_id']) : 'NO_CHANGE';
            $fecha_actualizacion_completado = $data_for_handler['fecha_actualizacion'] ?? getTodayDate();
            
            $nueva_fecha_inicio = $data_for_handler['fecha_inicio'] ?? null;
            $nueva_regla_recurrencia = $data_for_handler['regla_recurrencia'] ?? null;

            if ($id === null) { 
                jsonResponse(["error" => "ID de tarea es requerido para actualizar."], 400); 
            }
            if ($texto === null && $completado === null && $activo_estado === null && $nuevo_parent_id === 'NO_CHANGE' && $nueva_fecha_inicio === null && $nueva_regla_recurrencia === null) {
                 jsonResponse(["error" => "No se proporcionaron datos para actualizar la tarea."], 400);
            }
            
            $mysqli->begin_transaction();
            
            // 1. Actualizar texto principal si se proporcionó
            if ($texto !== null) {
                $sql_update_text = "UPDATE tareas_dia_a_dia SET texto = ? WHERE id = ?";
                $stmt_text = $mysqli->prepare($sql_update_text); 
                if(!$stmt_text){throw new Exception("DB err tdd_ut (prep):".$mysqli->error." SQL: ".$sql_update_text);}
                $stmt_text->bind_param("si", $texto, $id);
                if (!$stmt_text->execute()) throw new Exception("Err exec tdd_ut: " . $stmt_text->error); 
                $stmt_text->close();
            }
            
            // 2. Actualizar programación (fecha_inicio, regla_recurrencia) si se proporcionó
            if ($nueva_fecha_inicio !== null || $nueva_regla_recurrencia !== null) {
                $update_fields_schedule = []; 
                $types_schedule = ""; 
                $params_schedule_bind_values = []; 
                
                if ($nueva_fecha_inicio !== null) { $update_fields_schedule[] = "fecha_inicio = ?"; $types_schedule .= "s"; $params_schedule_bind_values[] = $nueva_fecha_inicio; }
                if ($nueva_regla_recurrencia !== null) { $update_fields_schedule[] = "regla_recurrencia = ?"; $types_schedule .= "s"; $params_schedule_bind_values[] = $nueva_regla_recurrencia; }
                $params_schedule_bind_values[] = $id; $types_schedule .= "i";

                if (!empty($update_fields_schedule)) {
                    $sql_update_schedule = "UPDATE tareas_dia_a_dia SET " . implode(", ", $update_fields_schedule) . " WHERE id = ?";
                    $stmt_schedule = $mysqli->prepare($sql_update_schedule); 
                    if(!$stmt_schedule){throw new Exception("DB err tdd_us (prep):".$mysqli->error." SQL: ".$sql_update_schedule);}
                    $stmt_schedule->bind_param($types_schedule, ...$params_schedule_bind_values);
                    if (!$stmt_schedule->execute()) throw new Exception("Err exec tdd_us: " . $stmt_schedule->error); 
                    $stmt_schedule->close();
                    
                    $info_tipo_sql = "SELECT tipo FROM tareas_dia_a_dia WHERE id = ?";
                    $stmt_tipo_check = $mysqli->prepare($info_tipo_sql); 
                    if(!$stmt_tipo_check){throw new Exception("DB err tdd_gtc (prep):".$mysqli->error." SQL: ".$info_tipo_sql);}
                    $stmt_tipo_check->bind_param("i", $id); $stmt_tipo_check->execute(); $result_tipo_check = $stmt_tipo_check->get_result();
                    $tarea_info_check = $result_tipo_check->fetch_assoc(); $stmt_tipo_check->close();

                    if ($tarea_info_check && $tarea_info_check['tipo'] === 'titulo') {
                        $params_subs_schedule_bind_values = array_slice($params_schedule_bind_values, 0, -1); 
                        $params_subs_schedule_bind_values[] = $id; 
                        
                        $sql_update_subs_schedule = "UPDATE tareas_dia_a_dia SET " . implode(", ", $update_fields_schedule) . " WHERE parent_id = ?";
                        $stmt_subs_schedule = $mysqli->prepare($sql_update_subs_schedule); 
                        if(!$stmt_subs_schedule){throw new Exception("DB err tdd_uss (prep):".$mysqli->error." SQL: ".$sql_update_subs_schedule);}
                        $stmt_subs_schedule->bind_param($types_schedule, ...$params_subs_schedule_bind_values); 
                        if (!$stmt_subs_schedule->execute()) throw new Exception("Err exec tdd_uss: " . $stmt_subs_schedule->error);
                        $stmt_subs_schedule->close();
                    }
                }
            }

            // 3. Actualizar estado de completado
            if ($completado !== null) {
                 $puede_actualizar_completado = true;
                if ($activo_estado !== null && $activo_estado === false) { $puede_actualizar_completado = false; }
                elseif ($activo_estado === null) { 
                    $sql_check_activo = "SELECT activo FROM tareas_dia_a_dia WHERE id = ?";
                    $stmt_ca = $mysqli->prepare($sql_check_activo); if(!$stmt_ca) throw new Exception ("DB err tdd_uca1_prep:".$mysqli->error." SQL: ".$sql_check_activo);
                    $stmt_ca->bind_param("i", $id); $stmt_ca->execute(); $res_ca = $stmt_ca->get_result(); $tarea_actual = $res_ca->fetch_assoc(); $stmt_ca->close();
                    if ($tarea_actual && !$tarea_actual['activo']) $puede_actualizar_completado = false; 
                }
                if ($puede_actualizar_completado) {
                    $sql_update_completed = "INSERT INTO tareas_dia_a_dia_completadas (tarea_id, fecha, completado) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE completado = VALUES(completado)";
                    $stmt_completed = $mysqli->prepare($sql_update_completed); if(!$stmt_completed){throw new Exception("DB err tdd_uc_prep:".$mysqli->error." SQL: ".$sql_update_completed);}
                    $completado_int = $completado ? 1 : 0;
                    $stmt_completed->bind_param("isi", $id, $fecha_actualizacion_completado, $completado_int);
                    if (!$stmt_completed->execute()) throw new Exception("Err exec tdd_uc: " . $stmt_completed->error); $stmt_completed->close();
                } elseif ($completado === true) { error_log("Intento de completar tarea ID: $id que está/será inactiva en fecha $fecha_actualizacion_completado"); }
            }

            // 4. Actualizar parent_id
            if ($nuevo_parent_id !== 'NO_CHANGE') {
                $sql_update_parent = "UPDATE tareas_dia_a_dia SET parent_id = ? WHERE id = ?";
                $stmt_parent = $mysqli->prepare($sql_update_parent); if(!$stmt_parent){throw new Exception("DB err tdd_up_prep:".$mysqli->error." SQL: ".$sql_update_parent);}
                if ($nuevo_parent_id === null) { $param_null_for_bind = null; $stmt_parent->bind_param("si", $param_null_for_bind, $id); } 
                else { $stmt_parent->bind_param("ii", $nuevo_parent_id, $id); }
                if (!$stmt_parent->execute()) throw new Exception("Err exec tdd_up: " . $stmt_parent->error); $stmt_parent->close();
            }

            // 5. Actualizar estado activo y manejar cascada
            if ($activo_estado !== null) {
                $activo_int_val = $activo_estado ? 1 : 0;
                $sql_update_active = "UPDATE tareas_dia_a_dia SET activo = ? WHERE id = ?";
                $stmt_active = $mysqli->prepare($sql_update_active); if(!$stmt_active){throw new Exception("DB err tdd_ua_prep:".$mysqli->error." SQL: ".$sql_update_active);}
                $stmt_active->bind_param("ii", $activo_int_val, $id);
                if (!$stmt_active->execute()) throw new Exception("Err exec tdd_ua:".$stmt_active->error); $stmt_active->close();
                
                if ($activo_int_val === 1) { 
                    $tipo_tarea_actual = ''; $parent_id_actual_db = null;
                    $info_sql = "SELECT tipo, parent_id FROM tareas_dia_a_dia WHERE id = ?";
                    $stmt_info = $mysqli->prepare($info_sql); if(!$stmt_info){throw new Exception("DB err tdd_gi_put_prep:".$mysqli->error." SQL: ".$info_sql);}
                    $stmt_info->bind_param("i", $id); $stmt_info->execute(); $result_info = $stmt_info->get_result();
                    if($info_row = $result_info->fetch_assoc()){ $tipo_tarea_actual = $info_row['tipo']; $parent_id_actual_db = $info_row['parent_id'];} 
                    $stmt_info->close();

                    if ($tipo_tarea_actual === 'titulo') {
                        $sql_reactivate_subs = "UPDATE tareas_dia_a_dia SET activo = TRUE WHERE parent_id = ?";
                        $stmt_react_subs = $mysqli->prepare($sql_reactivate_subs); if(!$stmt_react_subs){throw new Exception("DB err tdd_ras_prep:".$mysqli->error." SQL: ".$sql_reactivate_subs);}
                        $stmt_react_subs->bind_param("i", $id); if(!$stmt_react_subs->execute()) throw new Exception("Err exec tdd_ras:".$stmt_react_subs->error); $stmt_react_subs->close();
                    } elseif ($tipo_tarea_actual === 'subtarea' && $parent_id_actual_db !== null) { 
                         $sql_check_parent = "SELECT activo FROM tareas_dia_a_dia WHERE id = ?";
                        $stmt_cp = $mysqli->prepare($sql_check_parent); if(!$stmt_cp) throw new Exception("DB err (tcp_put_prep): ".$mysqli->error." SQL: ".$sql_check_parent);
                        $stmt_cp->bind_param("i", $parent_id_actual_db); $stmt_cp->execute(); $result_cp = $stmt_cp->get_result(); $parent_info = $result_cp->fetch_assoc(); $stmt_cp->close();
                        if ($parent_info && !$parent_info['activo']) {
                            $sql_activate_parent = "UPDATE tareas_dia_a_dia SET activo = TRUE WHERE id = ?";
                            $stmt_ap = $mysqli->prepare($sql_activate_parent); if(!$stmt_ap){throw new Exception("DB err tdd_ap_put_prep:".$mysqli->error." SQL: ".$sql_activate_parent);}
                            $stmt_ap->bind_param("i", $parent_id_actual_db); if(!$stmt_ap->execute()) throw new Exception("Err exec tdd_ap_put:".$stmt_ap->error); $stmt_ap->close();
                        }
                    }
                }
            }
            $mysqli->commit(); 
            jsonResponse(["success" => true, "message" => "Tarea actualizada."]);
        } catch (Exception $e) { 
            $mysqli->rollback(); 
            error_log("Error en PUT /tareas-dia-a-dia: " . $e->getMessage());
            jsonResponse(["error" => "Error al actualizar tarea: " . $e->getMessage()], 500); 
        }
        break; // Fin de PUT para tareas-dia-a-dia
        case 'DELETE': // Acción: Borrado Suave (marcar como inactivo)
        try {
            // $data_for_handler ya contiene el cuerpo JSON decodificado por el router principal
            // El router principal ya ha determinado que $handler_http_method es 'DELETE'
            $id = $data_for_handler['id'] ?? null; 
            $tipo_tarea_original = $data_for_handler['tipo'] ?? null; 

            if ($id === null) { 
                jsonResponse(["error" => "ID de tarea es requerido para borrado suave."], 400); 
            }
            
            $mysqli->begin_transaction();
            
            $sql_soft_delete = "";
            if ($tipo_tarea_original === 'subtarea') {
                 $sql_soft_delete = "UPDATE tareas_dia_a_dia SET activo = FALSE, parent_id = NULL WHERE id = ?";
            } else { 
                 $sql_soft_delete = "UPDATE tareas_dia_a_dia SET activo = FALSE WHERE id = ?";
            }
            
            $stmt_soft_del = $mysqli->prepare($sql_soft_delete);
            if(!$stmt_soft_del){throw new Exception("DB err tdd_sd (prep): ".$mysqli->error." SQL: ".$sql_soft_delete);}
            $stmt_soft_del->bind_param("i", $id);
            if (!$stmt_soft_del->execute()) { throw new Exception("Error en borrado suave: ".$stmt_soft_del->error); }
            $stmt_soft_del->close();

            if ($tipo_tarea_original === 'titulo') { 
                $sql_soft_delete_subs = "UPDATE tareas_dia_a_dia SET activo = FALSE WHERE parent_id = ?";
                $stmt_soft_del_subs = $mysqli->prepare($sql_soft_delete_subs);
                if(!$stmt_soft_del_subs){throw new Exception("DB err tdd_sds (prep): ".$mysqli->error." SQL: ".$sql_soft_delete_subs);}
                $stmt_soft_del_subs->bind_param("i", $id);
                if (!$stmt_soft_del_subs->execute()) { throw new Exception("Error en borrado suave de subtareas: ".$stmt_soft_del_subs->error); }
                $stmt_soft_del_subs->close();
            }
            
            $mysqli->commit();
            jsonResponse(["success" => true, "message" => "Elemento(s) marcado(s) como inactivo(s)."]);
        } catch (Exception $e) {
            $mysqli->rollback();
            error_log("Error en DELETE /tareas-dia-a-dia (soft): " . $e->getMessage());
            jsonResponse(["error" => "Error durante el borrado suave: " . $e->getMessage()], 500);
        }
        break; // Fin de DELETE para tareas-dia-a-dia

    case 'HARD_DELETE': // Acción: Borrado Permanente
        try {
            // $data_for_handler ya contiene el cuerpo JSON decodificado
            // El router principal ya ha determinado que $handler_http_method es 'HARD_DELETE'
            $id = $data_for_handler['id'] ?? null;
            $tipo_tarea_original = $data_for_handler['tipo'] ?? null; 
            
            if ($id === null) { jsonResponse(["error" => "ID es requerido para eliminación permanente."], 400); }

            $mysqli->begin_transaction();
            
            if ($tipo_tarea_original === 'titulo') {
                $sql_delete_subs = "DELETE FROM tareas_dia_a_dia WHERE parent_id = ?";
                $stmt_delete_subs = $mysqli->prepare($sql_delete_subs); 
                if(!$stmt_delete_subs){throw new Exception("DB err hd_ds_prep: ".$mysqli->error." SQL: ".$sql_delete_subs);}
                $stmt_delete_subs->bind_param("i", $id); 
                if(!$stmt_delete_subs->execute()){throw new Exception("Err exec hd_ds: ".$stmt_delete_subs->error);} 
                $stmt_delete_subs->close();
            }
            
            $sql_hard_delete = "DELETE FROM tareas_dia_a_dia WHERE id = ?";
            $stmt_hard_del = $mysqli->prepare($sql_hard_delete);
            if(!$stmt_hard_del){throw new Exception("DB err tdd_hd (prep): ".$mysqli->error." SQL: ".$sql_hard_delete);}
            $stmt_hard_del->bind_param("i", $id);
            if (!$stmt_hard_del->execute()) { throw new Exception("Error en borrado permanente: ".$stmt_hard_del->error); }
            $affected_rows = $stmt_hard_del->affected_rows;
            $stmt_hard_del->close();
            
            $mysqli->commit();
            if ($affected_rows > 0) {
                jsonResponse(["success" => true, "message" => "Elemento eliminado permanentemente de la lista de tareas."]);
            } else {
                 jsonResponse(["success" => false, "message" => "No se encontró el elemento para eliminar permanentemente o ya fue eliminado."], 404);
            }
        } catch (Exception $e) {
            $mysqli->rollback();
            error_log("Error en HARD_DELETE /tareas-dia-a-dia: " . $e->getMessage());
            jsonResponse(["error" => "Error durante el borrado permanente: " . $e->getMessage()], 500);
        }
        break; // Fin de HARD_DELETE para tareas-dia-a-dia

    default: // Para el switch($handler_http_method) principal de este archivo
        jsonResponse(["error" => "Método " . htmlspecialchars($handler_http_method) . " no soportado para el endpoint /tareas-dia-a-dia."], 405);
        break;
} // Fin del switch ($handler_http_method)

?>