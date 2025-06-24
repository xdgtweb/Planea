<?php
// api_handlers/share_task.php
// Este archivo maneja la lógica para compartir tareas con otros usuarios.

if (!isset($_SESSION['usuario_id'])) {
    json_response(['error' => 'Acceso no autorizado'], 401);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$method = $_SERVER['REQUEST_METHOD'];

// Incluir el helper para enviar correos
require_once __DIR__ . '/../helpers/email_helper.php';

if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    $task_id = $data['task_id'] ?? 0;
    $emails_to_share = $data['emails_to_share'] ?? [];
    $include_reminder_times = $data['include_reminder_times'] ?? false; // Si se deben incluir las horas de recordatorio en el correo del invitado
    $task_details_text = $data['task_details_text'] ?? 'Tarea compartida.'; // Texto de la tarea para el correo

    if ($task_id <= 0 || !is_array($emails_to_share) || empty($emails_to_share)) {
        json_response(['error' => 'ID de tarea o emails para compartir inválidos.'], 400);
        return;
    }

    // Verificar que la tarea a compartir realmente pertenece al usuario
    $stmt_check_task_owner = $mysqli->prepare("SELECT id, tipo, texto, fecha_inicio FROM tareas_diarias WHERE id = ? AND usuario_id = ?");
    if (!$stmt_check_task_owner) {
        json_response(['error' => 'Error al preparar la verificación de la tarea: ' . $mysqli->error], 500);
        return;
    }
    $stmt_check_task_owner->bind_param("ii", $task_id, $usuario_id);
    $stmt_check_task_owner->execute();
    $result_check_task_owner = $stmt_check_task_owner->get_result();
    $task_info = $result_check_task_owner->fetch_assoc();
    $stmt_check_task_owner->close();

    if (!$task_info) {
        json_response(['error' => 'Tarea no encontrada o no pertenece al usuario.'], 403);
        return;
    }

    $mysqli->begin_transaction();
    try {
        $shared_emails_success = [];
        $shared_emails_failed = [];

        foreach ($emails_to_share as $email_to_share) {
            $email_to_share = trim($email_to_share);
            if (!filter_var($email_to_share, FILTER_VALIDATE_EMAIL)) {
                $shared_emails_failed[] = ['email' => $email_to_share, 'reason' => 'Formato de correo inválido'];
                continue;
            }

            // 1. Verificar si el email ya es un usuario registrado
            $stmt_get_user_by_email = $mysqli->prepare("SELECT id, username FROM usuarios WHERE email = ?");
            $stmt_get_user_by_email->bind_param("s", $email_to_share);
            $stmt_get_user_by_email->execute();
            $result_user_by_email = $stmt_get_user_by_email->get_result();
            $invited_user = $result_user_by_email->fetch_assoc();
            $stmt_get_user_by_email->close();

            $shared_with_user_id = $invited_user['id'] ?? null;
            $shared_with_username = $invited_user['username'] ?? null;
            $access_token = null; // Token solo si el usuario no está registrado
            $is_new_invitation = false;

            // 2. Verificar si la tarea ya está compartida con este usuario/email
            $stmt_check_existing_share = $mysqli->prepare("
                SELECT id FROM shared_tasks 
                WHERE task_id = ? AND (shared_with_user_id = ? OR shared_with_email = ?)
            ");
            // Usar NULL si no hay user_id para la comprobación
            $stmt_check_existing_share->bind_param("iis", $task_id, $shared_with_user_id, $email_to_share);
            $stmt_check_existing_share->execute();
            $result_existing_share = $stmt_check_existing_share->get_result();
            if ($result_existing_share->num_rows > 0) {
                $shared_emails_failed[] = ['email' => $email_to_share, 'reason' => 'Tarea ya compartida con este correo/usuario.'];
                $stmt_check_existing_share->close();
                continue;
            }
            $stmt_check_existing_share->close();


            // 3. Insertar en shared_tasks
            if (!$shared_with_user_id) { // Si el usuario no está registrado, genera un token de acceso
                $access_token = bin2hex(random_bytes(32));
                $is_new_invitation = true;
                $stmt_insert_share = $mysqli->prepare("INSERT INTO shared_tasks (task_id, owner_user_id, shared_with_email, access_token) VALUES (?, ?, ?, ?)");
                $stmt_insert_share->bind_param("iiss", $task_id, $usuario_id, $email_to_share, $access_token);
            } else { // Si el usuario está registrado
                $stmt_insert_share = $mysqli->prepare("INSERT INTO shared_tasks (task_id, owner_user_id, shared_with_user_id) VALUES (?, ?, ?)");
                $stmt_insert_share->bind_param("iii", $task_id, $usuario_id, $shared_with_user_id);
            }

            if (!$stmt_insert_share->execute()) {
                if ($mysqli->errno == 1062) { // Error de duplicado
                    $shared_emails_failed[] = ['email' => $email_to_share, 'reason' => 'Ya compartida (duplicado inesperado).'];
                } else {
                    $shared_emails_failed[] = ['email' => $email_to_share, 'reason' => 'Error al guardar en DB: ' . $stmt_insert_share->error];
                }
                $stmt_insert_share->close();
                continue;
            }
            $stmt_insert_share->close();
            
            // 4. Enviar correo electrónico al invitado
            $owner_username_query = $mysqli->prepare("SELECT username FROM usuarios WHERE id = ?");
            $owner_username_query->bind_param("i", $usuario_id);
            $owner_username_query->execute();
            $owner_username = $owner_username_query->get_result()->fetch_assoc()['username'] ?? 'Un usuario de Planea';
            $owner_username_query->close();

            $email_subject = "¡Una tarea ha sido compartida contigo en Planea!";
            $email_body_html = "Hola" . ($shared_with_username ? " " . htmlspecialchars($shared_with_username) : "") . ",<br><br>"
                             . htmlspecialchars($owner_username) . " ha compartido una tarea contigo en Planea:<br>"
                             . "<strong>" . htmlspecialchars($task_details_text) . "</strong><br><br>";

            $email_body_alt = "Hola" . ($shared_with_username ? " " . $shared_with_username : "") . ",\n\n"
                            . $owner_username . " ha compartido una tarea contigo en Planea:\n"
                            . $task_details_text . "\n\n";

            if ($include_reminder_times && $task_info['tipo'] === 'titulo') {
                // Obtener horas de recordatorio para la tarea principal (si existen)
                $reminder_times = [];
                $stmt_get_times = $mysqli->prepare("
                    SELECT rt.time_of_day
                    FROM reminders r
                    JOIN reminder_times rt ON r.id = rt.reminder_id
                    WHERE r.tarea_id = ?
                ");
                if ($stmt_get_times) {
                    $stmt_get_times->bind_param("i", $task_id);
                    $stmt_get_times->execute();
                    $times_result = $stmt_get_times->get_result();
                    while($row = $times_result->fetch_assoc()) {
                        $reminder_times[] = date('H:i', strtotime($row['time_of_day']));
                    }
                    $stmt_get_times->close();
                }

                if (!empty($reminder_times)) {
                    $email_body_html .= "Está programada para el <strong>" . htmlspecialchars($task_info['fecha_inicio']) . "</strong> a las " . implode(', ', $reminder_times) . " horas.<br><br>";
                    $email_body_alt .= "Está programada para el " . $task_info['fecha_inicio'] . " a las " . implode(', ', $reminder_times) . " horas.\n\n";
                }
            }


            if ($is_new_invitation) {
                // Enlace para que el invitado se registre y vea la tarea
                $invite_link = "https://tu-dominio.com/index.html?invite_token=" . $access_token; // Ajusta a tu dominio
                $email_body_html .= "Si aún no tienes una cuenta en Planea, regístrate para acceder a tus tareas compartidas: <a href='" . htmlspecialchars($invite_link) . "'>" . htmlspecialchars($invite_link) . "</a><br><br>";
                $email_body_alt .= "Si aún no tienes una cuenta en Planea, regístrate para acceder a tus tareas compartidas: " . $invite_link . "\n\n";
            } else {
                // Enlace directo si ya está registrado
                $app_link = "https://tu-dominio.com/index.html"; // Ajusta a tu dominio
                $email_body_html .= "Accede a Planea para verla: <a href='" . htmlspecialchars($app_link) . "'>" . htmlspecialchars($app_link) . "</a><br><br>";
                $email_body_alt .= "Accede a Planea para verla: " . $app_link . "\n\n";
            }

            $email_body_html .= "El equipo de Planea.";
            $email_body_alt .= "El equipo de Planea.";

            if (sendEmail($email_to_share, $shared_with_username ?? $email_to_share, $email_subject, $email_body_html, $email_body_alt)) {
                $shared_emails_success[] = $email_to_share;
            } else {
                $shared_emails_failed[] = ['email' => $email_to_share, 'reason' => 'Error al enviar el correo.'];
            }
        }

        $mysqli->commit();
        json_response([
            'success' => true,
            'message' => 'Tareas compartidas.',
            'sent_to' => $shared_emails_success,
            'failed_to_send_to' => $shared_emails_failed
        ]);

    } catch (Exception $e) {
        $mysqli->rollback();
        error_log("Error al compartir tarea: " . $e->getMessage());
        json_response(['error' => 'Error al compartir la tarea: ' . $e->getMessage()], 500);
    }

} else if ($method === 'DELETE') {
    // Lógica para dejar de compartir una tarea
    $data = json_decode(file_get_contents("php://input"), true);
    $shared_task_id = $data['shared_task_id'] ?? 0;

    if ($shared_task_id <= 0) {
        json_response(['error' => 'ID de compartido inválido.'], 400);
        return;
    }

    // Asegurarse de que el usuario logueado es el dueño de la tarea compartida
    $stmt_delete = $mysqli->prepare("DELETE FROM shared_tasks WHERE id = ? AND owner_user_id = ?");
    if (!$stmt_delete) {
        json_response(['error' => 'Error al preparar la eliminación de compartido: ' . $mysqli->error], 500);
        return;
    }
    $stmt_delete->bind_param("ii", $shared_task_id, $usuario_id);

    if ($stmt_delete->execute()) {
        if ($stmt_delete->affected_rows > 0) {
            json_response(['success' => true, 'message' => 'Tarea dejada de compartir.']);
        } else {
            json_response(['error' => 'Compartido no encontrado o no autorizado.'], 404);
        }
    } else {
        json_response(['error' => 'Error al dejar de compartir: ' . $stmt_delete->error], 500);
    }
    $stmt_delete->close();

} else if ($method === 'GET') {
    // Lógica para obtener con quién se comparte una tarea específica
    $task_id = $_GET['task_id'] ?? 0;

    if ($task_id <= 0) {
        json_response(['error' => 'ID de tarea inválido.'], 400);
        return;
    }

    // Verificar que la tarea es del usuario que la consulta
    $stmt_check_owner = $mysqli->prepare("SELECT id FROM tareas_diarias WHERE id = ? AND usuario_id = ?");
    $stmt_check_owner->bind_param("ii", $task_id, $usuario_id);
    $stmt_check_owner->execute();
    if ($stmt_check_owner->get_result()->num_rows === 0) {
        json_response(['error' => 'Tarea no encontrada o no pertenece al usuario.'], 403);
        return;
    }
    $stmt_check_owner->close();


    $stmt_get_shared_with = $mysqli->prepare("
        SELECT st.id as shared_id, st.shared_with_user_id, st.shared_with_email, u.username, u.email as user_email
        FROM shared_tasks st
        LEFT JOIN usuarios u ON st.shared_with_user_id = u.id
        WHERE st.task_id = ? AND st.owner_user_id = ?
    ");
    if (!$stmt_get_shared_with) {
        json_response(['error' => 'Error al preparar la consulta de compartidos: ' . $mysqli->error], 500);
        return;
    }
    $stmt_get_shared_with->bind_param("ii", $task_id, $usuario_id);
    $stmt_get_shared_with->execute();
    $result_shared = $stmt_get_shared_with->get_result();

    $shared_with_list = [];
    while ($row = $result_shared->fetch_assoc()) {
        $shared_with_list[] = [
            'shared_id' => $row['shared_id'],
            'email' => $row['user_email'] ?? $row['shared_with_email'],
            'username' => $row['username'],
            'is_registered' => !empty($row['shared_with_user_id'])
        ];
    }
    json_response($shared_with_list);
    $stmt_get_shared_with->close();

} else {
    json_response(['error' => 'Método no permitido para compartir tareas'], 405);
}
?>