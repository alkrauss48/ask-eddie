---
paths:
  - config/books.php
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
