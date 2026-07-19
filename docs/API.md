# Contrato REST privado

Base en este sitio: `/index.php/wp-json/ceia/v1`.

Todos los endpoints exigen autenticación WordPress y la capacidad `ceia_submit_research`.

| Método | Ruta | Función |
|---|---|---|
| GET | `/health` | Compatibilidad y tamaño de cola |
| GET | `/worker/config` | Límites, política y claves descifradas para el trabajador |
| POST | `/jobs/claim` | Reclamar atómicamente el siguiente trabajo |
| POST | `/jobs/{uuid}/result` | Entregar evidencia y propuesta tipada |
| POST | `/jobs/{uuid}/fail` | Devolver fallo acotado |
| POST | `/worker/heartbeat` | Señal de estado sin secretos |

## Restricción decisiva

No existe endpoint de aprobación, publicación o reversión. Esas acciones solo se ejecutan en WordPress por usuarios con capacidades específicas y nonce de administración.

## Resultado

Campos principales:

- `change_required`
- `validation_status`
- `risk`
- `summary`
- `proposed_title`
- `proposed_content`
- `index_patch`
- `changes[]`
- `facts[]` con `evidence_ids[]`
- `conflicts[]`
- `citations[]`
- `evidence[]` con URL, hash, extracto, autoridad y fecha de recuperación

WordPress vuelve a validar tamaño, URL, HTML y estado del trabajo. Ignora cualquier petición del trabajador de aprobar o publicar.

