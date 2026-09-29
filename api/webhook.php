<?php
/**
 * Webhook / IPN Listener para recibir notificaciones automáticas de Mercado Pago.
 * Actualiza el estado de la orden en MySQLi e incrementa/descuenta el inventario.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../env.php';
require_once __DIR__ . '/../config/mailer.php'; // EQUIPO 9: envío automático del libro

// Mercado Pago envía notificaciones por GET o POST
$paymentId = $_GET['data_id'] ?? $_GET['id'] ?? null;
$type = $_GET['type'] ?? $_GET['topic'] ?? null;

if (!$paymentId) {
    $rawInput = file_get_contents('php://input');
    $body = json_decode($rawInput, true);
    if (isset($body['data']['id'])) {
        $paymentId = $body['data']['id'];
    }
    if (isset($body['type'])) {
        $type = $body['type'];
    }
}

// Log de depuración
$logMessage = sprintf("[%s] Webhook recibido: Type=%s, PaymentID=%s\n", date('Y-m-d H:i:s'), $type ?? 'N/A', $paymentId ?? 'N/A');
@file_put_contents(__DIR__ . '/webhook.log', $logMessage, FILE_APPEND);

if ($paymentId && ($type === 'payment' || $type === null)) {
    try {
        // 1. Consultar estado del pago a Mercado Pago API
        $ch = curl_init("https://api.mercadopago.com/v1/payments/" . $paymentId);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . MP_ACCESS_TOKEN
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $paymentData = json_decode($response, true);

            $status = $paymentData['status'] ?? 'pending';
            $externalReference = $paymentData['external_reference'] ?? null;
            $merchantOrderId = (string)($paymentData['order']['id'] ?? '');

            if ($externalReference) {
                $db = Database::getConnection();
                $db->begin_transaction();

                // Consultar estado previo de la orden
                $stmtPrev = $db->prepare("SELECT id, estado, email_cliente FROM ordenes WHERE external_reference = ? FOR UPDATE");
                $stmtPrev->bind_param("s", $externalReference);
                $stmtPrev->execute();
                $resPrev = $stmtPrev->get_result();
                $order = $resPrev->fetch_assoc();

                if ($order) {
                    $previousStatus = $order['estado'];

                    $dbStatus = 'pending';
                    if ($status === 'approved') {
                        $dbStatus = 'approved';
                    } elseif (in_array($status, ['rejected', 'cancelled', 'refunded', 'charged_back'])) {
                        $dbStatus = 'rejected';
                    }

                    // Actualizar orden en MySQLi
                    $stmtUpdate = $db->prepare("
                        UPDATE ordenes 
                        SET estado = ?, 
                            mp_payment_id = ?, 
                            mp_merchant_order_id = ?, 
                            updated_at = NOW()
                        WHERE id = ?
                    ");
                    $pIdStr = (string)$paymentId;
                    $orderIdInt = (int)$order['id'];

                    $stmtUpdate->bind_param("sssi", $dbStatus, $pIdStr, $merchantOrderId, $orderIdInt);
                    $stmtUpdate->execute();

                    // ===== EQUIPO 9: entrega automática de productos digitales =====
                    // Al aprobarse el pago (y solo la primera vez):
                    //   - Producto DIGITAL  -> generar token + link seguro (NO descuenta stock)
                    //   - Producto FÍSICO   -> descontar stock como siempre
                    $enlacesDigitales = [];

                    if ($dbStatus === 'approved' && $previousStatus !== 'approved') {
                        // Traer los ítems junto con los datos del producto
                        $stmtItems = $db->prepare("
                            SELECT oi.producto_id, oi.cantidad,
                                   p.nombre, p.es_digital, p.archivo_digital
                            FROM orden_items oi
                            INNER JOIN productos p ON p.id = oi.producto_id
                            WHERE oi.orden_id = ?
                        ");
                        $stmtItems->bind_param("i", $orderIdInt);
                        $stmtItems->execute();
                        $resItems = $stmtItems->get_result();
                        $items = $resItems->fetch_all(MYSQLI_ASSOC);

                        $stmtStock = $db->prepare("UPDATE productos SET stock = GREATEST(0, stock - ?) WHERE id = ?");
                        $stmtDesc  = $db->prepare("
                            INSERT INTO descargas (orden_id, producto_id, token, expira_en, max_descargas)
                            VALUES (?, ?, ?, ?, ?)
                        ");

                        $baseUrl = rtrim(trim(BASE_URL), '/');
                        $expiraEn = date('Y-m-d H:i:s', time() + (DOWNLOAD_EXPIRY_HOURS * 3600));

                        foreach ($items as $item) {
                            $prodIdInt = (int)$item['producto_id'];

                            if ((int)$item['es_digital'] === 1 && !empty($item['archivo_digital'])) {
                                // ---- Producto DIGITAL: generar link seguro y temporal ----
                                $token = bin2hex(random_bytes(32)); // 64 caracteres hex
                                $maxDesc = DOWNLOAD_MAX;
                                $stmtDesc->bind_param("iissi", $orderIdInt, $prodIdInt, $token, $expiraEn, $maxDesc);
                                $stmtDesc->execute();

                                $enlacesDigitales[] = [
                                    'nombre' => $item['nombre'],
                                    'url'    => $baseUrl . '/public/download.php?token=' . $token,
                                    'expira' => $expiraEn
                                ];
                            } else {
                                // ---- Producto FÍSICO: descontar stock ----
                                $cantInt = (int)$item['cantidad'];
                                $stmtStock->bind_param("ii", $cantInt, $prodIdInt);
                                $stmtStock->execute();
                            }
                        }
                    }

                    $db->commit();
                    @file_put_contents(__DIR__ . '/webhook.log', "Orden {$externalReference} actualizada a: {$dbStatus}\n", FILE_APPEND);

                    // ===== EQUIPO 9: enviar el email FUERA de la transacción (ya confirmada) =====
                    if (!empty($enlacesDigitales)) {
                        $emailCliente = trim($order['email_cliente'] ?? '');

                        // Dejar SIEMPRE registrados los links en el log (respaldo / demostración)
                        foreach ($enlacesDigitales as $e) {
                            @file_put_contents(
                                __DIR__ . '/webhook.log',
                                "  -> DESCARGA [{$e['nombre']}]: {$e['url']} (vence {$e['expira']})\n",
                                FILE_APPEND
                            );
                        }

                        // Intentar el envío automático por correo
                        $enviado = false;
                        if ($emailCliente !== '') {
                            $enviado = enviarCorreoDescargas($emailCliente, $emailCliente, $enlacesDigitales);
                        }
                        @file_put_contents(
                            __DIR__ . '/webhook.log',
                            "  -> Email a {$emailCliente}: " . ($enviado ? 'ENVIADO ✅' : 'NO enviado (usar link del log / revisar SMTP)') . "\n",
                            FILE_APPEND
                        );
                    }
                } else {
                    $db->rollback();
                }
            }
        }
    } catch (Exception $e) {
        if (isset($db)) {
            @$db->rollback();
        }
        @file_put_contents(__DIR__ . '/webhook.log', "Error en webhook MySQLi: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok']);
