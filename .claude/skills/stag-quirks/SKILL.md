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
- `predmety/najdiPredmety` and `predmety/getPredmetInfo` both answer anonymous callers, like `mistnost/getBudovy` (not like `getMistnostiInfo`).
- `predmety/najdiPredmety` wraps its list in `predmetKatedry`. Calling it with zero filters returns HTTP 500, not an empty list or 400 — always send at least one filter.
- `predmety/najdiPredmety`'s `nazev` param is an exact match on the full course title, not a substring search, unlike `GetBudovyTool`'s address filter. A partial title returns zero rows.
- `predmety/najdiPredmety` rows only ever populate `katedra`, `zkratka`, `rok`, `nazev` — every other field (`semestr`, `vyukaZS`, `pocetStudentu`, the `aMax`/`bMax`/`cMax`/`aSkut`/`bSkut`/`cSkut` capacity fields, etc.) is always null. Fetch `getPredmetInfo` for real data.
- `predmety/getPredmetInfo` for an unknown katedra/zkratka combo returns `[]` (empty array), not a 404 or an error — check for that shape rather than assuming an object comes back.
- `predmety/getPredmetInfo` has several fields (`metodyVyucovaci`, `metodyHodnotici`, `ziskaneZpusobilosti` observed so far) that return a fixed Czech placeholder sentence ("U tohoto předmětu se již používá nový QRAM...") instead of real content once a course has migrated to the newer QRAM system. Treat these as potentially-dead depending on the course.
- `predmety/getPredmetInfo`'s `lang` param (e.g. `en`) translates `nazev`, `nazevDlouhy`, `anotace` and other free-text fields server-side.
- `ng_predmety/searchSimplePredmety` 403s with "refused the requested role" for a ticket with the `ST` (student) role even when `stagUser` is set to that same account's own login — tried both a placeholder value and the real login name, same rejection either way. Untested whether a different role ticket (e.g. `UCIT`) works. Matches its docs' "Negarantováno!" flag; prefer the old `predmety` namespace until this is sorted out.
- `predmety/getPredmetyByFakulta` called with zero filters returns STAG's entire subject catalog (~9,900 rows, ~3.3MB) rather than erroring, unlike `najdiPredmety`'s 500-on-empty-filters. ~0.2s to fetch authenticated; filtering all rows client-side takes single-digit milliseconds, cheap enough to be the actual search implementation (`SearchPredmetyTool` fetches it unfiltered and matches nazev/katedra/zkratka as substrings locally, since `najdiPredmety`'s `nazev` exact-match makes it useless for free-text search). Unlike `najdiPredmety`/`getPredmetInfo`, it 401s anonymous — needs a real STAG token.
- `predmety/getPredmetyByFakulta` rows always populate `maVyuku`, `vyukaZS`, `vyukaLS`, `nabizetPrijezdyEcts`, and `jazyk1` (sparser for `jazyk2`-`jazyk4`) — unlike `najdiPredmety`'s search rows, which leave those null. `semestr`, `pocetStudentu`, and the `aMax`/`bMax`/`cMax`/`aSkut`/`bSkut`/`cSkut` capacity fields are always null on both endpoints.

## Writing notes
When adding to the file, add a new bullet point under `## Notes` with the minimum needed information, don't write unnecessary prose. Short and sweet beats verbose and unreadable.
