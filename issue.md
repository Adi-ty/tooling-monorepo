## Why we need this
Two things started this work. First, we wanted to ship a shared PHPCS baseline
(ISSUE1) so every skeleton enforces the same coding standards without copying
configs. Then we wanted a shared PHPStan baseline (ISSUE2) so every skeleton
catches type errors at the same level. Both were originally planned as additions
to `wp-php-toolkit`. But a toolkit that bundles CLI tooling, lint configs, AND
static analysis rules forces every consumer to install everything. A skeleton
that only wants PHPCS shouldn't pull in Jest or ESLint.
The solution is a monorepo: one repo, six independent packages, each consumed
on its own.
| Directory | Package name | What it ships |
|-----------|-------------|---------------|
| `node-packages/wp-tooling` | `@rtcamp/wp-tooling` | CLI, scaffolds, git hooks, release scripts (today's repo, moved) |
| `node-packages/eslint-config` | `@rtcamp/eslint-config` | ESLint flat config (extracted from `src/lint/eslint.js`) |
| `node-packages/stylelint-config` | `@rtcamp/stylelint-config` | Stylelint config (extracted from `src/lint/stylelint.js`) |
| `node-packages/jest-config` | `@rtcamp/jest-config` | Jest defaults (extracted from root `jest.config.js`) |
| `composer-packages/wp-phpcs` | `rtcamp/wp-phpcs` | PHPCS baseline: WordPress-Core, VIPMinimum, PHPCompatibilityWP |
| `composer-packages/wp-phpstan` | `rtcamp/wp-phpstan` | PHPStan baseline at level 5, WP-aware via `szepeviktor/phpstan-wordpress` |
The monorepo keeps everything on one branch, one PR review, one release process.
Each consumer installs only what they need.
---
## Before you start
### You can start once these are closed
- [ ] #6 — Detect Changes and bin/wp-tooling dispatcher
- [ ] #2, #15, #16, #17, #18 — ScaffoldRegistry
- [ ] #4 — Lint configs (sources eslint-config / stylelint-config package contents)
- [ ] #8 — Release scripts (ships inside `@rtcamp/wp-tooling` before the move)
- [ ] #9 — Git hook scripts (commit-msg, pre-commit, install-hooks CLI)
### Worth reading first
- **ISSUE1** — the PHPCS baseline discussion that led to `wp-phpcs` as a standalone package
- **ISSUE2** — the PHPStan baseline discussion that led to `wp-phpstan` as a standalone package
- **`rtCamp/coding-standards-d`** — reference PHPCS ruleset package (type `phpcodesniffer-standard`). Tiered rulesets under `rtCamp-Minimum/`, `rtCamp-Basic/`, `rtCamp-Extra/`, `rtCamp-Strict/`, `rtCamp-Docs/` subdirectories, each with `ruleset.xml`. Dependencies (`wpcs`, `vipwpcs`, `phpcompatibility-wp`) live in `require` not `require-dev` so consumers get them transitively. Uses `release-please` for automated releases. Our `composer-packages/wp-phpcs` follows the same package structure but with a single baseline ruleset rather than tiers
- **`szepeviktor/phpstan-wordpress`** — the PHPStan extension our `wp-phpstan` package depends on. Provides WordPress function stubs and dynamic return type extensions. The baseline's `includes:` line points at `extension.neon` from this package
- **`WordPress/gutenberg` `.github/workflows/publish-npm-packages.yml`** — production npm publish pattern: workflow_dispatch with choice inputs, permission scoping, environment gates. Our `release-node.yml` follows the same structure for GitHub Packages
- **`symplify/monorepo-builder`** — version sync + cross-package dependency alignment for Composer packages. Used for `validate` and `merge`, not for the release workers (we do per-package tags instead of lockstep)
- **`danharrin/monorepo-split-github-action`** — wraps `splitsh-lite` for the subtree split job
- **`CLAUDE.md`** — sections "What this repo is", "Non-negotiables", "Architecture patterns"
- The `v1.0.0/task/lint-configs` branch — `src/lint/eslint.js` and `src/lint/stylelint.js` are the verbatim source for eslint-config / stylelint-config
---
## What you're building
### In scope
- npm workspaces root (`workspaces: ["node-packages/*"]`); one `package-lock.json` for all
- Four Node workspaces under `node-packages/`: `@rtcamp/wp-tooling`, `@rtcamp/eslint-config`, `@rtcamp/stylelint-config`, `@rtcamp/jest-config`
- Two Composer packages under `composer-packages/`: `rtcamp/wp-phpcs`, `rtcamp/wp-phpstan`
- Root `composer.json` with path repositories pointing at `composer-packages/*` and `symplify/monorepo-builder` as a dev-require
- `monorepo-builder.php` that pins shared dev dependencies (`phpunit/phpunit`, `phpstan/phpstan`, `squizlabs/php_codesniffer`) in `dataToAppend` — keeps versions consistent across all Composer packages
- `splitsh.json` mapping each Composer package to its satellite repo
- **Two release workflows** (described below) — each triggered by per-package tags, each releasing only the identified package
- Root `eslint.config.js` consuming `@rtcamp/eslint-config` via the workspace
- Per-package thin `jest.config.js` files that require `@rtcamp/jest-config` and add `rootDir`
- `CHANGELOG.md` Unreleased entry covering the restructure
- **Docs refactor**: `CLAUDE.md`, root `README.md`, per-package `README.md` files, `.claude/commands/*.md` skills, `.github/pull_request_template.md` — every file reflects the monorepo layout
- **Satellite repos exist and `SPLIT_TOKEN` is provisioned**: `rtcamp/wp-phpcs` and `rtcamp/wp-phpstan` created as empty repos; fine-grained PAT with `contents: write` scoped to both; stored as `SPLIT_TOKEN` secret. Proven with a `workflow_dispatch` dry-run
### Release strategy — per-package tags for both ecosystems
Pushing a tag triggers exactly one workflow for exactly one package. The tag
format is the same whether you're releasing an npm package or a Composer
package: `<package-name>-v<semver>`.
| You push | Which workflow fires | What happens |
|----------|---------------------|--------------|
| `wp-tooling-v2.1.0` | `release-node.yml` | Extracts package name `wp-tooling`, auto-bumps `package.json` from tag version via `npm version --no-git-tag-version`, runs `npm publish` |
| `eslint-config-v1.0.1` | `release-node.yml` | Extracts package name `eslint-config`, auto-bumps, runs `npm publish` |
| `stylelint-config-v1.0.1` | `release-node.yml` | Same pattern |
| `jest-config-v0.3.0` | `release-node.yml` | Same pattern |
| `wp-phpcs-v1.2.3` | `release-php.yml` | Extracts package name `wp-phpcs`, looks up subtree path in `splitsh.json`, splits only that subtree, pushes to `rtcamp/wp-phpcs` with tag `v1.2.3` |
| `wp-phpstan-v0.5.1` | `release-php.yml` | Same pattern → pushes to `rtcamp/wp-phpstan` |
Each workflow only watches its own package prefixes. A `wp-phpcs-v1.2.3` tag
never triggers `release-node.yml`. An `eslint-config-v1.0.1` tag never triggers
`release-php.yml`. Both workflows also support `workflow_dispatch` so you can
dry-run before pushing a real tag.
### Out of scope
- Turborepo / remote caching
- `@rtcamp/prettier-config`, additional Composer packages — slot in later with no workflow changes
- Changesets-driven version bumping and changelog generation (separate issue) — tag-based push is the first iteration
---
## How to build it
### File layout
```
wp-tooling/
├── node-packages/
│   ├── wp-tooling/                    @rtcamp/wp-tooling — CLI, scaffolds, hooks, release scripts
│   │   ├── package.json
│   │   ├── bin/wp-tooling.js
│   │   ├── src/                       (everything except src/lint/)
│   │   ├── tests/
│   │   ├── jest.config.js             (thin — requires @rtcamp/jest-config)
│   │   └── .npmignore
│   ├── eslint-config/
│   │   ├── package.json               @rtcamp/eslint-config
│   │   └── index.js                   (from v1.0.0/task/lint-configs : src/lint/eslint.js)
│   ├── stylelint-config/
│   │   ├── package.json               @rtcamp/stylelint-config
│   │   └── index.js                   (from v1.0.0/task/lint-configs : src/lint/stylelint.js)
│   └── jest-config/
│       ├── package.json               @rtcamp/jest-config
│       └── index.js                   (cross-cutting defaults from root jest.config.js)
│
├── composer-packages/
│   ├── wp-phpcs/
│   │   ├── composer.json              rtcamp/wp-phpcs
│   │   └── phpcs.xml.dist             (WordPress-Core, VIPMinimum, PHPCompatibilityWP)
│   └── wp-phpstan/
│       ├── composer.json              rtcamp/wp-phpstan
│       └── phpstan.neon.dist          (level 5, includes szepeviktor/phpstan-wordpress)
│
├── package.json                       private root, "workspaces": ["node-packages/*"]
├── package-lock.json
├── composer.json                      path repos for composer-packages/*, symplify/monorepo-builder
├── composer.lock
├── monorepo-builder.php               packageDirectories, dataToAppend (shared dev deps)
├── splitsh.json                       Composer subtree → satellite mapping
├── eslint.config.js                   re-exports @rtcamp/eslint-config
├── .nvmrc                             22
└── .github/workflows/
    ├── release-php.yml                tags: wp-phpcs-v* / wp-phpstan-v*
    └── release-node.yml               tags: wp-tooling-v* / eslint-config-v* / stylelint-config-v* / jest-config-v*
```
### Implementation
**1. Convert the root to a workspace root.** Edit `package.json`:
```json
{
  "private": true,
  "workspaces": ["node-packages/*"]
}
```
Move every existing devDependency to whichever workspace actually uses it.
Nothing stays at root except cross-workspace tooling (eslint config, jest
config for root-level scripts). Root `node_modules` gets hoisted deps from
all workspaces automatically.
**2. Move `wp-tooling` into `node-packages/wp-tooling/`.** Take everything
from the merge-base branch as-is, except `src/lint/` (that code becomes the
eslint-config and stylelint-config packages). Trim the `exports` map in
`package.json` — remove `./eslint-config` and `./stylelint-config`. The
binary entry (`bin/wp-tooling.js`), subpath exports, and CLI behaviour stay
the same.
**3. Create `node-packages/eslint-config/` and `node-packages/stylelint-config/`.**
Copy `src/lint/eslint.js` from the `v1.0.0/task/lint-configs` branch as the
package's `index.js`. Same for `src/lint/stylelint.js`. Each gets a
`package.json` with:
- `"main": "index.js"`
- `peerDependencies` matching what the lint-configs branch declares
- `"publishConfig": { "registry": "https://npm.pkg.github.com" }`
- Scope set to `@rtcamp`
**4. Create `node-packages/jest-config/`.** The root `jest.config.js` today
sets `testEnvironment: 'node'`, `testMatch`, `clearMocks`, `restoreMocks`,
coverage thresholds. Extract those shared defaults into `index.js`:
```js
module.exports = {
  testEnvironment: 'node',
  clearMocks: true,
  restoreMocks: true,
  testMatch: ['<rootDir>/**/__tests__/**/*.js'],
  collectCoverageFrom: ['<rootDir>/src/**/*.js'],
};
```
Each workspace creates a one-liner `jest.config.js`:
```js
module.exports = { ...require('@rtcamp/jest-config'), rootDir: __dirname };
```
**5. Land a root `eslint.config.js`.** Re-export `@rtcamp/eslint-config` and
point ESLint at `node-packages/**`. Each workspace's `npm run lint` resolves
the binary from the hoisted root `node_modules` — no per-package eslint install
needed.
**6. Set up the Composer side.**
```bash
composer require symplify/monorepo-builder --dev
vendor/bin/monorepo-builder init
```
Edit the generated `monorepo-builder.php`:
- `$mbConfig->packageDirectories([__DIR__ . '/composer-packages'])` — scans only that folder
- `$mbConfig->dataToAppend([...])` — pins `phpunit/phpunit`, `phpstan/phpstan`, `squizlabs/php_codesniffer` to known-working versions so both Composer packages stay consistent
- No release workers configured (we don't use lockstep `monorepo-builder release`)
Root `composer.json` needs:
```json
"repositories": [
  { "type": "path", "url": "composer-packages/*" }
],
"require-dev": {
  "symplify/monorepo-builder": "^12.7"
}
```
The path repositories let you `composer require rtcamp/wp-phpcs` locally and
get the local copy via symlink. `monorepo-builder validate` checks for version
drift. `monorepo-builder merge --dry-run` previews cross-package dependency
sync without writing.
**Create `composer-packages/wp-phpcs/`.** Package type must be
`phpcodesniffer-standard` so that
`dealerdirect/phpcodesniffer-composer-installer` auto-registers the rules
when a skeleton requires this package. Sniff dependencies go in `require`
(not `require-dev`) so they install transitively for consumers:

```json
{
  "name": "rtcamp/wp-phpcs",
  "type": "phpcodesniffer-standard",
  "require": {
    "php": ">=7.4",
    "wp-coding-standards/wpcs": "^3.1",
    "automattic/vipwpcs": "^3.0",
    "phpcompatibility/phpcompatibility-wp": "^2.1",
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0"
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true
    }
  },
  "scripts": {
    "phpcs": "phpcs",
    "phpcbf": "phpcbf"
  }
}
```

`phpcs.xml.dist` at the package root — enforces WordPress-Core,
WordPress-Extra, WordPress-Docs, WordPressVIPMinimum, PHPCompatibilityWP.
Sets `testVersion` to `8.3-`, `minimum_supported_wp_version` to `6.5`.
Allows short array syntax. The file is autodiscovered when skeletons
add `<rule ref="vendor/rtcamp/wp-phpcs/phpcs.xml.dist"/>`.
**Create `composer-packages/wp-phpstan/`.**
`composer.json` with `require-dev`:
  - `phpstan/phpstan:^1.11`
  - `szepeviktor/phpstan-wordpress:^1.3`
- `phpstan.neon.dist` at the package root:
  ```neon
  includes:
      - vendor/szepeviktor/phpstan-wordpress/extension.neon
  parameters:
      level: 5
      paths:
          - src
      bootstrapFiles:
          - vendor/php-stubs/wordpress-stubs/wordpress-stubs.php
      treatPhpDocTypesAsCertain: false
  ```
  Level 5 catches real type errors without blocking on WordPress core's lack of
  return-type declarations. Skeletons include it via `includes:` in their own
  `phpstan.neon.dist`.
- Composer script: `"phpstan": "phpstan analyse"`
Both Composer packages have zero runtime dependencies. Everything is
`require-dev`.
**7. Author `splitsh.json`.**
```json
{
    "organization": "rtcamp",
    "subtrees": {
        "wp-phpcs": "composer-packages/wp-phpcs",
        "wp-phpstan": "composer-packages/wp-phpstan"
    },
    "defaults": {
        "branch": "main",
        "user_name": "github-actions[bot]",
        "user_email": "github-actions[bot]@users.noreply.github.com"
    }
}
```
The `subtrees` keys match the tag prefix used in release tags. When someone
pushes `wp-phpcs-v1.2.3`, the workflow looks up `"wp-phpcs"` in this JSON and
finds `"composer-packages/wp-phpcs"`. Schema matches what
`danharrin/monorepo-split-github-action` expects.
**8. Author `release-php.yml` — Composer package split.**
Trigger on tags matching known Composer packages, plus `workflow_dispatch` for
testing:
```yaml
name: Publish Composer package
on:
  push:
    tags:
      - wp-phpcs-v*
      - wp-phpstan-v*
  workflow_dispatch:
    inputs:
      package_name:
        description: 'Which Composer package to publish'
        type: choice
        options: [wp-phpcs, wp-phpstan]
      test_tag:
        description: 'Test tag version (e.g., v0.0.0-test)'
        type: string
        required: true
```
Two jobs run sequentially:
**Job 1 — `resolve`**: Figures out which package we're releasing and where to
push it. Uses pure bash + `jq` — no custom actions.
```
steps:
  - uses: actions/checkout@v4
  - name: Determine package and tag
    id: parse
    run: |
      if [[ "${{ github.event_name }}" == "workflow_dispatch" ]]; then
        PACKAGE="${{ inputs.package_name }}"
        TAG="${{ inputs.test_tag }}"
      else
        TAG="${GITHUB_REF#refs/tags/}"
        PACKAGE="${TAG%-v*}"
      fi
      # Look up in splitsh.json
      SUBTREE=$(jq -r ".subtrees[\"$PACKAGE\"]" splitsh.json)
      if [[ "$SUBTREE" == "null" ]]; then
        echo "Error: $PACKAGE not found in splitsh.json"
        exit 1
      fi
      ORG=$(jq -r ".organization" splitsh.json)
      BRANCH=$(jq -r ".defaults.branch" splitsh.json)
      TAG_VERSION="${TAG#*-v}"
      echo "subtree=$SUBTREE" >> $GITHUB_OUTPUT
      echo "mirror=$ORG/$PACKAGE" >> $GITHUB_OUTPUT
      echo "tag=$TAG_VERSION" >> $GITHUB_OUTPUT
      echo "branch=$BRANCH" >> $GITHUB_OUTPUT
```
Emits `subtree`, `mirror`, `tag`, `branch` as outputs.
**Job 2 — `split`**: Takes those outputs, checks out the full repo, and runs
the split action.
```yaml
needs: resolve
steps:
  - uses: actions/checkout@v4
    with:
      fetch-depth: 0
  - uses: danharrin/monorepo-split-github-action@v2.4.5
    with:
      package_directory: ${{ needs.resolve.outputs.subtree }}
      repository_full_name: ${{ needs.resolve.outputs.mirror }}
      tag: ${{ needs.resolve.outputs.tag }}
      branch: ${{ needs.resolve.outputs.branch }}
    env:
      GITHUB_TOKEN: ${{ secrets.SPLIT_TOKEN }}
```
This pushes `composer-packages/wp-phpcs` to `rtcamp/wp-phpcs` with tag
`v1.2.3`. Only one subtree per run — no wasteful splitting of everything.
To add a new Composer package later: add an entry to `splitsh.json` and a
tag prefix to this workflow. That's it.
**9. Author `release-node.yml` — npm package publish (tag-driven).**
The tag is the single source of truth. Version comes from the tag, not from
`package.json`. The workflow auto-bumps `package.json` using `npm version
--no-git-tag-version` before publishing. One step: `git push origin
wp-tooling-v2.1.0`.

Trigger on per-package tags, plus `workflow_dispatch` for dry-run testing:
```yaml
name: Publish npm package
on:
  push:
    tags:
      - wp-tooling-v*
      - eslint-config-v*
      - stylelint-config-v*
      - jest-config-v*
  workflow_dispatch:
    inputs:
      package_name:
        description: 'Which npm package to publish'
        type: choice
        options: [wp-tooling, eslint-config, stylelint-config, jest-config]
      dry_run:
        description: 'Simulate publish without actually publishing'
        type: boolean
        default: true
```
One job with these steps:

**Step 1 — Checkout.** Standard `actions/checkout@v4` with `fetch-depth: 0`.

**Step 2 — Parse the tag.** Extract package name and version:
```bash
if [[ "${{ github.event_name }}" == "workflow_dispatch" ]]; then
  PACKAGE="${{ inputs.package_name }}"
  VERSION="0.0.0-test"
  DRY_RUN="${{ inputs.dry_run }}"
else
  TAG="${GITHUB_REF#refs/tags/}"
  PACKAGE="${TAG%-v*}"
  VERSION="${TAG#*-v}"
  DRY_RUN="false"
fi
echo "package=$PACKAGE" >> $GITHUB_OUTPUT
echo "version=$VERSION" >> $GITHUB_OUTPUT
echo "dry_run=$DRY_RUN" >> $GITHUB_OUTPUT
```

**Step 3 — Auto-bump `package.json` from tag.**
```bash
npm version "$VERSION" --no-git-tag-version --workspace="$PACKAGE"
```
This sets `node-packages/<name>/package.json` version to match the tag.
No commit needed — the checkout is ephemeral.

**Step 4 — Validate the package directory exists.**
```bash
if [[ ! -d "node-packages/$PACKAGE" ]]; then
  echo "Error: node-packages/$PACKAGE does not exist"
  exit 1
fi
```

**Step 5 — Setup Node.js** with `.nvmrc` and `registry-url:
https://npm.pkg.github.com` (use `cache: npm`).

**Step 6 — Install dependencies.** `npm ci` from the monorepo root.

**Step 7 — Publish.** Uses the auto-bumped version from step 3:
```yaml
- name: Publish ${{ steps.parse.outputs.package }}@${{ steps.parse.outputs.version }}
  if: steps.parse.outputs.dry_run == 'false'
  working-directory: node-packages/${{ steps.parse.outputs.package }}
  run: npm publish --access restricted
  env:
    NODE_AUTH_TOKEN: ${{ secrets.GITHUB_TOKEN }}
- name: Dry-run ${{ steps.parse.outputs.package }}@${{ steps.parse.outputs.version }}
  if: steps.parse.outputs.dry_run == 'true'
  working-directory: node-packages/${{ steps.parse.outputs.package }}
  run: npm publish --access restricted --dry-run
  env:
    NODE_AUTH_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

Permissions follow least-privilege:
```yaml
permissions:
  contents: read      # checkout
  packages: write     # npm publish to GitHub Packages
```
**10. Stand up satellite repos and provision `SPLIT_TOKEN`.**
Create `rtcamp/wp-phpcs` and `rtcamp/wp-phpstan` under the rtCamp org:
- Empty repos — no README, no .gitignore, no license
- No branch protection on `main` (the split action force-pushes)
- No description templates
Generate a fine-grained PAT scoped to both repos with `contents: write` only.
Store as `SPLIT_TOKEN` on `rtCamp/wp-tooling`'s repository secrets.
Validate by running `release-php.yml` via `workflow_dispatch` with
`package_name=wp-phpcs` and `test_tag=v0.0.0-test`. Confirm the subtree
mirrors to `rtcamp/wp-phpcs`. Delete the test tag from the satellite
afterwards. Repeat for `wp-phpstan`.
**11. Refactor docs so the repo presents as a monorepo.**
- **`CLAUDE.md`**: Replace directory layout with `node-packages/` and
  `composer-packages/`. Add workspaces section listing all six packages with
  one-line roles. Add release flow section describing both workflows and the
  per-package tag convention. Update available skills file paths from `src/`
  to `node-packages/wp-tooling/src/`. Keep non-negotiables verbatim.
- **`README.md` (root)**: Consumer-facing table of all six packages with
  install commands, link to per-package READMEs.
- **`node-packages/*/README.md`** (one per package): 5–10 lines each. What
  the package does, `npm install @rtcamp/... --save-dev`, minimum usage
  snippet. npm renders this on the GitHub Packages page, so it needs to be
  self-contained.
- **`.claude/commands/*.md`**: Rewrite every file path to
  `node-packages/wp-tooling/...`. Affects `/handoff`, `/add-wizard-step`,
  `/add-scaffold`, `/add-ui-primitive`, `/add-ci-script`,
  `/add-version-detector`, `/review-tooling-pr`.
- **`.github/pull_request_template.md`**: Only edit if it references the
  old single-package layout. Leave `.github/ISSUE_TEMPLATE/task.md`
  untouched.
---
## How to verify your work
Run these locally before opening the PR:
```bash
npm install
npm run lint --workspaces --if-present
npm test --workspaces --if-present
composer install
vendor/bin/monorepo-builder validate
vendor/bin/monorepo-builder merge --dry-run
```
All must exit 0.
Workflow dry-runs (no PR-time CI — these are end-of-PR proof points):
- `workflow_dispatch` of `release-php.yml` with `wp-phpcs` + `v0.0.0-test`
  → `composer-packages/wp-phpcs` mirrors to `rtcamp/wp-phpcs`
- `workflow_dispatch` of `release-php.yml` with `wp-phpstan` + `v0.0.0-test`
  → `composer-packages/wp-phpstan` mirrors to `rtcamp/wp-phpstan`
- `workflow_dispatch` of `release-node.yml` with each package + `dry_run=true`
  → each resolves and dry-runs without error
- Tag `wp-phpcs-v1.0.0` triggers `release-php.yml`, splits only wp-phpcs
- Tag `wp-tooling-v2.1.0` triggers `release-node.yml`, publishes only wp-tooling
- Tag `eslint-config-v1.0.0` does NOT trigger `release-php.yml`
- Tag `wp-phpcs-v1.0.0` does NOT trigger `release-node.yml`
### Quick smoke test
```bash
npx wp-tooling --version
npx wp-tooling detect-changes --help
# Consumer installs only the lint config.
mkdir /tmp/consumer && cd /tmp/consumer && npm init -y
npm install --save-dev @rtcamp/eslint-config@file:../wp-tooling/node-packages/eslint-config
node -e "console.log(require('@rtcamp/eslint-config').length)"
```
---
## Acceptance criteria
- [ ] `npm install` at the monorepo root resolves all four Node workspaces; one `package-lock.json`
- [ ] `npm test --workspaces --if-present` runs every package's tests green
- [ ] `npx wp-tooling <subcommand>` from any workspace works exactly as pre-move
- [ ] `vendor/bin/monorepo-builder validate` exits 0
- [ ] `vendor/bin/monorepo-builder merge --dry-run` exits 0
- [ ] `workflow_dispatch` of `release-php.yml` with `wp-phpcs` + `v0.0.0-test` pushes only `composer-packages/wp-phpcs` to `rtcamp/wp-phpcs`
- [ ] Same for `wp-phpstan`
- [ ] `workflow_dispatch` of `release-node.yml` with each npm package + `dry_run=true` resolves tarball without error
- [ ] Tag `wp-phpcs-v1.0.0` triggers `release-php.yml` (and not `release-node.yml`)
- [ ] Tag `wp-tooling-v2.1.0` triggers `release-node.yml` (and not `release-php.yml`)
- [ ] Tag `eslint-config-v1.0.0` does not trigger `release-php.yml`
- [ ] Tag `wp-phpcs-v1.0.0` does not trigger `release-node.yml`
- [ ] `.github/workflows/` has exactly two files: `release-node.yml` and `release-php.yml`
- [ ] `rtcamp/wp-phpcs` and `rtcamp/wp-phpstan` exist as empty repos under the rtCamp org
- [ ] `SPLIT_TOKEN` is provisioned — fine-grained PAT, `contents: write` on both satellites only
---
## Submitting your work
| | |
|---|---|
| **Base branch** | `release/v1.0.0` |
| **Branch name** | `v1.0.0/task/monorepo-refactor` |
| **PR target** | `release/v1.0.0` |
| **PR title** | `[v1.0.0] refactor(monorepo): split into node-packages/* + composer-packages/*` |
| **Commit style** | [Conventional Commits](https://www.conventionalcommits.org/) |
```bash
git fetch origin
git checkout release/v1.0.0
git pull
git checkout -b v1.0.0/task/monorepo-refactor
# work, commit
git push -u origin v1.0.0/task/monorepo-refactor
```
PR description starts with `Closes #<this-issue>`.
---
## What this unblocks
- **Changesets-driven versioning** (separate issue) — replaces manual tag pushes with changeset files that autogenerate changelogs and bump versions in `package.json` before tagging
- Consumers install only the packages they need — a lint-only CI gets `@rtcamp/eslint-config`, a PHP-only CI gets `rtcamp/wp-phpcs`
- A canonical home for the PHPCS and PHPStan baselines, replacing the standalone `wp-php-toolkit` path