from __future__ import annotations

import re
import unicodedata
import urllib.parse
from dataclasses import dataclass, field
from difflib import SequenceMatcher
from html.parser import HTMLParser
from typing import Iterable

from .models import Change

CRITICAL_TERMS = (
    "plazo", "fecha", "requisit", "document", "solicitud", "recurso", "alegacion", "contacto",
    "tramita", "resuelve", "importe", "precio", "pago", "matricula", "admision", "acceso",
    "beneficiari", "incompatibilidad", "obligacion", "normativa", "reglamento", "resolucion",
)
BLOCK_TAGS = {"h1", "h2", "h3", "h4", "h5", "h6", "p", "li", "td", "th", "summary", "dt", "dd"}


def normalize_text(value: str) -> str:
    value = unicodedata.normalize("NFKD", value.lower()).encode("ascii", "ignore").decode("ascii")
    value = re.sub(r"\s+", " ", value)
    return value.strip()


@dataclass
class ParsedHTML:
    blocks: list[str] = field(default_factory=list)
    links: list[str] = field(default_factory=list)
    ids: list[str] = field(default_factory=list)
    headings: list[int] = field(default_factory=list)
    issues: list[str] = field(default_factory=list)


class AuditHTMLParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.result = ParsedHTML()
        self._tag: str | None = None
        self._buf: list[str] = []

    def handle_starttag(self, tag, attrs):
        tag = tag.lower()
        amap = {key.lower(): (value or "") for key, value in attrs}
        if amap.get("id"):
            self.result.ids.append(amap["id"])
        if tag == "a":
            href = amap.get("href", "").strip()
            if not href or href == "#":
                self.result.issues.append("Hay un enlace vacío o con destino #.")
            else:
                self.result.links.append(href)
            if amap.get("target") == "_blank":
                rel = {value.lower() for value in amap.get("rel", "").split()}
                if not {"noopener", "noreferrer"}.issubset(rel):
                    self.result.issues.append('Un enlace target="_blank" no incluye rel="noopener noreferrer".')
        if tag == "img" and not amap.get("alt", "").strip():
            self.result.issues.append("Hay una imagen sin texto alternativo.")
        if tag in BLOCK_TAGS:
            self._flush()
            self._tag = tag
            self._buf = []
            if tag.startswith("h") and tag[1:].isdigit():
                self.result.headings.append(int(tag[1:]))

    def handle_endtag(self, tag):
        if self._tag == tag.lower():
            self._flush()

    def handle_data(self, data):
        if self._tag:
            self._buf.append(data)

    def _flush(self):
        if self._tag:
            text = re.sub(r"\s+", " ", " ".join(self._buf)).strip()
            if text:
                self.result.blocks.append(text)
        self._tag = None
        self._buf = []

    def close(self):
        self._flush()
        super().close()


def parse_html(html: str) -> ParsedHTML:
    parser = AuditHTMLParser()
    parser.feed(html or "")
    parser.close()
    duplicates = sorted({value for value in parser.result.ids if parser.result.ids.count(value) > 1})
    if duplicates:
        parser.result.issues.append("Identificadores duplicados: " + ", ".join(duplicates[:10]))
    for before, after in zip(parser.result.headings, parser.result.headings[1:]):
        if after > before + 1:
            parser.result.issues.append(f"La jerarquía de encabezados salta de h{before} a h{after}.")
            break
    return parser.result


def _match_ratio(left: str, right: str) -> float:
    return SequenceMatcher(None, normalize_text(left), normalize_text(right), autojunk=False).ratio()


def _declared(block: str, changes: Iterable[Change]) -> bool:
    for change in changes:
        if not change.evidence_ids:
            continue
        if _match_ratio(block, change.current) >= 0.48:
            reason = normalize_text(change.reason)
            if any(word in reason for word in ("obsoleto", "sustitu", "elimina", "corrige", "actualiza")):
                return True
    return False


def semantic_audit(current_html: str, proposed_html: str, changes: list[Change]) -> dict:
    current = parse_html(current_html)
    proposed = parse_html(proposed_html)
    current_blocks = [block for block in current.blocks if len(normalize_text(block)) >= 12]
    proposed_blocks = [block for block in proposed.blocks if len(normalize_text(block)) >= 12]

    removed: list[str] = []
    retained_weight = 0
    total_weight = sum(len(normalize_text(block)) for block in current_blocks) or 1
    for block in current_blocks:
        best = max((_match_ratio(block, candidate) for candidate in proposed_blocks), default=0.0)
        if best >= 0.62:
            retained_weight += len(normalize_text(block))
        else:
            removed.append(block)

    added: list[str] = []
    for block in proposed_blocks:
        best = max((_match_ratio(block, candidate) for candidate in current_blocks), default=0.0)
        if best < 0.62:
            added.append(block)

    retention = 1.0 if not current_blocks else min(1.0, retained_weight / total_weight)
    critical_removed = [
        block
        for block in removed
        if any(term in normalize_text(block) for term in CRITICAL_TERMS) and not _declared(block, changes)
    ]

    current_links = {
        urllib.parse.urljoin("https://www.unioviedo.es", link) if link.startswith("/") else link
        for link in current.links
    }
    proposed_links = {
        urllib.parse.urljoin("https://www.unioviedo.es", link) if link.startswith("/") else link
        for link in proposed.links
    }
    removed_links = sorted(current_links - proposed_links)
    added_links = sorted(proposed_links - current_links)

    errors = list(proposed.issues)
    warnings: list[str] = []
    if retention < 0.80:
        errors.append(f"La propuesta conserva solo el {retention:.0%} del contenido textual; el mínimo es 80%.")
    if critical_removed:
        errors.append(
            "Se eliminan bloques críticos sin justificación individual y evidencia: "
            + " | ".join(critical_removed[:5])
        )
    internal_removed = [url for url in removed_links if "/cestudiantes/" in url]
    if internal_removed:
        errors.append("Se eliminan enlaces internos del Consejo: " + ", ".join(internal_removed[:10]))
    elif removed_links:
        warnings.append("Se eliminan enlaces existentes que deben comprobarse: " + ", ".join(removed_links[:10]))
    if not proposed_html.strip():
        errors.append("La propuesta de contenido está vacía.")

    return {
        "retention_ratio": round(retention, 4),
        "removed_blocks": removed[:30],
        "added_blocks": added[:30],
        "removed_links": removed_links[:50],
        "added_links": added_links[:50],
        "errors": errors,
        "warnings": warnings,
    }
