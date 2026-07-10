# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Dutch honorifics (Dhr., Mevr., Mw.) in the default parser, so "Dhr. Jan de Vries" reads the title as a salutation instead of a first name.
- Legal credential JD, so "King, Michelle JD, LPC" keeps both credentials in the suffix.

### Changed

- `Name::toArray()['given_name']` is now documented as first, middle, and initials only. Use `full_name` when you need the given name plus surname.
- Custom mapper lists set with `setMappers()` now survive `setMaxCombinedInitials()`, `setMaxSalutationIndex()`, and `setNicknameDelimiters()`. Passing an empty list resets the parser to the default pipeline.

### Fixed

- Credential-only rows such as "Jane DDS" and initial-plus-credential rows such as "John A. MD" now keep the credential in the suffix instead of rewriting it as the last name.
- All-caps short given names stay intact beside mixed-case salutations or credentials, so "JO ANDERSON PhD" keeps first name Jo.
- Parenthetical credentials such as "Jane Doe (MD)" now parse as suffixes instead of nicknames.
- Comma surname segments with real given names keep non-particle compound surnames and left-side suffixes, so "Hidalgo Castillo, Maria" and "Doe Jr, John" parse as expected.
- Interrupted credential tails keep recognized credentials in the suffix while preserving name-like bridge tokens; placeholder/punctuation noise such as "Unknown" or "-" is stripped from the tail, including immediately before the first credential ("Jane Doe Unknown MD").
- Bare single-letter roman numerals stay part of the name instead of becoming a suffix, so "Malcolm X" keeps X as the last name. Multi-letter forms ("John III") still parse as suffixes.
- A trailing comma with nothing after it ("John Smith MD,") no longer appends a trailing space to the first name.
- Nicknames preserve internal apostrophes, so "John (O'Brien) Smith" keeps O'Brien.
- Single-token salutations such as "Mr" now parse as salutations under the default salutation scan.
- Trailing punctuation no longer blocks credential lookup for tokens such as "MD;" and "MD)".

## [1.2.0] - 2026-06-27

### Added

- Dutch and Spanish surname particles (den, ten, los, las), so "van den Heuvel" and "de los Santos" keep the full surname.
- German (vom, zu, zum, zur) and French (le, des) particles in the default parser, so "vom Bruch" and "le Pen" parse without a language class.
- Portuguese (do, dos, das), Filipino joined (dela, delos, delas), and Italian (lo) surname particles, so "Joao dos Santos", "Maria dela Cruz", and "lo Russo" keep the full surname instead of orphaning the particle into the middle name.
- `setSurnameFirst(true)` reads comma-less names in CJK order (surname first), so "Mao Zedong" gives last "Mao". Opt-in; auto-detection from romanized text is not possible.

### Fixed

- Comma form "Last, First" no longer leaks a leading surname particle into the first name ("van der Berg, Johan" gives last "van der Berg", not first "Van Johan").
- A multi-particle surname with no first name keeps its leading particle ("von der Heide", "Dr. de la Cruz"), instead of reading it as the first name.
- A particle in a compound given name renders lowercase, matching surname prefixes ("Maria del Carmen" gives middle "del Carmen").

## [1.1.0] - 2026-06-24

### Added

- `Name::toArray()` returns every part under a fixed key set (empty string when absent), a machine-readable shape that is safe to consume without existence checks, unlike `getAll()`.
- `Name::getConfidence()` exposes the advisory confidence signal on the parsed result, derived from the same input the parser saw. `Parser::parse()` output is unchanged; the check is opt-in.
- Confidence now flags all-caps tokens that collide with Census surnames (II, III, IV, MBA) in uniform-case input, in addition to the existing name-leaning keys.
- Two-letter given names in all-caps input are kept as names instead of being split into initials; "JO ANDERSON" keeps first name Jo. Mixed-case combined initials like "JM Walker" still split.
- Comma input keeps a middle name after a second comma; "Smith, John, Robert" keeps Robert, while trailing and credential-only segments like "Smith, MD, PhD" still strip to suffixes.

### Changed

- Config setters (`setMaxCombinedInitials`, `setMaxSalutationIndex`, `setNicknameDelimiters`) take effect on a reused parser even when called after the first `parse()`, instead of using configuration cached on that first call.
- `getFullName()` and `toArray()['full_name']` no longer pad with a stray space when the first or last name is absent; "John" alone returns "John", not "John ".

### Fixed

- A lone bracket or quote token no longer crashes `parse()` with a TypeError; inputs like "(" or "Smith, (" return an empty Name instead of aborting the row.
- Multi-word salutation matching no longer accepts a partial tail, so "Smith, Her" keeps Her as the given name instead of reading it as "Her Honour", and no longer reads past the token list when a match shrinks it.

## [1.0.0] - 2026-06-07

### Added

- Casing-aware credential matching: an ALL-CAPS token reads as a credential, title or lower case as a name, so surnames like Do, Vi, Ma, and Ba no longer parse as suffixes.
- Nursing and allied-health credentials from the NPI registry (RN, NP, PharmD, APRN, PA-C, OTR/L, and 30+ more); first/last accuracy on 30k real names rose from 92.8% to 95.3%.
- `Confidence::assess()` flags names whose credential-vs-name split is undecidable from casing, for manual review.
- Expanded base credential and salutation dictionary (DDS, DO, DVM, PsyD, LCSW, Hon., roman numerals VI to X), from the CodeByZach fork.

### Changed

- Namespace is `Iliaal\NameParser` (was `TheIconic\NameParser`).
- Requires PHP 8.3+ and `ext-mbstring`. Tested through PHP 8.5.
- Tooling: PHPUnit 12, PHPStan 2 (level 9), PHP-CS-Fixer, GitHub Actions.

### Fixed

- Unclosed nickname delimiter no longer swallows the surname or leaks a stray bracket; "John (Bob Smith" keeps last name Smith (via tobyberster/name-parser).
- Multibyte initials are no longer corrupted; accented tokens like "É Durand" survive instead of becoming replacement characters.
- Trailing comma-separated credentials are no longer dropped; "Smith, John, MD, PhD" keeps both.
- Empty nickname no longer renders as "()" in the string cast of a name.
- `setWhitespace()` now trims the configured characters from the edges of the input.
- `setMaxSalutationIndex()` larger than the token count no longer emits undefined-array-key warnings.
