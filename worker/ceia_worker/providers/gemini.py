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

    def analyze(self, prompt: str) -> ModelProposal:
        try:
            from google import genai
        except ImportError as exc:
            raise ProviderError("No está instalado el paquete oficial google-genai") from exc

        client = genai.Client(api_key=self.api_key)
        last_error: Exception | None = None
        try:
            for attempt in range(2):
                try:
                    interaction = client.interactions.create(
                        model=self.model,
                        input=prompt,
                        store=False,
                        response_format={
                            "type": "text",
                            "mime_type": "application/json",
                            "schema": ModelProposal.model_json_schema(),
                        },
                    )
                    output = interaction.output_text
                    if not output:
                        raise ProviderError("Gemini devolvió una respuesta vacía")
                    return ModelProposal.model_validate_json(output)
                except ValidationError as exc:
                    last_error = exc
                    if attempt == 0:
                        time.sleep(1)
                        continue
                except Exception as exc:  # El SDK usa excepciones HTTP propias según la versión.
                    message = str(exc)
                    if any(marker in message.lower() for marker in ("429", "quota", "rate limit", "503", "temporar")):
                        raise ProviderTemporaryError("Gemini gratuito está temporalmente limitado") from exc
                    raise ProviderError("Gemini no pudo generar una propuesta estructurada") from exc
        finally:
            client.close()

        raise ProviderError("La respuesta de Gemini no cumple el contrato JSON") from last_error
