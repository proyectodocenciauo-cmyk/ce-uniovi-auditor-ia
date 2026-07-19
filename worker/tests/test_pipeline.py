from __future__ import annotations

import unittest
from datetime import UTC, datetime
from unittest.mock import patch

from ceia_worker.models import EvidenceRecord, ModelProposal, RemoteConfig
from ceia_worker.pipeline import Worker


SAFE_HTML = """<section id="ceia-pilot"><style>#ceia-pilot{color:#172033}#ceia-pilot *{box-sizing:border-box}@media(max-width:640px){#ceia-pilot{padding:12px}}</style><h2>Guía</h2><p>Contenido contrastado.</p></section>"""


class FakeWordPress:
    def __init__(self):
        self.contexts = [
            {
                "job": {"id": "11111111-1111-4111-8111-111111111111"},
                "item": {"id": 1, "title": "Trámite piloto", "category": "Ayudas y Becas", "risk": "high"},
                "post": {"id": 20, "title": "Trámite piloto", "url": "https://www.unioviedo.es/cestudiantes/piloto/", "content": "<p>Anterior</p>"},
                "tramite": {"id": 1, "nombre": "Trámite piloto"},
                "sources": [],
            }
        ]
        self.results = []
        self.heartbeats = []

    def config(self):
        return RemoteConfig(
            site_url="https://www.unioviedo.es/cestudiantes/",
            plugin_version="0.9.0",
            gemini_api_key="fake",
            allowed_source_hosts=["uniovi.es", "unioviedo.es", "boe.es"],
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
            summary="Se actualiza con dos fuentes oficiales.",
            proposed_content=SAFE_HTML,
            changes=[
                {
                    "section": "Plazo",
                    "current": "Anterior",
                    "proposed": "Nuevo",
                    "reason": "Resolución oficial",
                    "evidence_ids": ["E001", "E002"],
                }
            ],
            facts=[
                {
                    "fact_id": "F1",
                    "fact_type": "deadline",
                    "claim": "El plazo termina el 31 de marzo.",
                    "value": "2026-03-31",
                    "evidence_ids": ["E001", "E002"],
                    "confidence": 0.98,
                }
            ],
            citations=["E001", "E002"],
        )


class FakeRetriever:
    def __init__(self, config):
        self.config = config

    def collect(self, context):
        now = datetime.now(UTC).strftime("%Y-%m-%d %H:%M:%S")
        return [
            EvidenceRecord(local_id="E001", url="https://sede.uniovi.es/resolucion", title="Sede", authority=95, source_type="official_registry", retrieved_gmt=now, http_status=200, content_hash="a" * 64, excerpt="Plazo oficial."),
            EvidenceRecord(local_id="E002", url="https://www.boe.es/boe/dias/2026/03/01/pdfs/a.pdf", title="BOE", authority=100, source_type="official_gazette", retrieved_gmt=now, http_status=200, content_hash="b" * 64, excerpt="Publicación oficial."),
        ], []


class PipelineTests(unittest.TestCase):
    def test_pipeline_delivers_evidence_but_no_publish_instruction(self):
        wordpress = FakeWordPress()
        provider = FakeProvider()
        with patch("ceia_worker.pipeline.Retriever", FakeRetriever):
            stats = Worker(wordpress, provider=provider).run(max_jobs=1)

        self.assertEqual({"claimed": 1, "completed": 1, "failed": 0}, stats)
        self.assertEqual(1, len(wordpress.results))
        job_id, payload = wordpress.results[0]
        self.assertEqual("11111111-1111-4111-8111-111111111111", job_id)
        self.assertEqual("verified", payload["validation_status"])
        self.assertEqual(2, len(payload["evidence"]))
        self.assertNotIn("publish", payload)
        self.assertIn("Trámite piloto", provider.prompts[0])


if __name__ == "__main__":
    unittest.main()

