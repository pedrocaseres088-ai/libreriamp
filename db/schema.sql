-- =======================================================
-- Esquema de Base de Datos - LIBRERÍA ONLINE (Equipo 9)
-- Kiosco Online adaptado a venta de LIBROS y PRODUCTOS DIGITALES
-- con entrega automática por Mercado Pago (Webhook -> Email + URL segura)
-- Base de datos: kiosco_online
-- =======================================================

CREATE DATABASE IF NOT EXISTS `kiosco_online` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `kiosco_online`;

-- --------------------------------------------------------
-- Tabla: usuarios (módulo de administración)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `nombre` VARCHAR(100) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tabla: productos
-- CAMBIO EQUIPO 9: se agregan 'es_digital' y 'archivo_digital'
--   es_digital      -> 1 si es un libro/curso descargable, 0 si es físico
--   archivo_digital -> nombre del PDF guardado en la carpeta protegida /secure_files
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `productos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nombre` VARCHAR(150) NOT NULL,
  `descripcion` TEXT NULL,
  `precio` DECIMAL(10, 2) NOT NULL,
  `categoria` VARCHAR(50) NOT NULL,
  `imagen_url` VARCHAR(500) NULL,
  `stock` INT NOT NULL DEFAULT 0,
  `destacado` TINYINT(1) DEFAULT 0,
  `es_digital` TINYINT(1) NOT NULL DEFAULT 0,
  `archivo_digital` VARCHAR(255) NULL,
  `fecha_creacion` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tabla: ordenes
-- CAMBIO EQUIPO 9: se agrega 'email_cliente' para poder enviar la descarga
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ordenes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `external_reference` VARCHAR(64) NOT NULL UNIQUE,
  `email_cliente` VARCHAR(255) NULL,
  `monto_total` DECIMAL(10, 2) NOT NULL,
  `estado` ENUM('pending', 'approved', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
  `mp_payment_id` VARCHAR(100) NULL,
  `mp_merchant_order_id` VARCHAR(100) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tabla: orden_items
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `orden_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `orden_id` INT NOT NULL,
  `producto_id` INT NOT NULL,
  `cantidad` INT NOT NULL,
  `precio_unitario` DECIMAL(10, 2) NOT NULL,
  FOREIGN KEY (`orden_id`) REFERENCES `ordenes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`producto_id`) REFERENCES `productos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- NUEVA TABLA EQUIPO 9: descargas
-- Guarda un token único y temporal por cada producto digital comprado.
-- El cliente descarga a través de /public/download.php?token=XXXX
--   token        -> cadena aleatoria imposible de adivinar (256 bits)
--   expira_en    -> fecha/hora hasta la que el link es válido
--   max_descargas-> tope de descargas permitidas con ese token
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `descargas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `orden_id` INT NOT NULL,
  `producto_id` INT NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `expira_en` DATETIME NOT NULL,
  `descargas_realizadas` INT NOT NULL DEFAULT 0,
  `max_descargas` INT NOT NULL DEFAULT 5,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`orden_id`) REFERENCES `ordenes`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`producto_id`) REFERENCES `productos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Usuario administrador (admin / admin123)
-- --------------------------------------------------------
INSERT INTO `usuarios` (`username`, `password_hash`, `nombre`) VALUES
('admin', '$2y$10$K9W4r.2aA8zK5uH6hQ2S1.oT.2L6/S.q8u8V4W6z9y7eO.e1eK3K6', 'Administrador Librería')
ON DUPLICATE KEY UPDATE `username`=`username`;

-- --------------------------------------------------------
-- Seed de productos - LIBRERÍA
-- Los digitales (es_digital=1) tienen stock alto porque no se agotan.
-- 'archivo_digital' apunta a un PDF real dentro de /secure_files.
-- Se incluye 1 producto FÍSICO para mostrar la diferencia (descuenta stock).
-- --------------------------------------------------------
INSERT INTO `productos` (`nombre`, `descripcion`, `precio`, `categoria`, `imagen_url`, `stock`, `destacado`, `es_digital`, `archivo_digital`) VALUES
('Curso de PHP desde Cero (PDF)', 'Manual completo para aprender PHP y MySQL paso a paso, con ejercicios prácticos.', 8500.00, 'cursos', 'https://images.unsplash.com/photo-1517694712202-14dd9538aa97?w=500&q=80', 9999, 1, 1, 'curso_php.pdf'),
('Aprendé MySQL en 7 días (eBook)', 'Guía práctica de bases de datos relacionales, consultas SQL y modelado.', 6200.00, 'ebooks', 'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?w=500&q=80', 9999, 1, 1, 'mysql_7dias.pdf'),
('JavaScript Moderno (eBook)', 'ES6+, async/await, módulos y buenas prácticas para desarrollo web actual.', 6900.00, 'ebooks', 'https://images.unsplash.com/photo-1579468118864-1b9ea3c0db4a?w=500&q=80', 9999, 0, 1, 'js_moderno.pdf'),
('Patrones de Diseño de Software (PDF)', 'Los patrones GoF explicados con ejemplos claros y diagramas.', 7400.00, 'libros', 'https://images.unsplash.com/photo-1512820790803-83ca734da794?w=500&q=80', 9999, 1, 1, 'patrones_diseno.pdf'),
('Introducción a la Programación (PDF)', 'Fundamentos de lógica, algoritmos y estructuras de datos para principiantes.', 5300.00, 'libros', 'https://images.unsplash.com/photo-1532012197267-da84d127e765?w=500&q=80', 9999, 0, 1, 'intro_programacion.pdf'),
('Cuaderno Tapa Dura A5 (Físico)', 'Cuaderno de 200 hojas rayadas. Producto físico: se envía a domicilio.', 4200.00, 'fisicos', 'https://images.unsplash.com/photo-1531346878377-a5be20888e57?w=500&q=80', 25, 0, 0, NULL)
ON DUPLICATE KEY UPDATE `nombre`=`nombre`;
