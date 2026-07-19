from __future__ import annotations

import logging
from typing import Any

from . import __version__
from .models import EvidenceRecord, RemoteConfig
from .prompting import build_prompt
from .providers import AnalysisProvider, GeminiProvider, ProviderError, ProviderTemporaryError
from .retrieval import Retriever
from .validation import UnsafeProposalError, validate_proposal
from .wordpress import WordPressAPIError, WordPressClient


LOGGER = logging.getLogger("ceia")


class Worker:
    def __init__(self, wordpress: WordPressClient, provider: AnalysisProvider | None = None):
        self.wordpress = wordpress
        self.config: RemoteConfig = wordpress.config()
        if provider is not None:
            self.provider = provider
        else:
            self.provider = GeminiProvider(self.config.gemini_api_key, self.config.gemini_model)

    def run(self, max_jobs: int | None = None) -> dict[str, int]:
        limit = self.config.limits.max_jobs_per_run
        if max_jobs is not None:
            limit = max(1, min(limit, max_jobs))
        stats = {"claimed": 0, "completed": 0, "failed": 0}
        self.wordpress.heartbeat(__version__, self.provider.name, "ready")

        for _ in range(limit):
            context = self.wordpress.claim()
            if not context:
                break
            stats["claimed"] += 1
            job_id = str((context.get("job") or {}).get("id", ""))
            if not job_id:
                stats["failed"] += 1
                continue
            try:
                self._process(context)
                stats["completed"] += 1
            except ProviderTemporaryError as exc:
                stats["failed"] += 1
                self.wordpress.fail(job_id, "worker_temporary", str(exc))
            except (ProviderError, UnsafeProposalError) as exc:
                stats["failed"] += 1
                self.wordpress.fail(job_id, "research_validation_failed", str(exc))
            except Exception as exc:
                stats["failed"] += 1
                # No se envían trazas ni contenidos; solo clase y mensaje acotado.
                safe_message = f"{type(exc).__name__}: {str(exc)[:800]}"
                try:
                    self.wordpress.fail(job_id, "worker_temporary", safe_message)
                except WordPressAPIError:
                    LOGGER.error("No se pudo devolver el fallo de %s a WordPress", job_id)

        self.wordpress.heartbeat(
            __version__,
            self.provider.name,
            f"claimed={stats['claimed']};completed={stats['completed']};failed={stats['failed']}",
        )
        return stats

    def _process(self, context: dict[str, Any]) -> None:
        item = context.get("item") or {}
        job_id = str((context.get("job") or {}).get("id", ""))
        LOGGER.info("Investigando item=%s job=%s", item.get("id"), job_id)

        evidence, retrieval_notes = Retriever(self.config).collect(context)
        usable = [entry for entry in evidence if entry.http_status == 200 and entry.excerpt.strip()]
        if not usable:
            raise ProviderTemporaryError("No se pudo recuperar ninguna fuente utilizable")

        prompt = build_prompt(context, evidence, self.config, retrieval_notes)
        proposal = self.provider.analyze(prompt)
        report = validate_proposal(proposal, evidence, str(item.get("risk", "medium")))

        facts_by_evidence: dict[str, list[dict[str, Any]]] = {}
        for fact in proposal.facts:
            serialized = fact.model_dump(mode="json")
            for evidence_id in fact.evidence_ids:
                facts_by_evidence.setdefault(evidence_id, []).append(serialized)
        for entry in evidence:
            entry.facts = facts_by_evidence.get(entry.local_id, [])

        payload = proposal.model_dump(mode="json", exclude_none=True)
        payload["validation_status"] = report.status
        payload["risk"] = report.risk
        payload["evidence"] = [entry.for_wordpress() for entry in evidence]
        payload["citations"] = sorted(
            set(proposal.citations)
            | {evidence_id for fact in proposal.facts for evidence_id in fact.evidence_ids}
        )

        validation_notes = report.errors + report.warnings + retrieval_notes
        if validation_notes:
            payload["summary"] = (
                proposal.summary.rstrip()
                + "\n\nObservaciones automáticas:\n- "
                + "\n- ".join(validation_notes[:30])
            )[:20_000]

        self.wordpress.submit_result(job_id, payload)

