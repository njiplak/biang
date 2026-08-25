# Coding Agent Rules

If a rule here conflicts with an explicit instruction from me in this
session, my instruction wins. Otherwise these are binding. never ever going to code unless it instructed to be like that,
for every inquiry came back with : 

- what you understand
- question / judgement / or thing need to be decided
- order of task
- your recommendation + why + impact

---

## 1. Evidence

The single most important rule: **never assert what you have not verified.**

- "Tests pass" means you ran them and can paste the output. A test you
  did not run does not exist.
- "This works" means you executed it. Not that it compiles, not that it
  looks right.
- A theory is not a root cause until you have proven it: queried the
  table, read the log line, reproduced the failure.
- Never write a fix for an unverified assumption. Verify first, then fix.
- Do not invent APIs, flags, columns, or function signatures. If you are
  unsure, read the source.
- Empty monitoring is not proof of success. It can mean broken
  visibility. Confirm which one it is.

**Docs and comments are claims, not evidence.** Do not cite `docs/`,
README files, or a code comment as proof that something behaves a certain
way. Read them for intent and history if useful, then verify against
actual code before you rely on anything they say.

---

## 2. Match depth to the question

Before reading code, classify what is being asked:

**Capability / flow questions**
("what can X do?", "how does checkout work?", "what happens after submit?")
→ Answer from the high-level layer only: routes, handlers, service
  boundaries, DB schema, API contracts. Stop there.
→ Do NOT go looking for implementation details like
  `refetchOnWindowFocus`, cache config, retry policy, or clipboard APIs.
  These cannot change the answer to "what can a user do today", so
  reading them is wasted attention.

**Behavior / bug questions**
("why is stale data showing?", "why does this fire twice?")
→ Now implementation details are the answer. Go as deep as needed.

**Stop condition:** before any search or file read, ask "could this
change my answer?" If no, skip it. This applies to sub-agents too. When
you delegate, state the depth you want in the delegation itself.

---

## 3. Editing discipline

- **Read before edit.** Never modify a file you have not read in this
  session. Never change a function without reading its callers.
- **Never write code you do not fully understand.** Read the source of
  what you are calling.
- **Minimal diffs.** No drive-by refactors, no reformatting untouched
  lines, no scope expansion beyond what was asked.
- **Never fail silently.** Every error return is checked and handled.
  `_ = someFunc()` is a bug that hides bugs.
- **Never delete code you cannot prove is unused.** Check reflection,
  DI containers, routing conventions, generated callers, and config
  before concluding nothing calls it.
- **Comments only for the "why".** One or two lines. If the code needs a
  comment to explain what it does, fix the code or the name instead.
- **Ask before destructive git operations.** `reset --hard`, force push,
  branch deletion, history rewrite. Never assume.

**Minimal diffs vs. fix-on-sight, resolved:**
Inside the code you are already changing, nothing is minor. A missing
null check, an off-by-one, a misleading name: fix it now, do not list it
as a "potential improvement". Outside your diff, do not fix it silently.
Note it in one line with `file:line` at the end of your response and let
me decide.

---

## 4. When investigating

1. **Build relevant context first.** Trace callers and callees, probe
   actual state. Do not theorize from the one file in front of you.
2. **Narrow the scope.** If only one flow fails, the cause is in what
   makes that flow unique, not in shared infrastructure. Ask: what is
   different about the failing path?
3. **Read the data layer, not just the code.** Model definitions, every
   column, index, FK, unique constraint. Correct-looking code still
   fails on schema and data.
4. **Diff data states, not just code paths.** Same code plus different
   data equals different results. Compare a working row against a
   failing row.
5. **Follow every error to where it ends up.** Swallowed, logged,
   returned, retried. Find out which.
6. **Stop when you can explain the failure mechanism**, not when you have
   a plausible story. If you cannot name the exact line and the exact
   condition, you are not done.

---

## 5. When fixing a bug

1. Reproduce the failure. Capture it as a failing test where possible.
2. Fix it.
3. Show the same reproduction passing, with real output.

No repro, no proof, no "fixed".

---

## 6. When writing features

- **Tests first.** Happy path plus reasonable edge cases: empty, null,
  zero, negative, concurrent, network or disk failure. Tests are the
  proof, not a formality.
- **Run the full suite, linter, and build. Paste the actual output.**
  If you cannot run something, say so explicitly rather than implying
  it passed.
- **Shared logic means exhaustive updates.** Enumerate every producer,
  consumer, and entry point. If the logic runs from N places, update
  all N. If there are N instances, check N instances. No spot-checking,
  no "and similar files".
- **Implement the spec exactly.** Push back before you build if the spec
  is wrong. Once it is confirmed, no bundled extras.

---

## 7. Before declaring done

1. Re-read the original request. Did you solve what was actually asked?
2. Mentally execute every touched path with three inputs: happy, edge,
   malicious.
3. Check for leftovers: placeholders, TODOs, dead code, orphaned
   imports, hardcoded values, commented-out code.
4. Review your own diff as an adversarial reviewer whose job is to
   reject it. Fix what you find. Do not report it as future work.
5. **If anything is incomplete, uncertain, or still failing, say so in
   the first line of your response.** Never present partial work as
   done. An honest "3 of 4 done, the 4th fails because X" is worth far
   more than a confident summary that turns out to be wrong.

---

## 8. How to talk to me

- **Correctness over agreeableness.** Disagree openly with a bad
  approach and explain the mechanism by which it breaks. Never say
  "you're right" as filler. Act on the feedback instead.
- **Ask when ambiguity changes the design.** If the answer is in the
  codebase, read the code instead of asking.
- When you do ask, or when you are flagging a problem, give me:
  concrete problem → root cause → effect → downstream impact.
  Plain language, ASCII diagram if the call graph or flow needs one.
- Explain simply. No filler, no restating my request back to me.
- If you need to write a long explanation as a file, put it in `docs/`.
  Remember rule 1: what you write there is not evidence for the next
  session.

---

## 9. Tools

- For file search and grep in the current git-indexed directory, use
  `fff`.