from __future__ import annotations

import unittest
from datetime import UTC, datetime
from unittest.mock import patch

from ceia_worker.models import EvidenceRecord, ModelProposal, RemoteConfig
from ceia_worker.pipeline import Worker, _is_managed_item_url


SAFE_HTML = """
<section id="ceia-safe">
<style>
#ceia-safe{max-width:100%}
#ceia-safe *{box-sizing:border-box}
@media(max-width:640px){#ceia-safe{padding:12px}}
</style>
<h2>Información comprobada</h2>
<p><a class="ceia-action" href="https://www.uniovi.es/">Consultar</a></p>
</section>
"""

UNSAFE_HTML = SAFE_HTML.replace(
    '<a class="ceia-action" href="https://www.uniovi.es/">Consultar</a>',
    "<button>Consultar</button>",
)


def proposal(html: str) -> ModelProposal:
    return ModelProposal(
        change_required=True,
        validation_status="verified",
        risk="low",
        summary="Propuesta de prueba.",
        proposed_content=html,
        index_patch={},
        changes=[],
        facts=[],
        conflicts=[],
        citations=[],
    )


class FakeProvider:
    name = "fake"

    def __init__(self):
        self.responses = [proposal(UNSAFE_HTML), proposal(SAFE_HTML)]
        self.prompts: list[str] = []

    def analyze(self, prompt: str) -> ModelProposal:
        self.prompts.append(prompt)
        return self.responses.pop(0)


class FakeWordPress:
    def __init__(self):
        self.submitted: list[tuple[str, dict]] = []

    def config(self) -> RemoteConfig:
        return RemoteConfig(
            site_url="https://www.unioviedo.es/cestudiantes/",
            plugin_version="0.11.0",
            gemini_api_key="fake",
        )

    def submit_result(self, job_id: str, payload: dict) -> None:
        self.submitted.append((job_id, payload))


class ScopeTests(unittest.TestCase):
    def test_only_council_pages_are_managed(self):
        self.assertTrue(
            _is_managed_item_url(
                "https://www.unioviedo.es/cestudiantes/index.php/tramite-anulacion-de-asignaturas-excepcional/"
            )
        )
        self.assertTrue(_is_managed_item_url("https://www.unioviedo.es/cestudiantes/"))
        self.assertFalse(_is_managed_item_url("http://www.unioviedo.es/cestudiantes/index.php/tramite/"))
        self.assertFalse(_is_managed_item_url("https://unioviedo.es/cestudiantes/index.php/tramite/"))
        self.assertFalse(_is_managed_item_url("https://www.uniovi.es/estudios/"))
        self.assertFalse(_is_managed_item_url("https://www.unioviedo.es/otra-web/"))

    def test_unsafe_button_is_repaired_once(self):
        evidence = EvidenceRecord(
            local_id="E001",
            url="https://www.uniovi.es/estudia/grados",
            title="Fuente oficial",
            source_type="institutional",
            authority=85,
            retrieved_gmt=datetime.now(UTC).strftime("%Y-%m-%d %H:%M:%S"),
            http_status=200,
            content_hash="a" * 64,
            excerpt="Información oficial utilizable.",
        )
        wordpress = FakeWordPress()
        provider = FakeProvider()
        worker = Worker(wordpress, provider=provider)
        context = {
            "job": {"id": "job-test"},
            "item": {
                "id": 1,
                "url": "https://www.unioviedo.es/cestudiantes/index.php/tramite-prueba/",
                "risk": "low",
            },
            "post": {"content": "<p>Contenido actual</p>", "url": "https://www.unioviedo.es/cestudiantes/index.php/tramite-prueba/"},
            "tramite": {},
            "sources": [],
        }

        with patch("ceia_worker.pipeline.Retriever.collect", return_value=([evidence], [])):
            worker._process(context)

        self.assertEqual(2, len(provider.prompts))
        self.assertIn("CORRECCIÓN TÉCNICA OBLIGATORIA", provider.prompts[1])
        self.assertEqual(1, len(wordpress.submitted))
        self.assertNotIn("<button", wordpress.submitted[0][1]["proposed_content"])
        self.assertIn("<a ", wordpress.submitted[0][1]["proposed_content"])


if __name__ == "__main__":
    unittest.main()
