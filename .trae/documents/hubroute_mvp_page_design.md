# HubRoute MVP — Page Design Spec (Desktop-first)

## Global Styles (All Pages)
- Layout system: CSS Grid for overall page layout (header / sidebar / main), Flexbox for toolbars and form rows.
- Breakpoints:
  - Desktop (default): 1200px max content width for dense admin views; sidebar visible.
  - Tablet (<= 1024px): sidebar collapses to icon bar.
  - Mobile (<= 640px): stacked layout; primary actions fixed at bottom where helpful (Scan/Event capture).
- Colors:
  - Background: #F7F8FA
  - Surface (cards/tables): #FFFFFF
  - Text: #111827
  - Muted text: #6B7280
  - Primary: #2563EB
  - Success: #16A34A
  - Warning: #D97706
  - Danger: #DC2626
  - Border: #E5E7EB
- Typography:
  - Base: 14–16px system font stack
  - Headings: 18/20/24px with 600 weight
  - Table text: 13–14px
- Components:
  - Buttons: primary/secondary/danger; hover darken 6–8%; disabled with 40% opacity.
  - Inputs: 36px height; clear error states (red border + helper text).
  - Tables: sticky header; zebra rows optional; row click opens detail.
  - Status pills: color-coded by parcel/route status.
- Navigation pattern:
  - Top bar: app name, hub selector (for admin), user menu.
  - Left sidebar: Dashboard, Parcels, Routes, Scan/Events, (Agents/Hubs/Admin depending on role).

## Page: Login
- Meta:
  - Title: HubRoute — Login
  - Description: Sign in to manage parcels, routes, and delivery events.
- Structure:
  - Centered card (420px) on neutral background.
- Sections & Components:
  - Logo/app name
  - Email + password fields
  - Primary CTA: “Sign in”
  - Error banner for invalid credentials

## Page: Dashboard (Role-based)
- Meta:
  - Title: HubRoute — Dashboard
  - Description: Overview of today’s work and key metrics.
- Structure:
  - Two-column grid: left (work queues), right (KPIs + quick actions).
- Components (shared):
  - KPI tiles row: counts by status (config per role)
  - “Recent Events” table (last 20)
- Customer modules:
  - “My Shipments” table: tracking code, recipient, status, last update
  - “Create Shipment” quick panel (link to /parcels/new)
- Hub modules:
  - Queue cards: Inbound, Ready to Route, Exceptions
  - “Active Routes” table: route id, agent, status, parcels count
  - Quick actions: “Scan event”, “Create route”
- Agent modules:
  - “Today’s Route” card: current route, next stop
  - “My Stops” list with completion indicators
- Admin modules:
  - Filters: hub dropdown, date
  - System totals by hub/status; exceptions needing review

## Page: Parcels (List/Search)
- Meta:
  - Title: HubRoute — Parcels
  - Description: Search and manage parcels.
- Structure:
  - Toolbar row + results table.
- Sections & Components:
  - Search bar (tracking code/phone/name)
  - Filters: status, hub, date range
  - Primary CTA (role-gated): “Create parcel”
  - Results table: tracking code, recipient, current hub, status, updated at
  - Row click → /parcels/{id}

## Page: Parcel Detail
- Meta:
  - Title: HubRoute — Parcel Details
  - Description: Parcel information and tracking timeline.
- Structure:
  - Header summary + two-column content.
- Sections & Components:
  - Summary header: tracking code (copy), status pill, current hub/agent/route
  - Left column: recipient + address, shipment notes
  - Right column: Hub path (ordered): list of hubs the parcel has passed through with timestamps
  - Right column: Event timeline (vertical): type, time, actor, hub/route, note
  - Actions:
    - Hub/Agent: “Add event” (links to /scan prefilled)
    - Admin: “Edit parcel” (modal) + “Add correction note”

## Page: Public Tracking (/track)
- Meta:
  - Title: HubRoute — Track a Parcel
  - Description: Track parcel status and hub-to-hub movement using a tracking code.
- Access:
  - No login required.
  - Redact sensitive fields by default (e.g., show city/area only; hide phone/email).
- Structure:
  - Single-column layout optimized for mobile.
- Sections & Components:
  - Tracking code input + CTA “Track”
  - Result header: status pill, last updated time, current hub name (if known)
  - Hub path timeline: ordered hub names with timestamps (Arrived/Departed)
  - Recent events list: last 10 events with type + time + hub (notes hidden unless non-sensitive)
  - Map links:
    - Only show pickup/dropoff map links to authenticated roles; public page shows no full addresses by default.

## Page: Routes (List)
- Meta:
  - Title: HubRoute — Routes
  - Description: Create and manage delivery routes.
- Structure:
  - Toolbar + table.
- Sections & Components:
  - Filters: hub (admin), date, status
  - Primary CTA (hub/admin): “Create route”
  - Table columns: route id, hub, agent, date, status, parcels count

## Page: Route Detail
- Meta:
  - Title: HubRoute — Route Details
  - Description: Route stops, assignments, and dispatch state.
- Structure:
  - Split view: left (route info + actions), right (stops/parcels list).
- Sections & Components:
  - Route info card: hub, agent, date, status
  - Actions (hub/admin): assign agent, assign parcels, dispatch, close
  - Stops/parcels table: stop order, tracking, recipient/address, status
  - Inline controls (MVP): stop order numeric input; save
  - Embedded event log: route-level events (dispatch/close)

## Page: Events / Scanning (/scan)
- Meta:
  - Title: HubRoute — Scan / Add Event
  - Description: Quickly record parcel events.
- Structure:
  - Fast form optimized for repeated use.
- Sections & Components:
  - Tracking input (autofocus) + “Find”
  - Parcel preview strip: recipient, current status, current hub
  - Event type dropdown (role-limited)
  - Optional fields: note, failure reason (if failed_attempt), proof note (if delivered)
  - Primary CTA: “Submit event”
  - Confirmation toast + auto-clear tracking field

## Page: Agents (List + Detail)
- Meta:
  - Title: HubRoute — Agents
  - Description: View agents and workloads.
- Structure:
  - List: table; Detail: header + tabs.
- Sections & Components:
  - Agent list table: name, hub, active, today routes count
  - Agent detail: assigned routes table; delivered/failed counters (simple)
  - Admin actions: activate/deactivate

## Page: Hubs (List + Detail)
- Meta:
  - Title: HubRoute — Hubs
  - Description: Manage hubs and see hub queues.
- Structure:
  - List: cards or table; Detail: dashboard-like layout.
- Sections & Components:
  - Hub detail: queue cards + active routes + recent events

## Page: Admin Settings
- Meta:
  - Title: HubRoute — Admin
  - Description: Manage users and reference settings.
- Structure:
  - Left sub-nav + main content.
- Sections & Components:
  - Users: table + create/edit form (role, hub, active)
  - Reference: event types + status rules (MVP: limited editable list)
  - Audit: table of correction events; open detail with who/when/what
