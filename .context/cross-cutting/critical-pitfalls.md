# Critical Pitfalls

**This file is a redirect.** All 40 critical pitfalls are documented in the root CLAUDE.md.

## Quick Jump

See **[CLAUDE.md](/CLAUDE.md#40-critical-pitfalls)** for the comprehensive pitfalls list with code examples.

## Most Critical (Quick Reference)

1. **Dual Code Paths** - Update BOTH iOS REST API and Web AJAX for shared features
2. **Year Rollover Bug** - Use `MAX(year)` from data, not `date('Y')`
3. **Timezone** - Use `current_time()`, not `time()` or `date()`
4. **Property URLs** - Use `listing_id` (MLS number), not `listing_key` (hash)
5. **Summary Tables** - Use `bme_listing_summary` for performance (25x faster)

## Full Documentation

- **All 40 pitfalls with examples:** [../../CLAUDE.md](../../CLAUDE.md#40-critical-pitfalls)
- **Mandatory development rules:** [../AGENT_PROTOCOL.md](../AGENT_PROTOCOL.md)

---

*Consolidated on 2026-01-22. The authoritative source is now the root CLAUDE.md file.*
