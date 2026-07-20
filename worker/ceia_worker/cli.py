from __future__ import annotations

import argparse
import json
import logging
import sys

from . import __version__
from .config import ConfigurationError, WordPressCredentials
from .pipeline import Worker
from .providers import GeminiProvider
from .wordpress import WordPressAPIError, WordPressClient


GEMINI_CHECK_PROMPT = """
Esta es una prueba técnica aislada. Devuelve exclusivamente el objeto JSON solicitado por el esquema.
No investigues, no inventes fuentes y no propongas cambios. Usa estos valores semánticos:
- change_required: false
- validation_status: verified
- risk: low
- summary: "Conexión con Gemini verificada correctamente."
- proposed_title y proposed_content: cadenas vacías
- index_patch: objeto vacío
- changes, facts, conflicts y citations: listas vacías
""".strip()


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="ceia-worker", description="Trabajador gratuito de CE-IA")
    parser.add_argument("--version", action="version", version=__version__)
    subparsers = parser.add_subparsers(dest="command", required=True)
    run = subparsers.add_parser("run", help="Procesa la cola dentro del presupuesto configurado")
    run.add_argument("--max-jobs", type=int, default=None)
    subparsers.add_parser("status", help="Comprueba la conexión privada con WordPress")
    subparsers.add_parser(
        "gemini-check",
        help="Comprueba Gemini y el esquema sin reclamar ni modificar trabajos de la cola",
    )
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
    try:
        client = WordPressClient(WordPressCredentials.from_environment())
        if args.command == "status":
            health = client.health()
            print(
                json.dumps(
                    {
                        "ok": True,
                        "plugin_version": health.get("plugin_version"),
                        "queue": health.get("queue"),
                    },
                    ensure_ascii=False,
                )
            )
            return 0
        if args.command == "gemini-check":
            config = client.config()
            proposal = GeminiProvider(
                config.gemini_api_key,
                config.gemini_model,
            ).analyze(GEMINI_CHECK_PROMPT)
            print(
                json.dumps(
                    {
                        "ok": True,
                        "provider": "gemini",
                        "model": config.gemini_model,
                        "worker_version": __version__,
                        "summary": proposal.summary,
                    },
                    ensure_ascii=False,
                )
            )
            return 0
        stats = Worker(client).run(max_jobs=args.max_jobs)
        print(json.dumps(stats, ensure_ascii=False))
        return 0 if stats["failed"] == 0 else 2
    except (ConfigurationError, WordPressAPIError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    except Exception as exc:
        print(f"ERROR: {type(exc).__name__}: {str(exc)[:800]}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
