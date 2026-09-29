<?php
/**
 * Inicialización automática de la base de datos (se ejecuta al arrancar el contenedor).
 *  - Si la tabla `productos` no existe, importa db/schema.sql (sin CREATE DATABASE / USE,
 *    porque en Railway la base ya viene creada con otro nombre).
 *  - Si existe la variable ADMIN_PASSWORD, la aplica al usuario "admin".
 *  - En la primera instalación sin ADMIN_PASSWORD, genera una clave aleatoria y la imprime en los logs.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../env.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$db = null;
for ($i = 1; $i <= 15; $i++) {           // la DB puede tardar unos segundos en estar lista
    try {
        $db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
        break;
    } catch (mysqli_sql_exception $e) {
        echo "[init] Esperando a MySQL ({$i}/15): " . $e->getMessage() . "\n";
        sleep(2);
    }
}
if (!$db) { fwrite(STDERR, "[init] No se pudo conectar a MySQL.\n"); exit(1); }
$db->set_charset('utf8mb4');

$fresh = $db->query("SHOW TABLES LIKE 'productos'")->num_rows === 0;
if ($fresh) {
    echo "[init] Base vacía: importando schema.sql...\n";
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $sql = preg_replace('/^\s*CREATE DATABASE.*?;\s*$/mi', '', $sql);
    $sql = preg_replace('/^\s*USE\s+`?[\w]+`?\s*;\s*$/mi', '', $sql);
    $db->multi_query($sql);
    do { if ($r = $db->store_result()) { $r->free(); } } while ($db->more_results() && $db->next_result());
    echo "[init] Schema importado.\n";
}

$adminPass = getEnvVal('ADMIN_PASSWORD', '');
$generated = false;
if ($adminPass === '' && $fresh) {
    $adminPass = substr(bin2hex(random_bytes(8)), 0, 12);
    $generated = true;
}
if ($adminPass !== '') {
    $hash = password_hash($adminPass, PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO usuarios (username, password_hash, nombre) VALUES ('admin', ?, 'Administrador Librería')
                          ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)");
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    echo $generated
        ? "[init] Usuario admin creado. Contraseña generada: {$adminPass}  (definí ADMIN_PASSWORD para fijar la tuya)\n"
        : "[init] Contraseña del usuario admin sincronizada con ADMIN_PASSWORD.\n";
}
