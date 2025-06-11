<?php
if (!isset($_SESSION['usuario_id'])) {
    // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
    json_response(['error' => 'Acceso no autorizado'], 401);
    exit;
}
$usuario_id = $_SESSION['usuario_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mes = $_GET['mes'] ?? date('m');
    $anio = $_GET['anio'] ?? date('Y');

    // Preparar la consulta para obtener el progreso de cada día del mes
    $stmt = $mysqli->prepare("
        SELECT 
            DATE(fecha_inicio) as fecha,
            (SUM(completado) / COUNT(id)) * 100 AS porcentaje_completado
        FROM 
            tareas_diarias
        WHERE 
            usuario_id = ? 
            AND MONTH(fecha_inicio) = ? 
            AND YEAR(fecha_inicio) = ?
            AND tipo = 'subtarea'
        GROUP BY 
            DATE(fecha_inicio)
    ");
    $stmt->bind_param("iii", $usuario_id, $mes, $anio);
    $stmt->execute();
    $result = $stmt->get_result();

    $dias_con_progreso = [];
    while ($row = $result->fetch_assoc()) {
        $dias_con_progreso[] = [
            'fecha' => $row['fecha'],
            'porcentaje' => round($row['porcentaje_completado'])
        ];
    }
    
    // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
    json_response($dias_con_progreso);
    
    $stmt->close();

} else {
    // CORRECCIÓN: Se cambia 'jsonResponse' a 'json_response'
    json_response(['error' => 'Método no permitido'], 405);
}
?>