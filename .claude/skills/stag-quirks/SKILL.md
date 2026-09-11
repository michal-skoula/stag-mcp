---
name: stag-quirks
description: "Load every time when working with the STAG api, writing tools for the MCP server and when needing to document a significant discovery on your own. Evolving documentation for unconventional, weird or otherwise worthy of documenting behavior of IS STAG and its API."
---
    

## Notes
- `ng_*` namespaces mean "new generation", these are more modern versions of old API namespaces. When available always use these instead of the old ones. HOWEVER, they don't always have 100% feature parity with the old versions, so sometimes it might be needed to call from both the ng and old namespaces.
- some endpoints are noted as being `Negarantováno!` in the docs, meaning they are volatile and subject to change or be removed. These should probably get their own trait getting error messages like "This tool calls a volatile endpoint that isn't guaranteed". If there are multiple endpoints that do the same thing or overlap, prefer ones which aren't unstable.

## Writing notes
When adding to the file, add a new bullet point under `## Notes` with the minimum needed information, don't write unnecessary prose. Short and sweet beats verbose and unreadable.
