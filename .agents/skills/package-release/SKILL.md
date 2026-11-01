---
name: package-release
description: >
  Cut a release of kayedspace/laravel-mcp-bridge. Use when asked to tag a
  version, prepare release notes, or check whether something is safe to
  release.
license: MIT
---

# Releasing

## Before tagging

```bash
composer test
```

That is the whole gate, and it is what CI runs. Then check the three things CI
cannot:

1. **The dist archive.** `git archive --format=tar HEAD | tar -t` should list
   `src`, `config`, `database`, `resources`, `composer.json`, the README and
   the LICENSE, and nothing else. A development directory that slipped past
   `.gitattributes` ships to every consumer.
2. **The manifest format.** If `ManifestRepository::VERSION` changed, a
   compiled manifest from the previous release will not be read. That is a
   breaking change for anyone who caches the manifest in their build, and it
   belongs in the release notes.
3. **Config keys.** A key that was renamed or removed silently changes
   behaviour for an application that published the config file before. Keep
   reading the old key for one minor version and say so, or call it breaking.
4. **Migrations.** Before the first tag a shipped migration may be edited in
   place. From the first tag on, every schema change is a new migration file,
   because an application that already ran the old one will never run it
   again. `git log --since=<last tag> -- database/migrations` showing a
   modified file rather than an added one is a release blocker.

## Versioning

Until 1.0 the public API may change, which is stated in the README. Even so:

- A new attribute, source, command or config key is a minor.
- A changed default that exposes more than it did before is **breaking**, no
  matter how small. Exposure defaults are the promise this package makes.
- A changed refusal, a stricter check or a narrower default is a minor, and
  goes in the notes so nobody debugs it as a regression.

## The notes

There is no changelog file. Release notes live on the GitHub release, built
from pull request labels through `.github/release.yml`: `breaking`,
`enhancement`, `bug`, `documentation`, `dependencies`. The first release has
no notes about changes, because there is nothing before it to change from.

Write the summary for someone upgrading, not for someone reading the diff.
What changes for an application that does nothing? What must they change?
