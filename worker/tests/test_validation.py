from __future__ import annotations

import unittest
from datetime import UTC, datetime

from ceia_worker.models import EvidenceRecord, EvidenceSupport, Fact, ModelProposal
from ceia_worker.validation import UnsafeProposalError, validate_html, validate_proposal

CURRENT_HTML = """
<section id="old-guide">
  <h2>Solicitud</h2>
  <p>El plazo termina el 31 de marzo.</p>
  <p>Documentación necesaria y requisitos de acceso.</p>
  <a href="https://www.unioviedo.es/cestudiantes/index.php/contacto/">Contacto</a>
</section>
"""

SAFE_HTML = """
<section id="ceia-guide">
  <style>
    #ceia-guide{color:#172033;max-width:100%}
    #ceia-guide *{box-sizing:border-box}
    @media(max-width:640px){#ceia-guide{padding:12px}}
  </style>
  <h2>Solicitud</h2>
  <p>El plazo termina el 31 de marzo.</p>
  <p>Documentación necesaria y requisitos de acceso.</p>
  <a href="https://www.unioviedo.es/cestudiantes/index.php/contacto/">Contacto</a>
</section>
"""


def evidence(local_id: str, url: str, quote: str, authority: int = 95) -> EvidenceRecord:
    return EvidenceRecord(
        local_id=local_id,
        url=url,
        title="Fuente oficial",
        source_type="official_registry" if authority < 100 else "official_gazette",
        authority=authority,
        retrieved_gmt=datetime.now(UTC).strftime("%Y-%m-%d %H:%M:%S"),
        http_status=200,
        content_hash="a" * 64,
        excerpt=quote,
        relevance_score=90,
        required=True,
        primary=True,
        retrieval_status="ok",
    )


class HTMLValidationTests(unittest.TestCase):
    def test_accepts_scoped_responsive_block(self):
        self.assertEqual([], validate_html(SAFE_HTML))

    def test_rejects_span_script_duplicate_ids_and_empty_links(self):
        variants = (
            SAFE_HTML.replace("<p>El plazo", "<span>dato</span><p>El plazo"),
            SAFE_HTML.replace("<p>El plazo", "<script>alert(1)</script><p>El plazo"),
            SAFE_HTML.replace("<h2>", '<h2 id="ceia-guide">'),
            SAFE_HTML.replace('href="https://www.unioviedo.es/cestudiantes/index.php/contacto/"', 'href="#"'),
        )
        for html in variants:
            with self.subTest(html=html[:100]), self.assertRaises(UnsafeProposalError):
                validate_html(html)


class DeterministicSafetyTests(unittest.TestCase):
    def test_blocks_large_deletion(self):
        proposal = ModelProposal(
            change_required=True,
            validation_status="verified",
            risk="high",
            summary="Reescritura excesiva.",
            proposed_content=(
                '<section id="short"><style>#short{}@media(max-width:640px){#short{}}</style>'
                "<h2>Solicitud</h2></section>"
            ),
        )
        report = validate_proposal(
            proposal,
            [
                evidence("E001", "https://sede.uniovi.es/a", "Texto oficial"),
                evidence("E002", "https://www.boe.es/a", "Texto oficial", 100),
            ],
            "high",
            CURRENT_HTML,
        )
        self.assertEqual("blocked", report.quality.gate)
        self.assertTrue(any("conserva" in error for error in report.errors))

    def test_requires_literal_quotes_and_two_official_sources(self):
        fact = Fact(
            fact_id="F001",
            fact_type="deadline",
            claim="El plazo termina el 31 de marzo.",
            value="31 de marzo",
            evidence_ids=["E001", "E002"],
            supports=[
                EvidenceSupport(
                    evidence_id="E001",
                    quote="El plazo termina el 31 de marzo.",
                ),
                EvidenceSupport(
                    evidence_id="E002",
                    quote="El plazo termina el 31 de marzo.",
                ),
            ],
        )
        proposal = ModelProposal(
            change_required=True,
            validation_status="verified",
            risk="high",
            summary="Actualización contrastada.",
            proposed_content=SAFE_HTML,
            facts=[fact],
            citations=["E001", "E002"],
        )
        report = validate_proposal(
            proposal,
            [
                evidence(
                    "E001",
                    "https://sede.uniovi.es/a",
                    "El plazo termina el 31 de marzo.",
                ),
                evidence(
                    "E002",
                    "https://www.boe.es/a",
                    "El plazo termina el 31 de marzo.",
                    100,
                ),
            ],
            "high",
            CURRENT_HTML,
        )
        self.assertEqual("pass", report.quality.gate)
        self.assertLess(proposal.facts[0].confidence, 1)
        self.assertEqual("supported", proposal.facts[0].support_status)

    def test_detects_conflicting_critical_values_even_if_model_does_not(self):
        facts = []
        rows = [
            ("F001", "31 marzo", "E001"),
            ("F002", "15 abril", "E002"),
        ]
        for fact_id, value, evidence_id in rows:
            facts.append(
                Fact(
                    fact_id=fact_id,
                    fact_type="deadline",
                    claim=f"El plazo es {value}",
                    value=value,
                    evidence_ids=[evidence_id],
                    supports=[
                        EvidenceSupport(
                            evidence_id=evidence_id,
                            quote=f"El plazo es {value}",
                        )
                    ],
                )
            )
        proposal = ModelProposal(
            change_required=True,
            validation_status="verified",
            risk="high",
            summary="Sin conflictos según el modelo.",
            proposed_content=SAFE_HTML,
            facts=facts,
        )
        report = validate_proposal(
            proposal,
            [
                evidence("E001", "https://sede.uniovi.es/a", "El plazo es 31 marzo"),
                evidence("E002", "https://www.boe.es/a", "El plazo es 15 abril", 100),
            ],
            "high",
            CURRENT_HTML,
        )
        self.assertEqual("conflict", report.status)
        self.assertEqual("blocked", report.quality.gate)
        self.assertTrue(proposal.conflicts)


if __name__ == "__main__":
    unittest.main()
