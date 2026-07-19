# Hallazgos del diagnóstico de 19/07/2026

## Entorno

- WordPress 7.0.2, PHP 8.2.31 y MariaDB 10.11.14.
- HTTPS, sitio único, REST y contraseñas de aplicación disponibles.
- Sodium, cURL, DOM, XML, ZIP, Intl y MBString disponibles.
- Memoria WordPress 40 MB; tiempo máximo PHP 30 segundos.
- WP-Cron activo.
- Zona horaria WordPress configurada como `+00:00`, no `Europe/Madrid`.
- `WP_DEBUG_DISPLAY` activo pese a estar desactivado `WP_DEBUG`.

## Trámites

- Tabla `wp_tramites`: 171 registros.
- 26 permanentes y 145 con periodos.
- 145 destinos dentro de la web del Consejo.
- Nueve categorías efectivamente utilizadas.
- El plugin administrador conserva además la opción `Procedimientos Administrativos`, que no aparece en los datos actuales.

## Duplicados que no deben corregirse automáticamente

1. `Ayudas para movilidad Erasmus+` y `Programa Applied European Languages (AEL)` comparten la URL interna de Erasmus. El segundo caso parece sospechoso y requiere comprobación editorial.
2. `Matrícula: continuar estudios de grado` y `Matrícula: continuar estudios de máster` comparten la aplicación SIES. Puede ser legítimo, pero debe comprobarse que la página de destino diferencia correctamente ambas rutas.

CE-IA muestra ambos casos en `Sistema`; no decide por similitud.

## Plugin heredado

`Trámites UniOvi` 1.1.1:

- Crea y mantiene `wp_tramites` de forma no destructiva.
- Publica `[dynamic_table]` y `[listado_eventos_permanentes]`.
- Calcula correctamente el último día hasta las 23:59:59 y diferencia vigente, futuro y cerrado.
- No ofrece REST privada, fuentes, evidencias, propuestas, revisiones ni permisos separados.

Por eso CE-IA se implementa como compañero independiente.

## Página índice

La página `Trámites de la Universidad` (ID 7920) contiene `[dynamic_table]`. Su bloque de estilo define componentes visuales que el marcado no utiliza por completo. CE-IA no modifica esa página durante instalación o sincronización; cualquier rediseño debe tratarse como una propuesta editorial propia.

