from __future__ import annotations

import base64
from typing import Any

VIEWPORTS = (360, 390, 768, 1440)


class BrowserAuditError(RuntimeError):
    pass


def render_responsive(html: str) -> tuple[list[dict[str, Any]], list[dict[str, Any]], list[str]]:
    try:
        from playwright.sync_api import sync_playwright
    except ImportError as exc:
        raise BrowserAuditError("Playwright no está instalado; no se puede validar el diseño responsive.") from exc

    previews: list[dict[str, Any]] = []
    results: list[dict[str, Any]] = []
    errors: list[str] = []
    document = (
        '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width,initial-scale=1">'
        '<style>body{margin:0;padding:16px;font-family:Arial,sans-serif;background:#fff;color:#172033}'
        '*{box-sizing:border-box}</style></head><body>'
        + html
        + "</body></html>"
    )

    try:
        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(headless=True)
            for width in VIEWPORTS:
                page = browser.new_page(viewport={"width": width, "height": 900}, device_scale_factor=1)
                page.set_content(document, wait_until="domcontentloaded", timeout=30_000)
                audit = page.evaluate(
                    """() => {
                        const width = document.documentElement.clientWidth;
                        const all = [...document.querySelectorAll('*')];
                        const overflow = all.filter(el => {
                            const r = el.getBoundingClientRect();
                            return r.right > width + 1 || r.left < -1;
                        }).slice(0, 12).map(el => el.tagName.toLowerCase() + (el.id ? '#' + el.id : ''));
                        const interactive = [...document.querySelectorAll('button,input,select,textarea,form,iframe,script')]
                            .map(el => el.tagName.toLowerCase());
                        const missingAlt = [...document.querySelectorAll('img:not([alt]),img[alt=""]')].length;
                        const emptyLinks = [...document.querySelectorAll('a')]
                            .filter(a => !a.getAttribute('href') || a.getAttribute('href') === '#').length;
                        return {
                            scrollWidth: document.documentElement.scrollWidth,
                            clientWidth: width,
                            overflow,
                            interactive,
                            missingAlt,
                            emptyLinks,
                            height: document.documentElement.scrollHeight
                        };
                    }"""
                )
                issues: list[str] = []
                if audit["scrollWidth"] > audit["clientWidth"] + 1 or audit["overflow"]:
                    issues.append("Desbordamiento horizontal: " + ", ".join(audit["overflow"]))
                if audit["interactive"]:
                    issues.append("Controles interactivos no permitidos: " + ", ".join(audit["interactive"]))
                if audit["missingAlt"]:
                    issues.append(f"{audit['missingAlt']} imágenes sin alt")
                if audit["emptyLinks"]:
                    issues.append(f"{audit['emptyLinks']} enlaces vacíos")
                screenshot = page.screenshot(type="jpeg", quality=72, full_page=True)
                previews.append(
                    {
                        "width": width,
                        "mime": "image/jpeg",
                        "data": base64.b64encode(screenshot).decode("ascii"),
                    }
                )
                results.append(
                    {
                        "width": width,
                        "status": "blocked" if issues else "pass",
                        "issues": issues,
                        "page_height": audit["height"],
                    }
                )
                errors.extend(f"{width}px: {issue}" for issue in issues)
                page.close()
            browser.close()
    except Exception as exc:
        raise BrowserAuditError(
            f"No se pudo ejecutar Chromium: {type(exc).__name__}: {str(exc)[:400]}"
        ) from exc

    return previews, results, errors
