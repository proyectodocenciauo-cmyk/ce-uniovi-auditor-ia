from __future__ import annotations

import re
import urllib.parse
from dataclasses import dataclass, field

from .models import EvidenceRecord, ModelProposal, ValidationStatus


class UnsafeProposalError(RuntimeError):
    pass


@dataclass
class ValidationReport:
    status: ValidationStatus
    risk: str
    warnings: list[str] = field(default_factory=list)
    errors: list[str] = field(default_factory=list)


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


def _max_risk(left: str, right: str) -> str:
    return left if RISK_ORDER.get(left, 1) >= RISK_ORDER.get(right, 1) else right


def _organisation_domain(url: str) -> str:
    host = (urllib.parse.urlsplit(url).hostname or "").lower().removeprefix("www.")
    for suffix in ("uniovi.es", "unioviedo.es", "boe.es", "asturias.es"):
        if host == suffix or host.endswith("." + suffix):
            return suffix
    return host


def validate_html(html: str) -> list[str]:
    if not html.strip():
        return []
    forbidden = {
        r"<!doctype": "DOCTYPE",
        r"</?(?:html|head|body)\b": "documento HTML completo",
        r"</?(?:script|iframe|object|embed|form|input|button|textarea|select|meta|link|base|video|audio|source|track|foreignobject|animate|set|image|use)\b": "etiqueta ejecutable o interactiva",
        r"</?span\b": "etiqueta span",
        r"\son[a-z]+\s*=": "manejador JavaScript",
        r"(?:javascript|vbscript|data)\s*:": "URL ejecutable o incrustada",
        r"@import\b": "importación CSS",
        r"expression\s*\(": "expresión CSS",
        r"url\s*\(": "recurso cargado desde CSS",
        r"position\s*:\s*fixed\b": "elemento fijo sobre la interfaz",
        r"xlink:href": "referencia SVG externa",
    }
    for pattern, label in forbidden.items():
        if re.search(pattern, html, flags=re.IGNORECASE):
            raise UnsafeProposalError(f"El HTML contiene {label}")

    root = re.search(r"<section\b[^>]*\bid=[\"']([A-Za-z][A-Za-z0-9_-]*)[\"']", html, flags=re.IGNORECASE)
    if not root:
        raise UnsafeProposalError("Falta una sección raíz con id único")
    root_id = root.group(1)

    for url in re.findall(r"\b(?:href|src)\s*=\s*[\"']([^\"']+)[\"']", html, flags=re.IGNORECASE):
        if url.startswith(("#", "mailto:", "tel:")):
            continue
        parsed = urllib.parse.urlsplit(url)
        if parsed.scheme != "https":
            raise UnsafeProposalError("Todos los enlaces web deben usar HTTPS")

    style_blocks = re.findall(r"<style\b[^>]*>(.*?)</style>", html, flags=re.IGNORECASE | re.DOTALL)
    for css in style_blocks:
        if re.search(r"(^|[},])\s*(?:html|body|:root)\b", css, flags=re.IGNORECASE):
            raise UnsafeProposalError("El CSS no puede modificar selectores globales")
        # Acepta @media y @supports; el resto de reglas debe mencionar el id raíz.
        for selector in re.findall(r"([^{}]+)\{", css):
            selector = selector.strip()
            if not selector or selector.startswith("@"):
                continue
            if f"#{root_id}" not in selector:
                raise UnsafeProposalError("El CSS contiene un selector fuera de la sección raíz")

    warnings: list[str] = []
    if "@media" not in html:
        warnings.append("La propuesta no incluye una adaptación móvil explícita mediante @media.")
    if re.search(r"\b(?:actualmente|ahora)\s+(?:no\s+)?(?:está|esta)\s+(?:abiert|cerrad)", html, flags=re.IGNORECASE):
        warnings.append("La redacción contiene un estado temporal de apertura o cierre.")
    if re.search(r"\bcurso\s+(?:acad[eé]mico\s+)?20\d{2}\s*[-/]\s*20\d{2}\b", html, flags=re.IGNORECASE):
        warnings.append("La propuesta contiene una referencia específica a curso académico.")
    return warnings


def validate_proposal(
    proposal: ModelProposal,
    evidence: list[EvidenceRecord],
    item_risk: str,
) -> ValidationReport:
    warnings = validate_html(proposal.proposed_content)
    errors: list[str] = []
    evidence_map = {entry.local_id: entry for entry in evidence}
    available = {entry.local_id for entry in evidence if entry.http_status == 200 and entry.excerpt.strip()}

    invalid_citations = sorted(set(proposal.citations) - set(evidence_map))
    if invalid_citations:
        errors.append("Citas inexistentes: " + ", ".join(invalid_citations))

    referenced_elsewhere = {
        evidence_id
        for change in proposal.changes
        for evidence_id in change.evidence_ids
    } | {
        evidence_id
        for conflict in proposal.conflicts
        for evidence_id in conflict.evidence_ids
    }
    invalid_references = sorted(referenced_elsewhere - set(evidence_map))
    if invalid_references:
        errors.append("Referencias de cambio o conflicto inexistentes: " + ", ".join(invalid_references))

    risk = _max_risk(item_risk, proposal.risk)
    for fact in proposal.facts:
        cited = [evidence_map[eid] for eid in fact.evidence_ids if eid in available]
        if not cited:
            errors.append(f"El hecho {fact.fact_id} no tiene evidencia recuperada utilizable.")
            continue
        if fact.fact_type in CRITICAL_FACTS:
            risk = _max_risk(risk, "high")
            authoritative = [entry for entry in cited if entry.authority >= 85]
            if not authoritative:
                errors.append(f"El hecho crítico {fact.fact_id} no está respaldado por una fuente oficial.")
                continue
            hosts = {_organisation_domain(str(entry.url)) for entry in authoritative}
            has_primary_norm = any(entry.authority >= 100 for entry in authoritative)
            if fact.fact_type in {"deadline", "amount", "eligibility", "procedure"} and len(hosts) < 2:
                warnings.append(f"El hecho crítico {fact.fact_id} solo se ha confirmado en una fuente oficial independiente.")
            if fact.fact_type == "legal_basis" and not has_primary_norm:
                warnings.append(f"La base jurídica {fact.fact_id} no se ha cotejado con un boletín oficial.")

    if proposal.conflicts:
        status: ValidationStatus = "conflict"
    elif errors:
        status = "insufficient_evidence"
    elif proposal.validation_status in {"conflict", "insufficient_evidence", "human_review"}:
        status = proposal.validation_status
    elif warnings:
        status = "verified_with_observations"
    else:
        status = "verified"

    if proposal.change_required and not proposal.proposed_content.strip() and not proposal.index_patch.model_dump(exclude_none=True):
        errors.append("Se declaró un cambio, pero no se proporcionó contenido ni parche del índice.")
        status = "insufficient_evidence"
    if not evidence:
        errors.append("No se conservó ninguna evidencia.")
        status = "insufficient_evidence"

    return ValidationReport(status=status, risk=risk, warnings=warnings, errors=errors)
