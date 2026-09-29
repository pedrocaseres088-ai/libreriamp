<?php
/**
 * Configuración de entorno y credenciales para el Kiosco Online.
 * Carga automática de variables desde el archivo .env mediante vlucas/phpdotenv (Composer).
 */

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv')) {
        // Carga segura sin lanzar excepciones si no existe el archivo .env
        $dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__);
        $dotenv->safeLoad();
    }
}

// Función helper para obtener variables de entorno
function getEnvVal(string $key, string $default = ''): string {
    $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($val !== false && $val !== null && $val !== '') ? (string)$val : $default;
}

// Base de Datos MySQL (XAMPP por defecto: host=127.0.0.1, user=root, pass="")
define('DB_HOST', getEnvVal('DB_HOST', getEnvVal('MYSQLHOST', '127.0.0.1')));
define('DB_PORT', getEnvVal('DB_PORT', getEnvVal('MYSQLPORT', '3306')));
define('DB_NAME', getEnvVal('DB_NAME', getEnvVal('MYSQLDATABASE', 'kiosco_online')));
define('DB_USER', getEnvVal('DB_USER', getEnvVal('MYSQLUSER', 'root')));
define('DB_PASS', getEnvVal('DB_PASS', getEnvVal('MYSQLPASSWORD', '')));

// Mercado Pago Credentials
define('MP_ACCESS_TOKEN', getEnvVal('MP_ACCESS_TOKEN', ''));
define('MP_PUBLIC_KEY', getEnvVal('MP_PUBLIC_KEY', ''));

// ===== EQUIPO 9 (Librería / Digitales) =====
// Configuración SMTP para el envío automático del libro por email (PHPMailer).
// Con Gmail: activar verificación en 2 pasos y crear una "Contraseña de aplicación".
define('SMTP_HOST', getEnvVal('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT', (int)getEnvVal('SMTP_PORT', '587'));
define('SMTP_USER', getEnvVal('SMTP_USER', ''));            // tu_correo@gmail.com
define('SMTP_PASS', getEnvVal('SMTP_PASS', ''));            // contraseña de aplicación (16 caracteres)
define('SMTP_FROM', getEnvVal('SMTP_FROM', getEnvVal('SMTP_USER', 'no-reply@libreria.local')));
define('SMTP_FROM_NAME', getEnvVal('SMTP_FROM_NAME', 'Librería Online'));

// Envío de email por API HTTPS (Resend). Necesario en Railway Free/Hobby, donde el SMTP está bloqueado.
define('RESEND_API_KEY', getEnvVal('RESEND_API_KEY', ''));
define('RESEND_FROM', getEnvVal('RESEND_FROM', 'Librería Online <onboarding@resend.dev>'));

// Horas de validez del link de descarga temporal seguro
define('DOWNLOAD_EXPIRY_HOURS', (int)getEnvVal('DOWNLOAD_EXPIRY_HOURS', '72'));
// Máximo de descargas permitidas por token
define('DOWNLOAD_MAX', (int)getEnvVal('DOWNLOAD_MAX', '5'));

// URL Base del proyecto en XAMPP (sanitizada sin comillas ni barras al final)
$rawBaseUrl = getEnvVal('BASE_URL');
if (!$rawBaseUrl && getEnvVal('RAILWAY_PUBLIC_DOMAIN')) {
    $rawBaseUrl = 'https://' . getEnvVal('RAILWAY_PUBLIC_DOMAIN');
}
if (!$rawBaseUrl) {
    // Detrás del proxy de Railway el HTTPS llega en X-Forwarded-Proto
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $rawBaseUrl = ($https ? 'https' : 'http') . '://' . $host;
}

$cleanBaseUrl = trim($rawBaseUrl, "\"' \t\n\r\0\x0B/");
if (!preg_match('/^https?:\/\//i', $cleanBaseUrl)) {
    $cleanBaseUrl = 'http://' . $cleanBaseUrl;
}

define('BASE_URL', $cleanBaseUrl);


