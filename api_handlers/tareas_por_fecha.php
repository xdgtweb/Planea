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
            jsonResponse(["error" => "Parámetro 'fecha' es requerido."], 400);
        }
        
        $sql = "
            SELECT t.id, t.texto, t.tipo, t.parent_id, t.orden, t.activo,
                   COALESCE(tc.completado, 0) as completado
            FROM tareas_dia_a_dia t
            LEFT JOIN tareas_dia_a_dia_completadas tc ON t.id = tc.tarea_id AND t.fecha_inicio = tc.fecha
            WHERE t.usuario_id = ? AND t.fecha_inicio = ? AND t.regla_recurrencia = 'NONE' AND t.activo = 1
            ORDER BY t.orden, t.id
        ";

        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("is", $usuario_id, $fecha_consulta);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $all_tasks = [];
        while ($row = $result->fetch_assoc()) {
            $row['completado'] = (bool)$row['completado'];
            $row['activo'] = (bool)$row['activo'];
            $row['subtareas'] = [];
            $all_tasks[$row['id']] = $row;
        }
        $stmt->close();
        
        $tareas_final_output = [];
        foreach ($all_tasks as $tarea_id => $tarea) {
            if ($tarea['parent_id'] !== null && isset($all_tasks[$tarea['parent_id']])) {
                $all_tasks[$tarea['parent_id']]['subtareas'][] = $tarea;
            }
        }
        foreach ($all_tasks as $tarea_id => $tarea) {
            if ($tarea['parent_id'] === null) {
                $tareas_final_output[] = $tarea;
            }
        }
        jsonResponse($tareas_final_output);

    } catch (Throwable $e) {
        error_log("Error en GET /tareas-por-fecha (user $usuario_id): " . $e->getMessage());
        jsonResponse(["error" => "Error al procesar la solicitud de tareas."], 500);
    }
} else {
    jsonResponse(["error" => "Método no soportado."], 405);
}
?>