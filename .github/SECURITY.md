# Security

This package sits between AI agents and an application's own data: it issues
tokens, decides what an agent may call, and records what it did. A flaw here
is a flaw in someone's authorization, so please report one privately rather
than in a public issue.

## Reporting

Open a [private security advisory](https://github.com/kayedspace/laravel-mcp-bridge/security/advisories/new),
or email 3likayed@gmail.com. You will get an acknowledgement within a few
days, and a fix or an explanation of why something is not a vulnerability.

Please include the package version, the Laravel version, and the smallest
example that shows the problem.

## What is in scope

Anything that lets an agent reach a tool it was not granted: a scope or
ability check that can be bypassed, a confirmation handle that can be forged
or replayed, a staged upload one caller can claim from another, a token that
survives revocation, a secret that survives redaction, or a tool exposed
without the opt-in its source requires.

## What is not

An application that configures `mode` as `opt-out` and exposes a route it did
not mean to, or that turns off `require_scope_for_non_http` and then exposes a
command. Those are documented choices with documented consequences, and
`mcp-bridge:lint` reports them.

## Supported versions

Fixes land on the latest minor release. Until 1.0, that means the latest
release.
