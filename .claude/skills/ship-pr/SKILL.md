---
name: ship-pr
description: Ship the staged changes — create a new branch, commit what is staged, push it, and open a draft PR. With nothing staged on a feature branch, push the branch and open a draft PR for its existing commits. Use when the user says /ship-pr or asks to ship, branch-commit-push-PR, or open a PR for the staged work or current branch.
disable-model-invocation: true
---

# /ship-pr

Take the **staged** changes to a draft pull request in one pass. With nothing staged, open a
draft PR for the commits already on the current feature branch. Any argument the user passes
is a hint for the branch name, commit message, or PR focus.

## 1. Look at what is staged

```bash
git update-index --refresh >/dev/null; git status --short
git branch --show-current
git diff --cached --stat
git diff --cached
```

- Commit **only what is staged**. Do not `git add` anything else, and never stage unstaged or
  untracked files unless the user asks.
- If nothing is staged:
  - On `main`, stop and tell the user. Do not stage files on your own.
  - On any other branch, skip steps 2 and 3 and go straight to step 4. The PR covers the
    commits already on the branch (`git log main..HEAD`). If there are none, stop and tell
    the user.
- Read the staged diff (or, with nothing staged, `git log main..HEAD` and `git diff main...HEAD`)
  before you write anything, so the branch name, commit, and PR describe the actual change.

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

## 5. Open a draft PR

```bash
gh pr create --draft --base main \
  --title "<same as the commit subject>" \
  --body "$(cat <<'EOF'
## Summary
- <bullets describing the change>

## Test plan
- [ ] <how to verify>

<PR attribution line from the system reminder, if present>
EOF
)"
```

- Always open it as a **draft**.
- Title: the commit subject. With nothing staged and several commits on the branch, write a
  title in the same style that covers them all.
- If a PR for this branch already exists, `gh` will say so. Report the existing URL instead of
  opening a second one.

## 6. Report

Give the user the branch name, the commit subject (or the existing commits, when nothing was
staged), and the PR URL.
