<?php
// Manejar la petición POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"));

    // Validación básica de entrada
    if (!isset($data->username) || !isset($data->email) || !isset($data->password)) {
        json_response(['error' => 'Faltan campos requeridos'], 400);
        return;
    }

    $username = trim($data->username);
    $email = trim($data->email);
    $password = $data->password;

    if (empty($username) || empty($email) || empty($password)) {
        json_response(['success' => false, 'message' => 'Ningún campo puede estar vacío.'], 400);
        return;
    }

    if (strlen($password) < 8) {
        json_response(['success' => false, 'message' => 'La contraseña debe tener al menos 8 caracteres.'], 400);
        return;
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'message' => 'El formato del correo electrónico no es válido.'], 400);
        return;
    }

    // Hashear la contraseña
    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    // Nuevo: Generar token de verificación de correo y fecha de expiración
    $verification_token = bin2hex(random_bytes(32)); // Genera un token seguro
    $token_expires_at = (new DateTime())->modify('+1 day')->format('Y-m-d H:i:s'); // Token válido por 24 horas

    // Insertar nuevo usuario en la base de datos con el token de verificación
    // Se han añadido 'verification_token' y 'token_expires_at' a la inserción
    $stmt = $mysqli->prepare("INSERT INTO usuarios (username, email, password_hash, verification_token, token_expires_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $username, $email, $password_hash, $verification_token, $token_expires_at);

    if ($stmt->execute()) {
        // Nuevo: Enviar correo de verificación al usuario
        require_once '../helpers/email_helper.php'; // Asegúrate de crear este archivo

        $verify_link = "https://tu-dominio.com/api.php/verify-email?token=" . $verification_token; // Ajusta 'tu-dominio.com' a tu URL real
        $subject_user = 'Verifica tu correo electrónico para Planea';
        $body_html_user = "Hola " . htmlspecialchars($username) . ",<br><br>"
                       . "Gracias por registrarte en Planea. Por favor, verifica tu correo electrónico haciendo clic en el siguiente enlace: <a href='" . htmlspecialchars($verify_link) . "'>" . htmlspecialchars($verify_link) . "</a><br><br>"
                       . "Este enlace expirará en 24 horas.<br><br>"
                       . "Si no te registraste en Planea, por favor ignora este correo.<br>"
                       . "El equipo de Planea.";
        $body_alt_user = "Hola " . $username . ",\nGracias por registrarte en Planea. Por favor, verifica tu correo electrónico en: " . $verify_link . "\nEste enlace expirará en 24 horas.\nSi no te registraste en Planea, por favor ignora este correo.\nEl equipo de Planea.";

        sendEmail($email, $username, $subject_user, $body_html_user, $body_alt_user);

        // Nuevo: Enviar correo al administrador por cada nuevo usuario registrado
        $admin_email = 'tu_correo_administrador@ejemplo.com'; // ¡DEFINE AQUÍ EL CORREO DEL ADMINISTRADOR!
        $subject_admin = 'Nuevo Usuario Registrado en Planea';
        // Obtener la IP del usuario (puede no ser 100% precisa si hay proxies)
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? 'Desconocida';
        // Para ubicación más detallada necesitarías un servicio de geolocalización de IPs
        $location_info = 'Desconocida'; // Aquí podrías integrar un API como ip-api.com o geojs.io si lo deseas.
                                        // Ejemplo de cómo se obtendría la ciudad con una API (no implementado en este código):
                                        // $ip_details = json_decode(file_get_contents("http://ip-api.com/json/{$user_ip}"));
                                        // if ($ip_details && $ip_details->status == 'success') {
                                        //     $location_info = $ip_details->city . ', ' . $ip_details->country;
                                        // }

        $body_html_admin = "Se ha registrado un nuevo usuario en Planea:<br><br>"
                         . "<strong>Nombre de Usuario:</strong> " . htmlspecialchars($username) . "<br>"
                         . "<strong>Correo Electrónico:</strong> " . htmlspecialchars($email) . "<br>"
                         . "<strong>Fecha y Hora de Registro:</strong> " . date('Y-m-d H:i:s') . "<br>"
                         . "<strong>ID de Usuario (DB):</strong> " . $mysqli->insert_id . "<br>"
                         . "<strong>Dirección IP:</strong> " . htmlspecialchars($user_ip) . "<br>"
                         . "<strong>Ubicación Estimada:</strong> " . htmlspecialchars($location_info);
        $body_alt_admin = "Nuevo usuario registrado:\nNombre: " . $username . "\nCorreo: " . $email . "\nFecha: " . date('Y-m-d H:i:s') . "\nIP: " . $user_ip . "\nUbicación: " . $location_info;

        sendEmail($admin_email, 'Administrador Planea', $subject_admin, $body_html_admin, $body_alt_admin);


        json_response(['success' => true, 'message' => 'Usuario registrado exitosamente. Se ha enviado un enlace de verificación a su correo electrónico.'], 201);
    } else {
        // Manejar error de duplicado (ej. email o username ya existen)
        if ($mysqli->errno == 1062) {
            json_response(['success' => false, 'message' => 'El nombre de usuario o el correo electrónico ya están en uso.'], 409);
        } else {
            json_response(['error' => 'Error al registrar el usuario: ' . $stmt->error], 500);
        }
    }

    $stmt->close();
}
?>