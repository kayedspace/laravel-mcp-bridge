# Contributing

Thank you for looking. A few things that will make a pull request land faster.

## The gate

```bash
composer test
```

That runs PHPStan at max level, Pint in check mode, and the test suite, which
is exactly what CI runs. `composer lint` fixes formatting rather than
reporting it.

## What a change needs

- A test. Not for what Laravel or a package already guarantees, only for the
  behaviour being added or fixed.
- A reason in the commit message. What the code does is in the diff; why it
  does that is not.
- No new dependency without saying what it replaces. This package already
  decides a lot on an application's behalf, and every dependency it adds is
  one an application inherits.

## Security

Please do not open a public issue for a vulnerability. See
[SECURITY.md](SECURITY.md).

## Where the reasoning lives

In the code. Comments say why rather than what, `AGENTS.md` describes how the
package is put together, and `tests/ArchTest.php` states the rules that are
enforced rather than remembered.

If a change contradicts something the package asserts about itself, say so in
the pull request rather than changing the assertion quietly.
