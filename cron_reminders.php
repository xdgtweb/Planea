<?php
// cron_reminders.php
// Este script está diseñado para ser ejecutado por un cron job en el servidor.
// NO debe ser accesible directamente a través de una URL web.

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/vendor/autoload.php'; // Carga PHPMailer y otras dependencias de Composer

// Configuración de PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// #####################################################################################
// ##  ¡ATENCIÓN! CONFIGURACIÓN DE CORREO: MODIFICA ESTO CON TUS PROPIOS DATOS SMTP  ##
// #####################################################################################
// Estas credenciales son NECESARIAS para que el servidor pueda enviar correos.
// Consulta a tu proveedor de correo (Gmail, Outlook, tu hosting) para obtenerlas.
define('MAIL_HOST', 'smtp.your_email_provider.com'); // Ej: 'smtp.gmail.com', 'smtp.office365.com'
define('MAIL_USERNAME', 'tu_correo@ejemplo.com'); // Tu dirección de correo completa
define('MAIL_PASSWORD', 'tu_contraseña_de_correo'); // Tu contraseña del correo (¡Cuidado con la seguridad!)
                                                    // Si usas 2FA, puede que necesites una "contraseña de aplicación".
define('MAIL_PORT', 587); // Puerto SMTP. Comunes: 587 (STARTTLS), 465 (SMTPS)
define('MAIL_FROM_EMAIL', 'no-reply@planea.com'); // Correo que aparecerá como remitente
define('MAIL_FROM_NAME', 'Recordatorios de Planea'); // Nombre del remitente
// #####################################################################################

// Asegúrate de que el script no termine por tiempo de ejecución en tareas largas
set_time_limit(300); // 5 minutos de tiempo máximo de ejecución

// Función para enviar correos electrónicos de recordatorio
function sendReminderEmail($toEmail, $toName, $taskSubject, $htmlBody, $altBody) {
    $mail = new PHPMailer(true); // Pasar 'true' habilita excepciones para manejo de errores
    try {
        // Configuración del servidor SMTP
        $mail->isSMTP();                                            // Usar SMTP
        $mail->Host       = MAIL_HOST;                              // Especificar el servidor SMTP principal
        $mail->SMTPAuth   = true;                                   // Habilitar autenticación SMTP
        $mail->Username   = MAIL_USERNAME;                          // Nombre de usuario SMTP
        $mail->Password   = MAIL_PASSWORD;                          // Contraseña SMTP
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         // Habilitar cifrado TLS implícito/explícito
        $mail->Port       = MAIL_PORT;                              // Puerto TCP para conectar
        $mail->CharSet    = 'UTF-8';                                // Establecer codificación de caracteres

        // Destinatarios
        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName); // Añadir un destinatario

        // Contenido del correo
        $mail->isHTML(true); // Establecer el formato del correo a HTML
        $mail->Subject = 'Recordatorio de Tarea: ' . htmlspecialchars($taskSubject); // Asunto del correo
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody;

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Registrar cualquier error que ocurra durante el envío del correo
        error_log("Error al enviar email de recordatorio a $toEmail: {$mail->ErrorInfo} - Detalles: {$e->getMessage()}");
        return false;
    }
}

