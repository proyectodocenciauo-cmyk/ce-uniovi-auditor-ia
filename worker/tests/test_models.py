from __future__ import annotations

import unittest

from pydantic import ValidationError

from ceia_worker.models import DateRange, ModelProposal


class ModelTests(unittest.TestCase):
    def test_rejects_inverted_date_range(self):
        with self.assertRaises(ValidationError):
            DateRange(fecha_inicio="2026-05-10", fecha_fin="2026-05-01")

    def test_exclude_none_makes_safe_partial_index_patch(self):
        proposal = ModelProposal(
            change_required=True,
            validation_status="verified",
            risk="high",
            summary="Actualización de índice.",
            index_patch={"abierto_permanente": True},
        )
        dumped = proposal.model_dump(mode="json", exclude_none=True)
        self.assertEqual({"abierto_permanente": True}, dumped["index_patch"])
        self.assertNotIn("url", dumped["index_patch"])


if __name__ == "__main__":
    unittest.main()

