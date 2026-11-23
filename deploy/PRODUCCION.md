# Montar producción: lista de pasos

Lo que queda por hacer el día de poner la app en el servidor. En orden: cada
paso cuenta con que los anteriores ya están hechos.

## 1. Imagen de producción

- Partir de `php:8.4-fpm` (no de `php:8.4-cli`, que es solo para desarrollo).
- Instalar las extensiones `pdo_pgsql`, **`intl`** (hoy no está en el
  `Dockerfile`) y `opcache`.
- Instalar `ffmpeg` (recortar los vídeos de los platos) y `poppler-utils`
  (cartas en PDF).
- `composer install --no-dev --optimize-autoloader`.
- Fijar la versión de composer (hoy el `Dockerfile` usa `composer:latest`).

## 2. Límites de subida

- Copiar `docker/php/uploads.ini` (64 MB por fichero, 66 MB por petición) y
  `docker/php/memory.ini` a la imagen.
- En nginx: `client_max_body_size 66m;`. Si no, nginx corta los vídeos antes
  de que lleguen a PHP.

## 3. Volúmenes que no se pueden perder

- `public/uploads` (fotos, logos, vídeos).
- Los datos de Postgres.
- `var/log`.
- Las sesiones, si se guardan en disco (paso 6).

## 4. Configuración y secretos

- `APP_ENV=prod` y un `APP_SECRET` nuevo, que no sea el de desarrollo.
- Todas las variables de `.env.example` con sus valores de producción.
- `DEFAULT_URI` = la URL pública con https y sin barra final
  (`https://tudominio.com`). Es la dirección de los enlaces de los emails
  que salen del cron (`app:billing:send-notices`). Si está vacía o es
  localhost, ese comando no envía nada y lo apunta en `cron.log`.
- `composer dump-env prod`, que genera `.env.local.php`. Así la importación
  de cartas en segundo plano lee la misma configuración que la web, aunque
  php-fpm borre las variables de entorno (`clear_env=yes`).

## 5. Proxies de confianza

- `framework.trusted_proxies` y `trusted_headers` (`X-Forwarded-For`,
  `-Proto`, `-Host`). Hacen falta porque nginx o el balanceador van delante.
  Sin esto la app cree que va por http, y se rompen las URLs de Stripe y de
  los emails.
- Esto solo arregla las URLs de las peticiones web. Las del cron no tienen
  petición y salen de `DEFAULT_URI` (sección 4).

## 6. Sesiones fuera de /tmp

- Hoy las sesiones van al sitio por defecto de PHP (`/tmp` del contenedor):
  cada despliegue cerraría la sesión de todo el mundo.
- Guardarlas en Postgres (`PdoSessionHandler`) o en un volumen.

## 7. Logs

- Los warnings de la IA (camarero, importar carta) ya salen en producción por
  `php://stderr` con el handler `ai_warnings` de `config/packages/monolog.yaml`.
  Comprobar que se recogen (docker logs o lo que use el servidor).
- logrotate para `/var/log/qrmenu/cron.log` y cualquier otro fichero de log.

## 8. En cada despliegue

```sh
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console asset-map:compile --env=prod
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

## 9. Comprobaciones de una sola vez, tras el primer despliegue

- `php bin/console app:stripe:check-prices`: los precios de Stripe frente a
  los de la app.
- `php bin/console app:tags:resync-preset-translations`

## 10. Tareas programadas

- Instalar `deploy/crontab` (revendedores a las 04:00, tipos de cambio a las
  05:30, hora de Madrid). La traducción de ingredientes va **comentada**: está
  pausada.
- Al día siguiente, revisar `/var/log/qrmenu/cron.log`.

## 11. Copias de seguridad

- `pg_dump` diario y copia de `public/uploads`, las dos fuera del servidor.
- Probar a restaurar una copia al menos una vez.

## 12. Prueba final sin navegador

- Con una cuenta de prueba, subir una carta por `curl` y comprobar que pasa de
  "subida" a terminada (que la importación en segundo plano arranca con
  php-fpm).

---

## Antes de publicar

- **Stripe en España:** crear los 6 precios con "IVA incluido"
  (`tax_behavior` = `inclusive`) y poner sus IDs en las variables
  `STRIPE_PRICE_*`.
- **Stripe:** activar el email de "pago fallido" al cliente.
- **Legal:** rellenar `config/legal.yaml` (empresa, datos fiscales…).
- **IA:** pasar Gemini y Groq a claves **de pago** (las gratuitas tienen cupo
  diario).
- **Crontab:** instalarlo y revisar `cron.log` al día siguiente.
