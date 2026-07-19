# Arquitectura y decisiones

## Dos piezas, una sola gobernanza

La investigación de varias webs y PDF puede consumir bastante memoria y tiempo. El alojamiento diagnosticado limita WordPress a 40 MB y 30 segundos, por lo que el trabajo pesado se ejecuta en GitHub Actions. WordPress conserva inventario, configuración, claves cifradas, cola, evidencias, propuestas y decisiones.

El plugin es un acompañante de `Trámites UniOvi`, no una actualización destructiva. Se puede desactivar sin alterar `wp_tramites`, `[dynamic_table]` ni `[listado_eventos_permanentes]`.

## Tablas propias

| Tabla | Función |
|---|---|
| `wp_ceia_items` | Mapa de trámites, páginas, riesgo y calendario de revisión |
| `wp_ceia_sources` | Registro global y específico de fuentes |
| `wp_ceia_jobs` | Cola, intentos y errores |
| `wp_ceia_evidence` | URL, hash, pasaje, fecha y autoridad de cada evidencia |
| `wp_ceia_proposals` | Antes, después, hechos, conflictos, decisión y publicación |
| `wp_ceia_logs` | Registro inmutable de acciones operativas |

No hay claves foráneas para mantener compatibilidad con instalaciones WordPress heterogéneas. Las referencias se validan en la capa de aplicación.

## Estados

### Trabajo

`queued → running → completed` o `failed`. Un trabajo temporalmente bloqueado vuelve a `queued` hasta un máximo de tres intentos. Un bloqueo de más de dos horas se considera abandonado y puede reclamarse de nuevo.

### Propuesta

`review_required → approved → published → rolled_back`.

También existen `rejected` y `no_change`. No hay transición automática a `approved` ni `published`.

### Validación

- `verified`: fuentes y reglas suficientes.
- `verified_with_observations`: utilizable, pero con advertencias visibles.
- `human_review`: la IA no puede cerrar por sí sola una cuestión de criterio.
- `conflict`: fuentes incompatibles; bloquea aprobación.
- `insufficient_evidence`: faltan pruebas; bloquea aprobación.

## Control de concurrencia

La propuesta conserva un hash del contenido y del registro de índice existentes al finalizar la investigación. Antes de publicar se recalcula. Si cambió cualquier parte, la propuesta se considera caducada y queda bloqueada. La reversión aplica la misma protección frente a cambios posteriores.

## Autoridad de fuentes

| Nivel | Tipo | Uso |
|---:|---|---|
| 100 | BOE, BOPA u otro boletín oficial | Norma y acto publicado |
| 95 | Sede o registro oficial | Convocatoria, resolución, procedimiento |
| 85 | Portal institucional | Aplicación práctica, unidad y contacto |
| 75 | Web del Consejo | Explicación, navegación interna y contexto |
| 25 | Pista externa | Solo descubrimiento; nunca prueba crítica única |

## Hechos críticos

Plazos, importes, requisitos, base jurídica, procedimiento, órgano competente y contacto se vinculan a identificadores `E###`. El validador exige al menos una fuente oficial; para plazos, importes, requisitos y procedimiento avisa si solo existe una fuente independiente. La base jurídica se observa si no se ha cotejado con un boletín oficial.

