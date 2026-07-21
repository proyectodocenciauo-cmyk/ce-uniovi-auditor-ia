from __future__ import annotations

import hashlib
import io
import re
import unicodedata
import urllib.parse
from dataclasses import dataclass
from datetime import UTC, date, datetime
from html.parser import HTMLParser
from typing import Any

from pypdf import PdfReader

from .http import NetworkError, SafeFetcher
from .models import EvidenceRecord, RemoteConfig
from .tavily import TavilyDiscovery, TavilyError

OFFICIAL_HOSTS = {"uniovi.es", "unioviedo.es", "boe.es", "asturias.es"}
PRIMARY_MARKERS = (
    "convocatoria",
    "resolucion",
    "reglamento",
    "normativa",
    "bases",
    "plazos",
    "procedimiento",
    "solicitud",
    "bopa",
    "boe",
)
STOP = {
    "para",
    "como",
    "desde",
    "hasta",
    "sobre",
    "entre",
    "universidad",
    "oviedo",
    "tramite",
    "solicitud",
    "estudios",
    "estudiantes",
    "pagina",
    "informacion",
}


class ExtractionError(RuntimeError):
    pass


@dataclass
class LinkRef:
    url: str
    text: str


class PublicHTMLParser(HTMLParser):
    def __init__(self, base_url: str):
        super().__init__(convert_charrefs=True)
        self.base_url = base_url
        self.skip_depth = 0
        self.text_parts: list[str] = []
        self.title_parts: list[str] = []
        self.in_title = False
        self.links: list[LinkRef] = []
        self._link_url = ""
        self._link_text: list[str] = []
        self.published_date: str | None = None

    def handle_starttag(self, tag, attrs):
        tag = tag.lower()
        attributes = {key.lower(): (value or "") for key, value in attrs}
        if tag in {"script", "style", "noscript", "svg", "template"}:
            self.skip_depth += 1
            return
        if self.skip_depth:
            return
        if tag == "title":
            self.in_title = True
        if tag in {"p", "li", "h1", "h2", "h3", "h4", "h5", "h6", "tr", "br", "section", "article"}:
            self.text_parts.append("\n")
        if tag == "a" and attributes.get("href"):
            url = urllib.parse.urljoin(self.base_url, attributes["href"])
            if url.startswith("https://"):
                self._link_url = url
                self._link_text = []
        if tag == "meta":
            key = (attributes.get("property") or attributes.get("name") or "").lower()
            if key in {"article:published_time", "date", "dc.date", "dcterms.date", "citation_publication_date"}:
                self.published_date = attributes.get("content") or self.published_date

    def handle_endtag(self, tag):
        tag = tag.lower()
        if tag in {"script", "style", "noscript", "svg", "template"} and self.skip_depth:
            self.skip_depth -= 1
            return
        if tag == "title":
            self.in_title = False
        if tag == "a" and self._link_url:
            self.links.append(LinkRef(self._link_url, " ".join(self._link_text).strip()))
            self._link_url = ""
            self._link_text = []
        if tag in {"p", "li", "h1", "h2", "h3", "h4", "h5", "h6", "tr", "section", "article"}:
            self.text_parts.append("\n")

    def handle_data(self, data):
        if self.skip_depth:
            return
        clean = re.sub(r"\s+", " ", data).strip()
        if not clean:
            return
        self.text_parts.append(clean + " ")
        if self.in_title:
            self.title_parts.append(clean)
        if self._link_url:
            self._link_text.append(clean)

    @property
    def text(self) -> str:
        lines = [re.sub(r"\s+", " ", value).strip() for value in "".join(self.text_parts).splitlines()]
        return "\n".join(value for value in lines if value)

    @property
    def title(self) -> str:
        return " ".join(self.title_parts).strip()


@dataclass
class Candidate:
    url: str
    title: str
    source_id: int
    source_type: str
    authority: int
    origin: str
    required: bool = False
    primary: bool = False


def _norm(value: str) -> str:
    return unicodedata.normalize("NFKD", value.lower()).encode("ascii", "ignore").decode("ascii")


def _words(value: str) -> list[str]:
    return [word for word in re.findall(r"[a-z0-9]{4,}", _norm(value)) if word not in STOP]


