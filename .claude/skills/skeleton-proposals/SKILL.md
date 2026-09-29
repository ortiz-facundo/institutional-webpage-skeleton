---
name: skeleton-proposals
description: List every pending skeleton proposal saved in engram by the downstream projects (sci_gestion, web-nova-argentum), review them with the user, and mark each one implemented or discarded. Use when the user runs /skeleton-proposals or asks what the downstreams proposed for the skeleton.
---

# Skeleton proposals — review

Downstream projects record changes that could be generalized into the
skeleton as engram memories of `type: proposal` (convention: AGENTS.md →
"Skeleton proposals (engram)"). This skill lists the pending ones and records
the user's decisions.

## 1. Check the repo

Run this only in the skeleton. If `git remote get-url skeleton` succeeds,
this is a downstream: tell the user to run the skill from
`symfony-backoffice-skeleton` and stop.

## 2. Collect pending proposals

Load `mcp__engram__mem_search` and `mcp__engram__mem_update` with ToolSearch
if they're deferred.

Call `mem_search` with `query: "pending"`, `type: "proposal"`,
`all_projects: true`, `limit: 20`. If 20 come back, repeat per downstream
(`project: "sci_gestion"`, `project: "web-nova-argentum"`) so nothing is
cut off.

Search matches words anywhere in the text, so keep only results whose
`**State**:` line is exactly `pending`. Use `mem_get_observation` when a
preview is truncated.

If nothing is pending, say so and stop.

## 3. Show the list

One table, oldest first:

| id | project | feature | commits | why generic |
|---|---|---|---|---|

Group rows that clearly describe the same feature. Then ask the user
which one to look at first.

## 4. Review with the user

For each proposal the user picks:

- Show the change: `git -C ../<project> show --stat <sha>`, and the
  full diff of the shared parts when asked.
- Say whether the skeleton already has something similar (search the
  skeleton's code and `git log --format='%h %s'` for the same subject), and
  sketch what the generic version would look like.
- The user decides. Never decide on their behalf.

Implementing a proposal is normal skeleton work, following AGENTS.md. Never
commit (hard rule 1). When the user commits, remind them to add the trailer
`Source: <project>` (see `BRANCHING.md`).

## 5. Record the decision

Call `mem_update` on the proposal's id:

- Implemented: `find: "**State**: pending"`,
  `replace: "**State**: implemented (skeleton <sha>)"`. Before the skeleton
  commit exists, keep it `pending` and record it once the user gives you the sha.
- Discarded: `find: "**State**: pending"`,
  `replace: "**State**: discarded (<user's reason>)"`.

After each update, confirm it in one line. At the end, give the count of
proposals that are still pending.
