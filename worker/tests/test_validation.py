from __future__ import annotations

import unittest
from datetime import UTC, datetime

from ceia_worker.models import EvidenceRecord, Fact, ModelProposal
from ceia_worker.validation import UnsafeProposalError, validate_html, validate_proposal


SAFE_HTML = """
<section id="ceia-guide">
  <style>
    #ceia-guide{color:#172033;max-width:100%}
    #ceia-guide *{box-sizing:border-box}
    @media(max-width:640px){#ceia-guide{padding:12px}}
  </style>
  <h2>Guía comprobada</h2>
  <p>Consulta la fuente oficial.</p>
</section>
"""


def evidence(local_id: str, url: str, authority: int) -> EvidenceRecord:
    return EvidenceRecord(
        local_id=local_id,
        url=url,
        title="Fuente",
        source_type="institutional",
        authority=authority,
        retrieved_gmt=datetime.now(UTC).strftime("%Y-%m-%d %H:%M:%S"),
        http_status=200,
        content_hash="a" * 64,
        excerpt="El plazo oficial consta en esta resolución.",
    )


class HTMLValidationTests(unittest.TestCase):
    def test_accepts_scoped_responsive_block(self):
        self.assertEqual([], validate_html(SAFE_HTML))

    def test_rejects_span_and_script(self):
        for fragment in ("<span>dato</span>", "<script>alert(1)</script>"):
            with self.subTest(fragment=fragment), self.assertRaises(UnsafeProposalError):
                validate_html(SAFE_HTML.replace("<p>Consulta", fragment + "<p>Consulta"))

    def test_rejects_global_css(self):
        with self.assertRaises(UnsafeProposalError):
            validate_html(SAFE_HTML.replace("#ceia-guide{color", "body{color"))

    def test_rejects_remote_css_and_active_svg(self):
        unsafe_css = SAFE_HTML.replace("color:#172033", "background:url(https://example.org/a.png)")
        unsafe_svg = SAFE_HTML.replace("<h2>", '<svg><use href="https://example.org/icon.svg#x"></use></svg><h2>')
        for html in (unsafe_css, unsafe_svg):
            with self.subTest(html=html[:80]), self.assertRaises(UnsafeProposalError):
                validate_html(html)

    def test_warns_about_temporal_closed_wording(self):
        html = SAFE_HTML.replace("Consulta la fuente oficial.", "Actualmente está cerrado.")
        warnings = validate_html(html)
        self.assertTrue(any("temporal" in warning for warning in warnings))


class EvidenceValidationTests(unittest.TestCase):
    def test_critical_fact_with_two_official_sources_is_verified(self):
        proposal = ModelProposal(
            change_required=True,
            validation_status="verified",
            risk="high",
            summary="Se actualiza un plazo contrastado.",
            proposed_content=SAFE_HTML,
            facts=[
                Fact(
                    fact_id="F1",
                    fact_type="deadline",
                    claim="El último plazo publicado termina el 31 de marzo.",
                    value="2026-03-31",
                    evidence_ids=["E001", "E002"],
                    confidence=0.98,
                )
            ],
            citations=["E001", "E002"],
        )
        sources = [
            evidence("E001", "https://sede.uniovi.es/convocatoria", 95),
            evidence("E002", "https://www.boe.es/boe/dias/2026/03/01/pdfs/x.pdf", 100),
        ]
        report = validate_proposal(proposal, sources, "high")
        self.assertEqual("verified", report.status)
        self.assertFalse(report.errors)

    def test_critical_fact_without_official_source_is_blocked(self):
        proposal = ModelProposal(
            change_required=True,
            validation_status="verified",
            risk="medium",
            summary="Cambio sin suficiente prueba.",
            proposed_content=SAFE_HTML,
            facts=[
                Fact(
                    fact_id="F1",
                    fact_type="amount",
                    claim="El importe es 100 euros.",
                    value="100 EUR",
                    evidence_ids=["E001"],
                    confidence=0.8,
                )
            ],
        )
        report = validate_proposal(
            proposal,
            [evidence("E001", "https://example.org/noticia", 25)],
            "medium",
        )
        self.assertEqual("insufficient_evidence", report.status)
        self.assertTrue(report.errors)


if __name__ == "__main__":
    unittest.main()
