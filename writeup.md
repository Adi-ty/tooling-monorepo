# Proposal: Restructure `wp-tooling` into a Node + Composer Monorepo

**Goal:** Refactor the existing `wp-tooling` repo in place — turn it from a
single `@rtcamp/wp-tooling` npm package into a monorepo that also hosts
`@rtcamp/eslint-config`, `@rtcamp/stylelint-config` (npm),
`rtcamp/wp-phpcs`, and `rtcamp/wp-phpstan` (Composer) — all under one roof
with independent release flows.

## Architecture

| Layer | Tool | Role |
|-------|------|------|
| Workspace manager | `npm workspaces` | Links `node-packages/*` under one lockfile |
| Composer monorepo | `symplify/monorepo-builder` | Syncs versions, validates, drives release |
| Composer split | `splitsh-lite` (via `danharrin/monorepo-split-github-action`) | Subtree-pushes each `composer-packages/*` to its satellite repo on tag |
| Node release | `changesets` | Per-package independent `npm publish` (TBD — can layer later) |

## Proposed layout

```
wp-tooling/
├── node-packages/
│   ├── eslint-config/        # @rtcamp/eslint-config
│   ├── stylelint-config/     # @rtcamp/stylelint-config
│   └── wp-tooling/           # @rtcamp/wp-tooling (existing CLI + core)
├── composer-packages/
│   ├── wp-phpcs/             # rtcamp/wp-phpcs
│   └── wp-phpstan/           # rtcamp/wp-phpstan
├── package.json              # workspaces: ["node-packages/*"]
├── composer.json             # path-repos for composer-packages/*
├── monorepo-builder.php      # symplify config
├── splitsh.json              # satellite-repo → directory mapping
└── .github/workflows/
    └── release-php.yml       # split + push on tag (v*)
```

## CI/CD workflow (tested locally)

The split workflow is already scaffolded and verified in the `tooling-monorepo`
sandbox. It works as follows:

1. **On tag push** (`v*`) the workflow triggers
2. **`build-matrix` job** reads `splitsh.json` and dynamically generates a
   matrix of `{mirror_repo, package_directory}` pairs using `jq`
3. **`split` job** (parallel, one per subtree) uses
   `danharrin/monorepo-split-github-action@v2.4.5` to:
   - Checkout full monorepo history (`fetch-depth: 0`)
   - Run `splitsh-lite` to isolate the subtree
   - Force-push the subtree to its satellite repo with the same tag
4. **Manual trigger** (`workflow_dispatch`) is also wired for dry-runs with a
   throwaway tag before cutting a real release

The matrix approach means **adding a new Composer package is one entry in
`splitsh.json`** — no workflow file changes needed.

## What's already done (in `tooling-monorepo`)

- [x] npm workspace root with `node-packages/*` (eslint-config, stylelint-config)
- [x] `composer.json` with `symplify/monorepo-builder` dev-require
- [x] `monorepo-builder.php` — package directories, mutual-dependency workers, tag/push workers
- [x] `splitsh.json` — maps `composer-packages/<name>` → satellite repos
- [x] `release-php.yml` — build-matrix + split jobs, **dry-run verified locally**
- [x] Monorepo scaffold exercises: `npm workspaces`, `vendor/bin/monorepo-builder init`, `merge` commands tested

## What needs doing for `wp-tooling`

1. **Migrate** existing source into `node-packages/wp-tooling/`
2. **Split** lint configs out into `node-packages/eslint-config/` and `node-packages/stylelint-config/`
3. **Add** `composer-packages/wp-phpcs/` and `composer-packages/wp-phpstan/` with their config files
4. **Update** `splitsh.json` with real satellite repo names (`rtcamp/wp-phpcs`, `rtcamp/wp-phpstan`)
5. **Wire** CI workflows — composer validate, phpcs/phpstan lint, npm test
6. **Create satellite repos** under the org (empty, read-only mirrors)

## Infrastructure required

- **`SPLIT_TOKEN`** — fine-grained PAT with `contents:write` on every satellite
  repo, stored as a repo secret on the monorepo
- **Satellite repos** — empty repos created under the org for each
  `composer-packages/*` entry
- Can be set up in parallel with the code work; only needed before first release tag

---

**Ready to start refactoring `wp-tooling` in place.** The split workflow has
been tested locally and the scaffolding is stable. PAT/secrets can be handled
when we're ready for the first release.

**Give me the green light and I'll begin the migration.**
