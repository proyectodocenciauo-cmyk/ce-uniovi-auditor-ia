# Operación editorial

## Rutina normal

1. WP-Cron añade a la cola los elementos cuya revisión vence, si la programación está activada.
2. GitHub Actions se ejecuta dos veces al día y procesa hasta cinco.
3. `web.cest@uniovi.es` recibe un aviso cuando hay propuesta o fallo.
4. La persona revisora abre WordPress y comprueba fuentes, pasajes, hechos, conflictos, cambios y vista previa.
5. Si todo encaja, pulsa `Aprobar sin publicar` y deja una nota de revisión.
6. Una persona con capacidad de publicación pulsa después `Publicar versión aprobada`.

## Revisión humana mínima

- Abrir cada enlace oficial, no quedarse solo con el extracto.
- Verificar fecha de publicación y vigencia de la norma.
- Comprobar que el plazo corresponde al colectivo y modalidad correctos.
- Confirmar importes, precios, exenciones y efectos de pago.
- Confirmar el servicio que tramita y resuelve, teléfono y correo.
- Revisar el HTML a anchos móvil, tableta y escritorio.
- Confirmar que no se ha eliminado información vigente.
- Comprobar que los enlaces internos del Consejo existen y son pertinentes.

## Estados temporales y plazos

La página pública debe ser atemporal. No se publica «ahora está cerrado». Si no hay nueva convocatoria, se puede conservar el último plazo oficial publicado como referencia, claramente identificado y enlazado. El índice dinámico puede mostrar estados por fecha porque los calcula automáticamente.

## Conflicto

No se aprueba. La persona revisora identifica la fuente jerárquicamente superior o consulta al servicio competente. Después corrige el registro de fuentes o solicita una nueva investigación. No se edita la propuesta conflictiva para ocultar la discrepancia.

## Evidencia insuficiente

No se aprueba. Añade una fuente oficial específica, revisa el acceso al PDF o activa temporalmente Tavily para descubrir la resolución. Vuelve a poner el elemento en cola.

## Fallo técnico

Los fallos temporales se reintentan hasta tres veces. Si persisten:

1. Abre la ejecución de GitHub.
2. Comprueba que los tres secretos existen.
3. Ejecuta `ceia-worker status` desde Actions.
4. Comprueba el estado de Gemini y la cuota gratuita.
5. Revisa si la URL oficial redirige, exige sesión o ha desaparecido.

## Reversión

La propuesta publicada ofrece `Restaurar versión anterior`. Úsala solo si no hubo cambios posteriores. Si los hubo, CE-IA bloquea la reversión automática y se debe comparar manualmente con las revisiones de WordPress.

## Mantenimiento mensual

- Revisar consumo de GitHub Actions, Gemini y Tavily.
- Eliminar o corregir fuentes que fallen de forma persistente.
- Revisar duplicados de URL.
- Revisar elementos de riesgo y frecuencias.
- Comprobar que el correo de avisos funciona.
- Ejecutar el conjunto de pruebas tras cada actualización.

