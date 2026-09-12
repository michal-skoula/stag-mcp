---
name: stag-quirks
description: "Load every time when working with the STAG api, writing tools for the MCP server and when needing to document a significant discovery on your own. Evolving documentation for unconventional, weird or otherwise worthy of documenting behavior of IS STAG and its API."
---
    

## Notes
- `ng_*` namespaces mean "new generation", these are more modern versions of old API namespaces. When available always use these instead of the old ones. HOWEVER, they don't always have 100% feature parity with the old versions, so sometimes it might be needed to call from both the ng and old namespaces.
- some endpoints are noted as being `Negarantováno!` in the docs, meaning they are volatile and subject to change or be removed. These should probably get their own trait getting error messages like "This tool calls a volatile endpoint that isn't guaranteed". If there are multiple endpoints that do the same thing or overlap, prefer ones which aren't unstable.
- `getBudovy` answers anonymous callers; `getMistnostiInfo` in the same namespace returns 401 without a token even though the docs list it as "probably without the need to log in" for both.
- `mistnost/getBudovy` wraps its list in `items`; `mistnost/getMistnostiInfo` wraps its list in `mistnostInfo`. The wrapper key is per-endpoint, not a namespace-wide convention.
- `mistnost/getMistnostiInfo` (2411 rows, unfiltered) always returns null for `provozDo`, `serial`, `urlMistnost`, `identifikatorBudova`; `identifikatorMistnost` and `dvereCislo` are null in all but one row. `typCiselne` duplicates `typ` as a numeric code. Treat these as dead/near-dead columns.
- `mistnost/getBudovy` `provozOd`/`provozDo` data has quality issues, some rows show `provozOd` after `provozDo` (e.g. zkrBudovy A2: provozOd 4.7.2026, provozDo 1.1.2026). Don't assume the range is well-formed.
- `mistnost/getBudovy`'s `gpsBudovaX`/`gpsBudovaY` are longitude/latitude (X=lon, Y=lat). Its `gpsAdresniMistoX`/`gpsAdresniMistoY` are the reverse (X=lat, Y=lon) and redundant with the budova pair. `mistnost/getMistnostiInfo`'s `budovaGPSX`/`budovaGPSY` follow the `gpsBudova*` convention (X=lon, Y=lat). Map explicitly per field name, don't assume X/Y line up the same way across fields.

## Writing notes
When adding to the file, add a new bullet point under `## Notes` with the minimum needed information, don't write unnecessary prose. Short and sweet beats verbose and unreadable.
