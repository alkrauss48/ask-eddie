---
paths:
  - config/books.php
  - config/bar.php
---

# Config

## "X" / "X Cocktail" is a review, never a rule
There are 394 exact `X` / `X cocktail` canonical_key pairs. A blanket suffix-strip in DrinkNameNormalizer::key() is NOT safe and must not be added.

Measured on the corpus:
  Manhattan (10) / Manhattan Cocktail (20)   -- same drink, should merge
  Martini (8)    / Martini Cocktail (18)     -- same drink, should merge
  Champagne (11) / Champagne Cocktail (27)   -- DIFFERENT: bare name is the wine
  Gin (15)       / Gin Cocktail (26)         -- DIFFERENT: bare name is the spirit
  Brandy (16)    / Brandy Cocktail (25)      -- DIFFERENT

The discriminator is "is the bare name also an ingredient", which is a judgment, not a regex. Merging wrongly produces exactly the failure the fuzzy-matching block above already refuses to risk: a real book, a real page, a real byte offset, and the wrong word printed on it -- every invariant green, and a guest cannot tell.

`books:drinks --suffixes` prints the pairs with both book counts for review; safe ones get pasted into books.drinks.aliases, which already wins over the clusterer. Suggestion in, never a write -- the same doctrine as fuzzy.

Worth knowing for later: an ingredient layer would supply the missing discriminator and could reduce this to the genuinely ambiguous handful.

## One command, two bartenders, one registry row each
`bar:ask --bartender=` selects a row in `config/bar.php`, and that row decides the agent AND the retriever together. Do not split them into two options: `--sources` on Sasha's answer showing Eddie's passages would be a table of real citations from the wrong corpus, with nothing on screen to say so.

Provider and model live here and are passed at call time (`stream($q, provider: …, model: …)`) rather than as `#[Provider]` / `#[Model]` attributes on the agent classes, so `SASHA_PROVIDER` / `SASHA_TEXT_MODEL` move one bartender without touching a class. Null means "whatever `ai.default` says".

`config('bar.labels')` holds the in-character tool labels that used to be a private const on `AnswerStream`. The constructor takes the map; an unlisted tool falls back to its class name on purpose, because a silent fallback to nothing would hide a tool call entirely and make a pause look like a hang. `BarAskCommandTest` asserts every tool either bartender carries has a label.
