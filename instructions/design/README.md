# Design assistance

The project includes **Scrapling UI Design**, a small agent skill adapted for our Filament dashboard, plus a complete pinned copy of **Taste Skill** for deeper design work.

- [Project skill](../../.agents/skills/scrapling-ui-design/SKILL.md): the normal starting point for dashboard design and reviews.
- [Upstream Taste Skill](upstream/taste-skill/SKILL.md): optional detailed workflows, dashboard references, visual libraries, and helper scripts.
- [Source and license record](UPSTREAM.md): the exact imported revision and update procedure.

The root `AGENTS.md` points agents to the project skill for UI work. Agents that discover repository skills through `.agents/skills/` can also find it there. A new chat/session may be needed for skill discovery; the file can always be read directly.

You can ask, for example:

> Use scrapling-ui-design to improve the scraper results page, keeping Filament and the existing functionality.

Or:

> Review the history screen on desktop and mobile with the project design skill before suggesting changes.

Routine changes use the compact project skill. A substantial redesign can use the upstream app-screen workflow; upstream marketing-page rules are not the default for this admin application. This preserves Filament, the current brand, Herd, and existing accessibility/security behavior.

This is guidance for coding agents, not a Filament plugin or a frontend dependency. Pulling the repository is enough to obtain it; no change to local application setup is required. No dashboard redesign is included in this import.

The optional upstream Node helpers are development tools. Browser inspection additionally requires a locally installed Chrome/Edge/Chromium; finding imagery uses network access. Generated `.taste-skill/` files are ignored by Git. The application does not load the vendored CSS/JS or depend on its catalogs at runtime.
