from __future__ import annotations

from typing import Protocol

from ..models import ModelProposal


class ProviderError(RuntimeError):
    pass


class ProviderTemporaryError(ProviderError):
    pass


class AnalysisProvider(Protocol):
    name: str

    def analyze(self, prompt: str) -> ModelProposal:
        ...

