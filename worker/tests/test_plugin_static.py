from __future__ import annotations

import pathlib
import re
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
PLUGIN = ROOT / "wordpress" / "ce-ia-auditor"


class PluginSafetyTests(unittest.TestCase):
    def test_plugin_never_drops_tables(self):
        code = "\n".join(path.read_text(encoding="utf-8") for path in PLUGIN.rglob("*.php"))
        self.assertNotRegex(code, re.compile(r"\bDROP\s+TABLE\b", re.IGNORECASE))
        self.assertNotRegex(code, re.compile(r"\bTRUNCATE\s+TABLE\b", re.IGNORECASE))

    def test_worker_role_cannot_publish(self):
        activator = (PLUGIN / "includes" / "class-ceia-activator.php").read_text(encoding="utf-8")
        role_block = activator.split("'ceia_research_worker'", 1)[1].split(");", 1)[0]
        self.assertIn("ceia_submit_research", role_block)
        self.assertNotIn("ceia_publish_proposals", role_block)

    def test_rest_routes_have_permission_callbacks(self):
        rest = (PLUGIN / "includes" / "class-ceia-rest-controller.php").read_text(encoding="utf-8")
        route_count = rest.count("register_rest_route(")
        permission_count = rest.count("'permission_callback'")
        self.assertGreaterEqual(route_count, 6)
        self.assertEqual(route_count, permission_count)

    def test_no_hardcoded_api_secret(self):
        code = "\n".join(path.read_text(encoding="utf-8") for path in ROOT.rglob("*.*") if path.suffix in {".php", ".py", ".yml", ".yaml"})
        self.assertNotRegex(code, r"AIza[0-9A-Za-z_-]{30,}")
        self.assertNotRegex(code, r"tvly-[0-9A-Za-z_-]{20,}")
        self.assertNotRegex(code, r"github_pat_[0-9A-Za-z_]{20,}")
        self.assertNotRegex(code, r"sk-proj-[0-9A-Za-z_-]{20,}")

    def test_free_only_edition_has_no_openai_runtime(self):
        code = "\n".join(path.read_text(encoding="utf-8") for path in ROOT.rglob("*.*") if path.suffix in {".php", ".py"})
        self.assertNotIn("api." + "openai.com", code)
        self.assertFalse((ROOT / "worker" / "ceia_worker" / "providers" / "openai.py").exists())
        admin = (PLUGIN / "includes" / "class-ceia-admin.php").read_text(encoding="utf-8")
        rest = (PLUGIN / "includes" / "class-ceia-rest-controller.php").read_text(encoding="utf-8")
        self.assertNotIn('name="openai_api_key"', admin)
        self.assertNotIn("openai_api_key'    =>", rest)


if __name__ == "__main__":
    unittest.main()

