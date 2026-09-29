<?php
/**
 * ===== EQUIPO 9 (Librería / Digitales) =====
 * Descarga segura mediante token temporal.
 *
 * URL: /public/download.php?token=XXXXXXXX
 *
 * Valida que el token exista, no esté vencido y no haya superado el máximo de
 * descargas. Solo entonces entrega el PDF desde la carpeta protegida /secure_files.
 * De este modo el archivo real nunca queda expuesto por una URL directa.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../env.php';

/** Muestra una página de error simple y termina. */
function mostrarError(string $titulo, string $mensaje, int $code = 403): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html lang='es'><head><meta charset='utf-8'>
      <meta name='viewport' content='width=device-width, initial-scale=1'>
      <title>{$titulo}</title>
      <style>
        body{font-family:Arial,Helvetica,sans-serif;background:#0f172a;color:#e2e8f0;
             display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
        .card{background:#1e293b;padding:40px;border-radius:16px;max-width:440px;text-align:center;
              box-shadow:0 20px 60px rgba(0,0,0,.4)}
        h1{font-size:22px;margin:0 0 12px}
        p{color:#94a3b8;line-height:1.6}
        a{color:#60a5fa}
      </style></head><body>
      <div class='card'><h1>🔒 {$titulo}</h1><p>{$mensaje}</p>
      <p><a href='" . htmlspecialchars(BASE_URL, ENT_QUOTES) . "/index.php'>← Volver a la tienda</a></p>
      </div></body></html>";
    exit;
}

$token = trim($_GET['token'] ?? '');

// El token generado es hexadecimal de 64 caracteres
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
    mostrarError('Enlace inválido', 'El enlace de descarga no es correcto o está incompleto.', 400);
}

try {
    $db = Database::getConnection();

    // Traer la descarga junto con el archivo del producto
    $stmt = $db->prepare("
        SELECT d.id, d.expira_en, d.descargas_realizadas, d.max_descargas,
               p.nombre, p.archivo_digital, p.es_digital
        FROM descargas d
        INNER JOIN productos p ON p.id = d.producto_id
        WHERE d.token = ?
        LIMIT 1
    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();

    if (!$row) {
        mostrarError('Enlace no encontrado', 'Este enlace de descarga no existe.', 404);
    }

    // ¿Vencido?
    if (strtotime($row['expira_en']) < time()) {
        mostrarError('Enlace vencido', 'Este enlace de descarga expiró. Contactá a la librería para renovarlo.', 410);
    }

    // ¿Superó el tope de descargas?
    if ((int)$row['descargas_realizadas'] >= (int)$row['max_descargas']) {
        mostrarError('Límite alcanzado', 'Se alcanzó el máximo de descargas permitidas para este enlace.', 429);
    }

    // Ruta física del archivo protegido
    $archivo = basename((string)$row['archivo_digital']); // evita path traversal
    $ruta = __DIR__ . '/../secure_files/' . $archivo;

    if ($archivo === '' || !is_file($ruta)) {
        mostrarError('Archivo no disponible', 'El archivo digital no está disponible en este momento.', 500);
    }

    // Registrar la descarga (incrementar contador)
    $upd = $db->prepare("UPDATE descargas SET descargas_realizadas = descargas_realizadas + 1 WHERE id = ?");
    $idDescarga = (int)$row['id'];
    $upd->bind_param('i', $idDescarga);
    $upd->execute();

    // Nombre amigable para el archivo descargado
    $nombreDescarga = preg_replace('/[^A-Za-z0-9._-]+/', '_', $row['nombre']) . '.pdf';

    // Entregar el PDF
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Description: File Transfer');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $nombreDescarga . '"');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . filesize($ruta));
    readfile($ruta);
    exit;

} catch (Exception $e) {
    mostrarError('Error', 'Ocurrió un error al procesar la descarga.', 500);
}
