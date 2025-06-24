<?php
// helpers/email_helper.php
// Este archivo contiene una función auxiliar para enviar correos electrónicos.

// Asegúrate de que PHPMailer esté cargado. Si usas Composer, esto ya está incluido.
// Si no, necesitarías la línea: require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// #####################################################################################
// ##  ¡ATENCIÓN! CONFIGURACIÓN DE CORREO: MODIFICA ESTO CON TUS PROPIOS DATOS SMTP  ##
// #####################################################################################
// Estas credenciales son NECESARIAS para que el servidor pueda enviar correos.
// Consulta a tu proveedor de correo (Gmail, Outlook, tu hosting) para obtenerlas.
// Son las mismas que se usarían en cron_reminders.php.
define('MAIL_HOST_GENERAL', 'smtp.gmail.com'); // Ej: 'smtp.gmail.com', 'smtp.office365.com'
define('MAIL_USERNAME_GENERAL', 'xxxdddgggttt@gmail.com'); // Tu dirección de correo completa que enviará los emails
define('MAIL_PASSWORD_GENERAL', 'slavairbhvyhqwom'); // Tu contraseña del correo (¡Cuidado con la seguridad!)
                                                            // Si usas 2FA, puede que necesites una "contraseña de aplicación".
define('MAIL_PORT_GENERAL', 587); // Puerto SMTP. Comunes: 587 (STARTTLS), 465 (SMTPS)
define('MAIL_FROM_EMAIL_GENERAL', 'xxxdddgggttt@gmail.com'); // Correo que aparecerá como remitente
define('MAIL_FROM_NAME_GENERAL', 'Planea'); // Nombre del remitente
// #####################################################################################

/**
 * Función para enviar correos electrónicos.
 *
 * @param string $toEmail   Dirección de correo del destinatario.
 * @param string $toName    Nombre del destinatario (opcional).
 * @param string $subject   Asunto del correo.
 * @param string $htmlBody  Cuerpo del correo en formato HTML.
 * @param string $altBody   Cuerpo del correo en texto plano (para clientes que no soportan HTML).
 * @return bool             True si el correo se envió con éxito, False en caso contrario.
 */
function sendEmail($toEmail, $toName, $subject, $htmlBody, $altBody) {
    $mail = new PHPMailer(true); // Pasar 'true' habilita excepciones para manejo de errores
    try {
        // Configuración del servidor SMTP
        $mail->isSMTP();                                            // Usar SMTP
        $mail->Host       = MAIL_HOST_GENERAL;                      // Especificar el servidor SMTP principal
        $mail->SMTPAuth   = true;                                   // Habilitar autenticación SMTP
        $mail->Username   = MAIL_USERNAME_GENERAL;                  // Nombre de usuario SMTP
        $mail->Password   = MAIL_PASSWORD_GENERAL;                  // Contraseña SMTP
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;         // Habilitar cifrado TLS implícito/explícito
        $mail->Port       = MAIL_PORT_GENERAL;                      // Puerto TCP para conectar
        $mail->CharSet    = 'UTF-8';                                // Establecer codificación de caracteres

        // Destinatarios
        $mail->setFrom(MAIL_FROM_EMAIL_GENERAL, MAIL_FROM_NAME_GENERAL);
        $mail->addAddress($toEmail, $toName); // Añadir un destinatario

        // Contenido del correo
        $mail->isHTML(true); // Establecer el formato del correo a HTML
        $mail->Subject = $subject; // Asunto del correo
        $mail->Body    = $htmlBody;
        $mail->AltBody = $altBody;

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Registrar cualquier error que ocurra durante el envío del correo
        error_log("Error al enviar email a $toEmail (Asunto: $subject): {$mail->ErrorInfo} - Detalles: {$e->getMessage()}");
        return false;
    }
}
?>