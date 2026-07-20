## Plugin 0.11.0 / Worker 0.10.6
- Añade una pantalla de cola con cancelación individual y cancelación total, conservando el historial de auditoría.
- Restringe las páginas auditables a `https://www.unioviedo.es/cestudiantes/` y cancela trabajos pendientes fuera de ese alcance.
- Mantiene fuentes oficiales externas para contrastar información sin permitir que se modifiquen páginas externas.
- Identifica la etiqueta HTML exacta que vulnera la política de seguridad.
- Solicita una única reparación automática cuando Gemini genera controles interactivos como `<button>`.
- Devuelve el piloto a un único trabajo por ejecución una vez disponible la administración de cola.

## Worker 0.10.5
- Descarta en el primer intento los trabajos sin fuentes utilizables y permite limpiar una cola piloto antigua.

## Worker 0.10.4
- Sustituye el SDK por una llamada REST directa a `generateContent` con respuesta JSON validada localmente.

## 0.10.2
- Sustituye la API experimental `interactions.create` por la API estable `models.generate_content` de Gemini.
- Usa salida JSON estructurada mediante el modelo Pydantic `ModelProposal`.
- Mejora los mensajes de error HTTP sin exponer la clave de Gemini.
- Añade una prueba de regresión para impedir volver accidentalmente a la API experimental.

## 0.10.1
- Edición de coste cero: elimina por completo el proveedor OpenAI del plugin y del trabajador.
- Borra de la configuración cualquier clave OpenAI heredada al actualizar.
- Fuerza Gemini 3.1 Flash-Lite y desactiva la cola automática durante la migración.
- Añade una prueba que impide reintroducir el endpoint o la clave OpenAI.

# Changelog

## 0.10.0
- Añade OpenAI como proveedor seleccionable con salidas JSON estructuradas.
- Cifra la clave OpenAI en WordPress y nunca la almacena en GitHub.
- Mantiene Gemini como alternativa gratuita.
- Añade validación obligatoria de clave al cambiar de proveedor.

## 0.9.0
- Primera versión del piloto seguro.
