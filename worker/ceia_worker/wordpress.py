from __future__ import annotations

from typing import Any

from .config import WordPressCredentials
from .http import NetworkError, basic_auth_header, json_request
from .models import RemoteConfig


class WordPressAPIError(RuntimeError):
    pass


class WordPressClient:
    def __init__(self, credentials: WordPressCredentials):
        self.credentials = credentials
        self.headers = {
            "Authorization": basic_auth_header(credentials.username, credentials.application_password)
        }
        self.api_base = self._discover_api_base()

    def _discover_api_base(self) -> str:
        base = self.credentials.site_url.rstrip("/")
        if "/wp-json" in base:
            candidates = [base.rstrip("/") + "/ceia/v1"]
        else:
            candidates = [
                base + "/index.php/wp-json/ceia/v1",
                base + "/wp-json/ceia/v1",
            ]

        errors: list[str] = []
        for candidate in candidates:
            try:
                status, data = json_request(candidate + "/health", headers=self.headers, attempts=1)
            except NetworkError as exc:
                errors.append(str(exc))
                continue
            if status == 200 and data.get("ok"):
                return candidate
            errors.append(f"HTTP {status}")

        raise WordPressAPIError(
            "No se pudo conectar con la REST privada de CE-IA. Comprueba la URL, el usuario técnico y su contraseña de aplicación. "
            + "; ".join(errors[:2])
        )

    def health(self) -> dict[str, Any]:
        return self._request("GET", "/health")

    def config(self) -> RemoteConfig:
        return RemoteConfig.model_validate(self._request("GET", "/worker/config"))

    def heartbeat(self, version: str, provider: str, message: str = "ready") -> dict[str, Any]:
        return self._request(
            "POST",
            "/worker/heartbeat",
            {
                "worker_id": self.credentials.worker_id,
                "version": version,
                "provider": provider,
                "message": message,
            },
        )

    def claim(self) -> dict[str, Any] | None:
        response = self._request(
            "POST", "/jobs/claim", {"worker_id": self.credentials.worker_id}
        )
        return response.get("job")

    def submit_result(self, job_id: str, payload: dict[str, Any]) -> dict[str, Any]:
        return self._request("POST", f"/jobs/{job_id}/result", payload)

    def fail(self, job_id: str, code: str, message: str) -> dict[str, Any]:
        return self._request(
            "POST",
            f"/jobs/{job_id}/fail",
            {"code": code, "message": message[:5000]},
        )

    def _request(
        self, method: str, path: str, payload: dict[str, Any] | None = None
    ) -> dict[str, Any]:
        try:
            status, data = json_request(
                self.api_base + path,
                method=method,
                payload=payload,
                headers=self.headers,
                timeout=40,
            )
        except NetworkError as exc:
            raise WordPressAPIError(str(exc)) from exc

        if status < 200 or status >= 300:
            message = data.get("message") or data.get("error", {}).get("message") or f"HTTP {status}"
            raise WordPressAPIError(f"WordPress rechazó la operación: {message}")
        return data

