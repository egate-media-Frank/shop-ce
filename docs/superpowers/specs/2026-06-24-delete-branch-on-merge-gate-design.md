# Design: `delete_branch_on_merge` pre-flight gate for `bin/release`

**Issue:** o3-shop/o3-shop#190 — *Policy: release repos MUST keep `delete_branch_on_merge = false` (and verify it automatically)*
**Repo:** `o3-shop/shop-ce` (release tooling lives here)
**Date:** 2026-06-24
**Status:** Approved design — ready for implementation plan

## Problem

`bin/release` opens its merge-back PR with the **release branch itself as the head** (`b-1.x → main`). If a repo has GitHub's "Automatically delete head branches" (`delete_branch_on_merge = true`) enabled, merging that PR **deletes the release line branch**. The next release (`bin/release --to <next>`) then walks `shop-ce@b-1.x`, fails, and the maintenance line is gone until someone restores it by hand.

This already happened in v1.6.1: `shop-ce` had `delete_branch_on_merge = true`, so merging merge-back PR #168 deleted `b-1.6`. It was restored by re-pushing the v1.6.1 tag commit. `shop-metapackage-ce` and `o3-shop` survived only because their auto-delete was already off.

All three repos were set to `false` on 2026-06-04, but a repo setting is invisible and easy to flip back via the GitHub UI ("automatically delete head branches" is a common tidy-up). The policy must be **enforced by tooling, not trusted to memory**.

## Scope

**In scope (this branch):**
1. A `bin/release` pre-flight gate that verifies `delete_branch_on_merge == false` for every repo the run will open a merge-back PR against, aborting with a clear, actionable message if any is `true`.
2. A short "Create a Release" **prerequisite** doc snippet (markdown, in-repo) stating the policy and the verify/fix commands, for the maintainer to paste into the `projects.wiki.tro.net` "Create a Release" page.

**Out of scope (separate work):**
- #189 — opening the merge-back PR from a throwaway `merge-back/vX.Y.Z` branch so the release line is never the PR head. (Complementary belt-and-suspenders fix; either fix alone prevents the deletion. Tracked separately.)
- Auto-remediation (the gate verifies and aborts; it does **not** flip the setting back).

## Design

### Component: `DeleteBranchOnMergeGate`

New gate at `source/Internal/ReleaseTooling/Flow/Gates/DeleteBranchOnMergeGate.php`, mirroring the existing `gh`-CLI-backed gates (`IncomingPrGate`, `MergeBackPrGate`) one-for-one.

- `implements PreFlightGate`
- `public const NAME = 'delete-branch-on-merge';`
- Constructor: `(ProcessExecutor $exec, string $ghBin = 'gh')`
- `name(): string` → `self::NAME`
- `evaluate(string $repoPath, string $expectedBranch, string $packageName): GateOutcome`:
  1. Resolve the repo slug via `PackageRepoSlug::resolve($packageName)`.
  2. Run `gh api repos/<slug> --jq .delete_branch_on_merge` through `ProcessExecutor` (timeout 60s, same as sibling gates).
  3. Branch on the trimmed stdout:
     - `'false'` → `GateOutcome::passed(self::NAME)`
     - `'true'` → `GateOutcome::abort(self::NAME, [...])` (see message below)
     - **anything else / `gh` non-zero exit** → `GateOutcome::abort(self::NAME, [...])` — **fail-closed**: "couldn't verify the setting, so don't proceed."

The gate is **read-only** (a single `gh api` GET) and ignores side effects, consistent with the other gates.

### Repo scope — why no hardcoded list

The gate is dropped into the existing per-repo `PreFlightRunner` loop. That loop already runs for **exactly the set that receives a merge-back PR**: every tagged candidate plus `o3-shop/o3-shop` (see `LiveExecutor::openMergeBackPrs()` and the pre-flight loop in `ReleasePlanner`). So the gate automatically checks precisely the repos at risk — no central repo list to maintain or let drift. The issue names three repos (`shop-ce`, `shop-metapackage-ce`, `o3-shop`) because those are today's release-line repos; the dynamic set is strictly safer and matches the codebase's deliberate "discover repos from the dependency graph" design.

