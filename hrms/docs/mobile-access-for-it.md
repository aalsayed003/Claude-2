# ASSH HRMS on phones — access options for IT

**Audience:** IT / network administration
**Decision needed:** how staff and department heads reach the HRMS from their phones — and, if off-campus use is wanted, VPN vs. a published reverse proxy.

---

## 1. What the mobile app actually is

The HRMS is a **Progressive Web App (PWA)** — the same web application, with a
manifest, service worker and app icons added. There is **no App Store / Play
Store build to distribute and no separate mobile codebase**.

Staff open the site once in their phone browser and choose **"Add to Home
Screen."** After that it launches from an ASSH icon, **full-screen** (no browser
bars), and behaves like a native app. Updates are automatic — whatever is
deployed to the server is what everyone gets on next open.

- Camera capture (photographing a sick note) and file upload work through the
  browser — no native permissions plumbing required.
- The service worker caches static assets for speed and shows a friendly
  "you're offline" screen; it **never caches HR data pages**, so there is no
  stale- or cross-user-data risk.

**One hard requirement: HTTPS.** Service workers, "install to home screen", and
web push all require the site to be served over **HTTPS with a valid
certificate**. Plain HTTP will run in a desktop browser but will not install as
an app. This is true regardless of which access option below is chosen.

---

## 2. On the hospital network — works today

A phone on hospital Wi-Fi reaches the internal server exactly like a desktop
does. If the server is published over HTTPS internally, the PWA installs and
runs with **no extra infrastructure**. If mobile use is only ever needed
on-campus, you can stop here.

---

## 3. Off the hospital network — pick one

Department heads approving from home, or staff requesting leave on 4G, need the
internal server to be reachable from outside. Three options:

| Option | How it works | Pros | Cons / cost |
|---|---|---|---|
| **A. VPN** | Phone connects to the hospital VPN, then opens the internal URL | Nothing new is exposed to the internet; reuses existing security | Staff must start the VPN each time (friction, low adoption); needs VPN client + licences on personal phones; support burden |
| **B. Reverse proxy (recommended)** | Publish **only** the HRMS through a hardened HTTPS gateway (e.g. IIS ARR / Nginx / an existing WAF appliance) with its own public hostname + certificate | Frictionless — a normal URL, installs as a PWA, works anywhere; enables web-push notifications; only one app is exposed, not the network | Requires a public DNS name + TLS cert and firewall change; must be hardened (see §5) |
| **C. Cloud/DMZ host** | Run the app in a DMZ or cloud VM that reaches the DB over a private link | Fully isolates the internet-facing tier from the LAN | Most infrastructure to set up; DB connectivity/latency to design |

**Recommendation: Option B (reverse proxy).** It gives the "real app" experience
staff expect, keeps exposure limited to the HRMS alone, and is the only option
that makes phone **push notifications** practical. Option A is acceptable if
policy forbids publishing any internal app; Option C only if you already run a
DMZ pattern for other systems.

---

## 4. Push notifications (optional, nice-to-have)

Web push can alert a department head "3 requests waiting" without opening the
app. It needs **HTTPS + the app installed to the home screen**, and works on
Android and on iOS 16.4+. It is **only reachable when the app is served over the
public HTTPS endpoint** (Option B/C) — not over VPN-only. If push isn't
available, fall back to the existing email notifications.

---

## 5. If you publish (Option B) — security checklist

- Valid public **TLS certificate**; HTTP redirects to HTTPS; HSTS on.
- Expose **only** the HRMS host/path — no other internal apps behind the same
  name.
- Put it behind the **WAF / reverse proxy** you already use; enable rate
  limiting on `/login`.
- Keep the app's existing **session + CSRF** protections (already built in);
  consider **MFA** at the gateway for privileged roles (HR/Finance).
- Uploaded documents are stored outside the web root and served only through an
  authenticated route — no direct file URLs.
- Restrict by geo/IP at the gateway if the hospital only operates in-country.

---

## 6. What we need from IT to proceed

1. Decide **on-campus only** (nothing to do) **or off-campus** (pick A/B/C above).
2. If B/C: a **public hostname** + **TLS certificate**, a firewall/proxy rule to
   the HRMS server, and confirmation of the WAF in front.
3. Confirm HTTPS internally so the PWA installs on hospital Wi-Fi in the meantime.

*No application code changes are required for any option — this is purely a
hosting/network decision. The mobile app is ready.*
