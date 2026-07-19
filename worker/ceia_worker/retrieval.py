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


class ExtractionError(RuntimeError):
    pass


class PublicHTMLParser(HTMLParser):
    def __init__(self, base_url: str):
        super().__init__(convert_charrefs=True)
        self.base_url = base_url
        self.skip_depth = 0
        self.text_parts: list[str] = []
        self.links: list[str] = []
        self.title_parts: list[str] = []
        self.in_title = False
        self.published_date: str | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        tag = tag.lower()
        attributes = {key.lower(): value or "" for key, value in attrs}
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
                self.links.append(url)
        if tag == "meta":
            key = (attributes.get("property") or attributes.get("name") or "").lower()
            if key in {"article:published_time", "date", "dc.date", "dcterms.date", "citation_publication_date"}:
                self.published_date = attributes.get("content") or self.published_date

    def handle_endtag(self, tag: str) -> None:
        tag = tag.lower()
        if tag in {"script", "style", "noscript", "svg", "template"} and self.skip_depth:
            self.skip_depth -= 1
            return
        if tag == "title":
            self.in_title = False
        if tag in {"p", "li", "h1", "h2", "h3", "h4", "h5", "h6", "tr", "section", "article"}:
            self.text_parts.append("\n")

    def handle_data(self, data: str) -> None:
        if self.skip_depth:
            return
        clean = re.sub(r"\s+", " ", data).strip()
        if not clean:
            return
        self.text_parts.append(clean + " ")
        if self.in_title:
            self.title_parts.append(clean)

    @property
    def text(self) -> str:
        lines = [re.sub(r"\s+", " ", line).strip() for line in "".join(self.text_parts).splitlines()]
        return "\n".join(line for line in lines if line)

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


def _ascii_words(value: str) -> list[str]:
    normalized = unicodedata.normalize("NFKD", value.lower()).encode("ascii", "ignore").decode("ascii")
    stop = {"para", "como", "desde", "hasta", "sobre", "universidad", "oviedo", "tramite", "solicitud", "estudios", "estudiantes"}
    return [word for word in re.findall(r"[a-z0-9]{4,}", normalized) if word not in stop]


def _canonical_url(url: str) -> str:
    parsed = urllib.parse.urlsplit(url)
    query = urllib.parse.parse_qsl(parsed.query, keep_blank_values=True)
    query = [(key, value) for key, value in query if not key.lower().startswith("utm_") and key.lower() not in {"fbclid", "gclid"}]
    path = re.sub(r"/{2,}", "/", parsed.path or "/")
    return urllib.parse.urlunsplit((parsed.scheme.lower(), parsed.netloc.lower(), path, urllib.parse.urlencode(query), ""))


def _host(url: str) -> str:
    return (urllib.parse.urlsplit(url).hostname or "").lower()


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
        "external_lead": 25,
    }[_source_type(url)]


def _parse_date(value: str | None, text: str = "") -> date | None:
    candidates = [value or ""]
    candidates.extend(re.findall(r"\b(20\d{2})[-/](\d{1,2})[-/](\d{1,2})\b", text[:5000]))
    for candidate in candidates:
        if isinstance(candidate, tuple):
            raw = "-".join(candidate)
        else:
            raw = candidate[:10]
        try:
            return date.fromisoformat(raw.replace("/", "-"))
        except ValueError:
            continue
    return None


def _extract_pdf(body: bytes) -> tuple[str, str]:
    try:
        reader = PdfReader(io.BytesIO(body))
        pages = []
        total = 0
        for page in reader.pages[:250]:
            text = page.extract_text() or ""
            pages.append(text)
            total += len(text)
            if total >= 2_000_000:
                break
        title = str((reader.metadata or {}).get("/Title", "") or "")
        return title, "\n".join(pages)
    except Exception as exc:  # pypdf exposes several parser-specific exceptions
        raise ExtractionError("No se pudo extraer el PDF") from exc


def _select_excerpt(text: str, keywords: list[str], limit: int = 12000) -> str:
    if len(text) <= limit:
        return text
    paragraphs = [line.strip() for line in text.splitlines() if line.strip()]
    scored: list[tuple[int, int, str]] = []
    for index, paragraph in enumerate(paragraphs):
        normalized = unicodedata.normalize("NFKD", paragraph.lower()).encode("ascii", "ignore").decode("ascii")
        score = sum(2 for word in keywords if word in normalized)
        score += sum(1 for marker in ("plazo", "euros", "resolucion", "reglamento", "articulo", "solicitud", "requisitos", "contacto") if marker in normalized)
        if score:
            scored.append((score, index, paragraph))
    if not scored:
        return text[:limit]
    selected = sorted(scored, key=lambda row: (-row[0], row[1]))[:60]
    selected.sort(key=lambda row: row[1])
    excerpt = "\n".join(row[2] for row in selected)
    return excerpt[:limit]


