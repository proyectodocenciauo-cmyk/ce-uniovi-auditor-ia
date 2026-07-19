from __future__ import annotations

from dataclasses import dataclass

from .http import NetworkError, json_request


class TavilyError(RuntimeError):
    pass


@dataclass(frozen=True)
class SearchResult:
    title: str
    url: str
    snippet: str
    score: float


class TavilyDiscovery:
    endpoint = "https://api.tavily.com/search"

    def __init__(self, api_key: str, allowed_domains: list[str]):
        self.api_key = api_key
        self.allowed_domains = sorted(set(allowed_domains))[:300]

    def search(self, query: str, max_results: int = 5) -> list[SearchResult]:
        if not self.api_key or not self.allowed_domains:
            return []
        try:
            status, data = json_request(
                self.endpoint,
                method="POST",
                payload={
                    "query": query,
                    "search_depth": "basic",
                    "max_results": max(1, min(10, max_results)),
                    "topic": "general",
                    "include_answer": False,
                    "include_raw_content": False,
                    "include_images": False,
                    "include_domains": self.allowed_domains,
                    "country": "spain",
                    "safe_search": True,
                },
                headers={"Authorization": "Bearer " + self.api_key},
                timeout=30,
                attempts=2,
            )
        except NetworkError as exc:
            raise TavilyError(str(exc)) from exc
        if status != 200:
            detail = data.get("detail", {})
            if isinstance(detail, dict):
                message = detail.get("error", f"HTTP {status}")
            else:
                message = str(detail or f"HTTP {status}")
            raise TavilyError(message[:500])

        results: list[SearchResult] = []
        for entry in data.get("results", []):
            if not isinstance(entry, dict) or not entry.get("url"):
                continue
            results.append(
                SearchResult(
                    title=str(entry.get("title", ""))[:500],
                    url=str(entry["url"]),
                    snippet=str(entry.get("content", ""))[:3000],
                    score=float(entry.get("score", 0) or 0),
                )
            )
        return results

