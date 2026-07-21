from __future__ import annotations

import logging
import urllib.parse
from typing import Any

from . import __version__
from .browser import BrowserAuditError, render_responsive
from .content_audit import parse_html
from .models import QualityCheck, RemoteConfig
from .prompting import build_prompt
from .providers import AnalysisProvider, GeminiProvider, ProviderError, ProviderTemporaryError
from .research import EvidenceRetriever
from .validation import UnsafeProposalError, validate_proposal
from .wordpress import WordPressAPIError, WordPressClient

LOGGER = logging.getLogger("ceia")
MANAGED_HOST = "www.unioviedo.es"
MANAGED_PATH_PREFIX = "/cestudiantes/"
SAFE_EVALUATION_MAX_JOBS = 1


def _is_managed_item_url(url: str) -> bool:
    parsed = urllib.parse.urlsplit(url.strip())
    path = parsed.path or "/"
    return (
        parsed.scheme.lower() == "https"
        and (parsed.hostname or "").lower().rstrip(".") == MANAGED_HOST
        and (path.rstrip("/") == "/cestudiantes" or path.startswith(MANAGED_PATH_PREFIX))
    )


class Worker:
    def __init__(self, wordpress: WordPressClient, provider: AnalysisProvider | None = None):
        self.wordpress = wordpress
        self.config: RemoteConfig = wordpress.config()
        self.provider = provider or GeminiProvider(
            self.config.gemini_api_key,
            self.config.gemini_model,
        )

    def run(self, max_jobs: int | None = None) -> dict[str, int]:
        # La versión 0.12 permanece en evaluación controlada. Ni WordPress ni un
        # workflow antiguo pueden ampliar este límite hasta que se retire de forma
        # expresa después de superar el corpus de referencia.
        limit = SAFE_EVALUATION_MAX_JOBS
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
                LOGGER.error("Fallo temporal job=%s: %s", job_id, str(exc)[:1000])
                self.wordpress.fail(job_id, "worker_temporary", str(exc))
            except (ProviderError, UnsafeProposalError) as exc:
                stats["failed"] += 1
                LOGGER.error("Fallo de investigación job=%s: %s", job_id, str(exc)[:1000])
                self.wordpress.fail(job_id, "research_validation_failed", str(exc))
            except Exception as exc:
                stats["failed"] += 1
                safe_message = f"{type(exc).__name__}: {str(exc)[:800]}"
                LOGGER.error("Fallo inesperado job=%s: %s", job_id, safe_message)
                try:
                    self.wordpress.fail(job_id, "worker_temporary", safe_message)
                except WordPressAPIError:
                    LOGGER.error("No se pudo devolver el fallo a WordPress")

        self.wordpress.heartbeat(
            __version__,
            self.provider.name,
            f"claimed={stats['claimed']};completed={stats['completed']};failed={stats['failed']}",
        )
        return stats

    def _process(self, context: dict[str, Any]) -> None:
        item = context.get("item") or {}
        job_id = str((context.get("job") or {}).get("id", ""))
        item_url = str(item.get("url", ""))
        LOGGER.info("Investigando item=%s job=%s", item.get("id"), job_id)

        if not _is_managed_item_url(item_url):
            raise ProviderError("El trámite está fuera del alcance permitido.")

        retriever = EvidenceRetriever(self.config)
        evidence, retrieval_notes = retriever.collect(context)
        usable = [
            entry
            for entry in evidence
            if entry.retrieval_status == "ok"
            and entry.relevance_score >= 35
            and entry.excerpt.strip()
        ]
        if not usable:
            raise ProviderError("No se recuperó ninguna fuente oficial relevante y utilizable.")

        prompt = build_prompt(context, evidence, self.config, retrieval_notes)
        proposal = self.provider.analyze(prompt)
        current_content = str((context.get("post") or {}).get("content", ""))

        try:
            report = validate_proposal(
                proposal,
                evidence,
                str(item.get("risk", "medium")),
                current_content,
            )
        except UnsafeProposalError as exc:
            repair_prompt = (
                prompt
                + "\n\nCORRECCIÓN TÉCNICA OBLIGATORIA\n"
                + "La respuesta anterior fue rechazada: "
                + str(exc)
                + "\nDevuelve el JSON completo y corrige solo proposed_content sin añadir hechos ni URL."
            )
            proposal = self.provider.analyze(repair_prompt)
            report = validate_proposal(
                proposal,
                evidence,
                str(item.get("risk", "medium")),
                current_content,
            )

        previews: list[dict[str, Any]] = []
        if proposal.change_required:
            proposed_parsed = parse_html(proposal.proposed_content)
            current_parsed = parse_html(current_content)
            current_urls = {
                urllib.parse.urljoin(item_url, url) if url.startswith("/") else url
                for url in current_parsed.links
            }
            evidence_urls = {str(entry.url) for entry in usable}
            link_results, link_errors, link_warnings = retriever.audit_links(
                proposed_parsed.links,
                current_urls,
                evidence_urls,
            )
            report.quality.link_results = link_results
            report.errors.extend(link_errors)
            report.warnings.extend(link_warnings)
            report.quality.checks.append(
                QualityCheck(
                    check_id="links",
                    label="Enlaces",
                    status="blocked" if link_errors else ("warning" if link_warnings else "pass"),
                    detail="; ".join((link_errors or link_warnings)[:8])
                    or "Todos los enlaces comprobados son válidos y trazables.",
                )
            )

            if not report.errors and not proposal.conflicts:
                try:
                    previews, responsive_results, responsive_errors = render_responsive(
                        proposal.proposed_content
                    )
                    report.quality.responsive_results = responsive_results
                    report.errors.extend(responsive_errors)
                    report.quality.checks.append(
                        QualityCheck(
                            check_id="responsive",
                            label="Renderizado responsive real",
                            status="blocked" if responsive_errors else "pass",
                            detail="; ".join(responsive_errors[:8])
                            or "Superadas las vistas de 360, 390, 768 y 1440 píxeles.",
                        )
                    )
                except BrowserAuditError as exc:
                    report.errors.append(str(exc))
                    report.quality.checks.append(
                        QualityCheck(
                            check_id="responsive",
                            label="Renderizado responsive real",
                            status="blocked",
                            detail=str(exc),
                        )
                    )

        if proposal.conflicts:
            report.status = "conflict"
        elif report.errors:
            report.status = "insufficient_evidence"
        elif report.warnings:
            report.status = "verified_with_observations"
        else:
            report.status = "verified"

        report.quality.gate = (
            "pass"
            if proposal.change_required
            and report.status == "verified"
            and not report.errors
            and not proposal.conflicts
            else ("not_applicable" if not proposal.change_required else "blocked")
        )

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
        payload["quality_report"] = report.quality.model_dump(mode="json")
        payload["publication_gate"] = report.quality.gate
        payload["previews"] = previews

        observations = report.errors + report.warnings + retrieval_notes
        if observations:
            payload["summary"] = (
                proposal.summary.rstrip()
                + "\n\nControles automáticos:\n- "
                + "\n- ".join(observations[:40])
            )[:20_000]

        self.wordpress.submit_result(job_id, payload)
