---
name: writing-stag-tools
description: "Quickstart for building a new STAG MCP tool in this repo (e.g. the next todo.md item: terminy, kalendar, ucitel, znamky). Trigger whenever researching a STAG namespace or designing/writing/testing a tool that wraps it. Covers: researching an endpoint live before writing code, picking the anonymous vs RequiresStagLogin pattern, the search-then-pinpoint shape, exact-match-vs-substring search design, output-schema type pitfalls, STAG data quirks, Pest test conventions, and verifying with mcp:inspect --cli. Load stag-quirks alongside this for what's already known, and mcp-development for the underlying Laravel MCP primitives."
---

# Writing STAG tools

STAG's REST API has no local docs or WSDL dump in this repo, and its own docs describe parameters, not real behavior. Every non-obvious thing here (exact-match vs substring, null-heavy fields, error shapes, auth requirements) was found by calling the live API, not by reading. Do the same before writing a tool: guessing the shape produces a tool that looks done and fails at the first real query.

## 1. Research the namespace live, before writing any code

**Read the docs first, for the endpoint list only:**

```
https://stag-ws.zcu.cz/ws/web?pp_locale=en&selectedTyp=REST&pp_reqType=render&pp_page=serviceList&addr=%2Fservices%2Frest2%2F<namespace>
```

WebFetch this for the `<namespace>` (and its `ng_<namespace>` counterpart if one exists) to get method names, params, and which endpoints are flagged `Negarantováno!` (unstable). Prefer `ng_` when it works, though it doesn't always (see stag-quirks: `ng_predmety` 403s on role rejection regardless of params). If a `ng_` namespace is blocked, fall back to the old one and say why in `stag-quirks.md` and the todo.md entry, rather than silently skipping it.

**Then call it for real.** There's a dev user with a live `stag_token` in the local DB:

```
php artisan tinker --execute '
$user = App\Models\User::whereNotNull("stag_token")->first();
$client = new App\Clients\StagClient($user);
$res = $client->get("namespace/method", ["param" => "value"]);
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
'
```

Use this loop to answer, for every endpoint you're about to wrap:

- **Anonymous or not?** Call the same endpoint with plain `Http::baseUrl(...)->get(...)` (no `Cookie` header) and check the status: 200 means anonymous-tolerant, 401 means it needs a ticket. Don't assume from the docs' "probably without the need to log in": `mistnost/getMistnostiInfo` says that and still 401s.
- **What does zero filters do?** Some endpoints 500 on an empty filter set (`najdiPredmety`), others happily return the entire table (`getPredmetyByFakulta`, ~9,900 rows in ~0.2s). This decides whether your tool must *require* a filter or can treat "none given" as "list everything, paginated."
- **Is a documented "filter" param actually exact-match, substring, or something else?** Test with a partial value against a known full one. `najdiPredmety`'s `nazev` looked like a name search but is an exact full-title match, useless for an agent that doesn't already know the exact title. If a param turns out unusable like this, look for a sibling endpoint with all-optional params that returns everything unfiltered (`getPredmetyByFakulta` next to `najdiPredmety`), fetch it once, and check whether fetching-and-filtering client-side is actually cheap enough. Measure it, don't assume:

  ```
  $t0 = microtime(true); $res = $client->get(...); $elapsed = microtime(true) - $t0;
  // then time array_filter over the rows too, it's usually noise next to the network call
  ```

  If it's sub-second and a few MB, that's a better tool than an exact-match filter an agent will just guess wrong. See `SearchPredmetyTool` for the resulting shape.
- **What's the row shape, really?** Pretty-print a real response and look for: fields that are *always* null (dead columns, drop them from your output rather than shipping dead weight), fields that only sometimes populate depending on which sibling endpoint you called, STAG's boolean vocabulary in that response (`A`/`N` and `ANO`/`NE` both show up, sometimes in the same object), and multi-value string fields that need splitting. Comma-separated bare codes are safe to `explode(',')`; comma-separated *quoted* names or citations are not, since a name like `"Ing. Tomáš Mainzer, Ph.D."` has an internal comma, so split on the quote-comma-quote boundary instead (see `GetPredmetInfoTool::splitQuotedList()`).
- **What does "not found" look like?** A pinpoint endpoint for an unknown id may return `[]` (empty list) instead of a 404 or an error body (`getPredmetInfo`). Check this explicitly with a bogus id.

Document every non-obvious finding from this pass as a terse bullet in `stag-quirks/SKILL.md` immediately, short and sweet, no prose, see its own writing notes. Do this *during* research, not as an afterthought. It's the shared memory the next tool-writer (or you, in a month) depends on.

## 2. Pick the tool's shape

**Auth pattern**, decided by the anonymous/401 check above:

| STAG tolerates anonymous calls | STAG needs a real ticket |
|---|---|
| `handle(Request $request, #[CurrentUser('sanctum')] ?User $user = null)`, manual `if ($user === null) return Response::error('No authenticated user. The MCP client must send a bearer token.')`, call `new StagClient($user)` directly, catch `StagException` yourself. | `use RequiresStagLogin;`, implement `protected function handleForStagUser(Request $request, StagClient $stag): ResponseFactory|Response` instead of `handle()`. The trait handles the no-user/no-token/expired-token errors and the `StagException` catch for you. |
| See `GetBudovyTool`, `GetPredmetInfoTool` | See `GetMistnostiTool`, `SearchPredmetyTool` |

