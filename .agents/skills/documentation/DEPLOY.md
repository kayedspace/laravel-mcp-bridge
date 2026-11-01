# Deploying the documentation site

The site lives in `docs/` of this repository and builds to static files, so it
needs no PHP, no database and no runtime. It is deployed from the same push
that deploys nothing else: a code-only change rebuilds it for no reason, which
the watch paths below prevent.

## Coolify

A **Static Site** resource, not an application, pointed at this repository:

| Setting | Value |
| --- | --- |
| Build pack | Static |
| Base directory | `/docs` |
| Install command | `npm ci` |
| Build command | `npm run build` |
| Publish directory | `/docs/.vitepress/dist` |
| Watch paths | `docs/**` |
| Domain | `laravel-mcp-bridge.kayed.dev` |

`npm ci` rather than `npm install`, so the build uses `package-lock.json`
exactly and a transitive release cannot change the site between deploys.

The domain is already what `composer.json` declares as `homepage` and
`support.docs`, and what every documentation link in `README.md` points at, so
changing it means changing those three places too.

## Checking a build before pushing

```bash
cd docs
npm ci
npm run build     # fails on a dead internal link, which is the point
npm run start     # serves the build on $PORT, default 4173
```

`npm run build` is the gate: VitePress fails the build on a link to a page
that does not exist, so a renamed page cannot ship a broken navigation.

## What is not deployed

`.gitattributes` export-ignores `docs/`, so none of this reaches a
`composer require`. A consumer downloads `src`, `config`, `database` and
`resources` only, and no JavaScript at all.

## Dependency advisories

`npm audit` reports findings in the build toolchain: Vite and esbuild's
development server, and KaTeX inside Mermaid. They affect a dev server that is
never run in production, and nothing from `docs/` ships in the package, so
they are not a consumer's exposure. Worth clearing when VitePress releases a
version that moves off them, not worth a forced major upgrade that breaks the
site.
