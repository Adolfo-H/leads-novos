# Receita local dataset

## Release lifecycle

The conversion scripts accept `--processed-root`; point them at a staging
directory so their output is isolated from the active dataset. Once companies,
establishments, and the required domain Parquets are present for a period,
validate and publish that directory with:

```bash
python publish_release.py \
  --staging-dir /real-data/staging/2026-08 \
  --processed-root /real-data/processed \
  --period 2026-08
```

Publication creates the active-establishment pre-aggregation, validates table
schemas and samples, hashes the Parquet files, and writes `manifest.json` plus
the `_SUCCESS` commit marker. Releases are versioned and the publisher refuses
to overwrite an existing version; new releases remain inactive by default.
Only an explicit `--activate` atomically replaces
`/real-data/processed/active`; a failed validation or activation does not
replace the previous pointer.

The API defaults to `/real-data/processed/active`. An operator may override
`RECEITA_DATA_DIR` to select another manifest-backed release. Do not activate a
release as part of tests or routine data conversion.

## Health and prospecting

`/health` validates the manifest/commit digest, required metadata, file sizes,
Parquet header/footer markers, and a DuckDB schema/sample from each table. It
does not hash the full national dataset on every health check; full SHA-256
verification runs during publication. Health responses contain no filesystem
paths.

Prospecting reads persistent `active_group_stats.parquet` rather than
recalculating group statistics for every page. Each response carries the
immutable `dataset_version`; later pages must provide that version and receive
HTTP 409 if the active release changed. The query uses a bounded DuckDB thread
count and stable tie-breakers for offset pagination. The checked-in synthetic
benchmark observed 5 establishment rows scanned for the former per-page group
aggregation versus 4 pre-aggregated group rows (DuckDB `cumulative_rows_scanned`).
This small fixture demonstrates the removed full-table aggregate scan; it is
not a wall-clock or production-scale performance claim.

Import row, dispatch chunk, dispatch-per-run, ready-batch, and stale-recovery
limits are configurable through the `IMPORT_*` environment variables listed
in `config/prospector.php`. The scheduled `imports:dispatch-ready` command
continues bounded work left ready by an earlier run.
