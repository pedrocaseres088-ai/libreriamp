<?php
/**
 * ===== EQUIPO 9 (Librería / Digitales) =====
 * Helper de envío de correo con PHPMailer (SMTP).
 *
 * Envía automáticamente al cliente los enlaces de descarga de los libros
 * digitales que compró, una vez que Mercado Pago aprueba el pago.
 *
 * Requiere: composer require phpmailer/phpmailer
 * Si PHPMailer no está instalado o el SMTP no está configurado en .env,
 * la función devuelve false (el webhook igualmente registra los links en el log).
 */

require_once __DIR__ . '/../env.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Envía el email con los enlaces de descarga.
 *
 * @param string $to            Email del cliente
 * @param string $nombreCliente Nombre a mostrar (puede ser el mismo email)
 * @param array  $enlaces       Lista de ['nombre' => string, 'url' => string, 'expira' => string]
 * @return bool  true si se envió; false si no se pudo (sin PHPMailer o sin SMTP)
 */
function enviarCorreoDescargas(string $to, string $nombreCliente, array $enlaces): bool
{
    if (empty($enlaces) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $usaResend = RESEND_API_KEY !== '';
    if (!$usaResend) {
        // Sin PHPMailer instalado -> no se puede enviar (el webhook usa el fallback del log)
        if (!class_exists(PHPMailer::class)) {
            return false;
        }
        // Sin credenciales SMTP cargadas -> no se puede enviar
        if (SMTP_USER === '' || SMTP_PASS === '') {
            return false;
        }
    }

    // Construir la lista de descargas en HTML
    $filas = '';
    foreach ($enlaces as $e) {
        $nombre = htmlspecialchars($e['nombre'] ?? 'Libro digital', ENT_QUOTES, 'UTF-8');
        $url    = htmlspecialchars($e['url'] ?? '#', ENT_QUOTES, 'UTF-8');
        $expira = htmlspecialchars($e['expira'] ?? '', ENT_QUOTES, 'UTF-8');
        $filas .= "
            <tr>
              <td style='padding:12px 0;border-bottom:1px solid #eee;'>
                <strong style='color:#1e293b;'>{$nombre}</strong><br>
                <a href='{$url}' style='display:inline-block;margin-top:8px;padding:10px 18px;background:#2563eb;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;'>⬇ Descargar</a>
                <div style='font-size:12px;color:#94a3b8;margin-top:6px;'>Link válido hasta: {$expira}</div>
              </td>
            </tr>";
    }

    $cuerpo = "
      <div style='font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:auto;padding:24px;'>
        <h2 style='color:#1e293b;'>📚 ¡Gracias por tu compra!</h2>
        <p style='color:#475569;'>Hola " . htmlspecialchars($nombreCliente, ENT_QUOTES, 'UTF-8') . ",
        tu pago fue aprobado. Ya podés descargar tus productos digitales desde los siguientes enlaces seguros y temporales:</p>
        <table style='width:100%;border-collapse:collapse;margin-top:12px;'>{$filas}</table>
        <p style='color:#94a3b8;font-size:12px;margin-top:24px;'>Estos enlaces son personales. No los compartas.<br>Librería Online — Equipo 9</p>
      </div>";

    // ---- Opción 1: Resend por API HTTPS (funciona en Railway Free/Hobby) ----
    if ($usaResend) {
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . RESEND_API_KEY,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'from'    => RESEND_FROM,
                'to'      => [$to],
                'subject' => '📚 Tus libros digitales están listos para descargar',
                'html'    => $cuerpo,
            ]),
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            return true;
        }
        @file_put_contents(
            __DIR__ . '/../api/webhook.log',
            "[" . date('Y-m-d H:i:s') . "] Error Resend HTTP {$code}: " . substr((string)$resp, 0, 300) . "\n",
            FILE_APPEND
        );
        return false;
    }

    // ---- Opción 2: SMTP con PHPMailer (NO funciona en Railway Free/Hobby) ----
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // 587 = STARTTLS
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to, $nombreCliente);

        $mail->isHTML(true);
        $mail->Subject = '📚 Tus libros digitales están listos para descargar';
        $mail->Body    = $cuerpo;
        $mail->AltBody = "Gracias por tu compra. Descargá tus libros desde los enlaces enviados.";

        $mail->send();
        return true;
    } catch (PHPMailerException $ex) {
        @file_put_contents(
            __DIR__ . '/../api/webhook.log',
            "[" . date('Y-m-d H:i:s') . "] Error PHPMailer: " . $mail->ErrorInfo . "\n",
            FILE_APPEND
        );
        return false;
    }
}
