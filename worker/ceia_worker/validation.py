from __future__ import annotations

import re
import urllib.parse
from dataclasses import dataclass, field

from .content_audit import normalize_text, semantic_audit
from .models import Conflict, EvidenceRecord, ModelProposal, QualityCheck, QualityReport, ValidationStatus


class UnsafeProposalError(RuntimeError):
    pass


@dataclass
class ValidationReport:
    status: ValidationStatus
    risk: str
    warnings: list[str] = field(default_factory=list)
    errors: list[str] = field(default_factory=list)
    quality: QualityReport = field(default_factory=QualityReport)


RISK_ORDER = {"low": 0, "medium": 1, "high": 2, "critical": 3}
CRITICAL_FACTS = {
    "deadline",
    "amount",
    "eligibility",
    "legal_basis",
    "procedure",
    "competent_body",
    "contact",
}
FORBIDDEN_INTERACTIVE_TAGS = {
    "script",
    "iframe",
    "object",
    "embed",
    "form",
    "input",
    "button",
    "textarea",
    "select",
    "meta",
    "link",
    "base",
    "video",
    "audio",
    "source",
    "track",
    "foreignobject",
    "animate",
    "set",
    "image",
    "use",
}


def _max_risk(left: str, right: str) -> str:
    return left if RISK_ORDER.get(left, 1) >= RISK_ORDER.get(right, 1) else right


def _organisation_domain(url: str) -> str:
    host = (urllib.parse.urlsplit(url).hostname or "").lower().removeprefix("www.")
    if host.endswith("uniovi.es") or host.endswith("unioviedo.es"):
        return "universidad-oviedo"
    for suffix in ("boe.es", "asturias.es"):
        if host == suffix or host.endswith("." + suffix):
            return suffix
    return host


def _norm_contains(haystack: str, needle: str) -> bool:
    return normalize_text(needle) in normalize_text(haystack)


def _claim_overlap(claim: str, quote: str) -> float:
    stop = {
        "para",
        "como",
        "desde",
        "hasta",
        "sobre",
        "entre",
        "esta",
        "este",
        "estos",
        "estas",
        "debe",
        "puede",
        "seran",
        "sera",
        "universidad",
        "oviedo",
    }
    claim_words = {
        word for word in re.findall(r"[a-z0-9@.]{4,}", normalize_text(claim)) if word not in stop
    }
    quote_words = set(re.findall(r"[a-z0-9@.]{4,}", normalize_text(quote)))
    if not claim_words:
        return 0.0
    return len(claim_words & quote_words) / len(claim_words)


def validate_html(html: str) -> list[str]:
    if not html.strip():
        return []

    tag_pattern = r"</?(" + "|".join(sorted(FORBIDDEN_INTERACTIVE_TAGS, key=len, reverse=True)) + r")\b"
    match = re.search(tag_pattern, html, flags=re.IGNORECASE)
    if match:
        raise UnsafeProposalError(f"El HTML contiene la etiqueta no permitida <{match.group(1).lower()}>.")

    forbidden = {
        r"<!doctype": "DOCTYPE",
        r"</?(?:html|head|body)\b": "documento HTML completo",
        r"</?span\b": "etiqueta span",
        r"\son[a-z]+\s*=": "manejador JavaScript",
        r"(?:javascript|vbscript|data)\s*:": "URL ejecutable o incrustada",
        r"@import\b": "importación CSS",
        r"expression\s*\(": "expresión CSS",
        r"url\s*\(": "recurso cargado desde CSS",
        r"position\s*:\s*fixed\b": "elemento fijo",
        r"xlink:href": "referencia SVG externa",
    }
    for pattern, label in forbidden.items():
        if re.search(pattern, html, flags=re.IGNORECASE):
            raise UnsafeProposalError(f"El HTML contiene {label}")

    root = re.search(
        r"<section\b[^>]*\bid=[\"']([A-Za-z][A-Za-z0-9_-]*)[\"']",
        html,
        flags=re.IGNORECASE,
    )
    if not root:
        raise UnsafeProposalError("Falta una sección raíz con id único")
    root_id = root.group(1)

    ids = re.findall(r"\bid=[\"']([^\"']+)[\"']", html, flags=re.IGNORECASE)
    duplicates = sorted({value for value in ids if ids.count(value) > 1})
    if duplicates:
        raise UnsafeProposalError("Hay identificadores HTML duplicados: " + ", ".join(duplicates[:10]))

    for url in re.findall(r"\b(?:href|src)\s*=\s*[\"']([^\"']*)[\"']", html, flags=re.IGNORECASE):
        if not url or url == "#":
            raise UnsafeProposalError("Hay un enlace vacío o con destino #")
        if url.startswith(("#", "mailto:", "tel:", "/")):
            continue
        if urllib.parse.urlsplit(url).scheme != "https":
            raise UnsafeProposalError("Todos los enlaces web deben usar HTTPS")

    for css in re.findall(r"<style\b[^>]*>(.*?)</style>", html, flags=re.IGNORECASE | re.DOTALL):
        if re.search(r"(^|[},])\s*(?:html|body|:root)\b", css, flags=re.IGNORECASE):
            raise UnsafeProposalError("El CSS no puede modificar selectores globales")
        for selector in re.findall(r"([^{}]+)\{", css):
            selector = selector.strip()
            if selector and not selector.startswith("@") and f"#{root_id}" not in selector:
                raise UnsafeProposalError("El CSS contiene un selector fuera de la sección raíz")

    warnings: list[str] = []
    if "@media" not in html:
        warnings.append("La propuesta no incluye adaptación móvil mediante @media.")
    return warnings