class Retriever:
    fixed_hosts = {
        "uniovi.es",
        "unioviedo.es",
        "boe.es",
        "asturias.es",
    }

    def __init__(self, config: RemoteConfig):
        self.config = config
        self.allowed_hosts = set(config.allowed_source_hosts) | self.fixed_hosts
        self.fetcher = SafeFetcher(self.allowed_hosts, config.limits.max_source_bytes)

    def collect(self, context: dict[str, Any]) -> tuple[list[EvidenceRecord], list[str]]:
        item = context.get("item") or {}
        title = str(item.get("title", ""))
        keywords = _ascii_words(title + " " + str(item.get("category", "")))
        candidates = self._initial_candidates(context)
        notes: list[str] = []

        if self.config.tavily_enabled and self.config.tavily_api_key:
            try:
                candidates.extend(self._discover(title, str(item.get("category", "")), context))
            except TavilyError as exc:
                notes.append("Tavily no estuvo disponible: " + str(exc)[:300])

        evidence: list[EvidenceRecord] = []
        seen: set[str] = set()
        cursor = 0
        max_sources = self.config.limits.max_sources_per_job
        while cursor < len(candidates) and len(evidence) < max_sources:
            candidate = candidates[cursor]
            cursor += 1
            url = _canonical_url(candidate.url)
            if url in seen:
                continue
            seen.add(url)
            try:
                response = self.fetcher.fetch(url)
                record = self._to_evidence(candidate, response, keywords, len(evidence) + 1)
                evidence.append(record)
                if response.status == 200 and record.links:
                    for link in record.links:
                        if self._relevant_link(link, keywords) and _canonical_url(link) not in seen:
                            candidates.append(
                                Candidate(
                                    url=link,
                                    title="Enlace oficial relacionado",
                                    source_id=0,
                                    source_type=_source_type(link),
                                    authority=_authority(link),
                                )
                            )
            except (NetworkError, ExtractionError) as exc:
                notes.append(f"No se pudo leer {url}: {exc}")

        return evidence, notes

    def _initial_candidates(self, context: dict[str, Any]) -> list[Candidate]:
        item = context.get("item") or {}
        candidates: list[Candidate] = []
        for source in context.get("sources") or []:
            url = str(source.get("url", ""))
            path = urllib.parse.urlsplit(url).path.strip("/")
            # Las portadas globales definen dominios autorizados, pero no aportan evidencia concreta.
            if not int(source.get("item_id", 0) or 0) and path.lower() in {"", "bopa", "cestudiantes"}:
                continue
            candidates.append(
                Candidate(
                    url=url,
                    title=str(source.get("label", "")),
                    source_id=int(source.get("id", 0) or 0),
                    source_type=str(source.get("source_type", "institutional")),
                    authority=int(source.get("authority", 60) or 60),
                )
            )
        if item.get("url"):
            candidates.insert(
                0,
                Candidate(
                    url=str(item["url"]),
                    title="Destino publicado del trámite",
                    source_id=0,
                    source_type=_source_type(str(item["url"])),
                    authority=_authority(str(item["url"])),
                ),
            )

        post = context.get("post") or {}
        if post.get("content") and post.get("url"):
            parser = PublicHTMLParser(str(post["url"]))
            parser.feed(str(post["content"]))
            for link in parser.links[:60]:
                if self._relevant_link(link, _ascii_words(str(item.get("title", "")))):
                    candidates.append(
                        Candidate(link, "Enlace de la página actual", 0, _source_type(link), _authority(link))
                    )
        return candidates

    def _discover(self, title: str, category: str, context: dict[str, Any]) -> list[Candidate]:
        domains = []
        for source in context.get("sources") or []:
            if int(source.get("authority", 0) or 0) >= 75:
                host = _host(str(source.get("url", "")))
                if host:
                    domains.append(host)
        domains.extend(["uniovi.es", "boe.es", "asturias.es"])
        discovery = TavilyDiscovery(self.config.tavily_api_key, sorted(set(domains)))
        queries = [f'"{title}" {category} Universidad de Oviedo']
        if self.config.limits.max_searches_per_job > 1:
            queries.append(f'"{title}" resolución reglamento convocatoria plazo')
        candidates: list[Candidate] = []
        for query in queries[: self.config.limits.max_searches_per_job]:
            for result in discovery.search(query, max_results=6):
                candidates.append(
                    Candidate(
                        url=result.url,
                        title=result.title,
                        source_id=0,
                        source_type=_source_type(result.url),
                        authority=_authority(result.url),
                    )
                )
        return candidates

    def _to_evidence(self, candidate: Candidate, response, keywords: list[str], number: int) -> EvidenceRecord:
        title = candidate.title
        text = ""
        links: list[str] = []
        published: date | None = None
        if response.status == 200:
            if response.content_type == "application/pdf" or response.url.lower().split("?", 1)[0].endswith(".pdf"):
                pdf_title, text = _extract_pdf(response.body)
                title = pdf_title or title
                published = _parse_date(None, text)
            elif response.content_type in {"text/html", "application/xhtml+xml", ""}:
                parser = PublicHTMLParser(response.url)
                parser.feed(response.text())
                text = parser.text
                title = parser.title or title
                links = list(dict.fromkeys(_canonical_url(link) for link in parser.links))[:250]
                published = _parse_date(parser.published_date, text)
            elif response.content_type.startswith("text/"):
                text = response.text()
                published = _parse_date(None, text)

        excerpt = _select_excerpt(text, keywords)
        return EvidenceRecord(
            local_id=f"E{number:03d}",
            source_id=candidate.source_id,
            url=response.url,
            title=title[:500],
            source_type=candidate.source_type or _source_type(response.url),
            authority=max(candidate.authority, _authority(response.url)),
            retrieved_gmt=datetime.now(UTC).strftime("%Y-%m-%d %H:%M:%S"),
            published_date=published,
            http_status=response.status,
            content_hash=hashlib.sha256(response.body).hexdigest(),
            excerpt=excerpt,
            text=text[:80_000],
            links=links,
        )

    def _relevant_link(self, url: str, keywords: list[str]) -> bool:
        host = _host(url)
        if not any(host == allowed or host.endswith("." + allowed) for allowed in self.allowed_hosts):
            return False
        lowered = unicodedata.normalize("NFKD", urllib.parse.unquote(url).lower()).encode("ascii", "ignore").decode("ascii")
        if any(marker in lowered for marker in (".pdf", "resolucion", "convocatoria", "reglamento", "normativa", "beca", "ayuda", "admision", "matricula", "plazo")):
            return True
        return any(word in lowered for word in keywords[:12])
