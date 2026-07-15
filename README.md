# Hunted — Game Tracker

This game was run by Blackdown District Scouts as part of their district camp, this software is provided as is and with no warranty or support.

A small PHP + MySQL web app to run and score a **Hunted** game, served from Docker
and reachable by other computers on your local network.

## What it does

- Stores **teams** (e.g. *North*) and **players** numbered within their team
  (*North 1*, *North 2* …) with real name and age.
- Live game screen to mark captures, set treasure, and record return times.
- Automatic scoring with a configurable target return time.

## Scoring

- **Time:** hit the target return time = `time_base_points` (default **100**).
  Lose `penalty_per_minute` (default **1**) for every minute early *or* late.
  Works correctly around midnight (target `00:00`, back at `23:58` = 2 min early).
- **Lives:** everyone has `lives` (default **2**). Being caught that many times =
  *caught out* → **no time points** (treasure still counts).
- **Treasure:** each piece = `loot_value` (default **10**), up to `max_loot` (default **10**).
- **Total** = time points + treasure points.

All of these live in [`config.php`](config.php). Edit and refresh — no rebuild needed.

## Run it

```bash
docker compose up --build
```

Then open **http://localhost:8080** on this machine.

### From other computers on the network

Find this machine's LAN IP and share it:

```bash
ipconfig getifaddr en0      # macOS Wi-Fi (try en1 if blank)
```

Others browse to `http://<that-ip>:8080`. (Allow ports **8080** and **8081** through
the firewall if prompted — 8081 is the live-update WebSocket.)

Change the host port by editing `ports: "8080:80"` in `docker-compose.yml`.

## Live multi-admin updates

The Caught, Check-in and Scoreboard screens update **live across every open device**:

- Actions submit in the background (AJAX) — no full page reload.
- A small WebSocket relay (the `ws` container, port 8081) tells every open page when
  something changed; each page then re-fetches just its own region from PHP.
- If the WebSocket can't connect, pages fall back to polling every few seconds.
- The card you have open and the field you're typing in are preserved across updates.

PHP + MySQL stay the single source of truth — the relay only forwards "something
changed" pings and holds no game state.

> The **Players** and **Teams** setup screens still use ordinary form submits (they
> handle photo uploads); changes there appear on other screens on their next refresh.

## Stop / reset

```bash
docker compose down          # stop, keep data
docker compose down -v        # stop AND wipe the database
```

## Layout

| Path | Purpose |
|------|---------|
| `docker-compose.yml` / `Dockerfile` | web (PHP/Apache) + MySQL |
| `config.php` | all game/scoring settings |
| `db/init.sql` | schema, loaded on first DB start |
| `public/` | the web app (only this folder is web-served) |
| `public/live.js` | client-side AJAX + live-update engine |
| `ws/` | WebSocket relay container for live updates |
