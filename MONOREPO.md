# Monorepo proposal — `wp-tooling`

**Status:** discussion / pre-commitment
**Author:** initial draft for team review
**Driver:** PHPCS + PHPStan rulesets need a home. The team is asking whether wp-tooling should also become the home for the Node tooling packages (ESLint config, Stylelint config, Jest preset) and for the existing CLI work (TTY UI kit, detect-changes, install-hooks, scaffold-registry).

This document is a proposal — push back on anything that doesn't survive contact with reality.

---

## TL;DR

- **Yes, a Node + Composer monorepo for `wp-tooling` is feasible.** Both ecosystems can coexist in one repo with their respective publish flows running side by side.
- **The hard part is Composer.** Packagist cannot install from a subdirectory of a Git repo. We adopt the Symfony pattern: develop in the monorepo, split each Composer package's subtree out to a satellite repo on tag, Packagist serves consumers from the satellite.
- **Recommended stack:** **npm workspaces** (not pnpm, see rationale below) + **changesets** (Node release) + **symplify/monorepo-builder** + **splitsh-lite** via `symplify/monorepo-split-github-action` (Composer release). **Turborepo is optional** — adds caching, not load-bearing. Defer to Phase 2.
- **Recommended sequencing:** do NOT migrate mid-flight. Ship `wp-tooling` v1.0.0 as a single Node package. Create `rtcamp/wp-phpcs` and `rtcamp/wp-phpstan` as standalone Composer repos in parallel. Migrate everything into one monorepo for v2.0.0 as a focused epic. The migration does not get harder by waiting; we get real signal on package boundaries first.

---

## The hard constraint: Packagist

Composer installs a package from a Git repo (or SVN, or a local path). It cannot install from a subdirectory of a Git repo. Packagist registers `vendor/package` against `github.com/vendor/package` — the *whole* repo, not a folder inside it.

This shapes the entire architecture. The Node side does not have this restriction — `npm publish` works fine from any directory. So the monorepo's Composer half needs a publish flow that produces standalone Git repos for each package.

The Symfony pattern, used by Symfony itself and most large PHP monorepos:

1. **Develop** everything inside the monorepo, e.g. `composer-packages/wp-phpcs/`.
2. **Tag** the monorepo (`v1.2.0`).
3. **Split** each Composer package's subtree to a satellite repo (`github.com/rtcamp/wp-phpcs`) using `splitsh-lite`. The satellite mirrors the subtree's full git history.
4. **Packagist webhook** on the satellite picks up the new tag.
5. **Consumers** `composer require rtcamp/wp-phpcs:^1.2` and Packagist serves them from the satellite. No monorepo paths involved.

Satellite repos are **read-only mirrors**. No PR ever lands there directly. All work happens in the monorepo.

---

## Why npm workspaces and not pnpm

We had to choose between npm workspaces and pnpm workspaces. Both work. The trade-offs:

| Dimension | npm workspaces | pnpm workspaces |
|---|---|---|
| Ships with Node | Yes (npm 7+) | No — extra binary, extra step for every dev/CI |
| Disk usage | Hoisted, duplicates | Content-addressable, single copy per version |
| Strict isolation | No — phantom deps possible | Yes — each package only sees declared deps |
| Install speed (cold) | Slower | Faster |
| WordPress ecosystem alignment | Default — Gutenberg, `@wordpress/scripts`, every WP project uses npm | Outlier in WP |
| Worth it for our scope | — | Diminishing returns at 3–8 packages |

**Decision: npm workspaces.** Our packages are zero-runtime-dep tooling. Phantom-dep risk is near zero — we already lint for banned packages in `dependencies`. Disk efficiency does not matter at our scale. The WordPress-ecosystem alignment is real: every consuming skeleton uses npm; adopting pnpm in wp-tooling would mean every contributor and CI workflow installs pnpm just for this repo.

If we hit pnpm-justifying pain later (e.g. a phantom-dep incident, or repo grows past ~15 Node packages), migrating npm → pnpm is mechanical — one PR.

---

## Why Turborepo is optional, not load-bearing

Turborepo is a task runner with caching for JS/TS monorepos (Vercel-owned). It is **not** a workspace manager, **not** a publisher, **not** a linter. It sits on top of npm/pnpm workspaces and orchestrates running `lint`, `test`, `build` scripts across packages.

What it gives us:

