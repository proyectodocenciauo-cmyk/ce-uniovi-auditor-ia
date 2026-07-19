# Instalación completa y gratuita

## 1. Preparar WordPress

1. Haz una copia de seguridad de archivos y base de datos.
2. En `Ajustes → Generales`, cambia la zona horaria de `UTC+0` a `Europe/Madrid`. Es importante para el último día de cada plazo.
3. Mantén activos `Trámites UniOvi` y sus shortcodes.
4. Sube `CE-IA-Auditor-0.10.1.zip` en `Plugins → Añadir plugin → Subir plugin` y actívalo.
5. Abre `CE-IA → Sistema`. Deben aparecer PHP, WordPress, Sodium, REST y la tabla de duplicados.
6. Abre `CE-IA → Elementos` y pulsa `Sincronizar los 171 trámites` si el inventario no se creó durante la activación.

La activación crea tablas propias. No edita páginas ni registros del índice.

## 2. Elegir proveedor de análisis

En `CE-IA → Configuración` puedes elegir:

- **Gemini**: alternativa gratuita; crea una clave en Google AI Studio, guárdala en «Clave Gemini» y selecciona Gemini Flash-Lite.

El sistema solo envía páginas ya publicadas y fuentes públicas. No deben enviarse datos personales, solicitudes, correos, borradores privados ni expedientes. La publicación siempre requiere aprobación humana.

## 3. Tavily opcional

Tavily amplía el descubrimiento, pero no es imprescindible.

1. Crea una clave gratuita en [Tavily](https://app.tavily.com/).
2. Desactiva cualquier modalidad de pago por uso en su panel.
3. Guarda la clave en WordPress.
4. Activa la casilla Tavily solo después del primer piloto.

Cada búsqueda usa profundidad `basic`. El límite predeterminado es dos búsquedas por trámite.

## 4. Crear el usuario técnico de WordPress

1. En `Usuarios → Añadir nuevo`, crea un usuario distinto de cualquier administrador, por ejemplo `ceia-worker`.
2. Asígnale el rol `Trabajador CE-IA`.
3. No le asignes rol de editor ni administrador.
4. Abre el perfil de ese usuario y crea una contraseña de aplicación llamada `GitHub CE-IA`.
5. Copia el valor una sola vez. No uses ni compartas la contraseña normal del usuario.

El rol técnico puede leer la configuración privada, reclamar trabajos y devolver investigaciones. No puede editar, aprobar ni publicar.

## 5. Configurar GitHub

En el repositorio `proyectodocenciauo-cmyk/ce-uniovi-auditor-ia` abre `Settings → Secrets and variables → Actions` y crea:

| Secreto | Valor |
|---|---|
| `CEIA_WP_URL` | `https://www.unioviedo.es/cestudiantes/` |
| `CEIA_WP_USER` | Nombre del usuario técnico |
| `CEIA_WP_APP_PASSWORD` | Contraseña de aplicación copiada |

No añadas la clave Gemini o Tavily a GitHub: se administran cifradas desde WordPress.

Habilita GitHub Actions. Ejecuta primero `Tests`, después `CE-IA · Auditoría gratuita` manualmente. El comando `status` debe reconocer la versión del plugin.

### Botón «ejecutar ahora» opcional

Sin token, WordPress deja trabajos en cola y el horario de GitHub los procesa. Para despertar el flujo inmediatamente:

1. Crea un fine-grained personal access token limitado exclusivamente a este repositorio.
2. Concede `Actions: write` y `Metadata: read`; no concedas contenido, administración ni secretos.
3. Pégalo en `CE-IA → Configuración → Token de GitHub`.

El token queda cifrado en WordPress. Si no quieres mantenerlo, déjalo vacío: la automatización programada seguirá funcionando.

## 6. Garantizar coste cero

- Usa repositorio público o vigila la cuota incluida del repositorio privado.
- En GitHub configura un presupuesto de Actions de 0 € y sin ampliación automática.
- No habilites facturación en Google AI Studio.
- Mantén los límites predeterminados: 5 trabajos por ejecución, 12 fuentes y 2 búsquedas.
- La edición 0.10.1 es de coste cero: solo admite Gemini con un proyecto sin facturación y elimina cualquier clave OpenAI heredada.

## 7. Piloto obligatorio

No actives todavía la cola automática. Ejecuta manualmente tres casos:

1. Una página estable y sencilla.
2. Una ayuda o beca con plazos/importes.
3. Un trámite normativo de riesgo alto.

Para cada caso comprueba evidencia, enlaces, hechos, conflictos, HTML responsive y parche del índice. Aprueba, publica y prueba la reversión en una página de bajo riesgo. Solo después activa la programación.

## 8. Limpieza

Tras validar CE-IA, desactiva y elimina `CE-IA Diagnóstico Seguro`; ya no es necesario. Conserva `Trámites UniOvi`.

