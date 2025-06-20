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
function sendReminderEmail($toEmail, $toName, $taskText, $reminderType, $taskDate) {
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
        $mail->Subject = 'Recordatorio de Tarea: ' . htmlspecialchars($taskText); // Asunto del correo
        $mail->Body    = "Hola " . htmlspecialchars($toName) . ",<br><br>"
                       . "Solo un recordatorio de tu tarea programada: <strong>" . htmlspecialchars($taskText) . "</strong>.<br>"
                       . "Está programada para el <strong>" . htmlspecialchars($taskDate) . "</strong>.<br><br>"
                       . "¡Que tengas un gran día!<br>"
                       . "El equipo de Planea.";
        $mail->AltBody = "Hola " . $toName . ",\n\n" // Cuerpo para clientes de correo sin HTML
                       . "Solo un recordatorio de tu tarea programada: " . $taskText . ".\n"
                       . "Está programada para el " . $taskDate . ".\n\n"
                       . "¡Que tengas un gran día!\n"
                       . "El equipo de Planea.";

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
    SELECT r.id as reminder_id, r.tarea_id, r.reminder_datetime, r.type,
           td.texto as task_text, td.fecha_inicio as task_date,
           u.email as user_email, u.username as user_name
    FROM reminders r
    JOIN tareas_diarias td ON r.tarea_id = td.id
    JOIN usuarios u ON r.usuario_id = u.id
    WHERE r.status IN ('pending', 'failed') AND r.reminder_datetime <= ?
    ORDER BY r.reminder_datetime ASC
");

if (!$stmt) {
    error_log("Error al preparar la consulta de recordatorios: " . $mysqli->error);
    exit(); // Terminar el script si no se puede preparar la consulta
}

$stmt->bind_param("s", $current_time->format('Y-m-d H:i:s'));
$stmt->execute();
$result = $stmt->get_result();

// Procesar cada recordatorio encontrado
while ($row = $result->fetch_assoc()) {
    $sent_successfully = sendReminderEmail(
        $row['user_email'],
        $row['user_name'],
        $row['task_text'],
        $row['type'],
        $row['task_date']
    );

    // Actualizar el estado del recordatorio en la base de datos
    $update_status_stmt = $mysqli->prepare("UPDATE reminders SET status = ?, sent_at = ? WHERE id = ?");
    if (!$update_status_stmt) {
        error_log("Error al preparar la actualización de estado de recordatorio para ID {$row['reminder_id']}: " . $mysqli->error);
        continue; // Saltar al siguiente recordatorio si la preparación falla
    }
    $new_status = $sent_successfully ? 'sent' : 'failed';
    $sent_at_time = $current_time->format('Y-m-d H:i:s');
    $update_status_stmt->bind_param("ssi", $new_status, $sent_at_time, $row['reminder_id']);
    $update_status_stmt->execute();
    $update_status_stmt->close();
}

$stmt->close();
$mysqli->close(); // Cerrar la conexión a la base de datos al finalizar
?>