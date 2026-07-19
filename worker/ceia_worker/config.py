from __future__ import annotations

import os
import platform
import socket
from dataclasses import dataclass


class ConfigurationError(RuntimeError):
    pass


@dataclass(frozen=True)
class WordPressCredentials:
    site_url: str
    username: str
    application_password: str
    worker_id: str

    @classmethod
    def from_environment(cls) -> "WordPressCredentials":
        site_url = os.environ.get("CEIA_WP_URL", "").strip().rstrip("/") + "/"
        username = os.environ.get("CEIA_WP_USER", "").strip()
        password = os.environ.get("CEIA_WP_APP_PASSWORD", "").replace(" ", "").strip()
        worker_id = os.environ.get("CEIA_WORKER_ID", "").strip()
        if not worker_id:
            worker_id = f"github-{platform.system().lower()}-{socket.gethostname()}"[:191]

        missing = [
            name
            for name, value in (
                ("CEIA_WP_URL", site_url if site_url != "/" else ""),
                ("CEIA_WP_USER", username),
                ("CEIA_WP_APP_PASSWORD", password),
            )
            if not value
        ]
        if missing:
            raise ConfigurationError("Faltan variables obligatorias: " + ", ".join(missing))
        if not site_url.startswith("https://"):
            raise ConfigurationError("CEIA_WP_URL debe utilizar HTTPS")

        return cls(site_url, username, password, worker_id)