// Lógica principal para obtener y procesar recordatorios pendientes
$current_time = new DateTime();
// Solo selecciona recordatorios que estén 'pending' o 'failed' (para reintentos)
// y cuya fecha/hora de recordatorio sea igual o anterior a la hora actual.
$stmt = $mysqli->prepare("
    SELECT r.id as reminder_id_entry, r.tarea_id, r.reminder_datetime, r.type,
           td.texto as task_text, td.fecha_inicio as task_date,
           u.email as user_email, u.username as user_name,
           rt.time_of_day -- NUEVO: Seleccionar la hora específica
    FROM reminders r
    JOIN tareas_diarias td ON r.tarea_id = td.id
    JOIN usuarios u ON r.usuario_id = u.id
    LEFT JOIN reminder_times rt ON r.id = rt.reminder_id -- Unir con la nueva tabla
    WHERE r.status IN ('pending', 'failed')
      AND DATE(r.reminder_datetime) = CURRENT_DATE() -- Asegurar que es para hoy
      AND (rt.time_of_day IS NULL OR TIME(NOW()) >= rt.time_of_day) -- Considerar la hora específica o si no hay horas definidas
    ORDER BY r.reminder_datetime ASC, rt.time_of_day ASC
");

if (!$stmt) {
    error_log("Error al preparar la consulta de recordatorios: " . $mysqli->error);
    exit(); // Terminar el script si no se puede preparar la consulta
}

// Para la hora actual, se usa NOW() directamente en la consulta, no se necesita bindear aquí.
// Pero la consulta anterior tenía un '?', ajustaremos para que no lo necesite si solo filtra por fecha de recordatorio.
// Si r.reminder_datetime es DATETIME, entonces la consulta debería ser:
// AND r.reminder_datetime <= ?
// $stmt->bind_param("s", $current_time->format('Y-m-d H:i:s'));
// Pero si queremos que el CRON se ejecute una vez al día para los recordatorios de ESE día,
// y luego las horas específicas son el filtro interno, se mantiene así:
$stmt->execute(); // No se bindea nada aquí porque CURRENT_DATE() y TIME(NOW()) se usan en SQL

$result = $stmt->get_result();

// Modificación 2: Procesamiento y Envío
// Necesitaremos agrupar los recordatorios por tarea para enviar un solo correo por tarea,
// listando todas las horas si hay múltiples recordatorios para la misma tarea en el mismo día.
$reminders_to_send = [];
while ($row = $result->fetch_assoc()) {
    $tarea_id = $row['tarea_id'];
    $reminder_entry_id = $row['reminder_id_entry']; // El ID de la entrada en la tabla 'reminders'
    $time_of_day = $row['time_of_day'];

    // Si ya procesamos este ID de recordatorio principal (reminder_id_entry),
    // solo añadimos la hora si existe y no se ha añadido ya.
    if (!isset($reminders_to_send[$reminder_entry_id])) {
        $reminders_to_send[$reminder_entry_id] = [
            'user_email' => $row['user_email'],
            'user_name' => $row['user_name'],
            'task_text' => $row['task_text'],
            'task_date' => $row['task_date'],
            'type' => $row['type'], // Para la fecha del recordatorio general
            'times_of_day' => [],
            'reminder_id_entry' => $reminder_entry_id // Guardamos el ID principal para el update
        ];
    }
    
    if ($time_of_day) {
        $reminders_to_send[$reminder_entry_id]['times_of_day'][] = date('H:i', strtotime($time_of_day));
    } else {
        // Si no hay 'time_of_day' (por LEFT JOIN), y la tarea es para hoy, se envía de todas formas
        // Esto cubre casos donde no se especificaron horas pero se quiere un recordatorio general en la fecha
        if (empty($reminders_to_send[$reminder_entry_id]['times_of_day'])) {
             $reminders_to_send[$reminder_entry_id]['times_of_day'][] = 'hora indeterminada'; // O cualquier valor por defecto
        }
    }
}

foreach ($reminders_to_send as $reminder_id_entry => $data) {
    $time_info = '';
    // Filtrar duplicados de horas y ordenar si se desea
    $unique_times = array_unique($data['times_of_day']);
    sort($unique_times);

    if (!empty($unique_times)) {
        if (count($unique_times) === 1 && $unique_times[0] === 'hora indeterminada') {
            $time_info = ''; // No añadir "a las hora indeterminada"
        } else {
            $time_info = ' a las ' . implode(', ', $unique_times) . ' horas';
        }
    }

    $mail_body = "Hola " . htmlspecialchars($data['user_name']) . ",<br><br>"
               . "Solo un recordatorio de tu tarea programada: <strong>" . htmlspecialchars($data['task_text']) . "</strong>.<br>"
               . "Está programada para el <strong>" . htmlspecialchars($data['task_date']) . $time_info . "</strong>.<br><br>"
               . "¡Que tengas un gran día!<br>"
               . "El equipo de Planea.";
    $mail_alt_body = "Hola " . $data['user_name'] . ",\n\n"
                   . "Solo un recordatorio de tu tarea programada: " . $data['task_text'] . ".\n"
                   . "Está programada para el " . $data['task_date'] . $time_info . ".\n\n"
                   . "¡Que tengas un gran día!\n"
                   . "El equipo de Planea.";

    $sent_successfully = sendReminderEmail(
        $data['user_email'],
        $data['user_name'],
        $data['task_text'], // El asunto puede seguir siendo el texto de la tarea
        $mail_body,
        $mail_alt_body
    );

    // Actualizar el estado del recordatorio en la base de datos
    $new_status = $sent_successfully ? 'sent' : 'failed';
    $sent_at_time = $current_time->format('Y-m-d H:i:s');

    // Actualizar solo la entrada principal en la tabla `reminders`
    $update_status_stmt = $mysqli->prepare("UPDATE reminders SET status = ?, sent_at = ? WHERE id = ?");
    if (!$update_status_stmt) {
        error_log("Error al preparar la actualización de estado de recordatorio para ID {$reminder_id_entry}: " . $mysqli->error);
        continue;
    }
    $update_status_stmt->bind_param("ssi", $new_status, $sent_at_time, $reminder_id_entry);
    $update_status_stmt->execute();
    $update_status_stmt->close();
}

$stmt->close();
$mysqli->close(); // Cerrar la conexión a la base de datos al finalizar