from __future__ import annotations

import time

from pydantic import ValidationError

from ..models import ModelProposal
from .base import ProviderError, ProviderTemporaryError


class GeminiProvider:
    name = "gemini-free"

    def __init__(self, api_key: str, model: str):
        if not api_key:
            raise ProviderError("Falta la clave gratuita de Gemini en WordPress")
        self.api_key = api_key
        self.model = model

    def _safe_error_message(self, exc: Exception) -> str:
        message = " ".join(str(exc).split())
        if self.api_key:
            message = message.replace(self.api_key, "[clave oculta]")
        return (message or type(exc).__name__)[:500]

    def analyze(self, prompt: str) -> ModelProposal:
        try:
            from google import genai
            from google.genai import types
        except ImportError as exc:
            raise ProviderError("No está instalado el paquete oficial google-genai") from exc

        client = genai.Client(api_key=self.api_key)
        last_error: Exception | None = None
        try:
            for attempt in range(2):
                try:
                    response = client.models.generate_content(
                        model=self.model,
                        contents=prompt,
                        config=types.GenerateContentConfig(
                            response_mime_type="application/json",
                            response_schema=ModelProposal,
                        ),
                    )

                    parsed = getattr(response, "parsed", None)
                    if isinstance(parsed, ModelProposal):
                        return parsed
                    if parsed is not None:
                        return ModelProposal.model_validate(parsed)

                    output = getattr(response, "text", None)
                    if not output:
                        raise ProviderError("Gemini devolvió una respuesta vacía")
                    return ModelProposal.model_validate_json(output)
                except ValidationError as exc:
                    last_error = exc
                    if attempt == 0:
                        time.sleep(1)
                        continue
                except ProviderError:
                    raise
                except Exception as exc:  # El SDK usa excepciones HTTP propias según la versión.
                    message = self._safe_error_message(exc)
                    lowered = message.lower()
                    if any(marker in lowered for marker in ("429", "quota", "rate limit", "503", "temporar")):
                        raise ProviderTemporaryError("Gemini gratuito está temporalmente limitado") from exc
                    if any(marker in lowered for marker in ("400", "bad request", "invalid argument")):
                        raise ProviderError(f"Gemini rechazó la solicitud estructurada: {message}") from exc
                    raise ProviderError(f"Gemini no pudo generar una propuesta estructurada: {message}") from exc
        finally:
            client.close()

        raise ProviderError("La respuesta de Gemini no cumple el contrato JSON") from last_error
