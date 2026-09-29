# 📚 Librería Online — Desafío Equipo 9

**Desafío:** cuando el Webhook de Mercado Pago confirma un pago `approved`, el sistema debe
**enviar automáticamente por email el PDF/libro digital comprado** y **habilitar una URL de
descarga temporal segura**.

Este documento explica todo lo que se agregó sobre el proyecto base para resolverlo, cómo
instalarlo y cómo probarlo. Está pensado para que puedas **entenderlo y defenderlo** en clase.

---

## 1. Cómo funciona (flujo completo)

1. El cliente arma su carrito y **escribe su email** en el carrito (nuevo campo obligatorio).
2. Al pagar, `create_preference.php` guarda la orden en estado `pending` **con el email**.
3. El cliente paga en Mercado Pago.
4. Mercado Pago llama a `webhook.php` cuando el pago cambia de estado.
5. Si el pago quedó **`approved`**, el webhook, por cada ítem de la orden:
   - Si el producto es **digital** → genera un **token único** (256 bits), lo guarda en la
     tabla `descargas` con una **fecha de vencimiento** y arma el link seguro
     `…/public/download.php?token=XXXX`. **No descuenta stock** (un ebook no se agota).
   - Si el producto es **físico** → descuenta stock como siempre.
6. El webhook **envía el email** al cliente con los links (PHPMailer), y además **deja los links
   registrados en `api/webhook.log`** como respaldo.
7. Cuando el cliente abre el link, `download.php` valida token + vencimiento + tope de descargas
   y recién ahí entrega el PDF desde la carpeta protegida `secure_files/`.

---

## 2. Cambios en la base de datos (`db/schema.sql`)

| Tabla | Cambio | Para qué |
|---|---|---|
| `productos` | + `es_digital` (0/1) | Distinguir libro digital de producto físico |
| `productos` | + `archivo_digital` | Nombre del PDF dentro de `secure_files/` |
| `ordenes` | + `email_cliente` | Email a donde se envía la descarga |
| **`descargas`** (NUEVA) | `token`, `expira_en`, `descargas_realizadas`, `max_descargas` | Guarda cada link temporal seguro |

Los productos de ejemplo ahora son libros/cursos. Se incluye **1 producto físico** a propósito,
para demostrar que el sistema trata distinto lo digital de lo físico.

---

## 3. Archivos que se agregaron / modificaron

**Nuevos:**
- `public/download.php` — entrega segura del PDF validando el token.
- `config/mailer.php` — envío del email con PHPMailer.
- `secure_files/` — carpeta con los PDFs reales + `.htaccess` que **bloquea el acceso directo**.
- `composer.json` — dependencias (`phpdotenv` + `phpmailer`).

**Modificados:**
- `db/schema.sql` — columnas nuevas, tabla `descargas`, productos de librería.
- `env.php` / `env.txt` — configuración SMTP y de expiración de los links.
- `api/create_preference.php` — pide y guarda el `email` del cliente.
- `api/webhook.php` — **núcleo**: genera links, guarda `descargas`, manda el email.
- `api/get_products.php` — expone `es_digital`.
- `public/js/api.js` — envía el email al backend.
- `public/js/app.js` — valida el email antes de pagar + muestra "Descarga digital".
- `index.php` — campo de email en el carrito + marca "Librería".

---

## 4. Instalación (pasos del profe, adaptados)

1. Copiar el proyecto a `C:\Xampp\htdocs\2026\Antigravity` (o donde lo tengas).
2. **Composer** (instala phpdotenv y phpmailer de una):
   ```
   composer install
   ```
   (o, si preferís: `composer require vlucas/phpdotenv phpmailer/phpmailer`)
3. **Importar la base**: en phpMyAdmin importá `db/schema.sql`
   (si lo hacés por consola, usá `--default-character-set=utf8mb4` para que los acentos entren bien).
4. **Renombrar** `env.txt` → `.env` y completar:
   - `MP_ACCESS_TOKEN` y `MP_PUBLIC_KEY` (credenciales de Mercado Pago).
   - `BASE_URL` (ej. `http://localhost/2026/Antigravity`).
   - **SMTP** para el email (ver punto 5).
5. Colocar tus PDFs reales en `secure_files/` y, en la tabla `productos`, poner el nombre del
   archivo en la columna `archivo_digital` y `es_digital = 1`.
6. Probar:
   - Cliente: `http://localhost/2026/Antigravity/index.php`
   - Admin: `http://localhost/2026/Antigravity/admin/index.php` — **admin / admin123**

---

## 5. Configurar el email (Gmail)

En `.env`:
```
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=tucorreo@gmail.com
SMTP_PASS=xxxxxxxxxxxxxxxx   ← Contraseña de aplicación (NO la de tu cuenta)
SMTP_FROM=tucorreo@gmail.com
SMTP_FROM_NAME=Librería Online
```
Para obtener la **contraseña de aplicación**: en tu cuenta de Google activá la
*Verificación en 2 pasos* → *Contraseñas de aplicaciones* → generá una de 16 caracteres.

> Si no configurás SMTP, el sistema **igual funciona**: el webhook deja los links de descarga
> en `api/webhook.log`, así podés mostrar la entrega en la defensa.

---

## 6. Cómo probar el Webhook (¡importante!)

Mercado Pago necesita una **URL pública HTTPS** para llamar a tu `webhook.php`. En `localhost`
puro, MP no puede avisarte. Dos opciones:

**A) Prueba real de punta a punta (recomendada):** exponé tu XAMPP con un túnel:
```
ngrok http 80
```
Copiá la URL `https://xxxx.ngrok-free.app/2026/Antigravity` en `BASE_URL` del `.env`.
Ahora, al pagar en el sandbox de Mercado Pago, MP llamará a tu webhook y llegará el email. ✅

**B) Ver la entrega sin túnel:** después de un pago aprobado, mirá `api/webhook.log`; ahí quedan
los links `download.php?token=...`. Abrí uno en el navegador y se descarga el PDF.

**Probar solo la descarga segura** (sin pagar): tomá un `token` de la tabla `descargas` y entrá a
`http://localhost/2026/Antigravity/public/download.php?token=EL_TOKEN`.

---

## 7. Seguridad del link (para defender)

- El **token** es aleatorio de 256 bits (`random_bytes(32)`): imposible de adivinar.
- Tiene **vencimiento** (`DOWNLOAD_EXPIRY_HOURS`, por defecto 72 h).
- Tiene **tope de descargas** (`DOWNLOAD_MAX`, por defecto 5).
- El PDF vive en `secure_files/`, **bloqueada por `.htaccess`**: no se puede bajar por URL directa,
  solo a través de `download.php` tras validar el token.
- `download.php` usa `basename()` sobre el nombre del archivo para evitar *path traversal*.

Respuestas del endpoint según el caso: `200` (ok, entrega el PDF), `400` (token mal formado),
`404` (no existe), `410` (vencido), `429` (superó el tope de descargas).

---

## 8. Posibles mejoras (bonus)

- Agregar en el panel Admin un botón para **subir el PDF** y marcar `es_digital` desde la web.
- Una página "Mis descargas" donde el cliente reingrese su email y recupere sus links vigentes.
- Registrar en `descargas` la IP/fecha de cada descarga para auditoría.