### Enforce on final releases only

Merge-back PRs are auto-opened **only for final shop releases** (target `--to` without an `-rc`/`-alpha`/`-beta` suffix). An RC run opens no merge-back PR, so a flipped setting cannot bite it. The gate is therefore **registered only for final-release runs**, so an RC release is never aborted over a setting that is irrelevant to it. This matches the issue's wording precisely: verify "*for every repo it will open a merge-back PR against*."

Implementation: `ReleaseCommand::buildDefaultPreFlightRunner()` already constructs the gate array. It will conditionally append `DeleteBranchOnMergeGate` when the resolved `--to` is a final (non-prerelease) version, reusing the existing prerelease-suffix detection that governs merge-back PR opening. The `PreFlightGate` interface is unchanged (no new parameters threaded through every gate).

### Abort message

The abort message names the offending repo and includes the **exact remediation command**, so the operator can fix it immediately, e.g.:

```
Repo o3-shop/shop-ce has delete_branch_on_merge = true. Merging the merge-back
PR would delete the release branch 'b-1.6'. Set it to false before releasing:
  gh api -X PATCH repos/o3-shop/shop-ce -f delete_branch_on_merge=false
```

For the fail-closed (couldn't-verify) case, the message states that the setting could not be read and includes the underlying `gh` stderr.

### Dry-run behavior

Unchanged. Pre-flight runs only when `repoPaths` is non-empty (live mode, or explicit `--repo-path`); a pure remote dry-run with no repo paths skips pre-flight entirely, preserving the cheap preview path. The new gate inherits this behavior — no new plumbing.

## Testing

Unit tests in `tests/Unit/Internal/ReleaseTooling/Flow/Gates/` using the existing `FakeProcessExecutor`, mirroring `GateBehaviorTest`:

- **passes** when `gh api ... --jq .delete_branch_on_merge` returns `"false"`.
- **aborts** when it returns `"true"` — asserts the message contains the repo slug and the `gh api -X PATCH ... delete_branch_on_merge=false` fix command.
- **aborts (fail-closed)** when the `gh` call exits non-zero — asserts abort (not warning) and that stderr is surfaced.
- **aborts (fail-closed)** on unexpected/empty stdout.
- Asserts `name() === 'delete-branch-on-merge'` and that the exact `gh api repos/<slug> --jq .delete_branch_on_merge` command was invoked.
- **Wiring test**: the gate is present in the runner for a final `--to` (e.g. `v1.6.2`) and absent for a prerelease `--to` (e.g. `v1.6.2-rc1`).

## Wiki prerequisite snippet (deliverable)

A markdown snippet committed in-repo (e.g. `docs/release/delete-branch-on-merge-prerequisite.md`) for the maintainer to paste into the "Create a Release" wiki prerequisites. It will state:
- The policy: the release-line repos (`shop-ce`, `shop-metapackage-ce`, `o3-shop`) MUST keep `delete_branch_on_merge = false`.
- Why (the merge-back-PR deletion footgun; the v1.6.1 incident).
- How to verify: `gh api repos/<slug> --jq .delete_branch_on_merge` (expect `false`).
- How to fix: `gh api -X PATCH repos/<slug> -f delete_branch_on_merge=false`.
- Note that `bin/release` now enforces this automatically on final releases, but the setting should still be checked when adding a new release-line repo.

## Decisions on record

- **Fail-closed** on `gh` failure / unexpected output (abort, not warn) — this gate guards an irreversible footgun.
- **Final-release-only** enforcement — matches "repos it will open a merge-back PR against"; avoids aborting RC runs.
- **Dedicated gate** (not folded into `MergeBackPrGate`) — single responsibility, clean naming/testing.
- **Dynamic repo scope** via the existing per-repo pre-flight loop — no hardcoded repo list.
- **No auto-remediation** — verify and abort only.
