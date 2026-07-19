# Seguridad y privacidad

## Principios

- Menor privilegio: el trabajador no tiene capacidad de edición o publicación.
- Ningún secreto entra en el repositorio.
- Solo se investiga contenido público.
- Ningún cambio sustantivo se autopublica.
- Conflictos y pruebas insuficientes bloquean la aprobación.
- Cada decisión queda ligada a usuario, fecha y propuesta.

## Secretos

Gemini, Tavily y el token opcional de GitHub se cifran con `sodium_crypto_secretbox`; la clave deriva de las sales `AUTH` y `SECURE_AUTH` de WordPress. Cambiar esas sales invalida los secretos, que deberán introducirse otra vez.

Las claves se descifran únicamente en el endpoint privado del usuario `Trabajador CE-IA`, sobre HTTPS y autenticación de contraseña de aplicación. La interfaz solo muestra `Configurada` o `No configurada`.

## Protección web

- Solo HTTPS.
- Sin credenciales incrustadas en URL.
- DNS comprobado contra redes privadas, loopback, enlace local y rangos reservados.
- Redirecciones solo a hosts autorizados y, para API autenticadas, al mismo host.
- Tamaño, tiempo, número de fuentes y número de consultas limitados.
- HTML y PDF se tratan como datos; sus instrucciones se ignoran.

## HTML propuesto

Se bloquean `script`, `iframe`, formularios, controles, objetos, eventos `on*`, esquemas ejecutables, `@import`, selectores CSS globales, etiquetas de documento y `span`. Se exige una `section` raíz con id y CSS limitado a ella. El control se aplica en Python y de nuevo en WordPress.

## Integridad editorial

El hash de la versión de partida impide publicar sobre cambios posteriores. La versión anterior se guarda dentro de la propuesta y WordPress crea además su revisión normal. La reversión se bloquea si el contenido volvió a cambiar.

## Datos que no deben entrar

- Correos o buzones.
- Formularios y solicitudes estudiantiles.
- DNI, NIE, teléfonos personales o expedientes.
- Borradores internos y actas no públicas.
- Credenciales o tokens.

Si en el futuro se amplía el alcance, debe realizarse una evaluación específica de protección de datos antes de cambiar esta regla.

## Respuesta a incidente

1. Desactiva `CE-IA Auditor de Trámites`.
2. Revoca la contraseña de aplicación del usuario técnico.
3. Revoca Gemini, Tavily y el token de GitHub.
4. Revisa `CE-IA → Sistema → Registro de auditoría` y las ejecuciones de GitHub.
5. Restaura la última versión fiable desde la propuesta o revisiones de WordPress.
6. Rota las claves y vuelve a activar solo después de corregir la causa.

