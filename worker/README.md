# CE-IA Worker

Trabajador externo del plugin **CE-IA Auditor de Trámites**. Recupera únicamente contenido publicado y fuentes públicas, contrasta evidencias y entrega una propuesta a WordPress. No dispone de permiso para publicar.

La configuración operativa y las claves de investigación se administran cifradas en WordPress. En GitHub solo se guardan tres secretos: URL, usuario técnico y contraseña de aplicación de WordPress.

```bash
export CEIA_WP_URL="https://www.unioviedo.es/cestudiantes/"
export CEIA_WP_USER="ceia-worker"
export CEIA_WP_APP_PASSWORD="xxxx xxxx xxxx xxxx xxxx xxxx"
ceia-worker run
```

