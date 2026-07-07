# semverdict

Audits whether a Magento module (composer package) has historically followed
[semantic versioning](https://semver.org/). Given a package name, it downloads every
release from Packagist, compares each consecutive version pair with Magento's official
[Semantic Version Checker](https://github.com/magento/magento-semver), and reports where
the author under-bumped — e.g. shipped a backward-compatibility break as a patch release.

Use it as a quality signal before adopting a module: a vendor that repeatedly ships
BC breaks as patch releases will break your `composer update`. The exit code and
`--json` output make it easy to wire into CI or scoring pipelines.

## Installation

### Docker (preferred)

```bash
docker run --rm jbrada/semverdict audit vendor/module
```

Optional: add `-v "$PWD/.semverdict-cache:/audit/.semverdict-cache"` to keep the
release archive cache on the host — re-runs then skip all downloads.

Images are published to [Docker Hub](https://hub.docker.com/r/jbrada/semverdict)
for `linux/amd64` and `linux/arm64` on every release tag (`latest`, `X.Y.Z`, `X.Y`).

### From source

Requires PHP 8.2+ with the `zip` extension (Linux/macOS) and Composer.
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
(PHPStan, level max), and the test suite, exactly what CI enforces on
PHP 8.2–8.4 along with the Docker image build.

## Releasing

Pushing a `vX.Y.Z` git tag runs the test suite and, when green, builds and
pushes `jbrada/semverdict:X.Y.Z`, `:X.Y`, and `:latest` (multi-arch) to Docker
Hub. The repository needs `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` action
secrets.

## License

[MIT](LICENSE)