def validate_proposal(
    proposal: ModelProposal,
    evidence: list[EvidenceRecord],
    item_risk: str,
    current_content: str = "",
) -> ValidationReport:
    warnings = validate_html(proposal.proposed_content)
    errors: list[str] = []
    checks: list[QualityCheck] = []
    evidence_map = {entry.local_id: entry for entry in evidence}
    usable = {
        entry.local_id: entry
        for entry in evidence
        if entry.http_status == 200
        and entry.excerpt.strip()
        and entry.retrieval_status == "ok"
        and entry.relevance_score >= 35
    }

    required_failures = [entry for entry in evidence if entry.required and entry.local_id not in usable]
    primary = [entry for entry in usable.values() if entry.primary and entry.authority >= 85]
    if required_failures:
        detail = "No se pudieron validar fuentes obligatorias: " + ", ".join(
            str(entry.url) for entry in required_failures[:10]
        )
        errors.append(detail)
        checks.append(
            QualityCheck(
                check_id="required_sources",
                label="Fuentes obligatorias",
                status="blocked",
                detail=detail,
            )
        )
    else:
        checks.append(
            QualityCheck(
                check_id="required_sources",
                label="Fuentes obligatorias",
                status="pass",
                detail="No hay fallos de lectura en fuentes marcadas como obligatorias.",
            )
        )

    if proposal.change_required and not primary:
        detail = "No se recuperó una fuente primaria oficial y relevante para justificar cambios."
        errors.append(detail)
        checks.append(
            QualityCheck(
                check_id="primary_source",
                label="Fuente primaria",
                status="blocked",
                detail=detail,
            )
        )
    else:
        checks.append(
            QualityCheck(
                check_id="primary_source",
                label="Fuente primaria",
                status="pass",
                detail="; ".join(str(entry.url) for entry in primary[:5]) or "No aplicable.",
            )
        )

    invalid_citations = sorted(set(proposal.citations) - set(evidence_map))
    if invalid_citations:
        errors.append("Citas inexistentes: " + ", ".join(invalid_citations))

    risk = _max_risk(item_risk, proposal.risk)
    detected_conflicts: list[str] = []
    values_by_type: dict[str, set[str]] = {}

    for fact in proposal.facts:
        cited_ids = set(fact.evidence_ids) | {support.evidence_id for support in fact.supports}
        fact.evidence_ids = sorted(cited_ids)
        valid_supports: list[EvidenceRecord] = []
        contradicted = False

        for support in fact.supports:
            entry = usable.get(support.evidence_id)
            if not entry:
                errors.append(
                    f"El hecho {fact.fact_id} cita {support.evidence_id}, que no es evidencia utilizable y relevante."
                )
                continue
            if not _norm_contains(entry.excerpt, support.quote):
                errors.append(
                    f"La cita textual del hecho {fact.fact_id} no aparece en {support.evidence_id}."
                )
                continue
            overlap = _claim_overlap(fact.claim + " " + fact.value, support.quote)
            if overlap < 0.16 and fact.value and not _norm_contains(support.quote, fact.value):
                errors.append(
                    f"El pasaje {support.evidence_id} no respalda de forma concreta el hecho {fact.fact_id}."
                )
                continue
            if support.relation == "contradicts":
                contradicted = True
            if support.relation == "supports":
                valid_supports.append(entry)

        if contradicted:
            fact.support_status = "conflict"
            fact.confidence = 0.0
            fact.technical_confidence = 0.0
            fact.legal_confidence = 0.0
            detected_conflicts.append(f"{fact.fact_id}: existe una evidencia marcada como contradictoria.")
        elif not valid_supports:
            fact.support_status = "unsupported"
            fact.confidence = 0.0
            fact.technical_confidence = 0.0
            fact.legal_confidence = 0.0
            errors.append(f"El hecho {fact.fact_id} no tiene citas textuales verificables.")
        else:
            urls = {str(entry.url) for entry in valid_supports}
            organisations = {_organisation_domain(str(entry.url)) for entry in valid_supports}
            average_relevance = sum(entry.relevance_score for entry in valid_supports) / len(valid_supports)
            technical = min(0.95, 0.45 + 0.12 * len(urls) + 0.003 * average_relevance)
            legal = technical

            if fact.fact_type in CRITICAL_FACTS:
                risk = _max_risk(risk, "high")
                official = [entry for entry in valid_supports if entry.authority >= 85]
                primary_official = [
                    entry
                    for entry in official
                    if entry.authority >= 95 or entry.source_type == "official_gazette"
                ]
                if len(urls) < 2 or len(official) < 2 or not primary_official:
                    errors.append(
                        f"El hecho crítico {fact.fact_id} necesita dos fuentes oficiales distintas y al menos una fuente primaria."
                    )
                    fact.support_status = "partially_supported"
                    legal = min(legal, 0.45)
                else:
                    fact.support_status = "supported"
                    legal = min(0.95, 0.62 + 0.08 * len(organisations) + 0.05 * len(primary_official))
            else:
                fact.support_status = "supported"

            fact.technical_confidence = round(technical, 2)
            fact.legal_confidence = round(legal, 2)
            fact.confidence = round(min(technical, legal), 2)

        value = normalize_text(fact.value)
        if value:
            values_by_type.setdefault(fact.fact_type, set()).add(value)

    for fact_type, values in values_by_type.items():
        if fact_type in CRITICAL_FACTS and len(values) > 1:
            detected_conflicts.append(
                f"Valores incompatibles detectados para {fact_type}: " + " | ".join(sorted(values)[:6])
            )

    for message in detected_conflicts:
        proposal.conflicts.append(
            Conflict(
                topic="Conflicto detectado automáticamente",
                statements=[message, "La propuesta no puede elegir una versión sin revisión."],
                evidence_ids=list(usable)[:2] or ["E000", "E001"],
                recommended_resolution="Revisar las fuentes primarias y repetir la investigación.",
            )
        )

    if proposal.change_required:
        content = semantic_audit(current_content, proposal.proposed_content, proposal.changes)
    else:
        content = {
            "retention_ratio": 1.0,
            "removed_blocks": [],
            "added_blocks": [],
            "removed_links": [],
            "added_links": [],
            "errors": [],
            "warnings": [],
        }
    errors.extend(content["errors"])
    warnings.extend(content["warnings"])
    checks.append(
        QualityCheck(
            check_id="content_retention",
            label="Conservación del contenido",
            status="blocked" if content["errors"] else "pass",
            detail=f"Retención textual: {content['retention_ratio']:.0%}.",
        )
    )

    if proposal.index_patch.model_dump(exclude_none=True):
        index_changes = [
            change
            for change in proposal.changes
            if "indice" in normalize_text(change.section) or "índice" in change.section.lower()
        ]
        if not index_changes or not all(change.evidence_ids for change in index_changes):
            errors.append("El parche del índice no está justificado por un cambio específico con evidencias.")

    if proposal.conflicts or detected_conflicts:
        status: ValidationStatus = "conflict"
    elif errors:
        status = "insufficient_evidence"
    elif proposal.validation_status in {"conflict", "insufficient_evidence", "human_review"}:
        status = proposal.validation_status
    elif warnings:
        status = "verified_with_observations"
    else:
        status = "verified"

    if (
        proposal.change_required
        and not proposal.proposed_content.strip()
        and not proposal.index_patch.model_dump(exclude_none=True)
    ):
        errors.append("Se declaró un cambio, pero no se proporcionó contenido ni parche del índice.")
        status = "insufficient_evidence"

    gate = (
        "pass"
        if proposal.change_required and not errors and not proposal.conflicts and status == "verified"
        else ("not_applicable" if not proposal.change_required else "blocked")
    )
    quality = QualityReport(
        gate=gate,
        checks=checks,
        retention_ratio=content["retention_ratio"],
        removed_blocks=content["removed_blocks"],
        added_blocks=content["added_blocks"],
        removed_links=content["removed_links"],
        added_links=content["added_links"],
        detected_conflicts=detected_conflicts,
        primary_sources=[str(entry.url) for entry in primary],
        required_source_failures=[str(entry.url) for entry in required_failures],
    )
    return ValidationReport(
        status=status,
        risk=risk,
        warnings=warnings,
        errors=errors,
        quality=quality,
    )
