<?php
// cron_reminders.php
// Script para enviar recordatorios por correo electrónico. Diseñado para ser ejecutado por un cron job.

// Incluir la configuración de la base de datos
require_once 'db_config.php';

// Cargar la biblioteca PHPMailer
// Asegúrate de que PHPMailer esté instalado (ej. vía Composer: composer require phpmailer/phpmailer)
// y que el autoload.php esté en la ruta correcta.
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ZONA HORARIA (IMPORTANTE para que las fechas del servidor coincidan con las de la aplicación)
date_default_timezone_set('Europe/Madrid');

// #####################################################################################
// ##  ¡ATENCIÓN! CONFIGURACIÓN DE CORREO: MODIFICA ESTO CON TUS PROPIOS DATOS SMTP  ##
// #####################################################################################
// Credenciales de SMTP para el envío de recordatorios
define('MAIL_HOST', 'smtp.gmail.com'); // Servidor SMTP (ej: 'smtp.gmail.com' para Gmail)
define('MAIL_USERNAME', 'xxxdddgggttt@gmail.com'); // Tu dirección de correo completa que enviará los emails
define('MAIL_PASSWORD', 'slavairbhvyhqwom'); // Contraseña SMTP (Contraseña de Aplicación para Gmail si usas 2FA)
define('MAIL_PORT', 587); // Puerto SMTP (587 para STARTTLS, 465 para SMTPS)
define('MAIL_FROM_EMAIL', 'xxxdddgggttt@gmail.com'); // Correo que aparecerá como remitente
define('MAIL_FROM_NAME', 'Planea'); // <--- NOMBRE ACTUALIZADO AQUÍ
// #####################################################################################


// Función para enviar correos electrónicos (similar a email_helper.php pero con config propia de cron)
function sendReminderEmail($toEmail, $toName, $subject, $htmlBody, $altBody) {
    $mail = new PHPMailer(true);
    try {
        // Configuración del servidor SMTP
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // O ENCRYPTION_SMTPS para puerto 465
        $mail->Port       = MAIL_PORT;
        $mail->CharSet    = 'UTF-8';

        // Destinatarios
        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        // Contenido del correo
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Error al enviar recordatorio a $toEmail (Asunto: $subject): {$mail->ErrorInfo} - Detalles: {$e->getMessage()}");
        return false;
    }
}

// Lógica principal del cron job
// 1. Obtener los recordatorios pendientes cuya hora de envío ha llegado o pasado
// Se deben procesar recordatorios que tienen status 'pending'
// y cuya `reminder_datetime` es menor o igual a la hora actual.
// También se unen las horas específicas de reminder_times.

$current_datetime = date('Y-m-d H:i:s');
$current_time = date('H:i:s'); // Solo la hora actual para comparar con reminder_times

