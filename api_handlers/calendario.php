<?php
// api_handlers/calendario.php

// Este archivo es incluido por el api.php principal.
// Las variables $mysqli (de db_config.php, global), 
// $handler_http_method (el método HTTP efectivo: GET, POST, PUT, DELETE),
// y las funciones jsonResponse() y getTodayDate() están disponibles desde el router principal.

if (!isset($mysqli)) {
    jsonResponse(["error" => "Error crítico: Conexión a base de datos no disponible en calendario.php."], 500);
    exit;
}
if (!isset($handler_http_method)) {
    jsonResponse(["error" => "Error crítico: Método HTTP no determinado en calendario.php."], 500);
    exit;
}

// Este endpoint solo maneja GET. 
if ($handler_http_method === 'GET') {
    try {
        $mes_param = $_GET['mes'] ?? date("m");
        $anio_param = $_GET['anio'] ?? date("Y");

        $mes = filter_var($mes_param, FILTER_VALIDATE_INT, ["options" => ["default" => date("m"), "min_range" => 1, "max_range" => 12]]);
        $anio = filter_var($anio_param, FILTER_VALIDATE_INT, ["options" => ["default" => date("Y"), "min_range" => 1900, "max_range" => 2100]]);

        if (!$mes || !$anio) {
            jsonResponse(["error" => "Parámetros de mes y año inválidos."], 400);
        }

        $dias_del_mes_data = [];
        $num_dias_mes = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
        $fecha_inicio_mes_str = sprintf("%04d-%02d-01", $anio, $mes);
        $fecha_fin_mes_str = sprintf("%04d-%02d-%02d", $anio, $mes, $num_dias_mes);
        
        $tareas_aplicables_al_mes = [];
        $sql_tareas = "SELECT id, fecha_inicio, regla_recurrencia, fecha_creacion FROM tareas_dia_a_dia WHERE activo = TRUE";
        $stmt_tareas = $mysqli->prepare($sql_tareas); 
        if(!$stmt_tareas) { throw new Exception("DB Error (cal_prep_tareas): ".$mysqli->error." SQL: ".$sql_tareas); }
        if(!$stmt_tareas->execute()) { throw new Exception("DB Error (cal_exec_tareas): ".$stmt_tareas->error." SQL: ".$sql_tareas); }
        $result_tareas = $stmt_tareas->get_result();
        while($row_tarea = $result_tareas->fetch_assoc()){ 
            $tareas_aplicables_al_mes[$row_tarea['id']] = $row_tarea; 
        } 
        $stmt_tareas->close();
        
        $tareas_completadas_en_mes = [];
        $sql_completadas = "SELECT tc.tarea_id, tc.fecha 
                            FROM tareas_dia_a_dia_completadas tc
                            JOIN tareas_dia_a_dia t ON tc.tarea_id = t.id
                            WHERE tc.fecha >= ? AND tc.fecha <= ? AND tc.completado = TRUE AND t.activo = TRUE";
        $stmt_completadas = $mysqli->prepare($sql_completadas); 
        if(!$stmt_completadas) { throw new Exception("DB Error (cal_prep_completadas): ".$mysqli->error." SQL: ".$sql_completadas); }
        $stmt_completadas->bind_param("ss", $fecha_inicio_mes_str, $fecha_fin_mes_str);
        if(!$stmt_completadas->execute()) { throw new Exception("DB Error (cal_exec_completadas): ".$stmt_completadas->error." SQL: ".$sql_completadas); }
        $result_completadas = $stmt_completadas->get_result();
        while($row_comp = $result_completadas->fetch_assoc()){ 
            if (!isset($tareas_completadas_en_mes[$row_comp['fecha']])) {
                $tareas_completadas_en_mes[$row_comp['fecha']] = []; 
            } 
            $tareas_completadas_en_mes[$row_comp['fecha']][$row_comp['tarea_id']] = true; 
        }
        $stmt_completadas->close();

        for ($i = 1; $i <= $num_dias_mes; $i++) {
            // Usar DateTimeImmutable para evitar modificaciones accidentales y trabajar con UTC para consistencia de fechas
            $current_date_obj = new DateTimeImmutable("$anio-$mes-$i", new DateTimeZone('UTC')); 
            $current_date_str = $current_date_obj->format("Y-m-d");
            $total_tareas_para_el_dia = 0; 
            $tareas_completadas_para_el_dia = 0;

            foreach ($tareas_aplicables_al_mes as $tarea_id => $tarea_info) {
                $fecha_inicio_tarea_obj = $tarea_info['fecha_inicio'] ? new DateTimeImmutable($tarea_info['fecha_inicio'], new DateTimeZone('UTC')) : new DateTimeImmutable($tarea_info['fecha_creacion'], new DateTimeZone('UTC'));
                
                if ($current_date_obj < $fecha_inicio_tarea_obj && ($tarea_info['regla_recurrencia'] === 'NONE' || !$tarea_info['regla_recurrencia'])) {
                    continue;
                }

                $es_dia_de_tarea = false;
                switch ($tarea_info['regla_recurrencia']) {
                    case 'NONE': if ($fecha_inicio_tarea_obj->format('Y-m-d') == $current_date_str) $es_dia_de_tarea = true; break;
                    case 'DAILY': if ($current_date_obj >= $fecha_inicio_tarea_obj) $es_dia_de_tarea = true; break;
                    case (strpos($tarea_info['regla_recurrencia'], 'WEEKLY:') === 0):
                        if ($current_date_obj >= $fecha_inicio_tarea_obj) {
                            $parts = explode(':', $tarea_info['regla_recurrencia']); 
                            if(count($parts) === 2) {
                                $days_of_week_abbr = explode(',', $parts[1]);
                                $current_day_of_week_php = $current_date_obj->format('N'); // 1 (Mon) a 7 (Sun)
                                $day_map_php_to_abbr = [1=>'MON', 2=>'TUE', 3=>'WED', 4=>'THU', 5=>'FRI', 6=>'SAT', 7=>'SUN'];
                                $current_day_abbr_php = $day_map_php_to_abbr[$current_day_of_week_php] ?? '';
                                if (in_array($current_day_abbr_php, $days_of_week_abbr)) $es_dia_de_tarea = true;
                            }
                        } break;
                    case 'MONTHLY_DAY': if ($current_date_obj >= $fecha_inicio_tarea_obj && $current_date_obj->format('d') == $fecha_inicio_tarea_obj->format('d')) $es_dia_de_tarea = true; break;
                    default: if ($tarea_info['fecha_inicio'] == $current_date_str) $es_dia_de_tarea = true; else if (!$tarea_info['fecha_inicio'] && $tarea_info['fecha_creacion'] == $current_date_str) $es_dia_de_tarea = true; break;
                }
                if ($es_dia_de_tarea) { 
                    $total_tareas_para_el_dia++; 
                    if (isset($tareas_completadas_en_mes[$current_date_str][$tarea_id])) {
                        $tareas_completadas_para_el_dia++; 
                    }
                }
            }
            $porcentaje = ($total_tareas_para_el_dia > 0) ? round(($tareas_completadas_para_el_dia / $total_tareas_para_el_dia) * 100) : 0;
            $dias_del_mes_data[] = ["fecha" => $current_date_str, "porcentaje" => $porcentaje];
        }
        jsonResponse($dias_del_mes_data);

    } catch (Exception $e) {
        error_log("Error en GET /calendario-dia-a-dia: " . $e->getMessage());
        jsonResponse(["error" => "No se pudo generar los datos del calendario.", "details" => $e->getMessage()], 500);
    }
} else {
    jsonResponse(["error" => "Método " . htmlspecialchars($handler_http_method) . " no soportado para el endpoint /calendario-dia-a-dia."], 405);
}

?>