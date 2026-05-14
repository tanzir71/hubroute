# HubRoute MVP (PHP 8 + SQLite)

## Deploy (shared hosting)
1. Upload `hubroute.php` to your web root (e.g., `public_html/`).
2. Create a writable folder next to it: `data/` (permissions `775` or `777` depending on host).
3. Visit `https://yourdomain.com/hubroute.php` (first run auto-creates `data/hubroute.sqlite` and seeds sample data).
4. Public tracking (no login): `hubroute.php?r=track` or `hubroute.php/track/TRACKINGCODE` (if PATH_INFO works).

## Default accounts (seeded)
- Admin: `admin@hubroute.local` / `admin1234`
- Hubs: `pickuphub@hubroute.local`, `warehouse@hubroute.local`, `eastmile@hubroute.local` / `hub1234`
- Agents: `amina@hubroute.local`, `noah@hubroute.local`, `liam@hubroute.local`, `maya@hubroute.local`, `sofia@hubroute.local`, `owen@hubroute.local` / `agent1234`
- Customers: `alice@example.com`, `bob@example.com` / `customer1234`

## Cron (optional)
- Cleanup old events (example weekly): `0 3 * * 0 php -r "new PDO('sqlite:' . __DIR__ . '/data/hubroute.sqlite');"`
- Daily settlement export (example): implement a CSV export script or use route CSV export from the UI.

## Operational notes
- Routes are keyword/postcode matchers; create/update them under Hub → Routes.
- Hub → Parcels lets you assign/unassign parcels to agents/routes; assignments use conditional updates for safety.
- Hub chain visibility comes from events with `hub_id` (e.g., `hub_arrived`, `hub_departed`, `in_warehouse`).
- Scale note: for higher volume, move to Postgres and introduce background jobs; this MVP is designed for shared hosting.

## Hub operator checklist
1. Start of day: confirm routes and agents are active; review `requested` queue.
2. Assign: bulk-assign `requested` parcels to routes/agents.
3. During runs: agents update `en_route` → `picked_up` → `in_warehouse` → `out_for_delivery` → `delivered/failed/returned`.
4. Intake: scan `hub_arrived` at each hub touchpoint for accurate hub chain visibility.
5. COD: ensure `payment_collected` events are recorded; reconcile and mark `Settlements` as settled.
6. End of day: review exceptions (`failed`, `returned`) and reassign for next run.

