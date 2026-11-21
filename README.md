# Alchemy of Dreams

Modular game backend built with **PHP 8.3**, **Laravel**, **Docker**, and a transactional **Outbox** architecture.

## About the Game & Architecture

**Alchemy of Dreams** is a simulation and progression game combining city building, resident management, expeditions, alchemy transmutation, and time-driven world phases.

The backend is organized into domain modules under `backend/app/Modules`:

- **Auth** — Telegram authentication, hashed session tokens (`sha256`), session revocation (`logout`), and unified `401` error responses (`UNAUTHENTICATED`, `SESSION_REVOKED`, `SESSION_EXPIRED`).
- **City** — island progression, grid footprint & boundary validation, historical building cost calculation (`Cost(N)`), building upgrades/moves/demolition, and offline economy checkpoints.
- **Residents** — resident lifecycle, statuses/energy/constraints, building assignment, and resident requests.
- **Economy & Outbox** — idempotent resource ledger updates, offline storage-capped progression, and reliable event delivery via `transaction_outbox` (`FOR UPDATE SKIP LOCKED` and failure tracking).
- **Expedition** — expedition roster validation, energy deduction, deterministic rewards, reward claiming, and returning participants to `available` status.
- **Alchemy** — order-independent component normalization, recipe matching, recipe archive unlocking without duplicates, and unmatched experiment event logging.
- **WorldPhase** — world phase schedule calculation and synchronization.

## Repository Structure

- `backend/app/Modules/` — Domain modules (`Auth`, `City`, `Residents`, `Economy`, `Expedition`, `Alchemy`, `WorldPhase`)
- `backend/database/` — Migrations and catalog seeders
- `backend/tests/` — Feature & Unit tests (49 tests, 350 assertions)
- `docker/` — Nginx & container configuration
- `docker-compose.yml` — Local service orchestration

## Quick Start & Testing

Run the test suite inside the application container:

    docker exec -it alchemy_app vendor/bin/phpunit
