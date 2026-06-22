# Therapy Organics — Bramble Search E2E Checklist

Production validation site: `/Users/phillmorgan/Development/Bramble/Repos/therapy-organics`

## Setup

```sh
cd /Users/phillmorgan/Development/Bramble/Repos/therapy-organics
docker compose up -d
docker compose exec web composer update madebybramble/craft-bramble-search
docker compose exec web php craft up
docker compose exec web php craft project-config/apply
docker compose exec web php craft bramble-search/stats/index --detailed
```

Link the local plugin via path repository in `craft/composer.json` before `composer update`.

Mount the plugin into the web container so Composer's path repo resolves inside Docker:

```yaml
# docker-compose.yml (web service volumes)
- ../craft-5/lib/bramble-search:/bramble-search
```

If host port 80 is taken, map web to another port (e.g. `8888:80`).

Set `BRAMBLE_SEARCH_REDIS_HOST=redis` (or equivalent) so the web container reaches Redis.

After linking, clear and rebuild the index before validating search:

```sh
docker compose exec web php craft clear-caches/bramble-search
docker compose exec web php craft queue/run --interactive=0
docker compose exec web php /bramble-search/tests/e2e/therapy-organics-validate.php
```

## A. Index lifecycle

| ID | Scenario | Pass |
|----|----------|------|
| A1 | Edit journal `body` only — untouched field terms still searchable | ☐ |
| A2 | Edit product `description` only — other searchable fields still match | ☐ |
| A3 | Restore element from trash — custom fields searchable | ☐ |
| A4 | Delete entry/product — term removed from `/search` | ☐ |
| A5 | Clear Caches → Bramble Search rebuild | ☐ |
| A6 | `php craft resave/products --update-search-index` | ☐ |
| A7 | CP save + `php craft queue/run` updates index | ☐ |

## B. Frontend search (`/search?q=…&type=…`)

| ID | Scenario | Pass |
|----|----------|------|
| B1 | Product exact match | ☐ |
| B2 | Product fuzzy/typo | ☐ |
| B3 | Multi-word AND (`serum vitamin`) | ☐ |
| B4 | Journal tab | ☐ |
| B5 | Therapedia tab | ☐ |
| B6 | Ingredients tab | ☐ |
| B7 | Related taxonomy expansion (brand/ethos → products) | ☐ |
| B8 | Search + brand filter | ☐ |
| B9 | Sort relevancy vs name | ☐ |
| B10 | Therapedia inline `?q=` | ☐ |
| B11 | Catalog page hidden `?q=` | ☐ |
| B12 | No-results copy for nonsense query | ☐ |

## C. CP search

| ID | Scenario | Pass |
|----|----------|------|
| C1 | Journal index search | ☐ |
| C2 | Products index search | ☐ |
| C3 | Count elements with active search | ☐ |
| C4 | Export with search filter | ☐ |
| C5 | Asset folder name search (Craft-side, unaffected) | ☐ |

## D. Commerce

| ID | Scenario | Pass |
|----|----------|------|
| D1 | SKU search on products tab | ☐ |
| D2 | All four product types indexed | ☐ |
| D3 | Nutrition test searchable fields | ☐ |
| D4 | CP Orders search by email/order number | ☐ |
| D5 | Cart purge removes stale index entries | ☐ |

## E. Query parity (post-upgrade)

| ID | Scenario | Pass |
|----|----------|------|
| E1 | OR syntax if used in templates | ☐ |
| E2 | Exclude syntax (`-term`) | ☐ |
| E3 | GraphQL search if enabled | ☐ |

## F. Performance smoke

| ID | Scenario | Accept | Pass |
|----|----------|--------|------|
| F1 | Broad product search TTFB | < 2s local/staging | ☐ |
| F2 | Repeated identical search | Not slower on repeat | ☐ |

## G. Logs and stats

| ID | Check | Pass |
|----|-------|------|
| G1 | No errors in `craft/storage/logs/bramble-search.log` during run | ☐ |
| G2 | `bramble-search/stats --detailed` stable before/after | ☐ |

## Sign-off

- [ ] All A–D scenarios pass
- [ ] E–G pass for shipped features
- [ ] PHPUnit + integration matrices pass in plugin repo

Tester: _______________  Date: _______________
