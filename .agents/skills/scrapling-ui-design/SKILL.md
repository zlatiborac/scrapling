---
name: scrapling-ui-design
description: Design or review this project's Laravel Filament dashboard. Use for meaningful layout, theme, navigation, form, table, responsive, or accessibility work. Provides a short project-specific workflow and optional pinned Taste Skill tools for larger redesigns.
---

# Scrapling dashboard design

Read the root `AGENTS.md` and `instructions/` project guidance first. This skill adapts ideas from [Taste Skill](../../../instructions/design/UPSTREAM.md) to the existing Laravel 12 / Filament 4 application.

## Scope and effort

Use this short workflow for routine dashboard design. The full upstream workflow and reference catalogs are optional for major redesigns or when explicitly requested; do not load the entire library for a small UI change. Installing this skill alone does not authorize a redesign.

Use the existing Filament/Livewire/Blade components, Heroicons, and admin theme. Keep Herd and the current build pipeline. Do not introduce React, another component system, remote fonts, or a replacement app shell simply because an upstream example uses them. Project/user requirements take precedence over upstream style preferences.

## Design the task

1. Inspect the affected page, related screens, current theme, and real data before editing. For an existing screen, capture its current state when browser tools are available.
2. State the main user action and what information supports it. For this app that usually means testing a URL, understanding a fetch failure, or finding an earlier attempt.
3. Preserve established navigation and branding. Prefer a clear heading, one primary action, and useful content density. Keep status colors meaningful and readable with text labels.
4. Use real persisted data in production; use fixtures for development. Never invent scraping totals, trends, or success rates to fill a layout.
5. Design empty, loading, success, validation-error, fetch-error, and disabled states alongside the normal state. Clear stale results and prevent accidental duplicate actions.
6. Keep forms labelled, controls keyboard accessible, focus visible, and contrast sufficient. Honor reduced motion. Ensure narrow screens can use navigation, URL inputs, history filters, long URLs, and tables without broken page layout.
7. Align table figures and show units, timestamps, and timezones where they matter. Keep raw technical details secondary to the useful result. Avoid decorative charts, generic metric cards, and motion without a task-related purpose.

## Implement within Filament

- Prefer native Filament components, schemas, tables, actions, notifications, and responsive behavior.
- Put theme changes in `resources/css/filament/admin/theme.css` and page markup in existing Blade/Filament paths. Extend Tailwind `@source` coverage when adding component sources.
- Treat upstream CSS/JS snippets as references. Do not load its global `app.css`, `base.css`, or scripts into the application without a specific need and review for collisions.
- Preserve administrator checks, URL destination checks, redaction, timeouts, existing passwords, and escaped fetched content. Do not execute scraped HTML to make a preview look realistic.

## Check the result

- Run `npm run build` for theme/asset changes; run relevant Laravel/Livewire tests for behavior changes.
- When a browser is available, inspect affected states at desktop, tablet, and phone widths (1440, 768, and 390 pixels are useful defaults). Include both color modes if the change affects them.
- Check keyboard flow, long text, small/large datasets, focus/contrast, and console/asset errors. Screenshots support functional checks; they do not replace them.
- Fix the most consequential issues, then stop when the requested change is verified. Report exactly what was and was not checked.

## Optional upstream tools

The full, MIT-licensed [Taste Skill snapshot](../../../instructions/design/upstream/taste-skill/SKILL.md) is available locally. For substantial admin design work, use its app-screen guidance rather than its marketing-page workflow. Read the relevant upstream instructions before using a tool; keep the project's brand, palette, and typography unless the user asks to change them.

The tools need Node 22+ (this project uses 24); browser inspection also needs Chrome, Edge, or Chromium. No global installer, API key, or additional npm package is needed. Store generated design notes, draws, screenshots, and reports in the ignored `.taste-skill/` directory. Set `TASTE_SKILL_HISTORY` there as well so tools do not write to a home directory. Never include credentials, authenticated screenshots, or sensitive scraped content in Git.
