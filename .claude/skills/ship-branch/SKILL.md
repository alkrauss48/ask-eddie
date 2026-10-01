---
name: ship-branch
description: Put the staged changes on a new branch — create the branch, commit what is staged, and push it. No PR. Use when the user says /ship-branch or asks to branch, commit, and push the staged work without opening a PR.
disable-model-invocation: true
---

# /ship-branch

Take the **staged** changes to a pushed branch in one pass. Do not open a PR. Any argument the
user passes is a hint for the branch name or commit message.

## 1. Look at what is staged

```bash
git update-index --refresh >/dev/null; git status --short
git diff --cached --stat
git diff --cached
```

- Commit **only what is staged**. Do not `git add` anything else, and never stage unstaged or
  untracked files unless the user asks.
- If nothing is staged, stop and tell the user. Do not stage files on your own.
- Read the staged diff before you write anything, so the branch name and commit describe the
  actual change.

## 2. Branch

- If you are on `main`, create a branch: `git switch -c <type>/<short-kebab-summary>`, with
  `<type>` describing the change (`feat`, `fix`, `refactor`, `docs`, `chore`, `ci`, …), e.g.
  `feat/overheard-consult`.
- If you are already on a feature branch, stay on it and commit there.

## 3. Commit

Match this repo's style: a plain imperative sentence that says what the change does, no
`type(scope):` prefix, capitalized, no trailing period, under about 72 chars (e.g. "Stream the
consult live so the guest can overhear it"). Add a short body that says what changed and why.
End with the attribution line from the system reminder, if one is present.
Pass the message through a heredoc (`git commit -F - <<'EOF' … EOF`).

## 4. Push

```bash
git push -u origin HEAD
```

## 5. Report

Give the user the branch name and the commit subject. Mention that `/ship-pr` can open the PR
later if they want one.
