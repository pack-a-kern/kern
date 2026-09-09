# Code quality standard — binding

`BLUEPRINT.md` §19. These are enforced by tooling where possible and by review where not.

## 1. DRY — with the blueprint's precise nuance (§19.2)

> **Duplication of stable domain knowledge must be removed. Incidental similarity does not justify
> an abstraction.**

- A fiscal threshold, rule, or business invariant appearing twice is a **defect** — extract it.
- Two functions that merely look alike but change for different reasons are **not** duplication.
  Leave them alone.
- Do not build a `RuleEngineFactoryBuilderManager` to save ten similar lines. Premature abstraction
  is more expensive than the duplication it removes, because it couples things that should move
  independently.

**Before writing new code, search for an existing implementation.** Reuse beats reinvention, and an
agent that adds a second way to do something already solved has made the codebase worse.

## 2. No dead code — none

Forbidden outright (§19.1, §28 rule 10):
- Commented-out code. Git remembers; delete it.
- Unused functions, classes, variables, imports, routes, config keys, CSS.
- Empty directories or placeholder modules "for a future milestone".
- Feature flags for features that do not exist.
- Speculative parameters, interfaces, or abstraction layers with a single implementation added
  "in case we need it later".
- `TODO` without an issue reference and an owner.

Build only what the task asks for. Extensibility comes from good boundaries, not from unused code.

## 3. Naming and annotation

- Names say what a thing *is* or *does* in domain language — `TaxSubject`, not `Entity2`.
- Types carry the contract; PHPDoc only where the type system genuinely cannot express it.
- Comments explain **why**, never **what** — the code already says what.
- Do not paste legislative quotations into docblocks (§20). Source IDs live in the knowledge DB.
- Every `TaxRule` carries machine-readable metadata (`#[TaxRule(code:…, version:…)]`), per §20.
- Public domain services get a short docblock **only** when the contract is not evident from the
  signature. Examples belong in tests, not in comments that go stale.

## 4. Non-negotiables

- `declare(strict_types=1);` in every PHP file. Return types everywhere. PSR-12.
- No float for money — `Money` value objects over integer minor units.
- No magic numbers, and absolutely no magic tax numbers.
- No catch-all exception handlers that swallow the error.
- No implicit timezone. UTC in storage; `Europe/Bucharest` stated explicitly where it applies.
- No PII in logs, ever.
- Enums for finite state. Readonly DTOs and value objects. Constructor injection.
- No facades or service locators inside domain core.

## 4b. A formatter trap you must know about — object comparison

`pint.json` enables **`strict_comparison`**, which silently rewrites `==` into `===` and `!=` into
`!==`. That is right for scalars and **actively wrong for objects**: `!==` on two distinct object
instances is *always* true regardless of their values.

This has already caused one real bug. In `Domain/Wallet`, a rate-period validator compared two
`DateTimeImmutable` values with `!=`; Pint rewrote it to `!==`, and the validator began reporting
false gaps and overlaps on perfectly valid data. Static analysis did not catch it — a test did.

**Rules:**
- Never rely on `==` / `!=` to compare objects by value. Pint will change it under you, and the
  change looks harmless in review.
- Compare explicitly instead: the spaceship operator (`<=>`, which Pint leaves alone), an
  `->equals()` method on the value object, or an explicit scalar (`->getTimestamp()`,
  `->format(...)`).
- Comparing an object's **scalar properties** with `===` is correct and expected — that is what the
  `Money::equals()` implementations do.

## 5. Tests are part of the code, not an afterthought

- Written with the change, not bolted on after.
- Tax rules: many pure unit tests, no database, no HTTP, no clock.
- Every official ANAF numeric example becomes a golden test (§21.2).
- Security tests are mandatory for cross-tenant isolation, encryption-at-rest assertions against the
  raw database, and sensitive-logging checks (§21.4).
- Never weaken or skip a test to make CI pass (§28 rule 8). Fix the code.
