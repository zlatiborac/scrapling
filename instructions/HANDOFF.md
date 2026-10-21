# Current handoff

## Current state

- Native Laravel 12/Filament 4 + Python Scrapling + SQLite app; work only on `main`, without worktrees. The user confirms the app runs successfully on their Mac with Herd.
- Test scraper now has a Protocol selector (HTTPS default) and a separate Page URL input. Domain/path entries use the selected protocol; complete HTTP/HTTPS URLs override it. Surrounding whitespace is trimmed, and history records the resolved URL.
- The fields sit side by side on wider screens and stack on small screens using native Filament layout. Existing authentication, administrator restrictions, URL destination checks, timeouts, and history redaction remain in place.
- Existing activity-log/environment-indicator plugins and project UI design skill remain installed. Read the root AGENTS.md and fetch remote main before starting work.

## Validation

- 32 Laravel tests passed (195 assertions), including protocol selection, pasted-URL precedence, invalid input/protocol rejection, history, authentication, and a real local PHP → Python → Scrapling fetch.
- All 4 Python tests passed; npm production build and PHP formatting passed.
- Used the published cloud environment without reinstalling dependencies. Browser visual checks and external live fetching were not performed for this change.

## Follow-up

- On the Mac, pull main and run `npm run build` to refresh theme assets. No new dependencies or migrations are required.
