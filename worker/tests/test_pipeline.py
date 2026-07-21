from __future__ import annotations

import unittest
from datetime import UTC, datetime
from unittest.mock import patch

from ceia_worker.models import EvidenceRecord, ModelProposal, RemoteConfig
from ceia_worker.pipeline import Worker

SAFE_HTML = """<section id="ceia-pilot"><style>#ceia-pilot{color:#172033;max-width:100%}#ceia-pilot *{box-sizing:border-box}@media(max-width:640px){#ceia-pilot{padding:12px}}</style><h2>Guía</h2><p>El plazo termina el 31 de marzo.</p><p>Contenido contrastado.</p></section>"""


class FakeWordPress:
    def __init__(self):
        self.contexts = [
            {
                "job": {"id": "11111111-1111-4111-8111-111111111111"},
                "item": {
                    "id": 1,
                    "title": "Trámite piloto",
                    "category": "Ayudas y Becas",
                    "risk": "high",
                    "url": "https://www.unioviedo.es/cestudiantes/piloto/",
                },
                "post": {
                    "id": 20,
                    "title": "Trámite piloto",
                    "url": "https://www.unioviedo.es/cestudiantes/piloto/",
                    "content": SAFE_HTML,
                },
                "tramite": {"id": 1, "nombre": "Trámite piloto"},
                "sources": [],
            }
        ]
        self.results = []
        self.heartbeats = []

    def config(self):
        return RemoteConfig(
            site_url="https://www.unioviedo.es/cestudiantes/",
            plugin_version="0.12.0",
            gemini_api_key="fake",
            allowed_source_hosts=["uniovi.es", "unioviedo.es", "boe.es", "asturias.es"],
        )

    def heartbeat(self, version, provider, message="ready"):
        self.heartbeats.append((version, provider, message))
        return {"ok": True}

    def claim(self):
        return self.contexts.pop(0) if self.contexts else None

    def submit_result(self, job_id, payload):
        self.results.append((job_id, payload))
        return {"ok": True}

    def fail(self, job_id, code, message):
        raise AssertionError(f"No debía fallar: {job_id} {code} {message}")


class FakeProvider:
    name = "fake"

    def __init__(self):
        self.prompts = []

    def analyze(self, prompt):
        self.prompts.append(prompt)
        return ModelProposal(
            change_required=True,
            validation_status="verified",
            risk="high",
            summary="Se mantiene el contenido con dos fuentes oficiales.",
            proposed_content=SAFE_HTML,
            facts=[
                {
                    "fact_id": "F1",
                    "fact_type": "deadline",
                    "subject": "plazo de presentación",
                    "claim": "El plazo termina el 31 de marzo.",
                    "value": "31 de marzo",
                    "evidence_ids": ["E001", "E002"],
                    "supports": [
                        {
                            "evidence_id": "E001",
                            "quote": "El plazo termina el 31 de marzo.",
                            "relation": "supports",
                        },
                        {
                            "evidence_id": "E002",
                            "quote": "El plazo termina el 31 de marzo.",
                            "relation": "supports",
                        },
                    ],
                }
            ],
            citations=["E001", "E002"],
        )


class FakeRetriever:
    def __init__(self, config):
        self.config = config

    def collect(self, context):
        now = datetime.now(UTC).strftime("%Y-%m-%d %H:%M:%S")
        common = {
            "retrieved_gmt": now,
            "http_status": 200,
            "excerpt": "El plazo termina el 31 de marzo.",
            "relevance_score": 95,
            "required": True,
            "primary": True,
            "retrieval_status": "ok",
        }
        return [
            EvidenceRecord(
                local_id="E001",
                url="https://sede.uniovi.es/resolucion",
                title="Sede",
                authority=95,
                source_type="official_registry",
                content_hash="a" * 64,
                **common,
            ),
            EvidenceRecord(
                local_id="E002",
                url="https://www.boe.es/boe/dias/2026/03/01/pdfs/a.pdf",
                title="BOE",
                authority=100,
                source_type="official_gazette",
                content_hash="b" * 64,
                **common,
            ),
        ], []

    def audit_links(self, urls, current_urls, evidence_urls):
        return [], [], []


class PipelineTests(unittest.TestCase):
    def test_pipeline_delivers_quality_report_and_never_publishes(self):
        wordpress = FakeWordPress()
        provider = FakeProvider()
        previews = [
            {"width": width, "mime": "image/jpeg", "data": "ZmFrZQ=="}
            for width in (360, 390, 768, 1440)
        ]
        responsive = [
            {"width": width, "status": "pass", "issues": []}
            for width in (360, 390, 768, 1440)
        ]
        with patch("ceia_worker.pipeline.EvidenceRetriever", FakeRetriever), patch(
            "ceia_worker.pipeline.render_responsive",
            return_value=(previews, responsive, []),
        ):
            stats = Worker(wordpress, provider=provider).run(max_jobs=1)

        self.assertEqual({"claimed": 1, "completed": 1, "failed": 0}, stats)
        self.assertEqual(1, len(wordpress.results))
        job_id, payload = wordpress.results[0]
        self.assertEqual("11111111-1111-4111-8111-111111111111", job_id)
        self.assertEqual("verified", payload["validation_status"])
        self.assertEqual("pass", payload["publication_gate"])
        self.assertEqual(2, len(payload["evidence"]))
        self.assertEqual(4, len(payload["previews"]))
        self.assertNotIn("publish", payload)
        self.assertIn("Trámite piloto", provider.prompts[0])


if __name__ == "__main__":
    unittest.main()
