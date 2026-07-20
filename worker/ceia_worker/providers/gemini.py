from __future__ import annotations

import time
import urllib.parse
from typing import Any

from pydantic import ValidationError

from ..http import NetworkError, json_request
from ..models import ModelProposal
from .base import ProviderError, ProviderTemporaryError


class GeminiProvider:
    name = "gemini-free"

    _OUTPUT_CONTRACT = """
Devuelve exclusivamente un objeto JSON completo, sin Markdown ni comentarios, con esta estructura:
{
  "change_required": boolean,
  "validation_status": "verified" | "verified_with_observations" | "human_review" | "conflict" | "insufficient_evidence",
  "risk": "low" | "medium" | "high" | "critical",
  "summary": string,
  "proposed_title": string,
  "proposed_content": string,
  "index_patch": {
    "nombre": string | null,
    "tipo": [string] | null,
    "url": string | null,
    "abierto_permanente": boolean | null,
    "fechas_adicionales": [{"fecha_inicio": "YYYY-MM-DD", "fecha_fin": "YYYY-MM-DD"}] | null
  },
  "changes": [{"section": string, "current": string, "proposed": string, "reason": string, "evidence_ids": [string]}],
  "facts": [{"fact_id": string, "fact_type": "deadline" | "amount" | "eligibility" | "legal_basis" | "procedure" | "competent_body" | "contact" | "definition" | "other", "claim": string, "value": string, "evidence_ids": [string], "confidence": number}],
  "conflicts": [{"topic": string, "statements": [string, string], "evidence_ids": [string, string], "recommended_resolution": string}],
  "citations": [string]
}
Usa cadenas vacías, listas vacías y un objeto index_patch vacío cuando no proceda completar esos campos. No añadas ninguna clave distinta.
""".strip()

    def __init__(self, api_key: str, model: str):
        if not api_key:
            raise ProviderError("Falta la clave gratuita de Gemini en WordPress")
        self.api_key = api_key
        self.model = model

    def _safe_message(self, value: Any) -> str:
        message = " ".join(str(value).split())
        if self.api_key:
            message = message.replace(self.api_key, "[clave oculta]")
        return (message or "error desconocido")[:800]

    def _endpoint(self) -> str:
        model = urllib.parse.quote(self.model.strip(), safe="-._")
        key = urllib.parse.quote(self.api_key, safe="")
        return (
            "https://generativelanguage.googleapis.com/v1beta/models/"
            f"{model}:generateContent?key={key}"
        )

    @staticmethod
    def _extract_text(data: dict[str, Any]) -> str:
        chunks: list[str] = []
        candidates = data.get("candidates")
        if isinstance(candidates, list):
            for candidate in candidates[:1]:
                if not isinstance(candidate, dict):
                    continue
                content = candidate.get("content")
                if not isinstance(content, dict):
                    continue
                parts = content.get("parts")
                if not isinstance(parts, list):
                    continue
                for part in parts:
                    if isinstance(part, dict) and isinstance(part.get("text"), str):
                        chunks.append(part["text"])
        return "".join(chunks).strip()

    def _generate(self, prompt: str) -> str:
        payload = {
            "contents": [
                {
                    "role": "user",
                    "parts": [{"text": prompt}],
                }
            ],
            "generationConfig": {
                "responseMimeType": "application/json",
            },
        }
        try:
            status, data = json_request(
                self._endpoint(),
                method="POST",
                payload=payload,
                timeout=180,
                attempts=2,
            )
        except NetworkError as exc:
            raise ProviderTemporaryError(
                f"No se pudo contactar con Gemini: {self._safe_message(exc)}"
            ) from exc

        error = data.get("error") if isinstance(data, dict) else None
        error_message = error.get("message") if isinstance(error, dict) else ""
        safe_error = self._safe_message(error_message or f"HTTP {status}")
        if status in (408, 429, 500, 502, 503, 504):
            raise ProviderTemporaryError(
                f"Gemini gratuito está temporalmente limitado: {safe_error}"
            )
        if status < 200 or status >= 300:
            raise ProviderError(f"Gemini rechazó la solicitud: {safe_error}")

        output = self._extract_text(data)
        if not output:
            finish_reason = ""
            candidates = data.get("candidates") if isinstance(data, dict) else None
            if isinstance(candidates, list) and candidates and isinstance(candidates[0], dict):
                finish_reason = str(candidates[0].get("finishReason", ""))
            raise ProviderError(
                "Gemini devolvió una respuesta vacía"
                + (f" ({self._safe_message(finish_reason)})" if finish_reason else "")
            )
        return output

    def analyze(self, prompt: str) -> ModelProposal:
        base_prompt = prompt.rstrip() + "\n\n" + self._OUTPUT_CONTRACT
        last_error: ValidationError | None = None

        for attempt in range(2):
            current_prompt = base_prompt
            if attempt and last_error is not None:
                details = self._safe_message(last_error)
                current_prompt += (
                    "\n\nLa respuesta anterior no cumplió el contrato. Corrígela y devuelve "
                    f"el objeto JSON completo. Error de validación: {details}"
                )
            output = self._generate(current_prompt)
            try:
                return ModelProposal.model_validate_json(output)
            except ValidationError as exc:
                last_error = exc
                if attempt == 0:
                    time.sleep(1)
                    continue

        raise ProviderError(
            "La respuesta JSON de Gemini no cumple el contrato: "
            + self._safe_message(last_error)
        ) from last_error
