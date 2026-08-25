# Event Sourced Content Repository - Content Graph PostgreSQL Adapter

The PostgreSQL database backend for the Neos content graph. It plugs into the Neos 9 content
repository as an alternative `contentGraphProjection`, replacing the default Doctrine/MariaDB
adapter.

> **⚠️ Alpha release ⚠️**
>
> This adapter is usable for experimentation, benchmarking and testing, but it is **not
> production-ready**. Database schema, projection internals and APIs may change without a
> migration or compatibility layer, and a few projection integrity checks are still
> unimplemented. Expect to reset and replay rather than upgrade in place.
>
> The Doctrine/MariaDB adapter remains the default and the only production-supported backend
> for Neos 9. Running the `Neos.Neos` projections on top of PostgreSQL is still work in
> progress.
>
> For progress and open questions see
> [neos-development-collection#3855](https://github.com/neos/neos-development-collection/issues/3855).

## Requirements

| | |
|---|---|
| PHP | 8.4+ (CI covers 8.4 and 8.5) |
| Neos | 9.2 — `neos/contentrepository-core` `~9.2` |
| PostgreSQL | a [currently supported release](https://www.postgresql.org/support/versioning/); CI runs `postgres:18.3` |
| Doctrine | `doctrine/dbal ^3.1.4`, `doctrine/migrations` |

## How it works

This adapter implements a *hypergraph* model rather than the row-per-edge model of the
MariaDB/DBAL adapter. It is not simply the same schema in a different SQL dialect. A single
hierarchy relation row holds an ordered `bigint[]` of child node anchor points, and subtree
tags are stored as per-anchor JSONB on that same row. The projection therefore leans on
PostgreSQL-specific features throughout: `jsonb`, array column types, GIN indexes and
PL/pgSQL functions created during `setUp()`.

Tables and functions are namespaced per content repository: `cr_<crId>_p_graph_*` for tables,
`neoscr_<crId>_*` for helper functions.

`PostgresContentGraphProjectionFactory` is the only `@api` class; everything else is
`@internal` and may change at any time.

## Usage

Install the package into your Neos distribution:

```bash
composer require neos/contentgraph-postgresqladapter
```

Point Flow's persistence at PostgreSQL and swap the content graph projection of your content
repository preset, in `Configuration/Settings.yaml`:

```yaml
Neos:
  Flow:
    persistence:
      backendOptions:
        host: '127.0.0.1'
        port: 5432
        driver: pdo_pgsql
        user: 'neos'
        password: 'neos'
        dbname: 'neos'
        charset: 'utf8'
        defaultTableOptions:
          charset: 'utf8'

  ContentRepositoryRegistry:
    presets:
      default:
        contentGraphProjection:
          factoryObjectName: Neos\ContentGraph\PostgreSQLAdapter\PostgresContentGraphProjectionFactory
```

The factory takes a `Doctrine\DBAL\Connection` as a constructor argument, so it also needs to
be wired in `Configuration/Objects.yaml` — this part is not optional:

```yaml
Neos\ContentGraph\PostgreSQLAdapter\PostgresContentGraphProjectionFactory:
  scope: singleton
  factoryObjectName: 'Neos\ContentRepositoryRegistry\Infrastructure\GenericObjectFactory'
  arguments:
    1:
      value: 'Neos\ContentGraph\PostgreSQLAdapter\PostgresContentGraphProjectionFactory'
    2:
      object: 'Doctrine\DBAL\Connection'

Neos\ContentRepository\Core\Projection\ContentGraph\ProjectionIntegrityViolationDetectionRunnerFactoryInterface:
  scope: singleton
  factoryObjectName: Neos\ContentRepositoryRegistry\Infrastructure\GenericObjectFactory
  arguments:
    1:
      value: Neos\ContentGraph\PostgreSQLAdapter\PostgresProjectionIntegrityViolationDetectionRunnerFactory
    2:
      object: Doctrine\DBAL\Connection
```

Then create the schema and bring the projection up:

```bash
./flow doctrine:migrate
./flow cr:setup
```

For an existing content repository the projection has to be rebuilt from the event stream
afterwards (`./flow cr:projection:replayall`, or `./flow help` for the replay commands
available in your Neos version).

## Development

Development happens in this repository. Issues and pull requests belong here, not in
`neos/neos-development-collection`.

The package has no standalone test harness: it is developed against a checked-out Neos
distribution, which provides the PHPUnit and Behat configuration. The setup below mirrors
[`.github/workflows/tests.yml`](.github/workflows/tests.yml), which is the authoritative
reference if anything drifts.

### 1. Get a Neos distribution as the Flow root

Clone `neos/neos-development-distribution` on the `9.2` branch and point `FLOW_PATH_ROOT` at
it.

```bash
git clone --depth 1 --branch 9.2 https://github.com/neos/neos-development-distribution.git ../neos-base-distribution

export FLOW_PATH_ROOT=../neos-base-distribution
export FLOW_CONTEXT=Testing
```

### 2. Register this checkout as a path repository

```bash
cd $FLOW_PATH_ROOT

composer config --no-plugins allow-plugins.neos/composer-plugin true
composer config repositories.package '{ "type": "path", "url": "../contentgraph-postgresqladapter", "options": { "symlink": false } }'
composer require --no-update --no-interaction neos/contentgraph-postgresqladapter:@dev
composer require --dev --no-update --no-interaction phpstan/phpstan:^1.10

rm -rf composer.lock
composer install --no-interaction --no-progress --prefer-dist
```

### 3. Start PostgreSQL

```bash
docker run --rm -d --name neos-postgres -p 5432:5432 \
  -e POSTGRES_USER=neos -e POSTGRES_PASSWORD=neos \
  -e POSTGRES_DB=neos_behaviour_testing \
  postgres:18.3
```

### 4. Configure the Testing context

Write the same `Settings.yaml` and `Objects.yaml` blocks as in [Usage](#usage) — into
`Configuration/Testing/` of the distribution, with `dbname: 'neos_behaviour_testing'`.

If your distribution does not use the `Neos.Neos` content repository preset, you additionally
have to declare the defaults it would otherwise provide (`eventStore`, `subscriptionStore`
and the `propertyConverters`); see the "Setup Flow configuration" step in the workflow for
the exact block.

### 5. Run the checks

All commands run from `$FLOW_PATH_ROOT`:

```bash
# Static analysis (level 8, no phpstan.neon — the level is passed on the CLI)
bin/phpstan analyse Packages/Libraries/neos/contentgraph-postgresqladapter/src --level 8

# Unit tests
bin/phpunit -c Build/BuildEssentials/PhpUnit/UnitTests.xml \
  Packages/Libraries/neos/contentgraph-postgresqladapter/Tests/Unit

# Migrate up front — this keeps the Behat run's DB transaction from being closed mid-test
FLOW_CONTEXT=Testing/Behat ./flow doctrine:migrate

# Behavioural tests from Neos core — the actual conformance suite for this adapter
FLOW_CONTEXT=Testing/Behat bin/behat -f progress --strict --no-interaction \
  -c Packages/Neos/Neos.ContentRepository.BehavioralTests/Tests/Behavior/behat.yml.dist \
  -vvv --stop-on-failure
```

Note that this package ships no `phpunit.xml`, `behat.yml`, `phpstan.neon`, Makefile,
composer scripts or docker-compose file — all of that comes from the distribution checkout.
Its own unit coverage is a single test (`Tests/Unit/Domain/Repository/NodeFactoryTest.php`);
the Neos core Behat suite is the real safety net, so run it before opening a pull request.

### Contributing

- Open pull requests against `main`. CI also builds `9.2`-style maintenance branches, and can
  be triggered manually on any branch via `gh workflow run tests.yml -r <branch-name>`.
- PHPStan level 8 and the Behat suite must pass.
- Contributions are licensed under GPL-3.0+, in line with the rest of Neos.


## Links

- Forum: https://discuss.neos.io/
- Documentation: https://docs.neos.io/
- Content repository core: [neos/neos-development-collection](https://github.com/neos/neos-development-collection) (branch 9.2+)
- PostgreSQL support status: [neos-development-collection#3855](https://github.com/neos/neos-development-collection/issues/3855)

## License

GPL-3.0+ — see [LICENSE](LICENSE).
