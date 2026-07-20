from __future__ import annotations

import json
import unittest
from unittest.mock import patch

from ceia_worker.models import ModelProposal
from ceia_worker.providers.gemini import GeminiProvider, ProviderError


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


def gemini_response(payload: dict) -> dict:
    return {
        "candidates": [
            {
                "content": {
                    "parts": [{"text": json.dumps(payload, ensure_ascii=False)}]
                },
                "finishReason": "STOP",
            }
        ]
    }


class GeminiProviderTests(unittest.TestCase):
    @patch("ceia_worker.providers.gemini.json_request")
    def test_uses_stable_generate_content_rest_with_json_mode(self, request):
        request.return_value = (200, gemini_response(VALID_PROPOSAL))

        result = GeminiProvider("fake-key", "gemini-3.1-flash-lite").analyze("prompt")

        self.assertIsInstance(result, ModelProposal)
        self.assertEqual("Contenido comprobado.", result.summary)
        self.assertEqual(1, request.call_count)
        url = request.call_args.args[0]
        kwargs = request.call_args.kwargs
        self.assertIn("/v1beta/models/gemini-3.1-flash-lite:generateContent", url)
        self.assertIn("key=fake-key", url)
        self.assertEqual("POST", kwargs["method"])
        config = kwargs["payload"]["generationConfig"]
        self.assertEqual("application/json", config["responseMimeType"])
        self.assertNotIn("responseJsonSchema", config)
        self.assertNotIn("response_json_schema", config)

    @patch("ceia_worker.providers.gemini.json_request")
    def test_retries_json_that_does_not_validate(self, request):
        request.side_effect = [
            (200, gemini_response({"summary": "incompleto"})),
            (200, gemini_response(VALID_PROPOSAL)),
        ]

        result = GeminiProvider("fake-key", "gemini-3.1-flash-lite").analyze("prompt")

        self.assertEqual("Contenido comprobado.", result.summary)
        self.assertEqual(2, request.call_count)

    @patch("ceia_worker.providers.gemini.json_request")
    def test_hides_key_in_http_error(self, request):
        request.return_value = (
            400,
            {"error": {"message": "invalid request for fake-key"}},
        )

        with self.assertRaises(ProviderError) as caught:
            GeminiProvider("fake-key", "gemini-3.1-flash-lite").analyze("prompt")

        self.assertNotIn("fake-key", str(caught.exception))
        self.assertIn("[clave oculta]", str(caught.exception))


if __name__ == "__main__":
    unittest.main()
