<?php
// api_handlers/calendario.php

if (!isset($_SESSION['usuario_id'])) { jsonResponse(["error" => "No autorizado."], 401); exit; }
$usuario_id = $_SESSION['usuario_id'];

if (!isset($mysqli)) { jsonResponse(["error" => "Error crítico: Conexión a base de datos no disponible."], 500); exit; }
if (!isset($handler_http_method)) { jsonResponse(["error" => "Error crítico: Método HTTP no determinado."], 500); exit; }

if ($handler_http_method === 'GET') {
    try {
        $mes = filter_var($_GET['mes'] ?? date("m"), FILTER_VALIDATE_INT);
        $anio = filter_var($_GET['anio'] ?? date("Y"), FILTER_VALIDATE_INT);
        if (!$mes || !$anio) { jsonResponse(["error" => "Parámetros de mes y año inválidos."], 400); }

        $num_dias_mes = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
        $fecha_inicio_mes_str = sprintf("%04d-%02d-01", $anio, $mes);
        $fecha_fin_mes_str = date("Y-m-t", strtotime($fecha_inicio_mes_str));
        
        $tareas_aplicables = [];
        $sql_tareas = "SELECT id, fecha_inicio, regla_recurrencia, creado_en FROM tareas_dia_a_dia WHERE activo = TRUE AND tipo = 'subtarea' AND usuario_id = ?";
        $stmt_tareas = $mysqli->prepare($sql_tareas);
        $stmt_tareas->bind_param("i", $usuario_id);
        $stmt_tareas->execute();
        $result_tareas = $stmt_tareas->get_result();
        while($row_tarea = $result_tareas->fetch_assoc()){ 
            $tareas_aplicables[$row_tarea['id']] = $row_tarea; 
        } 
        $stmt_tareas->close();
        
        $tareas_completadas_en_mes = [];
        $sql_completadas = "SELECT tc.tarea_id, tc.fecha 
                            FROM tareas_dia_a_dia_completadas tc
                            JOIN tareas_dia_a_dia t ON tc.tarea_id = t.id
                            WHERE tc.fecha >= ? AND tc.fecha <= ? AND tc.completado = TRUE AND t.activo = TRUE AND t.usuario_id = ?";
        $stmt_completadas = $mysqli->prepare($sql_completadas);
        $stmt_completadas->bind_param("ssi", $fecha_inicio_mes_str, $fecha_fin_mes_str, $usuario_id);
        $stmt_completadas->execute();
        $result_completadas = $stmt_completadas->get_result();
        while($row_comp = $result_completadas->fetch_assoc()){ 
            if (!isset($tareas_completadas_en_mes[$row_comp['fecha']])) { $tareas_completadas_en_mes[$row_comp['fecha']] = []; } 
            $tareas_completadas_en_mes[$row_comp['fecha']][] = $row_comp['tarea_id']; 
        }
        $stmt_completadas->close();

        $day_of_week_map = ['Sunday' => 'SUN', 'Monday' => 'MON', 'Tuesday' => 'TUE', 'Wednesday' => 'WED', 'Thursday' => 'THU', 'Friday' => 'FRI', 'Saturday' => 'SAT'];
        $dias_del_mes_data = [];

        for ($i = 1; $i <= $num_dias_mes; $i++) {
            $current_date_obj = new DateTimeImmutable("$anio-$mes-$i", new DateTimeZone('UTC')); 
            $current_date_str = $current_date_obj->format("Y-m-d");
            $total_tareas_dia = 0; 
            $completadas_dia = 0;

            foreach ($tareas_aplicables as $tarea_id => $tarea_info) {
                try {
                    $fecha_base_str = $tarea_info['fecha_inicio'] ?: $tarea_info['creado_en'];
                    if (empty($fecha_base_str) || strpos($fecha_base_str, '0000-00-00') === 0) {
                        continue;
                    }
                    $fecha_inicio_tarea_obj = new DateTimeImmutable($fecha_base_str, new DateTimeZone('UTC'));
                } catch (Throwable $th) {
                    error_log("Saltando tarea ID {$tarea_id} en calendario.php por fecha inválida: '{$fecha_base_str}'");
                    continue;
                }
                
                $es_dia_de_tarea = false;
                if ($current_date_obj < $fecha_inicio_tarea_obj && (empty($tarea_info['regla_recurrencia']) || $tarea_info['regla_recurrencia'] === 'NONE')) continue; 
                
                $regla = $tarea_info['regla_recurrencia'];
                if ($regla === 'NONE') {
                    if ($fecha_inicio_tarea_obj->format('Y-m-d') == $current_date_str) $es_dia_de_tarea = true;
                } elseif ($regla === 'DAILY') {
                    if ($current_date_obj >= $fecha_inicio_tarea_obj) $es_dia_de_tarea = true;
                } elseif (strpos($regla, 'WEEKLY:') === 0) {
                    if ($current_date_obj >= $fecha_inicio_tarea_obj) {
                        $parts = explode(':', $regla);
                        if (count($parts) === 2) {
                            $days_of_week_abbr = explode(',', $parts[1]);
                            $current_day_abbr_php = $day_of_week_map[$current_date_obj->format('l')] ?? '';
                            if (in_array($current_day_abbr_php, $days_of_week_abbr)) $es_dia_de_tarea = true;
                        }
                    }
                } elseif ($regla === 'MONTHLY_DAY') {
                    if ($current_date_obj >= $fecha_inicio_tarea_obj && $current_date_obj->format('d') == $fecha_inicio_tarea_obj->format('d')) $es_dia_de_tarea = true;
                }

                if ($es_dia_de_tarea) { 
                    $total_tareas_dia++; 
                    if (isset($tareas_completadas_en_mes[$current_date_str]) && in_array($tarea_id, $tareas_completadas_en_mes[$current_date_str])) {
                        $completadas_dia++; 
                    }
                }
            }
            $porcentaje = ($total_tareas_dia > 0) ? round(($completadas_dia / $total_tareas_dia) * 100) : -1;
            $dias_del_mes_data[] = ["fecha" => $current_date_str, "porcentaje" => $porcentaje];
        }
        jsonResponse($dias_del_mes_data);

    } catch (Throwable $e) {
        error_log("Error en GET /calendario-dia-a-dia (user $usuario_id): " . $e->getMessage() . " on line " . $e->getLine());
        jsonResponse(["error" => "No se pudo generar los datos del calendario."], 500);
    }
} else {
    jsonResponse(["error" => "Método no soportado."], 405);
}
?>