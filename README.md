# semverdict

Audits whether a Magento module (composer package) has historically followed
[semantic versioning](https://semver.org/). Given a package name, it downloads every
release from Packagist, compares each consecutive version pair with Magento's official
[Semantic Version Checker](https://github.com/magento/magento-semver), and reports where
the author under-bumped — e.g. shipped a backward-compatibility break as a patch release.

Use it as a quality signal before adopting a module: a vendor that repeatedly ships
BC breaks as patch releases will break your `composer update`. The exit code and
`--json` output make it easy to wire into CI or scoring pipelines.

It also works the other way around: point `semverdict next` at your own working
copy and it tells you which tag your unreleased changes require — and why.

## Installation

### Docker (preferred)

```bash
docker run --rm jbrada/semverdict audit vendor/module
```

Optional: add `-v "$PWD/.semverdict-cache:/audit/.semverdict-cache"` to keep the
release archive cache on the host — re-runs then skip all downloads.

The image runs PHP with a 1 GB memory limit; raise it with
`-e PHP_MEMORY_LIMIT=2G` if a particularly large package needs more.

Images are published to [Docker Hub](https://hub.docker.com/r/jbrada/semverdict)
for `linux/amd64` and `linux/arm64` on every release tag (`latest`, `X.Y.Z`, `X.Y`).

### From source

Requires PHP 8.4+ with the `zip` extension (Linux/macOS) and Composer — the
project targets PHP 8.5 (the Docker image's runtime); Composer's platform
check is disabled so 8.4 works for local development.
`magento/magento-semver` is not published on Packagist, so the tool must be
installed from a clone (a `composer require` into another project would not
inherit the VCS repository entry):

```bash
git clone https://github.com/jbrada/semverdict.git
cd semverdict
composer install
```

## Usage

```bash
bin/semverdict audit fooman/printorderpdf-m2       # docker run --rm jbrada/semverdict audit ...
bin/semverdict audit yireo/magento2-webp2 --limit=15 -v
bin/semverdict audit vendor/module --json | jq .followsSemver
```

Example output:

```
+--------+--------+--------+----------+---------+
| From   | To     | Actual | Required | Verdict |
+--------+--------+--------+----------+---------+
| 2.2.2  | 3.0.0  | MAJOR  | MAJOR    | OK      |
| 0.14.0 | 0.14.1 | PATCH  | MAJOR    | ZERO_X  |
...
✔ fooman/printorderpdf-m2 follows semantic versioning
```

### Verdicts

| Verdict | Meaning |
| --- | --- |
| `OK` | Actual bump matches the required level exactly. |
| `OVER` | Bumped more than required — allowed by semver, informational. |
| `VIOLATION` | Bumped less than required — a semver violation. |
| `ZERO_X` | Under-bump within the 0.x range; exempt unless `--strict` (semver makes no promises below 1.0.0). |
| `FAILED` | Download, extraction, or analysis failed for this pair; never aborts the run. |

### Options

| Option | Description |
| --- | --- |
| `--json` | Machine-readable JSON on stdout (progress/warnings go to stderr). |
| `--strict` | Count 0.x under-bumps as violations. |
| `--include-prereleases` | Include alpha/beta/RC releases (dev versions are always skipped). |
| `--limit=N` | Audit only the N most recent version pairs. |
| `--repo=URL` | Composer repository base URL (default `https://repo.packagist.org`). |
| `--auth=user:pass` | HTTP basic auth for `--repo` (e.g. repo.magento.com keys). Credentials are sent only to the repository's origin — they are dropped when a download redirects to another host (e.g. a CDN). |
| `--cache-dir=PATH` | Where release archives are cached (default `./.semverdict-cache`). |
| `--report-types=a,b` | Restrict the analyzed surfaces (`api`, `all`, `dbSchema`, `diXml`, `layout`, `systemXml`, `xsd`, `less`, `et_schema`, `mftf`); unknown values are rejected. Under `--policy=strict`, `api`/`all` select the strict PHP analyzer. |
| `--policy=magento\|strict` | `magento` (default): only `@api` PHP is a contract, non-API PHP changes dampen to PATCH. `strict`: every public/protected PHP signature is a contract — Magento's severity mapping applied to all code without the `@api` dampening (XML surfaces are judged identically in both policies). Known blind spot: the PHP analyzer does not inspect return types. |
| `-v` | Show all change details for every pair (by default, only the offending changes of violation/0.x pairs are detailed). |

Exit codes: `0` compliant, `1` violations found, `2` fatal (package not found, fewer than
2 auditable releases). Re-runs are near-instant thanks to the archive cache.

## Auditing a whole project (`audit-project`)

Audits every first-party dependency of a Composer project in one go — including
packages from private repositories:

```bash
bin/semverdict audit-project /path/to/store
# or in Docker (mount the project read-only):
docker run --rm -v /path/to/store:/project:ro \
  -v "$PWD/.semverdict-cache:/audit/.semverdict-cache" \
  jbrada/semverdict audit-project /project
```

For each direct `require` in the project's `composer.json` (platform packages and
`magento/*` skipped — pass `--include-magento` to audit those too), the package's
repository is resolved the way Composer would: the project's configured
`composer`-type repositories are tried in declared order, then Packagist (unless
the project disables it), with credentials looked up per host from the project's
`auth.json` or the `COMPOSER_AUTH` environment variable. Paid vendor repositories,
repo.magento.com and other private repositories therefore work without any flags —
including the ones that only speak the Composer v1 protocol.

```
+----------------------------+------------------+-------+------------+--------------------------+
| Package                    | Verdict          | Pairs | Violations | Source                   |
+----------------------------+------------------+-------+------------+--------------------------+
| acme/paid-module           | ✘ violations     | 10    | 2          | composer.vendor.example  |
| cweagans/composer-patches  | ✔ follows semver | 10    | 0          | repo.packagist.org       |
| psr/http-client-implementation | — not audited |      |            | virtual package — …      |
+----------------------------+------------------+-------+------------+--------------------------+
```

Rows that cannot be audited say why: a virtual `*-implementation` package has no
releases of its own, a package with a single release has nothing to compare, and a
package none of the configured repositories serve reports the first repository error.

`audit-project` accepts the same `--strict`, `--include-prereleases`, `--limit`
(default 10 per package), `--cache-dir`, `--report-types`, `--policy` and `--json`
options as `audit`. The aggregate `--json` output contains per-package summaries
plus a `summary` block (`total` / `compliant` / `violations` / `unresolved`).

Exit codes: `0` all audited packages compliant, `1` at least one package has
violations, `2` nothing could be audited.

## Suggesting the next tag (`next`)

Before tagging a release, run `next` against the package's working copy: it finds the
highest stable semver tag, exports that tag's tree with `git archive`, compares it
against the current working tree (tracked files with uncommitted edits, plus
untracked-but-not-ignored files — `vendor/` and other ignored paths never pollute the
comparison), and prints the tag the changes require:

```bash
bin/semverdict next                                # current directory
bin/semverdict next path/to/module --policy=strict
bin/semverdict next --json | jq -r .suggestedTag
docker run --rm -v "$PWD:/audit" jbrada/semverdict next
```

Example output:

```
Baseline: v1.2.0
Required bump: MINOR

  MINOR class  /src/Api/GreeterInterface.php Acme\Api\GreeterInterface::wave — [public] Method has been added (V034)

Suggested next tag: v1.3.0
```

The suggestion follows the baseline's style (`v` prefix kept, `1.2` → `1.2.1`) and a few
conventions, each spelled out in the output when it applies: a break below 1.0.0 bumps
the 0.x minor (with a nudge toward 1.0.0 if the API is ready), and a pre-release
baseline promotes to its stable triple, since semver allows any change before the
stable ships. Monorepos work too — point it at the package subdirectory and only that
subtree is compared.

| Option | Description |
| --- | --- |
| `--tag=X` | Compare against this tag instead of the highest stable semver tag. |
| `--include-prereleases` | Allow a pre-release tag (e.g. `v1.3.0-beta1`) as the baseline. |
| `--json` | Machine-readable JSON on stdout (`baselineTag`, `requiredLevel`, `suggestedTag`, `notes`, `changes`). |
| `--report-types`, `--policy`, `-v` | Same as `audit`. |

Exit codes: `0` analysis succeeded (including "no release needed"), `2` fatal (not a git
work tree, no semver tags to compare against, analysis failed).

## How it works

1. `RepositoryClient` fetches the package's release list from the composer repo's p2
   endpoint, expands the delta-minified metadata, and sorts versions ascending.
2. `ArchiveCache` downloads and extracts each release zip (stripping GitHub's single
   wrapper directory when present) into a resumable cache.
3. `MagentoSemverEngine` runs `bin/analyze-pair` in a fresh child process per pair —
   magento-semver mutates global analyzer state, and a fatal on one weird old release
   must not kill the whole audit. The worker calls magento-semver's classes directly and
   emits structured JSON (no text-report scraping).
4. `Auditor` classifies the actual bump between the two versions, compares it against
   the required level, and applies the verdict policy above.

Note on strictness: magento-semver encodes Magento's official versioning policy — a BC
break in `@api`-annotated PHP code requires MAJOR, while *any* change to non-`@api` PHP
code (even removing a public method) is dampened to PATCH, because without `@api` there
is no compatibility contract. XML surfaces (di.xml, system.xml, db_schema, layout, xsd)
have no `@api` concept and are always judged at full severity. This is laxer than strict
semver but is the accepted standard for Magento modules — a module that never uses
`@api` annotations effectively only gets its XML audited. Use `--policy=strict` to
treat every public PHP signature as a contract instead; comparing the two verdicts is
itself a useful signal (e.g. mageplaza/module-smtp: 14 violations under Magento policy,
55 under strict semver).

The engine sits behind `Engine\EngineInterface`, so a second opinion (e.g.
`roave/backward-compatibility-check` over a synthetic git repo) can be added later.

`magento/magento-semver` is not published on Packagist; it is installed from GitHub via
a composer VCS repository, pinned to a develop commit (`13.0.0-beta10` — the only line
that supports PHP 8.4).

## Tests

```bash
composer test                             # unit + integration (spawns local HTTP servers and the engine worker)
composer test:unit                        # skip the slower integration tests
```

## Contributing

Issues and pull requests are welcome. Please run `composer check` before
pushing — it runs the code-style check (PHP-CS-Fixer), static analysis
(PHPStan, level max), and the test suite, exactly what CI enforces along
with the Docker image build.

## Releasing

Pushing a `vX.Y.Z` git tag triggers `release.yml`, which runs the test suite and,
when green, builds and pushes `jbrada/semverdict:X.Y.Z`, `:X.Y`, and `:latest`
(multi-arch) to Docker Hub. The repository needs `DOCKERHUB_USERNAME` and
`DOCKERHUB_TOKEN` action secrets.

Releases are built entirely cache-free and in a workflow separate from `ci.yml`
(which caches but publishes nothing), so no cache entry can ever influence a
published artifact — see the `cache-poisoning` audit in
[zizmor](https://docs.zizmor.sh), which runs over these workflows in
`pedantic` mode on every change under `.github/`.

## License

[MIT](LICENSE)