def _canonical(url: str) -> str:
    parsed = urllib.parse.urlsplit(url)
    query = [
        (key, value)
        for key, value in urllib.parse.parse_qsl(parsed.query, keep_blank_values=True)
        if not key.lower().startswith("utm_") and key.lower() not in {"fbclid", "gclid"}
    ]
    return urllib.parse.urlunsplit(
        (
            parsed.scheme.lower(),
            parsed.netloc.lower(),
            re.sub(r"/{2,}", "/", parsed.path or "/"),
            urllib.parse.urlencode(query),
            "",
        )
    )


def _host(url: str) -> str:
    return (urllib.parse.urlsplit(url).hostname or "").lower().rstrip(".")


def _allowed_host(host: str) -> bool:
    return any(host == base or host.endswith("." + base) for base in OFFICIAL_HOSTS)


def _source_type(url: str) -> str:
    host = _host(url)
    if host in {"boe.es", "www.boe.es"} or host.endswith("asturias.es"):
        return "official_gazette"
    if host in {"sede.uniovi.es", "euniovi.uniovi.es"}:
        return "official_registry"
    if host.endswith("uniovi.es"):
        return "council" if "/cestudiantes/" in url else "institutional"
    if host.endswith("unioviedo.es") and "/cestudiantes/" in url:
        return "council"
    return "external_lead"


def _authority(url: str) -> int:
    return {
        "official_gazette": 100,
        "official_registry": 95,
        "institutional": 85,
        "council": 75,
        "external_lead": 0,
    }[_source_type(url)]


def _parse_date(value: str | None, text: str = "") -> date | None:
    values = [(value or "")[:10]]
    values += ["-".join(match) for match in re.findall(r"\b(20\d{2})[-/](\d{1,2})[-/](\d{1,2})\b", text[:5000])]
    for raw in values:
        try:
            return date.fromisoformat(raw.replace("/", "-"))
        except ValueError:
            continue
    return None


def _extract_pdf(body: bytes) -> tuple[str, str]:
    try:
        reader = PdfReader(io.BytesIO(body))
        pages: list[str] = []
        total = 0
        for page in reader.pages[:250]:
            text = page.extract_text() or ""
            pages.append(text)
            total += len(text)
            if total >= 2_000_000:
                break
        return str((reader.metadata or {}).get("/Title", "") or ""), "\n".join(pages)
    except Exception as exc:
        raise ExtractionError("No se pudo extraer el PDF") from exc


def _excerpt(text: str, keywords: list[str], limit: int = 12000) -> str:
    if len(text) <= limit:
        return text
    rows: list[tuple[int, int, str]] = []
    for index, paragraph in enumerate(value.strip() for value in text.splitlines() if value.strip()):
        normalized = _norm(paragraph)
        score = sum(3 for word in keywords if word in normalized)
        score += sum(
            1
            for marker in ("plazo", "euros", "resolucion", "reglamento", "articulo", "requisitos", "contacto", "recurso")
            if marker in normalized
        )
        if score:
            rows.append((score, index, paragraph))
    if not rows:
        return text[:limit]
    chosen = sorted(rows, key=lambda row: (-row[0], row[1]))[:70]
    chosen.sort(key=lambda row: row[1])
    return "\n".join(row[2] for row in chosen)[:limit]


def _relevance(title: str, url: str, text: str, keywords: list[str], is_item: bool = False) -> int:
    if is_item:
        return 100
    haystack = _norm(title + " " + urllib.parse.unquote(url) + " " + text[:50000])
    unique = list(dict.fromkeys(keywords))[:12]
    matches = sum(1 for word in unique if word in haystack)
    coverage = matches / max(1, min(len(unique), 8))
    score = int(coverage * 75)
    path = _norm(urllib.parse.urlsplit(url).path)
    if any(word in _norm(title + " " + path) for word in unique):
        score += 10
    if any(marker in haystack for marker in PRIMARY_MARKERS):
        score += 10
    if _source_type(url) in {"official_gazette", "official_registry"}:
        score += 5
    return max(0, min(100, score))


