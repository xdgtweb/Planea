<?php
// api_handlers/tareas_por_fecha.php

if (!isset($_SESSION['usuario_id'])) { jsonResponse(["error" => "No autorizado."], 401); exit; }
$usuario_id = $_SESSION['usuario_id'];

if (!isset($mysqli)) { jsonResponse(["error" => "Error crítico: Conexión a base de datos no disponible."], 500); exit; }
if (!isset($handler_http_method)) { jsonResponse(["error" => "Error crítico: Método HTTP no determinado."], 500); exit; }

if ($handler_http_method === 'GET') {
    try {
        $fecha_consulta = $_GET['fecha'] ?? '';
        if (empty($fecha_consulta) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $fecha_consulta)) {
            jsonResponse(["error" => "Parámetro 'fecha' es requerido y debe estar en formato YYYY-MM-DD."], 400);
        }

        $sql = "SELECT t.id, t.texto, t.tipo, t.parent_id, 
                       COALESCE(tc.completado, FALSE) as completado, 
                       t.activo, t.orden, t.fecha_inicio, t.regla_recurrencia, t.creado_en
                FROM tareas_dia_a_dia t 
                LEFT JOIN tareas_dia_a_dia_completadas tc ON t.id = tc.tarea_id AND tc.fecha = ?
                WHERE t.usuario_id = ? 
                ORDER BY t.activo DESC, t.orden ASC, t.id ASC";
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) { throw new Exception("DB Prepare Error (tpf): " . $mysqli->error); }
        $stmt->bind_param("si", $fecha_consulta, $usuario_id);
        if (!$stmt->execute()) { throw new Exception("DB Execute Error (tpf): " . $stmt->error); }
        $result = $stmt->get_result();
        
        $all_tasks_from_db_temp = [];
        $current_date_obj_param_tpf = new DateTimeImmutable($fecha_consulta, new DateTimeZone('UTC'));
        
        while ($row = $result->fetch_assoc()) { 
            try {
                $fecha_base_str = $row['fecha_inicio'] ?: $row['creado_en'];
                if (empty($fecha_base_str) || strpos($fecha_base_str, '0000-00-00') === 0) continue;
                $fecha_base_recurrencia_obj = new DateTimeImmutable($fecha_base_str, new DateTimeZone('UTC'));
            } catch (Throwable $th) {
                error_log("Saltando tarea ID {$row['id']} en tareas_por_fecha.php por fecha inválida: '{$fecha_base_str}'");
                continue;
            }
            
            $mostrar_tarea_tpf = false;
            
            if ($current_date_obj_param_tpf < $fecha_base_recurrencia_obj && (empty($row['regla_recurrencia']) || $row['regla_recurrencia'] === 'NONE')) {
                continue;
            } else {
                $regla = $row['regla_recurrencia'];
                if ($regla === 'NONE') {
                    if ($fecha_base_recurrencia_obj->format('Y-m-d') == $current_date_obj_param_tpf->format('Y-m-d')) $mostrar_tarea_tpf = true;
                } elseif ($regla === 'DAILY') {
                    if ($current_date_obj_param_tpf >= $fecha_base_recurrencia_obj) $mostrar_tarea_tpf = true;
                } elseif (strpos($regla, 'WEEKLY:') === 0) {
                    if ($current_date_obj_param_tpf >= $fecha_base_recurrencia_obj) {
                        $parts = explode(':', $regla);
                        if (count($parts) === 2) {
                            $days_of_week_abbr = explode(',', $parts[1]);
                            $day_map_php_to_abbr = [1=>'MON', 2=>'TUE', 3=>'WED', 4=>'THU', 5=>'FRI', 6=>'SAT', 7=>'SUN'];
                            $current_day_abbr_php = $day_map_php_to_abbr[$current_date_obj_param_tpf->format('N')] ?? '';
                            if (!empty($current_day_abbr_php) && in_array($current_day_abbr_php, $days_of_week_abbr)) $mostrar_tarea_tpf = true;
                        }
                    }
                } elseif ($regla === 'MONTHLY_DAY') {
                    if ($current_date_obj_param_tpf >= $fecha_base_recurrencia_obj && $current_date_obj_param_tpf->format('d') == $fecha_base_recurrencia_obj->format('d')) $mostrar_tarea_tpf = true;
                } else {
                    if ($fecha_base_recurrencia_obj->format('Y-m-d') == $current_date_obj_param_tpf->format('Y-m-d')) $mostrar_tarea_tpf = true;
                }
            }

            if ($mostrar_tarea_tpf && (bool)$row['activo']) { 
                $row['completado'] = (bool)$row['completado'];
                $row['activo'] = true;
                $row['subtareas'] = []; 
                $all_tasks_from_db_temp[$row['id']] = $row; 
            }
        }
        $stmt->close();
        
        $tareas_final_output = [];
        foreach ($all_tasks_from_db_temp as $tarea_id => $tarea) {
            if ($tarea['parent_id'] !== null && isset($all_tasks_from_db_temp[$tarea['parent_id']])) {
                 $all_tasks_from_db_temp[$tarea['parent_id']]['subtareas'][] = $tarea;
            }
        }
        foreach ($all_tasks_from_db_temp as $tarea_id => $tarea) {
            if ($tarea['parent_id'] === null) { 
                if(!empty($tarea['subtareas'])){ usort($tarea['subtareas'],function($a,$b){ return ($a['orden'] ?? $a['id']) <=> ($b['orden'] ?? $b['id']); }); }
                $tareas_final_output[]=$tarea;
            }
        }
        usort($tareas_final_output, function($a, $b) { return ($a['orden'] ?? $a['id']) <=> ($b['orden'] ?? $b['id']); });

        jsonResponse($tareas_final_output);

    } catch (Throwable $e) {
        error_log("Error en GET /tareas-por-fecha (user $usuario_id): " . $e->getMessage());
        jsonResponse(["error" => "Error al procesar la solicitud de tareas."], 500);
    }
} else {
    jsonResponse(["error" => "Método no soportado."], 405);
}
?>