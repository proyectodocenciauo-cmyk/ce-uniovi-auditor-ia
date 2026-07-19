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