class EvidenceRetriever:
    def __init__(self, config: RemoteConfig):
        self.config = config
        self.fetcher = SafeFetcher(OFFICIAL_HOSTS, config.limits.max_source_bytes)

    def collect(self, context: dict[str, Any]) -> tuple[list[EvidenceRecord], list[str]]:
        item = context.get("item") or {}
        title = str(item.get("title", ""))
        keywords = _words(title + " " + str(item.get("category", "")))
        candidates = self._initial(context, keywords)
        notes: list[str] = []
        evidence: list[EvidenceRecord] = []
        seen: set[str] = set()
        cursor = 0
        attempts = 0

        if self.config.tavily_enabled and self.config.tavily_api_key:
            try:
                candidates.extend(self._discover(title, str(item.get("category", ""))))
            except TavilyError as exc:
                notes.append("Tavily no estuvo disponible: " + str(exc)[:300])

        max_attempts = self.config.limits.max_sources_per_job
        while cursor < len(candidates) and attempts < max_attempts:
            candidate = candidates[cursor]
            cursor += 1
            url = _canonical(candidate.url)
            if url in seen or not _allowed_host(_host(url)):
                continue
            seen.add(url)
            attempts += 1
            local_id = f"E{len(evidence) + 1:03d}"
            now = datetime.now(UTC).strftime("%Y-%m-%d %H:%M:%S")
            try:
                response = self.fetcher.fetch(url)
                text = ""
                links: list[str] = []
                published: date | None = None
                resolved_title = candidate.title
                if response.status == 200:
                    if response.content_type == "application/pdf" or response.url.lower().split("?", 1)[0].endswith(".pdf"):
                        pdf_title, text = _extract_pdf(response.body)
                        resolved_title = pdf_title or resolved_title
                        published = _parse_date(None, text)
                    elif response.content_type in {"text/html", "application/xhtml+xml", ""}:
                        parser = PublicHTMLParser(response.url)
                        parser.feed(response.text())
                        parser.close()
                        text = parser.text
                        links = [link.url for link in parser.links]
                        resolved_title = parser.title or resolved_title
                        published = _parse_date(parser.published_date, text)
                    elif response.content_type.startswith("text/"):
                        text = response.text()
                        published = _parse_date(None, text)
                relevance = _relevance(resolved_title, response.url, text, keywords, candidate.origin == "item")
                status = (
                    "ok"
                    if response.status == 200 and text.strip() and relevance >= 35
                    else ("irrelevant" if response.status == 200 and text.strip() else "http_error")
                )
                record = EvidenceRecord(
                    local_id=local_id,
                    source_id=candidate.source_id,
                    url=response.url,
                    title=resolved_title,
                    source_type=candidate.source_type,
                    authority=candidate.authority,
                    retrieved_gmt=now,
                    published_date=published,
                    http_status=response.status,
                    content_hash=hashlib.sha256(response.body).hexdigest(),
                    excerpt=_excerpt(text, keywords) if status == "ok" else "",
                    text=text,
                    links=links,
                    relevance_score=relevance,
                    required=candidate.required,
                    primary=candidate.primary,
                    retrieval_status=status,
                    retrieval_error=(
                        ""
                        if status == "ok"
                        else ("Fuente irrelevante para el trámite" if status == "irrelevant" else f"HTTP {response.status}")
                    ),
                    origin=candidate.origin,
                    content_type=response.content_type,
                )
                evidence.append(record)
                if status == "ok":
                    for link in links[:80]:
                        if self._link_relevant(link, keywords) and _canonical(link) not in seen:
                            marker = _norm(link)
                            primary = any(value in marker for value in PRIMARY_MARKERS) and _authority(link) >= 85
                            candidates.append(
                                Candidate(
                                    link,
                                    "Enlace oficial relacionado",
                                    0,
                                    _source_type(link),
                                    _authority(link),
                                    "followed",
                                    required=primary,
                                    primary=primary,
                                )
                            )
            except (NetworkError, ExtractionError) as exc:
                evidence.append(
                    EvidenceRecord(
                        local_id=local_id,
                        source_id=candidate.source_id,
                        url=url,
                        title=candidate.title,
                        source_type=candidate.source_type,
                        authority=candidate.authority,
                        retrieved_gmt=now,
                        http_status=0,
                        content_hash="",
                        excerpt="",
                        relevance_score=0,
                        required=candidate.required,
                        primary=candidate.primary,
                        retrieval_status="network_error",
                        retrieval_error=str(exc)[:1000],
                        origin=candidate.origin,
                    )
                )
                notes.append(f"No se pudo leer {url}: {exc}")
        return evidence, notes

    def _initial(self, context: dict[str, Any], keywords: list[str]) -> list[Candidate]:
        item = context.get("item") or {}
        candidates: list[Candidate] = []
        if item.get("url"):
            candidates.append(
                Candidate(
                    str(item["url"]),
                    "Página publicada del trámite",
                    0,
                    _source_type(str(item["url"])),
                    _authority(str(item["url"])),
                    "item",
                    required=True,
                    primary=False,
                )
            )

        for source in context.get("sources") or []:
            url = str(source.get("url", ""))
            host = _host(url)
            path = urllib.parse.urlsplit(url).path.strip("/").lower()
            if not _allowed_host(host):
                continue
            if not int(source.get("item_id", 0) or 0) and path in {"", "bopa", "cestudiantes"}:
                continue
            label = str(source.get("label", ""))
            marker = _norm(label + " " + url)
            item_specific = int(source.get("item_id", 0) or 0) > 0
            primary = item_specific and int(source.get("authority", 0) or 0) >= 85 and any(
                value in marker for value in PRIMARY_MARKERS
            )
            candidates.append(
                Candidate(
                    url,
                    label,
                    int(source.get("id", 0) or 0),
                    _source_type(url),
                    _authority(url),
                    "item_source" if item_specific else "global",
                    required=primary,
                    primary=primary,
                )
            )

        post = context.get("post") or {}
        if post.get("content") and post.get("url"):
            parser = PublicHTMLParser(str(post["url"]))
            parser.feed(str(post["content"]))
            parser.close()
            for link in parser.links[:100]:
                if not _allowed_host(_host(link.url)):
                    continue
                combined = _norm(link.text + " " + link.url)
                relevant = self._link_relevant(link.url, keywords) or any(word in combined for word in keywords)
                if not relevant:
                    continue
                primary = _authority(link.url) >= 85 and any(value in combined for value in PRIMARY_MARKERS)
                candidates.append(
                    Candidate(
                        link.url,
                        link.text or "Enlace de la página actual",
                        0,
                        _source_type(link.url),
                        _authority(link.url),
                        "current_link",
                        required=primary,
                        primary=primary,
                    )
                )
        return candidates

    def _link_relevant(self, url: str, keywords: list[str]) -> bool:
        haystack = _norm(urllib.parse.unquote(url))
        return any(word in haystack for word in keywords) or any(marker in haystack for marker in PRIMARY_MARKERS)

    def _discover(self, title: str, category: str) -> list[Candidate]:
        discovery = TavilyDiscovery(self.config.tavily_api_key, ["uniovi.es", "unioviedo.es", "boe.es", "asturias.es"])
        queries = [
            f'"{title}" {category} Universidad de Oviedo',
            f'"{title}" resolución reglamento convocatoria plazo',
        ][: self.config.limits.max_searches_per_job]
        candidates: list[Candidate] = []
        for query in queries:
            for result in discovery.search(query, max_results=6):
                if _allowed_host(_host(result.url)):
                    marker = _norm(result.title + " " + result.url)
                    primary = _authority(result.url) >= 85 and any(value in marker for value in PRIMARY_MARKERS)
                    candidates.append(
                        Candidate(
                            result.url,
                            result.title,
                            0,
                            _source_type(result.url),
                            _authority(result.url),
                            "discovery",
                            required=False,
                            primary=primary,
                        )
                    )
        return candidates

    def audit_links(self, urls: list[str], current_urls: set[str], evidence_urls: set[str]):
        results: list[dict[str, Any]] = []
        errors: list[str] = []
        warnings: list[str] = []
        for raw in list(dict.fromkeys(urls))[:40]:
            if raw.startswith(("#", "mailto:", "tel:")):
                continue
            url = urllib.parse.urljoin("https://www.unioviedo.es", raw) if raw.startswith("/") else raw
            host = _host(url)
            is_new = url not in current_urls
            if not _allowed_host(host):
                errors.append(f"El enlace {url} está fuera de la lista blanca institucional.")
                results.append({"url": url, "status": "blocked", "detail": "dominio no permitido"})
                continue
            if is_new and "/cestudiantes/" not in url and url not in evidence_urls:
                errors.append(f"El enlace nuevo {url} no procede de ninguna evidencia recuperada.")
                results.append({"url": url, "status": "blocked", "detail": "enlace inventado o no evidenciado"})
                continue
            try:
                response = self.fetcher.fetch(url)
                ok = 200 <= response.status < 400
                results.append(
                    {"url": url, "status": "pass" if ok else "blocked", "http_status": response.status, "new": is_new}
                )
                if not ok:
                    errors.append(f"El enlace {url} devuelve HTTP {response.status}.")
            except NetworkError as exc:
                results.append(
                    {
                        "url": url,
                        "status": "blocked" if is_new else "warning",
                        "detail": str(exc),
                        "new": is_new,
                    }
                )
                (errors if is_new else warnings).append(f"No se pudo comprobar {url}: {exc}")
        return results, errors, warnings
