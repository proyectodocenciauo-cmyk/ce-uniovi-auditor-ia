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


class FakeGenerateContentConfig:
    def __init__(self, **kwargs):
        self.kwargs = kwargs


class FakeResponse:
    parsed = None
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
    def test_uses_stable_generate_content_with_pydantic_schema(self):
        google_module = pytypes.ModuleType("google")
        genai_module = pytypes.ModuleType("google.genai")
        genai_module.Client = FakeClient
        genai_module.types = pytypes.SimpleNamespace(GenerateContentConfig=FakeGenerateContentConfig)
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
        self.assertEqual("application/json", call["config"].kwargs["response_mime_type"])
        self.assertIs(ModelProposal, call["config"].kwargs["response_schema"])


if __name__ == "__main__":
    unittest.main()
