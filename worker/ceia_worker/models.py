from __future__ import annotations

from datetime import date
from typing import Any, Literal

from pydantic import BaseModel, Field, HttpUrl, field_validator

Risk = Literal["low", "medium", "high", "critical"]
ValidationStatus = Literal[
    "verified",
    "verified_with_observations",
    "human_review",
    "conflict",
    "insufficient_evidence",
]
SupportRelation = Literal["supports", "contradicts", "context_only"]
SupportStatus = Literal["supported", "partially_supported", "unsupported", "conflict"]
RetrievalStatus = Literal["ok", "http_error", "network_error", "irrelevant", "unsupported"]
PublicationGate = Literal["pass", "blocked", "not_applicable"]


class DateRange(BaseModel):
    fecha_inicio: date
    fecha_fin: date

    @field_validator("fecha_fin")
    @classmethod
    def end_not_before_start(cls, value: date, info):
        start = info.data.get("fecha_inicio")
        if start and value < start:
            raise ValueError("fecha_fin no puede ser anterior a fecha_inicio")
        return value


class IndexPatch(BaseModel):
    nombre: str | None = None
    tipo: list[str] | None = None
    url: HttpUrl | None = None
    abierto_permanente: bool | None = None
    fechas_adicionales: list[DateRange] | None = None


class EvidenceSupport(BaseModel):
    evidence_id: str = Field(min_length=1, max_length=20)
    quote: str = Field(min_length=1, max_length=2500)
    relation: SupportRelation = "supports"


class Fact(BaseModel):
    fact_id: str = Field(min_length=1, max_length=80)
    fact_type: Literal[
        "deadline",
        "amount",
        "eligibility",
        "legal_basis",
        "procedure",
        "competent_body",
        "contact",
        "definition",
        "other",
    ]
    subject: str = Field(min_length=3, max_length=300)
    claim: str = Field(min_length=1, max_length=4000)
    value: str = Field(default="", max_length=2000)
    evidence_ids: list[str] = Field(default_factory=list, max_length=12)
    supports: list[EvidenceSupport] = Field(default_factory=list, max_length=12)
    confidence: float = Field(default=0.0, ge=0, le=1)
    technical_confidence: float = Field(default=0.0, ge=0, le=1)
    legal_confidence: float = Field(default=0.0, ge=0, le=1)
    support_status: SupportStatus = "unsupported"


class Change(BaseModel):
    section: str = Field(min_length=1, max_length=300)
    current: str = Field(default="", max_length=5000)
    proposed: str = Field(default="", max_length=5000)
    reason: str = Field(min_length=1, max_length=5000)
    evidence_ids: list[str] = Field(default_factory=list, max_length=12)


class Conflict(BaseModel):
    topic: str = Field(min_length=1, max_length=500)
    statements: list[str] = Field(min_length=2, max_length=10)
    evidence_ids: list[str] = Field(min_length=2, max_length=12)
    recommended_resolution: str = Field(default="", max_length=3000)


class ModelProposal(BaseModel):
    change_required: bool
    validation_status: ValidationStatus
    risk: Risk
    summary: str = Field(min_length=1, max_length=20000)
    proposed_title: str = Field(default="", max_length=255)
    proposed_content: str = Field(default="", max_length=2_000_000)
    index_patch: IndexPatch = Field(default_factory=IndexPatch)
    changes: list[Change] = Field(default_factory=list, max_length=80)
    facts: list[Fact] = Field(default_factory=list, max_length=150)
    conflicts: list[Conflict] = Field(default_factory=list, max_length=50)
    citations: list[str] = Field(default_factory=list, max_length=100)


class SourceRecord(BaseModel):
    id: int = 0
    item_id: int = 0
    label: str
    url: HttpUrl
    source_type: str = "institutional"
    authority: int = Field(default=60, ge=0, le=100)


class EvidenceRecord(BaseModel):
    local_id: str
    source_id: int = 0
    url: HttpUrl
    title: str = ""
    source_type: str = "institutional"
    authority: int = Field(default=0, ge=0, le=100)
    retrieved_gmt: str
    published_date: date | None = None
    http_status: int = 0
    content_hash: str = ""
    excerpt: str = ""
    text: str = Field(default="", exclude=True)
    links: list[str] = Field(default_factory=list, exclude=True)
    facts: list[dict[str, Any]] = Field(default_factory=list)
    relevance_score: int = Field(default=0, ge=0, le=100)
    required: bool = False
    primary: bool = False
    retrieval_status: RetrievalStatus = "ok"
    retrieval_error: str = Field(default="", max_length=1000)
    origin: str = Field(default="", max_length=80)
    content_type: str = Field(default="", max_length=120)

    def for_wordpress(self) -> dict[str, Any]:
        return self.model_dump(mode="json", exclude={"text", "links"})


class QualityCheck(BaseModel):
    check_id: str = Field(min_length=1, max_length=80)
    label: str = Field(min_length=1, max_length=300)
    status: Literal["pass", "warning", "blocked"]
    detail: str = Field(default="", max_length=5000)


class QualityReport(BaseModel):
    gate: PublicationGate = "blocked"
    checks: list[QualityCheck] = Field(default_factory=list, max_length=100)
    retention_ratio: float = Field(default=1.0, ge=0, le=1)
    removed_blocks: list[str] = Field(default_factory=list, max_length=30)
    added_blocks: list[str] = Field(default_factory=list, max_length=30)
    removed_links: list[str] = Field(default_factory=list, max_length=50)
    added_links: list[str] = Field(default_factory=list, max_length=50)
    link_results: list[dict[str, Any]] = Field(default_factory=list, max_length=100)
    responsive_results: list[dict[str, Any]] = Field(default_factory=list, max_length=10)
    detected_conflicts: list[str] = Field(default_factory=list, max_length=50)
    primary_sources: list[str] = Field(default_factory=list, max_length=30)
    required_source_failures: list[str] = Field(default_factory=list, max_length=30)


class RemoteLimits(BaseModel):
    max_jobs_per_run: int = Field(default=5, ge=1, le=25)
    max_sources_per_job: int = Field(default=12, ge=3, le=30)
    max_searches_per_job: int = Field(default=2, ge=0, le=5)
    max_source_bytes: int = Field(default=6_000_000, ge=100_000, le=10_000_000)


class RemoteConfig(BaseModel):
    site_url: str
    plugin_version: str
    analysis_provider: Literal["gemini"] = "gemini"
    gemini_model: str = "gemini-3.1-flash-lite"
    gemini_api_key: str = ""
    tavily_enabled: bool = False
    tavily_api_key: str = ""
    limits: RemoteLimits = Field(default_factory=RemoteLimits)
    allowed_source_hosts: list[str] = Field(default_factory=list)
    editorial_policy: dict[str, Any] = Field(default_factory=dict)