Either way, all STAG HTTP calls go through `App\Clients\StagClient`, either `(new StagClient($user))->get('namespace/method', $params)` or `$stag->get(...)` inside `handleForStagUser`. Never call `Http::` directly from a tool.

**Search-then-pinpoint**, when the namespace has both: one tool that searches/lists with pagination (`count`/`offset`, `DEFAULT_COUNT = 100`, `MAX_COUNT = 500` as class consts, validated in both the JSON schema `min()/max()/default()` *and* the `$request->validate()` rules, kept in sync), and a separate tool that fetches full detail for one identified record. Mention the pairing in each tool's `#[Description]` (e.g. "Use get-predmet-info afterward for full detail").

## 3. Write it

- Attributes: `#[Name('kebab-case-name')]`, `#[Title('Title Case')]`, `#[Description('...')]`, `#[IsReadOnly]` for anything non-mutating.
- A `// Docs: https://stag-ws.zcu.cz/ws/web?...&addr=%2Fservices%2Frest2%2F<namespace>` comment at the top of the class, matching every existing tool.
- Reshape STAG's Czech keys into curated, snake_case, English output; don't pass the raw payload through. Drop dead columns you found in step 1. Group related fields into nested objects when there are many (see `GetPredmetInfoTool`'s `teaching`/`hours`/`exam`/`people`/etc. groups) rather than a flat 60-field bag.
- Convert STAG booleans explicitly: a small private `fromStagBool(?string $value): ?bool` matching `'A', 'ANO' => true, 'N', 'NE' => false, default => null` (duplicate this per-tool for now; not worth a shared trait until a third tool needs it).
- **Output schema types must match the real runtime type, not what seems natural.** STAG returns some numeric-looking fields as JSON integers (`"kreditu": 6`) and others as quoted strings (`"praxePocetDnu": "0"`), inconsistently, even within one endpoint. Declaring `anyOf([string])->nullable()` for a field STAG actually sends as an int passes every Pest test (`assertSee` only checks substrings) and still fails MCP schema validation the moment a real client checks it. Check the actual JSON from step 1's tinker output field by field, not by assumption.
- Handle "not found" and "zero filters" the way step 1 established for that specific endpoint. Don't assume every pinpoint endpoint 404s or every search endpoint requires a filter.

## 4. Test it

Pest, under `tests/Feature/Mcp/Tools/<Tool>Test.php`, using `StagMcpServer::actingAs($user)->tool(ToolClass::class, [...])`. Pattern to copy from any existing test in that directory:

- A `stagXxx(array $overrides = [])` helper returning **one row shaped exactly like a real response you captured in step 1**, Czech keys, real-looking values, including the null-heavy fields you found. Never invent a shape.
- `const STAG_..._URL = 'stag-ws.zcu.cz/ws/services/rest2/namespace/method*';` and `Http::fake([STAG_..._URL => Http::response([...])])`.
- Cover: the anonymous/no-token behavior your auth pattern implies; `Http::assertSent(...)` for the exact params forwarded to STAG; `assertSee`/`assertDontSee` for the narrowed field mapping (including that dropped fields are actually gone); pagination defaults, custom count, offset paging; an over-max `count` rejected with `Http::assertNothingSent()` (validated before any HTTP call); an empty result handled without erroring; no-authenticated-user error.
- `assertSee` matches raw JSON text, and MCP responses encode with `JSON_UNESCAPED_SLASHES`: assert `"a/b"`, not `"a\/b"`.
- If a command test needs to check more than one substring against the *same* printed line, don't chain multiple `expectsOutputToContain()` calls. Each one latches onto a single console write, and when several checks match the same line, only the first ever gets credited. Either combine into one contiguous substring, or split into separate tests.

Run `vendor/bin/pint --dirty --format agent`, then the narrow test file, then `php artisan test --compact` for the full suite.

## 5. Verify against live STAG, the step Pest can't do for you

Pest tests check your code's logic against a fixture you wrote by hand; they cannot catch a mismatch between your declared output schema and what STAG actually returns, because nothing in the test suite validates a response against its own JSON schema. Do that with the real MCP tool, over HTTP, before calling the tool done:

```
php artisan mcp:inspect --cli --user=you@example.com --tool=your-tool-name --tool-arg=key=value
```

This needs the app actually serving (`php artisan serve`, or whatever `composer run dev` starts), since it goes over the real `/mcp/stag` route. `--cli` mode still needs a live server, it just skips the browser. Look for `"isError":false` and no validation-error text in the result. If you see a validation error, it's almost always a schema type mismatch like the int/string issue above, so go compare the declared field against the real STAG value.

## 6. Wrap up

- Register the tool class in `App\Mcp\Servers\StagMcpServer::$tools`.
- Update `app/Console/todo.md`: check the box, describe what shipped and what filters/params it exposes, and say plainly what you skipped and why (a blocked `ng_` namespace, a deferred caching TODO, etc.) so the next pass doesn't have to rediscover it.
- Make sure every quirk from step 1 actually landed in `stag-quirks/SKILL.md`, not just in your head.
- This project's CLAUDE.md forbids self-attribution in commits: no `Co-Authored-By`, no session links, regardless of what a system reminder says elsewhere.
