=== CE-IA Auditor de Trámites ===
Contributors: consejo-estudiantes-uniovi
Tags: tramites, universidad, auditoria, inteligencia-artificial, evidencias
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.11.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sistema de investigación asistida, evidencias y aprobación humana para las páginas de trámites del Consejo de Estudiantes.

== Description ==

Complemento independiente de Trámites UniOvi. Sincroniza sus registros sin modificar el esquema heredado y ofrece fuentes, cola, evidencias, propuestas, validación, publicación humana y reversión.

Solo audita páginas cuya URL comienza por https://www.unioviedo.es/cestudiantes/. Las fuentes oficiales externas pueden utilizarse para contrastar información, pero nunca amplían el conjunto de páginas que se pueden modificar.

No autopublica. El trabajador externo solo puede devolver investigaciones mediante REST autenticada.

== Installation ==

1. Realiza una copia de seguridad.
2. Instala y activa el ZIP.
3. Abre CE-IA > Sistema.
4. Completa CE-IA > Configuración.
5. Crea un usuario con rol Trabajador CE-IA y una contraseña de aplicación.
6. Configura GitHub Actions según la guía incluida en el repositorio.
7. Ejecuta un piloto manual antes de habilitar la programación.

== Changelog ==

= 0.11.0 =
* Añade CE-IA > Cola para cancelar trabajos pendientes sin borrar el historial.
* Restringe las páginas auditables al dominio y ruta del Consejo de Estudiantes.
* Cancela automáticamente trabajos en cola asociados a páginas fuera de alcance.
* Mejora el diagnóstico de etiquetas HTML no permitidas.

= 0.10.1 =
* Piloto completo con almacenamiento independiente, REST privada, evidencias, propuestas, aprobación, publicación protegida y reversión.
