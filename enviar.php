<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo no permitido']);
    exit;
}

$nombre = filter_input(INPUT_POST, 'nombre', FILTER_SANITIZE_SPECIAL_CHARS);
$email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
$mensaje = filter_input(INPUT_POST, 'mensaje', FILTER_SANITIZE_SPECIAL_CHARS);

if (empty($nombre) || empty($email) || empty($mensaje)) {
    http_response_code(400);
    echo json_encode(['error' => 'Todos los campos son obligatorios']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Email invalido']);
    exit;
}

// SMTP config - Zoho
$smtpHost = 'smtppro.zoho.com';
$smtpPort = 465;
$smtpUser = 'contacto@vibetech.cl';
$smtpPass = getenv('ZOHO_SMTP_PASS');

if (empty($smtpPass)) {
    // Fallback: read from config file
    $configFile = __DIR__ . '/.smtp_config';
    if (file_exists($configFile)) {
        $smtpPass = trim(file_get_contents($configFile));
    }
}

if (empty($smtpPass)) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuracion SMTP incompleta']);
    exit;
}

$to = 'contacto@vibetech.cl';
$subject = 'Contacto desde vibetech.cl - ' . $nombre;
$mensajeHtml = nl2br(htmlspecialchars($mensaje));
$fecha = date('d/m/Y H:i');
$body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background-color:#F8FAFC;font-family:'Inter',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#F8FAFC;padding:32px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
        <!-- Header -->
        <tr>
          <td style="background-color:#4F46E5;padding:28px 32px;">
            <table cellpadding="0" cellspacing="0"><tr>
              <td style="background-color:rgba(255,255,255,0.2);border-radius:8px;width:36px;height:36px;text-align:center;vertical-align:middle;">
                <span style="color:#ffffff;font-size:18px;font-weight:800;">V</span>
              </td>
              <td style="padding-left:10px;color:#ffffff;font-size:20px;font-weight:700;">Vibe Tech</td>
            </tr></table>
          </td>
        </tr>
        <!-- Title -->
        <tr>
          <td style="padding:32px 32px 8px;">
            <h2 style="margin:0;color:#1E293B;font-size:22px;font-weight:800;">Nuevo mensaje de contacto</h2>
          </td>
        </tr>
        <!-- Date -->
        <tr>
          <td style="padding:0 32px 24px;">
            <span style="color:#64748B;font-size:13px;">$fecha</span>
          </td>
        </tr>
        <!-- Info cards -->
        <tr>
          <td style="padding:0 32px;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#F8FAFC;border-radius:12px;padding:20px;">
              <tr>
                <td style="padding:12px 20px;border-bottom:1px solid #E2E8F0;">
                  <span style="color:#64748B;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Nombre</span><br>
                  <span style="color:#1E293B;font-size:16px;font-weight:600;">$nombre</span>
                </td>
              </tr>
              <tr>
                <td style="padding:12px 20px;">
                  <span style="color:#64748B;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Email</span><br>
                  <a href="mailto:$email" style="color:#4F46E5;font-size:16px;font-weight:600;text-decoration:none;">$email</a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
        <!-- Message -->
        <tr>
          <td style="padding:24px 32px;">
            <span style="color:#64748B;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Mensaje</span>
            <div style="margin-top:8px;padding:20px;background-color:#F8FAFC;border-radius:12px;border-left:4px solid #4F46E5;">
              <p style="margin:0;color:#1E293B;font-size:15px;line-height:1.6;">$mensajeHtml</p>
            </div>
          </td>
        </tr>
        <!-- CTA -->
        <tr>
          <td style="padding:8px 32px 32px;">
            <a href="mailto:$email" style="display:inline-block;background-color:#4F46E5;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:12px 24px;border-radius:9999px;">Responder a $nombre</a>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background-color:#0F172A;padding:20px 32px;text-align:center;">
            <span style="color:#64748B;font-size:13px;">Enviado desde el formulario de contacto de </span>
            <a href="https://vibetech.cl" style="color:#818CF8;font-size:13px;text-decoration:none;font-weight:600;">vibetech.cl</a>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

function sendSmtp($host, $port, $user, $pass, $to, $subject, $body, $replyTo) {
    $socket = stream_socket_client(
        "ssl://$host:$port",
        $errno, $errstr, 30,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])
    );

    if (!$socket) {
        return "Connection failed: $errstr ($errno)";
    }

    $response = fgets($socket, 512);
    if (substr($response, 0, 3) !== '220') return "Bad greeting: $response";

    $commands = [
        "EHLO vibetech.cl",
        "AUTH LOGIN",
        base64_encode($user),
        base64_encode($pass),
    ];

    $expectedCodes = ['250', '334', '334', '235'];

    foreach ($commands as $i => $cmd) {
        fwrite($socket, $cmd . "\r\n");
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ' || substr($line, 3, 1) === "\r") break;
        }
        $code = substr(trim($response), 0, 3);
        if ($code !== $expectedCodes[$i]) {
            fwrite($socket, "QUIT\r\n");
            fclose($socket);
            return "SMTP error at step $i: $response";
        }
    }

    $mailFrom = "MAIL FROM:<$user>";
    fwrite($socket, $mailFrom . "\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) !== '250') return "MAIL FROM failed: $response";

    fwrite($socket, "RCPT TO:<$to>\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) !== '250') return "RCPT TO failed: $response";

    fwrite($socket, "DATA\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) !== '354') return "DATA failed: $response";

    $message = "From: $user\r\n";
    $message .= "To: $to\r\n";
    $message .= "Reply-To: $replyTo\r\n";
    $message .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Date: " . date('r') . "\r\n";
    $message .= "\r\n";
    $message .= $body . "\r\n";
    $message .= ".\r\n";

    fwrite($socket, $message);
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) !== '250') return "Send failed: $response";

    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return true;
}

$result = sendSmtp($smtpHost, $smtpPort, $smtpUser, $smtpPass, $to, $subject, $body, $email);

if ($result === true) {
    echo json_encode(['success' => true, 'message' => 'Mensaje enviado correctamente']);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo enviar el mensaje', 'detail' => $result]);
}
