from __future__ import annotations

import unittest

from ceia_worker.browser import VIEWPORTS, render_responsive


class BrowserAuditTests(unittest.TestCase):
    def test_safe_page_renders_at_all_required_widths(self):
        html = """
        <section id="safe">
          <style>
            #safe{max-width:100%;padding:16px}
            #safe *{box-sizing:border-box}
            #safe .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
            @media(max-width:640px){#safe .grid{grid-template-columns:1fr}}
          </style>
          <h2>Información</h2>
          <div class="grid"><p>Primer bloque.</p><p>Segundo bloque.</p></div>
          <a href="https://www.unioviedo.es/cestudiantes/">Consejo</a>
        </section>
        """
        previews, results, errors = render_responsive(html)
        self.assertEqual([], errors)
        self.assertEqual(list(VIEWPORTS), [row["width"] for row in results])
        self.assertTrue(all(row["status"] == "pass" for row in results))
        self.assertEqual(4, len(previews))
        self.assertTrue(all(preview["data"] for preview in previews))


if __name__ == "__main__":
    unittest.main()
