# Alchemy of Dreams

**Alchemy of Dreams** is a simulation, city-building, and progression game set in a world shaped by dreams, memory, silence, and shifting tides of fog.

Players expand floating islands, manage residents with distinct archetypes and requests, send expeditions into unknown depths, experiment with alchemy recipes, and adapt their city to global world phases — all backed by a modular Laravel backend, PostgreSQL, Redis, transactional outbox delivery, and a React + Phaser client.

---

## World & Core Gameplay

In **Alchemy of Dreams**, progression revolves around five core resources (`NUMERIC(20,6)` precision):

- **Fog (`fog`)** — the primordial mist that fuels construction and shifts in density across world phases.
- **Silence (`silence`)** — quiet resonance required for deeper journeys and delicate workings.
- **Memories (`memories`)** — fragments recovered from expeditions that carry history and meaning.
- **Metaphors (`metaphors`)** — symbolic catalysts discovered through exploration and transmutation.
- **Nightmare Shards (`nightmare_shards`)** — volatile remnants tied to darker phases and friction in the dreamscape.

### Key Game Loops

- **Islands & City Building** — unlock new islands (`island_index`), validate placement footprints and boundaries, calculate historical scaling costs (`Cost(N)`), upgrade or move structures, and handle demolition cleanly.
- **Residents & Requests** — work with residents across three archetypes (**Dream**, **Phobia**, **Memory**), track energy and availability states, assign them to buildings, and fulfill or decline their personal requests.
- **Expeditions** — assemble a roster, spend energy and resources, send parties on **surface** or **deep** expeditions, and claim rewards while safely returning residents to available status.
- **Alchemy & Recipe Archive** — combine components with order-independent normalization, match recipes across categories (`critical`, `functional`, `decorative`, `secret`), unlock discovered entries in the archive without duplicates, and log unmatched experiments.
- **World Phases** — respond to time-driven global phases (`lucid_calm`, `twilight`, `collective_nightmare`) that alter production multipliers and the mood of the world.

---

## Architecture & Domain Modules

The backend (`backend/app/Modules`) is split into isolated domain modules with strict invariants, idempotency protection, and transactional outbox reliability:

- **Auth** — Telegram authentication, `sha256`-hashed session tokens, session revocation (`logout`), and structured session error handling (`401`).
- **City** — island progression, grid footprint and bounds checks, historical building cost calculation, upgrades, moves, demolition, and offline economy checkpoints.
- **Residents** — resident states, energy, building assignments, and resident requests.
- **Economy & Outbox** — idempotent ledger mutations, storage-capped offline progression, and reliable event dispatch via `transaction_outbox` (`FOR UPDATE SKIP LOCKED` and failure tracking).
- **Expedition** — roster validation, energy deduction, reward resolution and claiming, and resident release back to `available`.
- **Alchemy** — order-independent ingredient normalization, recipe resolution, archive deduplication, and experiment logging.
- **WorldPhase** — schedule calculation and world phase synchronization.

The frontend (`frontend/`) pairs **React** UI with a **Phaser** game canvas for city and world interaction. Balance parameters are tracked centrally in `Balance-Spec.md`.

---

## Repository Structure

- `backend/app/Modules/` — Domain modules (`Auth`, `City`, `Residents`, `Economy`, `Expedition`, `Alchemy`, `WorldPhase`)
- `backend/database/` — Migrations and catalog seeders
- `backend/tests/` — Feature & Unit tests (`51 tests, 361 assertions`)
- `frontend/` — React + Phaser client application
- `docker/` — Nginx and container configuration
- `docker-compose.yml` — Local service orchestration (`nginx`, `app`, `postgres`, `redis`)
- `Balance-Spec.md` — Centralized balance specification draft

---

## Quick Start & Verification

Run the backend test suite inside the application container:

```powershell
docker exec -it alchemy_app vendor/bin/phpunit
Build the frontend bundle:
Set-Location "W:\alchemy_of_dreams\frontend"
npm run build
