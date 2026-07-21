from __future__ import annotations

import unittest

from ceia_worker.http import NetworkError, validate_public_https_url
from ceia_worker.research import PublicHTMLParser, _canonical, _source_type


class RetrievalTests(unittest.TestCase):
    def test_extracts_text_anchor_labels_and_title_without_scripts(self):
        parser = PublicHTMLParser("https://sede.uniovi.es/base/")
        parser.feed(
            """
            <html><head><title>Resolución oficial</title><script>ignorar()</script></head>
            <body><h1>Convocatoria</h1><p>Plazo: diez días.</p>
            <a href="/documento.pdf">Resolución y bases</a></body></html>
            """
        )
        parser.close()
        self.assertEqual("Resolución oficial", parser.title)
        self.assertIn("Plazo: diez días.", parser.text)
        self.assertNotIn("ignorar", parser.text)
        self.assertEqual("https://sede.uniovi.es/documento.pdf", parser.links[0].url)
        self.assertEqual("Resolución y bases", parser.links[0].text)

    def test_private_ip_is_blocked(self):
        with self.assertRaises(NetworkError):
            validate_public_https_url("https://127.0.0.1/private")

    def test_canonical_url_removes_tracking_and_fragment(self):
        canonical = _canonical("https://www.uniovi.es/x?utm_source=a&id=2#parte")
        self.assertEqual("https://www.uniovi.es/x?id=2", canonical)

    def test_council_domain_is_classified(self):
        self.assertEqual(
            "council",
            _source_type("https://www.unioviedo.es/cestudiantes/index.php/tramite/"),
        )


if __name__ == "__main__":
    unittest.main()
