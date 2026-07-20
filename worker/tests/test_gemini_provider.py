from __future__ import annotations

import json
import sys
import types as pytypes
import unittest
from unittest.mock import patch

from ceia_worker.models import ModelProposal
from ceia_worker.providers.gemini import GeminiProvider


VALID_PROPOSAL = {
    "change_required": False,
    "validation_status": "verified",
    "risk": "low",
    "summary": "Contenido comprobado.",
    "proposed_title": "",
    "proposed_content": "",
    "index_patch": {},
    "changes": [],
    "facts": [],
    "conflicts": [],
    "citations": [],
}


class FakeResponse:
    text = json.dumps(VALID_PROPOSAL)


class FakeModels:
    def __init__(self):
        self.calls = []

    def generate_content(self, **kwargs):
        self.calls.append(kwargs)
        return FakeResponse()


class FakeClient:
    instances = []

    def __init__(self, api_key):
        self.api_key = api_key
        self.models = FakeModels()
        self.closed = False
        self.__class__.instances.append(self)

    def close(self):
        self.closed = True


class GeminiProviderTests(unittest.TestCase):
    def test_uses_generate_content_with_response_json_schema(self):
        google_module = pytypes.ModuleType("google")
        genai_module = pytypes.ModuleType("google.genai")
        genai_module.Client = FakeClient
        google_module.genai = genai_module

        with patch.dict(sys.modules, {"google": google_module, "google.genai": genai_module}):
            result = GeminiProvider("fake-key", "gemini-3.1-flash-lite").analyze("prompt")

        self.assertIsInstance(result, ModelProposal)
        client = FakeClient.instances[-1]
        self.assertTrue(client.closed)
        self.assertEqual(1, len(client.models.calls))
        call = client.models.calls[0]
        self.assertEqual("gemini-3.1-flash-lite", call["model"])
        self.assertEqual("prompt", call["contents"])
        self.assertEqual("application/json", call["config"]["response_mime_type"])
        self.assertIn("response_json_schema", call["config"])
        self.assertNotIn("response_schema", call["config"])

    def test_schema_contains_only_supported_keywords(self):
        schema = GeminiProvider.response_json_schema()
        unsupported = {"minLength", "maxLength", "default", "examples"}

        def visit(value):
            if isinstance(value, list):
                for item in value:
                    visit(item)
                return
            if not isinstance(value, dict):
                return
            for key, item in value.items():
                self.assertNotIn(key, unsupported)
                if key in {"properties", "$defs"} and isinstance(item, dict):
                    for child in item.values():
                        visit(child)
                else:
                    visit(item)

        visit(schema)
        self.assertEqual("object", schema["type"])
        self.assertIn("summary", schema["properties"])


if __name__ == "__main__":
    unittest.main()
