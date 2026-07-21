from __future__ import annotations

import unittest

from ceia_worker.research import _allowed_host, _relevance


class ResearchSafetyTests(unittest.TestCase):
    def test_source_whitelist_rejects_unrelated_domains(self):
        self.assertTrue(_allowed_host("sede.uniovi.es"))
        self.assertTrue(_allowed_host("sede.asturias.es"))
        self.assertTrue(_allowed_host("www.boe.es"))
        self.assertFalse(_allowed_host("example.org"))
        self.assertFalse(_allowed_host("uniovi.es.example.org"))

    def test_relevance_rejects_unrelated_course_page(self):
        keywords = ["anulacion", "asignaturas", "excepcional"]
        relevant = _relevance(
            "Anulación excepcional de asignaturas",
            "https://www.uniovi.es/estudia/grados/anulacion-asignaturas",
            "Procedimiento, requisitos y plazo para solicitar la anulación excepcional de asignaturas.",
            keywords,
        )
        irrelevant = _relevance(
            "Curso de investigación educativa",
            "https://www.uniovi.es/estudia/cursos/investigacion",
            "Programa del curso, profesorado, seminarios y líneas de investigación.",
            keywords,
        )
        self.assertGreaterEqual(relevant, 35)
        self.assertLess(irrelevant, 35)


if __name__ == "__main__":
    unittest.main()
