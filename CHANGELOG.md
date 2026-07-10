# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Dutch honorifics (Dhr., Mevr., Mw.) in the default parser, so "Dhr. Jan de Vries" reads the title as a salutation instead of a first name.
- Legal credential JD, so "King, Michelle JD, LPC" keeps both credentials in the suffix.
- `Name::getSource()` returns the normalized input the name was parsed from (null for a manually constructed Name), the same string `getConfidence()` assesses.

### Changed

- `Name::toArray()['given_name']` is now documented as first, middle, and initials only. Use `full_name` when you need the given name plus surname.
- Custom mapper lists set with `setMappers()` now survive `setMaxCombinedInitials()`, `setMaxSalutationIndex()`, and `setNicknameDelimiters()`. Passing an empty list resets the parser to the default pipeline.
- Hostile inputs (kilobytes of unmatched quotes, 100 KB tokens of any case shape, megabyte rows) now parse in linear time and bounded memory.
- `LanguageInterface` documents the dictionary key format: keys must already be normalized (lowercase, periods removed, no edge punctuation) as `Text::key()` produces, and may be int or string, so a numeric ordinal like the German "2." keys under the bare digit.
- Export is faster on large batches; a full parse-plus-`toArray()` row is net faster than 1.2.0, while raw `parse()` alone is slightly slower from the new credential and comma safeguards.

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
- Unknown trailing credentials are kept when a known credential anchors the tail, so "John Smith MD FACS" keeps both MD and FACS in the suffix instead of leaking FACS into the name. Uniform all-caps rows still cannot recover an unknown credential (casing carries no signal there).
- A credential-only segment after the given name is pulled out to the suffix, so "Smith, MD, John" keeps first name John and credential MD instead of reading MD as a name. Mixed given-plus-credential tails ("John Smith, MD, FACS") keep all credentials.
- A leading credential run in the given segment maps to the suffix, so "Smith, MD John" keeps first name John and credential MD instead of shredding MD into initials.
- The confidence pass keys punctuation-wrapped tokens the same way the parser does, so "NGUYEN, VI;" is flagged as ambiguous instead of slipping past on the trailing semicolon.
- German ordinal suffixes are recognized, so "Friedrich Wilhelm 2." keeps 2. as the suffix (the ordinal keys under the bare digit).
- Caseless-script names are not split into initials, so "Wang, 李明" keeps 李明 as the first name instead of splitting it into two initials.
- Comma-form initials use the whole input's casing, so "Smith, JM" splits JM into J and initial M (the mixed-case surname proves the signal) while all-caps "SMITH, JM" keeps Jm as a first name.
- Surname-first parsing handles a leading salutation and a credential-only tail, so "Dr. Kim Jong Un" keeps surname Kim (not the title) and "Kim Jong Un, MD" keeps surname Kim with credential MD instead of falling back to Western order.
- A comma inside a delimited nickname no longer bisects the name and survives into the nickname, for bracketed, quoted, and custom multi-character forms alike: "John (Bob, Jr) Doe", "John 'Bob, Jr' Doe", and "John <<Bob, Jr>> Doe" (with `['<<' => '>>']`) keep nickname "Bob, Jr", and the given-side "Smith, John (Jack, Robert)" keeps "Jack, Robert" whole.
- An all-caps token behind a preserved name token stays combined initials, so "John Paul JM Smith MD" keeps initials J M; an unknown credential is only recognized inside the contiguous run at the tail.
- Surname-first input with both a leading salutation and a credential-only comma tail keeps the surname, so "Dr. Kim Jong Un, MD" gives surname Kim, salutation Dr., and credential MD.
- Multibyte custom whitespace (U+3000, NBSP) no longer corrupts unrelated glyphs that share its bytes; the collapse pattern matches whole characters. A whitespace set that is not valid UTF-8 keeps the old bytewise semantics instead of warning on every parse.
- Invalid-UTF-8 nickname delimiter keys are ignored instead of emitting a compile warning per token.
- Nickname delimiters accept multibyte and multi-character opener/closer pairs, and empty-string delimiter keys are ignored instead of emitting a warning per parse.
- An elided surname particle survives instead of being read as an unterminated nickname or an initial, so "'t Hooft" keeps the leading particle.
- Spaced parentheses yield a clean nickname, so "John ( Bob ) Smith" gives nickname Bob without stray spaces, and a delimiter pair that cleans to nothing emits no nickname at all.
- A name part that normalizes to "0" survives `getAll()` and the string cast, so "Jane 0" is not silently dropped.
- `setWhitespace('')` no longer emits a warning per parse; an empty whitespace set simply skips the collapse step.

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

[Unreleased]: https://github.com/iliaal/nameparser/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/iliaal/nameparser/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/iliaal/nameparser/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/iliaal/nameparser/releases/tag/v1.0.0