$stmt = $mysqli->prepare("
    SELECT 
        r.id AS reminder_id, 
        r.usuario_id, 
        r.tarea_id, 
        r.type, 
        td.texto AS tarea_texto, 
        td.fecha_inicio AS tarea_fecha,
        u.username, 
        u.email,
        GROUP_CONCAT(rt.time_of_day ORDER BY rt.time_of_day ASC) AS specific_times_str
    FROM reminders r
    JOIN tareas_diarias td ON r.tarea_id = td.id
    JOIN usuarios u ON r.usuario_id = u.id
    LEFT JOIN reminder_times rt ON r.id = rt.reminder_id
    WHERE r.status = 'pending' 
      AND r.reminder_datetime <= ?
    GROUP BY r.id
    ORDER BY r.reminder_datetime ASC
");

if (!$stmt) {
    error_log("Error al preparar la consulta de recordatorios: " . $mysqli->error);
    exit;
}

$stmt->bind_param("s", $current_datetime);
$stmt->execute();
$result = $stmt->get_result();

$reminders_to_send = [];
while ($row = $result->fetch_assoc()) {
    $reminders_to_send[] = $row;
}
$stmt->close();

if (empty($reminders_to_send)) {
    // error_log("No hay recordatorios pendientes para enviar en este momento."); // Descomentar para depuración
    exit;
}

error_log("Procesando " . count($reminders_to_send) . " recordatorios.");

foreach ($reminders_to_send as $reminder) {
    $reminder_id = $reminder['reminder_id'];
    $user_email = $reminder['email'];
    $user_username = $reminder['username'];
    $tarea_texto = $reminder['tarea_texto'];
    $tarea_fecha = $reminder['tarea_fecha'];
    $reminder_type = $reminder['type'];
    $specific_times_str = $reminder['specific_times_str']; // string 'HH:MM:SS,HH:MM:SS' or null

    $subject = "Recordatorio: ¡No olvides tu tarea en Planea!";
    $html_body = "Hola " . htmlspecialchars($user_username) . ",<br><br>";
    $alt_body = "Hola " . $user_username . ",\n\n";

    $reminder_detail = "";
    if ($reminder_type === 'hours_before') {
        $reminder_detail = "Tienes una tarea próxima: <strong>" . htmlspecialchars($tarea_texto) . "</strong> programada para hoy, " . date('d/m/Y', strtotime($tarea_fecha)) . ".";
        if ($specific_times_str) {
            $times = array_map(function($t){ return date('H:i', strtotime($t)); }, explode(',', $specific_times_str));
            $reminder_detail .= " Se te recordará a las: " . implode(', ', $times) . ".";
        }
    } elseif ($reminder_type === 'day_before') {
        $reminder_detail = "Mañana tienes una tarea pendiente: <strong>" . htmlspecialchars($tarea_texto) . "</strong> programada para " . date('d/m/Y', strtotime($tarea_fecha)) . ".";
    } elseif ($reminder_type === 'week_before') {
        $reminder_detail = "La semana que viene tienes una tarea: <strong>" . htmlspecialchars($tarea_texto) . "</strong> programada para " . date('d/m/Y', strtotime($tarea_fecha)) . ".";
    } elseif ($reminder_type === 'month_before') {
        $reminder_detail = "En aproximadamente un mes tienes esta tarea: <strong>" . htmlspecialchars($tarea_texto) . "</strong> programada para " . date('d/m/Y', strtotime($tarea_fecha)) . ".";
    }

    $html_body .= $reminder_detail . "<br><br>";
    $html_body .= "Accede a tu aplicación Planea para gestionarla: <a href='https://tu-dominio.com/index.html'>https://tu-dominio.com/index.html</a><br><br>";
    $html_body .= "El equipo de Planea.";

    $alt_body .= strip_tags($reminder_detail) . "\n\n";
    $alt_body .= "Accede a tu aplicación Planea para gestionarla: https://tu-dominio.com/index.html\n\n";
    $alt_body .= "El equipo de Planea.";

    // Lógica para enviar el recordatorio si se cumplen las horas específicas (si existen)
    $should_send_now = true;
    if ($specific_times_str) {
        $specific_times = explode(',', $specific_times_str);
        $found_matching_time = false;
        foreach ($specific_times as $time_str) {
            // Compara solo la hora actual con la hora específica del recordatorio
            // Ajusta la precisión si es necesario (ej. para minutos)
            if (date('H:i', strtotime($current_time)) === date('H:i', strtotime($time_str))) {
                $found_matching_time = true;
                break;
            }
        }
        $should_send_now = $found_matching_time;
    }

    if ($should_send_now) {
        if (sendReminderEmail($user_email, $user_username, $subject, $html_body, $alt_body)) {
            $stmt_update = $mysqli->prepare("UPDATE reminders SET status = 'sent', sent_at = ? WHERE id = ?");
            if ($stmt_update) {
                $stmt_update->bind_param("si", $current_datetime, $reminder_id);
                $stmt_update->execute();
                $stmt_update->close();
                error_log("Recordatorio ID {$reminder_id} enviado a {$user_email}.");
            } else {
                error_log("Error al preparar la actualización de recordatorio ID {$reminder_id}: " . $mysqli->error);
            }
        } else {
            $stmt_update = $mysqli->prepare("UPDATE reminders SET status = 'failed' WHERE id = ?");
            if ($stmt_update) {
                $stmt_update->bind_param("i", $reminder_id);
                $stmt_update->execute();
                $stmt_update->close();
                error_log("Fallo al enviar recordatorio ID {$reminder_id} a {$user_email}.");
            } else {
                error_log("Error al preparar la actualización de recordatorio fallido ID {$reminder_id}: " . $mysqli->error);
            }
        }
    } else {
        // Recordatorio no se envía ahora porque no coincide con ninguna hora específica,
        // pero su reminder_datetime general sí ha pasado. Esto está bien si hay horas específicas.
        // Solo loguear si es relevante para depuración.
        // error_log("Recordatorio ID {$reminder_id} no enviado en este ciclo porque no coincide con horas específicas. Proxima hora: {$specific_times_str}");
    }
}

// Cerrar la conexión a la base de datos
$mysqli->close();
?>