from __future__ import annotations

import pathlib
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
PLUGIN = ROOT / "wordpress" / "ce-ia-auditor"


class WordPressScopeControlTests(unittest.TestCase):
    def test_plugin_loads_scope_controls(self):
        main = (PLUGIN / "ce-ia-auditor.php").read_text(encoding="utf-8")
        self.assertIn("class-ceia-scope-controls.php", main)
        self.assertIn("CEIA_Scope_Controls::register_hooks", main)
        self.assertIn("Version: 0.11.0", main)

    def test_scope_is_exact_council_prefix(self):
        code = (PLUGIN / "includes" / "class-ceia-scope-controls.php").read_text(encoding="utf-8")
        self.assertIn("https://www.unioviedo.es/cestudiantes/", code)
        self.assertIn("'www.unioviedo.es' === $host", code)
        self.assertIn("'/cestudiantes/'", code)

    def test_queue_can_be_cancelled_without_deleting_history(self):
        code = (PLUGIN / "includes" / "class-ceia-scope-controls.php").read_text(encoding="utf-8")
        self.assertIn("'state'         => 'cancelled'", code)
        self.assertIn("job_cancelled", code)
        self.assertNotIn("$wpdb->delete( $tables['jobs']", code)


if __name__ == "__main__":
    unittest.main()
