from __future__ import annotations

import base64
import ipaddress
import json
import socket
import ssl
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any, Callable


class NetworkError(RuntimeError):
    pass


def _is_public_address(address: str) -> bool:
    ip = ipaddress.ip_address(address)
    return not (
        ip.is_private
        or ip.is_loopback
        or ip.is_link_local
        or ip.is_multicast
        or ip.is_reserved
        or ip.is_unspecified
    )


def validate_public_https_url(url: str, allowed_hosts: set[str] | None = None) -> str:
    parsed = urllib.parse.urlsplit(url.strip())
    if parsed.scheme.lower() != "https" or not parsed.hostname:
        raise NetworkError("Solo se permiten URL HTTPS absolutas")
    if parsed.username or parsed.password:
        raise NetworkError("No se permiten credenciales dentro de una URL")
    if parsed.port not in (None, 443):
        raise NetworkError("No se permiten puertos no estándar")

    host = parsed.hostname.lower().rstrip(".")
    if allowed_hosts and not any(host == allowed or host.endswith("." + allowed) for allowed in allowed_hosts):
        raise NetworkError(f"El host {host} no está autorizado")

    try:
        addresses = {entry[4][0] for entry in socket.getaddrinfo(host, 443, type=socket.SOCK_STREAM)}
    except socket.gaierror as exc:
        raise NetworkError(f"No se pudo resolver el host {host}") from exc
    if not addresses or not all(_is_public_address(address) for address in addresses):
        raise NetworkError("La URL resuelve a una red privada o reservada")

    return urllib.parse.urlunsplit(("https", parsed.netloc, parsed.path or "/", parsed.query, ""))


class SafeRedirectHandler(urllib.request.HTTPRedirectHandler):
    def __init__(self, validator: Callable[[str], str]):
        super().__init__()
        self.validator = validator

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: ANN001
        safe_url = self.validator(urllib.parse.urljoin(req.full_url, newurl))
        return super().redirect_request(req, fp, code, msg, headers, safe_url)


class SameHostRedirectHandler(urllib.request.HTTPRedirectHandler):
    def __init__(self, original_host: str):
        super().__init__()
        self.original_host = original_host.lower()

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: ANN001
        target = urllib.parse.urljoin(req.full_url, newurl)
        host = (urllib.parse.urlsplit(target).hostname or "").lower()
        if host != self.original_host:
            raise NetworkError("Se ha bloqueado una redirección a otro dominio")
        return super().redirect_request(req, fp, code, msg, headers, target)


@dataclass
class HttpResponse:
    url: str
    status: int
    headers: dict[str, str]
    body: bytes

    @property
    def content_type(self) -> str:
        return self.headers.get("content-type", "").split(";", 1)[0].strip().lower()

    def text(self) -> str:
        content_type = self.headers.get("content-type", "")
        charset = "utf-8"
        for part in content_type.split(";")[1:]:
            if "charset=" in part.lower():
                charset = part.split("=", 1)[1].strip().strip('"')
                break
        try:
            return self.body.decode(charset, errors="replace")
        except LookupError:
            return self.body.decode("utf-8", errors="replace")


class SafeFetcher:
    def __init__(self, allowed_hosts: set[str], max_bytes: int, timeout: int = 25):
        self.allowed_hosts = {host.lower().rstrip(".") for host in allowed_hosts if host}
        self.max_bytes = max_bytes
        self.timeout = timeout
        validator = lambda url: validate_public_https_url(url, self.allowed_hosts)  # noqa: E731
        self.opener = urllib.request.build_opener(
            SafeRedirectHandler(validator),
            urllib.request.HTTPSHandler(context=ssl.create_default_context()),
        )

    def fetch(self, url: str) -> HttpResponse:
        safe_url = validate_public_https_url(url, self.allowed_hosts)
        request = urllib.request.Request(
            safe_url,
            headers={
                "User-Agent": "CE-IA-Auditor/0.9 (+https://www.unioviedo.es/cestudiantes/)",
                "Accept": "text/html,application/xhtml+xml,application/pdf,text/plain;q=0.9,*/*;q=0.2",
            },
            method="GET",
        )
        try:
            with self.opener.open(request, timeout=self.timeout) as response:
                length = response.headers.get("Content-Length")
                if length and int(length) > self.max_bytes:
                    raise NetworkError("La fuente supera el tamaño máximo permitido")
                body = response.read(self.max_bytes + 1)
                if len(body) > self.max_bytes:
                    raise NetworkError("La fuente supera el tamaño máximo permitido")
                return HttpResponse(
                    url=response.geturl(),
                    status=int(response.status),
                    headers={key.lower(): value for key, value in response.headers.items()},
                    body=body,
                )
        except urllib.error.HTTPError as exc:
            body = exc.read(min(self.max_bytes, 32_000))
            return HttpResponse(
                url=exc.geturl(),
                status=int(exc.code),
                headers={key.lower(): value for key, value in exc.headers.items()},
                body=body,
            )
        except (urllib.error.URLError, TimeoutError, ssl.SSLError) as exc:
            raise NetworkError(f"No se pudo recuperar la fuente: {type(exc).__name__}") from exc


def json_request(
    url: str,
    method: str = "GET",
    payload: dict[str, Any] | None = None,
    headers: dict[str, str] | None = None,
    timeout: int = 30,
    attempts: int = 2,
) -> tuple[int, dict[str, Any]]:
    data = None if payload is None else json.dumps(payload, ensure_ascii=False).encode("utf-8")
    request_headers = {"Accept": "application/json", "User-Agent": "CE-IA-Worker/0.9"}
    if data is not None:
        request_headers["Content-Type"] = "application/json"
    if headers:
        request_headers.update(headers)

    last_error: Exception | None = None
    for attempt in range(attempts):
        request = urllib.request.Request(url, data=data, headers=request_headers, method=method)
        host = (urllib.parse.urlsplit(url).hostname or "").lower()
        opener = urllib.request.build_opener(
            SameHostRedirectHandler(host),
            urllib.request.HTTPSHandler(context=ssl.create_default_context()),
        )
        try:
            with opener.open(request, timeout=timeout) as response:
                raw = response.read(12_000_000)
                parsed = json.loads(raw.decode("utf-8")) if raw else {}
                return int(response.status), parsed
        except urllib.error.HTTPError as exc:
            raw = exc.read(100_000)
            try:
                parsed_error = json.loads(raw.decode("utf-8")) if raw else {}
            except (json.JSONDecodeError, UnicodeDecodeError):
                parsed_error = {"error": {"message": f"HTTP {exc.code}"}}
            if exc.code in (429, 500, 502, 503, 504) and attempt + 1 < attempts:
                time.sleep(2**attempt)
                continue
            return int(exc.code), parsed_error
        except (urllib.error.URLError, TimeoutError, ssl.SSLError) as exc:
            last_error = exc
            if attempt + 1 < attempts:
                time.sleep(2**attempt)
                continue

    raise NetworkError(f"Fallo de red: {type(last_error).__name__ if last_error else 'desconocido'}")


def basic_auth_header(username: str, password: str) -> str:
    token = base64.b64encode(f"{username}:{password}".encode("utf-8")).decode("ascii")
    return "Basic " + token
