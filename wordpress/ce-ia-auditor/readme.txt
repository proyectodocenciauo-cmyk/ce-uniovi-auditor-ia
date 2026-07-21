=== CE-IA Auditor de Trámites ===
Contributors: consejo-estudiantes-uniovi
Tags: tramites, universidad, auditoria, inteligencia-artificial, evidencias
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.12.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Auditor seguro con evidencias, controles deterministas, capturas responsive y aprobación humana para las páginas de trámites del Consejo de Estudiantes.

== Description ==

Complemento independiente de Trámites UniOvi. Sincroniza sus registros sin modificar el esquema heredado y ofrece fuentes, cola, evidencias, propuestas, control de calidad, publicación humana y reversión.

Solo audita páginas cuya URL comienza por https://www.unioviedo.es/cestudiantes/. Las fuentes de contraste se limitan a dominios de UniOvi, Unioviedo, BOE y Principado de Asturias.

La versión 0.12.0 no confía en la etiqueta de éxito generada por la IA. WordPress y el trabajador vuelven a comprobar de forma independiente:

* citas textuales de cada hecho;
* dos fuentes oficiales y una primaria para hechos críticos;
* relevancia de las fuentes y fallos de lectura separados;
* conservación mínima del 80 % del contenido;
* desaparición de temas críticos y enlaces internos;
* HTML, ids, encabezados, accesibilidad y enlaces;
* renderizado real a 360, 390, 768 y 1440 píxeles;
* conflictos sobre el mismo asunto;
* funcionamiento público de la página y del índice tras publicar.

No autopublica. Aprobar y publicar son acciones humanas separadas. Los casos bloqueados no pueden aprobarse y cualquier fallo posterior restaura los cambios aplicados.

La cola automática queda desactivada al actualizar. Solo debe reactivarse después de superar un conjunto de pilotos controlados.

== Installation ==

1. Realiza una copia de seguridad.
2. Instala el ZIP y reemplaza la versión anterior.
3. Comprueba CE-IA > Sistema: versión 0.12.0.
4. Mantén desactivada la cola automática.
5. Ejecuta un único piloto manual de riesgo bajo o medio.
6. Abre CE-IA > Calidad para revisar controles y capturas.
7. No actives lotes hasta completar la evaluación controlada.

== Changelog ==

= 0.12.0 =
* Sustituye la confianza declarada por el modelo por confianza técnica y jurídica calculada.
* Exige citas textuales verificables y doble evidencia oficial para hechos críticos.
* Distingue fuente irrelevante, HTTP, red y extracción fallida.
* Bloquea reescrituras que conserven menos del 80 % o eliminen contenido crítico.
* Añade comparación semántica, trazabilidad de enlaces y detección independiente de conflictos.
* Añade capturas responsive reales a cuatro anchuras mediante Chromium.
* Añade CE-IA > Calidad con controles, fuentes y capturas.
* Hace transaccional la publicación y verifica la página y el índice públicamente.
* Congela la ejecución automática y limita el piloto a un trámite.

= 0.11.0 =
* Añade CE-IA > Cola para cancelar trabajos pendientes sin borrar el historial.
* Restringe las páginas auditables al dominio y ruta del Consejo de Estudiantes.
* Cancela automáticamente trabajos en cola asociados a páginas fuera de alcance.

= 0.10.1 =
* Piloto inicial con almacenamiento independiente, REST privada, evidencias, propuestas, aprobación, publicación protegida y reversión.
