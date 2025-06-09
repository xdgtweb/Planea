<?php
// api_handlers/tareas_por_fecha.php

// Este archivo es incluido por el api.php principal.
// Las variables $mysqli (de db_config.php, global), 
// $handler_http_method (el método HTTP efectivo: GET, POST, PUT, DELETE),
// y las funciones jsonResponse() y getTodayDate() están disponibles desde el router principal.

if (!isset($mysqli)) {
    jsonResponse(["error" => "Error crítico: Conexión a base de datos no disponible en tareas_por_fecha.php."], 500);
    exit;
}
if (!isset($handler_http_method)) {
    jsonResponse(["error" => "Error crítico: Método HTTP no determinado en tareas_por_fecha.php."], 500);
    exit;
}

// Este endpoint solo maneja GET
if ($handler_http_method === 'GET') {
    try {
        $fecha_consulta = $_GET['fecha'] ?? '';
        if (empty($fecha_consulta) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $fecha_consulta)) {
            jsonResponse(["error" => "Parámetro 'fecha' es requerido y debe estar en formato YYYY-MM-DD."], 400);
        }

        // Usar UTC para las fechas para consistencia en comparaciones
        $current_date_obj_param_tpf = new DateTimeImmutable($fecha_consulta, new DateTimeZone('UTC'));

        // 1. Obtener todas las tareas y su estado de completado para la fecha específica (si existe el registro de completado)
        $sql = "SELECT t.id, t.texto, t.tipo, t.parent_id, 
                       COALESCE(tc.completado, FALSE) as completado, 
                       t.activo, t.orden, t.fecha_inicio, t.regla_recurrencia, t.fecha_creacion 
                FROM tareas_dia_a_dia t 
                LEFT JOIN tareas_dia_a_dia_completadas tc ON t.id = tc.tarea_id AND tc.fecha = ? 
                ORDER BY t.activo DESC, t.orden ASC, t.id ASC"; // Orden base
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) { 
            throw new Exception("DB Prepare Error (tpf): " . $mysqli->error . " SQL: " . $sql); 
        }
        $stmt->bind_param("s", $fecha_consulta);
        if (!$stmt->execute()) { 
            throw new Exception("DB Execute Error (tpf): " . $stmt->error . " SQL: " . $sql); 
        }
        $result = $stmt->get_result();
        
        $all_tasks_from_db_temp = [];
        while ($row = $result->fetch_assoc()) { 
            // 2. Aplicar filtro de recurrencia en PHP para determinar si la tarea es aplicable a $fecha_consulta
            $mostrar_tarea_tpf = false;
            $fecha_base_recurrencia_obj = $row['fecha_inicio'] ? new DateTimeImmutable($row['fecha_inicio'], new DateTimeZone('UTC')) : new DateTimeImmutable($row['fecha_creacion'], new DateTimeZone('UTC'));
            
            if ($current_date_obj_param_tpf < $fecha_base_recurrencia_obj && ($row['regla_recurrencia'] === 'NONE' || !$row['regla_recurrencia'])) {
                // No mostrar si es una tarea NO recurrente y su fecha_inicio es futura.
            } else {
                 switch ($row['regla_recurrencia']) {
                    case 'NONE': 
                        if ($fecha_base_recurrencia_obj->format('Y-m-d') == $current_date_obj_param_tpf->format('Y-m-d')) $mostrar_tarea_tpf = true;
                        break;
                    case 'DAILY': 
                        if ($current_date_obj_param_tpf >= $fecha_base_recurrencia_obj) $mostrar_tarea_tpf = true;
                        break;
                    case (strpos($row['regla_recurrencia'], 'WEEKLY:') === 0):
                        if ($current_date_obj_param_tpf >= $fecha_base_recurrencia_obj) {
                            $parts = explode(':', $row['regla_recurrencia']); 
                            if(count($parts) === 2){ 
                                $days_of_week_abbr = explode(',', $parts[1]); 
                                $current_day_of_week_php = $current_date_obj_param_tpf->format('N'); // 1 (Mon) a 7 (Sun)
                                $day_map_php_to_abbr = [1=>'MON', 2=>'TUE', 3=>'WED', 4=>'THU', 5=>'FRI', 6=>'SAT', 7=>'SUN'];
                                $current_day_abbr_php = $day_map_php_to_abbr[$current_day_of_week_php] ?? '';
                                if (!empty($current_day_abbr_php) && in_array($current_day_abbr_php, $days_of_week_abbr)) $mostrar_tarea_tpf = true;
                            }
                        } 
                        break;
                    case 'MONTHLY_DAY': 
                        if ($current_date_obj_param_tpf >= $fecha_base_recurrencia_obj && $current_date_obj_param_tpf->format('d') == $fecha_base_recurrencia_obj->format('d')) $mostrar_tarea_tpf = true;
                        break;
                    default: // Para tareas con regla_recurrencia vacía o no reconocida (se asume como NONE)
                         if ($fecha_base_recurrencia_obj->format('Y-m-d') == $current_date_obj_param_tpf->format('Y-m-d')) $mostrar_tarea_tpf = true;
                        break;
                }
            }

            // Solo añadir tareas activas que son relevantes para el día
            if ($mostrar_tarea_tpf && (bool)$row['activo']) { 
                $row['completado'] = (bool)$row['completado'];
                $row['activo'] = true; // Ya hemos filtrado por activo=true
                $row['subtareas'] = []; 
                $all_tasks_from_db_temp[$row['id']] = $row; 
            }
        }
        $stmt->close();
        
        $tareas_final_output = [];
        // 3. Anidar subtareas bajo sus títulos (que también deben ser visibles hoy)
        foreach ($all_tasks_from_db_temp as $tarea_id => $tarea) {
            if ($tarea['parent_id'] !== null && isset($all_tasks_from_db_temp[$tarea['parent_id']])) {
                 $all_tasks_from_db_temp[$tarea['parent_id']]['subtareas'][] = $tarea;
            }
        }
        // 4. Filtrar solo tareas raíz (títulos) para la salida principal y ordenar subtareas
        foreach ($all_tasks_from_db_temp as $tarea_id => $tarea) {
            if ($tarea['parent_id'] === null && $tarea['tipo'] === 'titulo') { 
                if(!empty($tarea['subtareas'])){ 
                     usort($tarea['subtareas'],function($a,$b){
                         return ($a['orden'] ?? $a['id']) <=> ($b['orden'] ?? $b['id']); 
                     });
                }
                $tareas_final_output[]=$tarea;
            }
        }
        // 5. Ordenar la lista final de tareas raíz (títulos) por 'orden'
        usort($tareas_final_output, function($a, $b) { 
            return ($a['orden'] ?? $a['id']) <=> ($b['orden'] ?? $b['id']); 
        });

        jsonResponse($tareas_final_output);

    } catch (Exception $e) {
        error_log("Error en GET /tareas-por-fecha: " . $e->getMessage());
        jsonResponse(["error" => "Error al procesar la solicitud de tareas por fecha.", "details" => $e->getMessage()], 500);
    }
} else {
    jsonResponse(["error" => "Método " . htmlspecialchars($handler_http_method) . " no soportado para el endpoint /tareas-por-fecha."], 405);
}

?>