- **Task graph awareness** — `turbo run build` runs in topological order based on `dependencies`. `^build` syntax means "build my deps first."
- **Caching** — hashes inputs (source files + lockfile + env) and replays cached stdout if hash matches. Local cache (`.turbo/`) by default. Remote cache (Vercel-hosted or self-hosted) shares hits across CI and developer laptops.
- **Selective execution** — `turbo run test --filter=...[origin/main]` runs tests only for packages changed since `main`.

What it costs:

- A `turbo.json` to maintain.
- A new tool in the toolchain.
- Cache invalidation debugging when something looks "stuck cached" (rare but real).

For wp-tooling's current Node side — 3–5 small packages, lint + test only, no compilation step — the caching wins are real but modest. Maybe 30 seconds saved per CI run on a small PR. Worth doing eventually; not worth blocking the initial monorepo enablement on.

**Decision: defer Turborepo to Phase 2.** Initial monorepo uses npm workspaces + plain `npm run -ws` scripts. Add Turborepo when CI lint+test feels slow or the package count grows past ~8.

Adding Turborepo later is purely additive: drop in `turbo.json`, change `npm run lint --workspaces` to `turbo run lint` in CI, no other code moves.

---

## Repo layout

```
wp-tooling/
├── packages/                          ← Node workspaces
│   ├── wp-tooling/
│   │   ├── package.json               @rtcamp/wp-tooling
│   │   ├── bin/                       CLI entry
│   │   └── src/                       CLI subcommands, TTY UI, scaffold registry, hooks, version-monitor
│   ├── eslint-config/
│   │   └── package.json               @rtcamp/wp-eslint-config
│   ├── stylelint-config/
│   │   └── package.json               @rtcamp/wp-stylelint-config
│   ├── prettier-config/               (optional; only if we have one)
│   │   └── package.json               @rtcamp/wp-prettier-config
│   └── jest-preset/                   (optional; only if we have one)
│       └── package.json               @rtcamp/wp-jest-preset
│
├── composer-packages/                 ← Composer packages (invisible to npm/workspaces)
│   ├── wp-phpcs/
│   │   ├── composer.json              rtcamp/wp-phpcs
│   │   └── ruleset.xml
│   └── wp-phpstan/
│       ├── composer.json              rtcamp/wp-phpstan
│       └── phpstan.neon
│
├── package.json                       ← workspace root, defines `workspaces: ["packages/*"]`
├── package-lock.json                  ← npm lockfile, one for the whole monorepo
├── composer.json                      ← root, path repos for `composer-packages/*`, dev-only
├── composer.lock
├── monorepo-builder.yaml              ← symplify config for Composer version sync
├── .changeset/                        ← changesets config + pending changesets
│   └── config.json
├── .github/
│   └── workflows/
│       ├── ci-node.yml                paths: packages/**
│       ├── ci-composer.yml            paths: composer-packages/**
│       ├── release-node.yml           changesets → npm publish
│       └── release-composer.yml       splitsh-lite → satellite repos
├── CLAUDE.md
├── CHANGELOG.md                       ← root-level aggregate, or per-package via changesets
└── MONOREPO.md                        ← this doc, deleted once the migration is done
```

### Why split by ecosystem (`packages/` + `composer-packages/`) instead of mixed

Symmetric layout in one `packages/` folder is tempting but causes problems:

- A workflow `paths: ['packages/**']` filter cannot distinguish Node from PHP changes.
- If anyone ever adds a build helper `package.json` to a PHP package, it accidentally becomes an npm workspace.
- Mental model is clearer when each ecosystem has its own root.

Splitting also makes the splitsh-lite config trivial — every entry in the matrix matches `composer-packages/<name>`.

### Why npm/workspaces is unaware of `composer-packages/`

npm workspaces resolves workspaces by scanning the `workspaces` glob in root `package.json`. `composer-packages/wp-phpcs/` has no `package.json` and is not matched by `packages/*`, so npm ignores it entirely. **Zero exclusion config needed.** Same for Turborepo when we add it — it only sees what npm workspaces sees.

---

## Package boundaries — be ruthless about splits

The temptation in monorepo migrations is "every concept gets its own package." Resist it. Each boundary costs:

- A `package.json` to maintain.
- Version coordination on every cross-cutting change.
- An entry in the publish flow.
- A README, a changelog, an exports map.

For wp-tooling, the question to ask every candidate split is: **what does a consumer install separately?**

### Splits that earn their keep

| Package | Why separate |
|---|---|
| `@rtcamp/wp-eslint-config` | A plain WP plugin that only wants lint should not drag in the scaffold CLI |
| `@rtcamp/wp-stylelint-config` | Same |
| `@rtcamp/wp-prettier-config` (if any) | Same |
| `@rtcamp/wp-jest-preset` (if any) | Same |
| `rtcamp/wp-phpcs` | Composer-only consumers |
| `rtcamp/wp-phpstan` | Composer-only consumers |

### Splits I would push back on

- **TTY UI kit as its own npm package.** It is only consumed by the CLI in `@rtcamp/wp-tooling`. The existing `exports` map (`@rtcamp/wp-tooling/ui`) is the right granularity. Splitting it adds version sync for zero consumer benefit.
- **Each CLI subcommand as its own package** (`@rtcamp/wp-detect-changes`, `@rtcamp/wp-install-hooks`, etc.). They share one dispatcher. No consumer installs one subcommand standalone.
- **Scaffold registry as its own package** for the same reason.

Default position: **everything CLI / scaffolds / hooks / TTY UI / version-monitor stays inside `@rtcamp/wp-tooling`** with sub-exports. Configs split out because configs have genuinely independent consumers.

### Open question: do PHPCS/PHPStan packages contain code or only config?

This affects whether the Composer monorepo overhead is justified.

- If `rtcamp/wp-phpcs` is just a `ruleset.xml` that extends `wp-coding-standards/wpcs`, plus a thin `composer.json`, it is a small, stable, low-churn package. Standalone repo is fine — monorepo overhead may not pay off.
- If it contains **custom PHP sniffs** (actual code, with PHPUnit tests), shared CI infra with the Node side starts mattering.

Same for `wp-phpstan`. **Confirm before locking in the layout.** If both are config-only, consider keeping them as standalone Composer repos and skipping the Composer monorepo half entirely. The hybrid "Node monorepo + standalone Composer repos" is a valid simpler endpoint.

---

## Daily developer workflow

### One-time setup

```bash
git clone git@github.com:rtcamp/wp-tooling.git
cd wp-tooling
npm install               # installs all Node workspaces
composer install          # installs Composer packages from path repos
```

### Working on a Node package

```bash
cd packages/wp-tooling
npm run test
npm run lint
```

Or from root, for all packages:

```bash
npm test --workspaces --if-present
npm run lint --workspaces --if-present
```

### Working on a Composer package

```bash
cd composer-packages/wp-phpcs
composer test
vendor/bin/phpunit
```

### Working on a Node package that depends on another Node package

npm workspaces symlinks workspace packages automatically. Inside `packages/wp-tooling/package.json`:

```json
{
    "devDependencies": {
        "@rtcamp/wp-eslint-config": "*"
    }
}
```

The `*` resolves to the local workspace. When we publish, npm/changesets rewrites it to the actual version.

### Working on a Composer package that depends on another Composer package

Root `composer.json` declares a path repository, so `composer install` resolves locally:

```json
{
    "repositories": [
        { "type": "path", "url": "composer-packages/*" }
    ]
}
```

Inside `composer-packages/wp-phpstan/composer.json`:

```json
{
    "require": { "rtcamp/wp-phpcs": "^1.0" }
}
```

Locally: symlinked from `composer-packages/wp-phpcs`. Published: resolved from the satellite repo via Packagist. Identical contract for the caller.

---

## Release / publish flow

### Node side: changesets

[`@changesets/cli`](https://github.com/changesets/changesets) drives Node releases. Workflow:

1. **PR**: contributor adds a `.changeset/*.md` file declaring which packages changed and at what semver level (patch/minor/major) and a one-line summary.
2. **Merge to main**: changesets bot opens a "Version Packages" PR that aggregates all pending changesets, bumps each affected package's version in its `package.json`, and writes per-package `CHANGELOG.md` entries.
3. **Merge the Version Packages PR**: the release workflow runs `changeset publish`, which `npm publish`es each changed package.

Per-package independent versioning. `wp-tooling` and `wp-eslint-config` are not forced to move in lockstep. Configs can release `0.0.x` patches independently.

### Composer side: monorepo-builder + splitsh-lite

[`symplify/monorepo-builder`](https://github.com/symplify/monorepo-builder) and [`symplify/monorepo-split-github-action`](https://github.com/symplify/monorepo-split-github-action):

1. **PR**: contributor changes `composer-packages/wp-phpcs/`.
2. **CI** runs `vendor/bin/monorepo-builder validate` to enforce that shared keys (e.g. `php: ^8.1`) agree across all Composer packages.
3. **Release**: maintainer runs `vendor/bin/monorepo-builder release v1.2.0` locally, which bumps every Composer package's version in lockstep, commits, and tags.
4. **Push tag**: the release-composer workflow runs the split action, which uses splitsh-lite to push each `composer-packages/<name>` subtree to its satellite repo with the same tag.
5. **Packagist** webhook fires on each satellite; new version is consumable.

Composer packages move in lockstep. If `wp-phpstan` extends `wp-phpcs`, they share a version and bump together. Different cadence than Node side, deliberately.

### Tag convention

| Tag | Triggers |
|---|---|
| `v<N>.<M>.<P>` on monorepo | Composer release workflow (split + tag satellites) |
| changeset publish on main | Node release workflow (per-package `npm publish`) |

Monorepo tag drives Composer release. Node release is decoupled from monorepo tag (driven by changeset merges instead).

---

## CI design

### Two workflows with path filters

`ci-node.yml`:
```yaml
on:
  pull_request:
    paths:
      - 'packages/**'
      - 'package-lock.json'
      - '.github/workflows/ci-node.yml'
```

`ci-composer.yml`:
```yaml
on:
  pull_request:
    paths:
      - 'composer-packages/**'
      - 'composer.lock'
      - '.github/workflows/ci-composer.yml'
```

A PR touching only PHP skips Node CI entirely, and vice versa. A PR touching both runs both.

### Node CI body

```yaml
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: 22
          cache: 'npm'
      - run: npm ci
      - run: npm run lint --workspaces --if-present
      - run: npm test --workspaces --if-present
```

When we add Turborepo (Phase 2), `npm run lint --workspaces` becomes `npx turbo run lint`. Turborepo's remote cache slot drops in here.

### Composer CI body

```yaml
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.1'
      - run: composer install --no-interaction --no-progress
      - run: vendor/bin/monorepo-builder validate
      - run: composer test
```

### Release workflows

`release-composer.yml`:
```yaml
name: Release Composer packages
on:
  push:
    tags: ['v*']

jobs:
  split:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        package:
          - { local: composer-packages/wp-phpcs,   remote: wp-phpcs }
          - { local: composer-packages/wp-phpstan, remote: wp-phpstan }
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0
      - uses: symplify/monorepo-split-github-action@v2.3
        with:
          package_directory: ${{ matrix.package.local }}
          repository_organization: rtcamp
          repository_name: ${{ matrix.package.remote }}
          user_name: 'rtcamp-bot'
          user_email: 'bot@rtcamp.com'
          branch: main
          tag: ${{ github.ref_name }}
        env:
          GITHUB_TOKEN: ${{ secrets.SPLIT_TOKEN }}
```

`release-node.yml`:
```yaml
name: Release Node packages
on:
  push:
    branches: [main]

jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0
      - uses: actions/setup-node@v4
        with:
          node-version: 22
          registry-url: 'https://npm.pkg.github.com'
      - run: npm ci
      - uses: changesets/action@v1
        with:
          publish: npm run release
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
          NODE_AUTH_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

Root `package.json` adds `"release": "changeset publish"`.

### One-time setup outside the repo

- Create empty `github.com/rtcamp/wp-phpcs` and `github.com/rtcamp/wp-phpstan` repos.
- Register both with Packagist.
- Create a fine-grained PAT (`SPLIT_TOKEN`) with `contents: write` on both satellites; add as a repo secret on the monorepo.
- Register the monorepo with GitHub Packages for npm publishing (or npm registry if we publish there).

---

## Migration plan — phased

### Phase 0 — discussion (current)

- Land this doc.
- Confirm whether `wp-phpcs` / `wp-phpstan` are config-only or contain PHP code.
- Decide: full monorepo for v2.0.0, or hybrid (Node monorepo + standalone Composer repos).

### Phase 1 — finish v1.0.0 single-repo

- Continue current Sprint plan.
- Ship `@rtcamp/wp-tooling` as a single package with sub-exports.
- Create `rtcamp/wp-phpcs` and `rtcamp/wp-phpstan` as **standalone Composer repos** (not yet in monorepo). Unblocks WordPress side immediately.

### Phase 2 — monorepo migration epic (v2.0.0)

Estimated 2–3 weeks of focused work, plus one freeze week.

1. **Layout migration**:
   - Create `packages/wp-tooling/` and move existing `src/`, `bin/`, `tests/` underneath.
   - Move `src/lint/eslint.js` → `packages/eslint-config/`.
   - Move `src/lint/stylelint.js` → `packages/stylelint-config/`.
   - Update `package.json` exports map and entry points per package.
2. **Workspace wiring**:
   - Root `package.json` with `workspaces: ["packages/*"]`.
   - npm workspaces install: `npm install` at root.
3. **Composer side**:
   - `git subtree add` the existing `rtcamp/wp-phpcs` and `rtcamp/wp-phpstan` standalone repos into `composer-packages/` (preserves history).
   - Root `composer.json` with path repos.
   - `monorepo-builder.yaml` with version sync rules.
4. **Release flows**:
   - `.changeset/` initialised, first changeset generated for the migration itself.
   - `release-node.yml` and `release-composer.yml` added.
   - `SPLIT_TOKEN` secret created.
   - First dry-run release into a `v2.0.0-rc.1` tag to validate the split workflow before cutting `v2.0.0`.
5. **Consumer migration**:
   - WP skeletons update `package.json` from `@rtcamp/wp-tooling` to `@rtcamp/wp-tooling` + `@rtcamp/wp-eslint-config` + `@rtcamp/wp-stylelint-config`.
   - Older consumers stuck on v1 continue working — no break.

### Phase 3 — Turborepo (post-v2.0.0, only if needed)

- Add `turbo.json`.
- Replace `npm run lint --workspaces` with `turbo run lint` in CI.
- Configure remote cache.
- Trigger: CI feels slow, or package count exceeds ~8.

---

## Risks and open questions

### Risks

- **First Composer subtree push is the riskiest step.** splitsh-lite force-pushes to the satellite. If we mis-configure paths, we can publish a wrong subtree. Mitigation: dry-run `v2.0.0-rc.1` first, verify the satellite contents manually before tagging the real `v2.0.0`.
- **Version sync drift.** Without monorepo-builder validation in CI, Composer package versions can diverge silently. Mitigation: monorepo-builder validate runs on every Composer PR.
- **Lockfile churn at root.** One `package-lock.json` for the whole Node monorepo. Lockfile diffs in PRs will get larger. Mitigation: review tooling treats lockfile diffs separately; team gets used to it.
- **No widely-used reference repo for Node + Composer monorepos.** We are carving a path. Both halves individually are well-trodden (Symfony for Composer, Gutenberg-style for Node). The wiring is ours.

### Open questions

1. **Do `wp-phpcs` and `wp-phpstan` contain PHP code or only XML/neon config?** Decides whether Composer monorepo is justified. (Pinged: needs an owner to confirm.)
2. **GitHub Packages or public npm registry?** Current `publishConfig` targets `npm.pkg.github.com`. Configs intended for public WordPress consumption may want public npm. Decide before tagging.
3. **Is the v2.0.0 migration acceptable as a hard fork in package names?** `@rtcamp/wp-tooling` v1 stays as-is; v2 means consumers explicitly opt in to the monorepo packages. Or do we want `v1.x → v2.0` to be a drop-in?
4. **Root-level CHANGELOG vs per-package?** Changesets supports both. Per-package is the default; root aggregate would need a custom script.
5. **Branch naming after monorepo lands.** Current `<milestone>/task/<slug>` works fine across the monorepo; no change needed unless we want per-package prefixes.

---

## Decision points the team needs to settle

1. **Migration timing.** v2.0.0 epic as proposed, or earlier? Recommendation: v2.0.0.
2. **Hybrid vs full monorepo.** If `wp-phpcs` / `wp-phpstan` are config-only, do we even monorepo them, or keep them standalone? Recommendation: monorepo them only if they contain non-trivial PHP code.
3. **Workspace manager.** npm workspaces vs pnpm. Recommendation: npm.
4. **Turborepo timing.** Phase 2 (post-migration) vs Phase 1 (during migration). Recommendation: Phase 2 / on-demand.
5. **Publish registry.** GitHub Packages vs public npm for Node side. Open.
6. **Package boundaries.** Confirm the list under "Splits that earn their keep" matches team intent.

---

## Reference material

- [Symfony's monorepo](https://github.com/symfony/symfony) — the canonical Composer monorepo
- [`symplify/monorepo-builder`](https://github.com/symplify/monorepo-builder)
- [`symplify/monorepo-split-github-action`](https://github.com/symplify/monorepo-split-github-action)
- [`splitsh/lite`](https://github.com/splitsh/lite) — the underlying Go tool
- [Changesets](https://github.com/changesets/changesets) — Node release coordination
- [npm workspaces](https://docs.npmjs.com/cli/v10/using-npm/workspaces)
- [Turborepo docs](https://turbo.build/repo/docs)
- [Gutenberg's Lerna setup](https://github.com/WordPress/gutenberg/blob/trunk/lerna.json) — closest WordPress-ecosystem reference for a Node monorepo (note: Gutenberg uses Lerna; we are proposing changesets, which is a more modern replacement for the publish half of Lerna)
