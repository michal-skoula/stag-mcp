## MCP Tools

### STAG API
- [ ] ? Ciselniky, not sure wtf it is. needs testing
- [ ] **UserInfoTool:** `help` namespace, info about the current user, to get roles, name, etc. Either save from ticket resolution or call the tool. Can combine multiple calls together.  Also note some overlap in the `orion` namespace
- [ ] **Kalendar\*Tool:** Everything in the `kalendar` namespace. Contains important dates for the semester, events, etc. test the output shapes, and what the tool could look like. Ideally combine multiple endpoints into one with bool flags, so chat context doesnt get spammed too hard. Also note some overlap in the `orion` namespace
- [x] **GetBudovyTool:** finished! No caching yet — plain pass-through. TODO left in the tool itself for long-TTL caching with checksum-based invalidation.
- [x] **GetMistnostiTool:** finished! One tool covering `getMistnostiInfo`, narrowed to a fixed field set (no coarse/detail split). Filters: zkr_budovy, cislo_mistnosti, pracoviste, typ, jen_platne. Paged with count/offset (default 100, max 500) instead of a hard truncation cutoff.
- [ ] **Predmety:** `ng_predmety` and `predmety`: Has search and info, copies the pattern of **Mistnosti tools**, search then pinpoint. Needs to find the output schema to design something that makes sense. NG is marked unstable. the ng_ namespace here is LOADED with goods. Re-read when it comes to building the tools
- [ ] **TerminyStatnicTool:** `ng_terminy`, Might overlap with the **Kalendar** suite of tools, but it is its own volatile endpoint. This is a caching candidate, as this changes... once or twice a year.
- [x] **OznameniTools:** finished!
- [ ] **SemestralniPrace tools**: `podporaVyuky` namespace, readonly for semestralni prace metadata. If i understand it correctly this is huge because it lets you see what you need to do and what you have already done, which is same level of huge as surfacing notifications. Pog!
- [ ] `programy` looks interesting, too tired to read it rn
- [ ] `soubory` should be able to retrieve any kind of submitted file you have access to, not sure how to list them though. could be useful
- [ ] `student` namespace has statistics for success rates and other interesting bits as well as well as checking if you completed a class or not, which could be spun into a HaveIPassedThisTool or a similar idea.
- [ ] **Terminy tools**: `terminy` namespace, Super important, has both read AND write for checking dates and registering for exams. this is HUGE because your agent can sign you up according to your calendar. check out the whole namespace and plan it thoroughly 
- [ ] `ucitel` namespace has info and great search tools, invaluable for questions like "what is my IDT lecturer's email and phone number".
- [ ] `vizitka` has `getUredniHodinyPracoviste`, if it works really handy
- [ ] `vsp` is vizualizace studijniho planu. need the output shape, could be super cool for planning your study and quickly listing subjects for other tools
- [ ] `getSeznamProvozovanychWS` for fun try what this shows, maybe it exposes more endpoints that arent documented
- [ ] `znamky` quite self-describing huh. also has POST for writing grades, high trolling potential >:D

### Internal
- [ ] **UpdateMcpPreferencesTool:** Internal tool for configuring preferences, such as the language of responses, and importantly the role to use. Currently quite vague in spec, usage will materialize as more tools are added.
- [ ] **ReportIssueTool:** Internal tool for reporting issues. When the user encounters an unexpected state or other error, this tool should be available to easily submit an issue. Probably disabled on self-hosted configs unless you set it up manually

## MCP Resources
> My current mental model is treat these as "evergreen topics" and general knowledge like a list of faculties, must-knows by older students etc. I name them resources but due to subpar support for resources in MCP clients they'll likely be tools that behave like resources. Unsure on this yet

- [ ] FacultiesList
- [ ] CommonSoftwareAndWebsitesList
- [ ] any kind of tutorial like "how to do předzápis", can be community based, this is deff not V1 territorry though
