# CAPTCHA cookie-tool consent integration — design

**Date:** 2026-06-30
**Feature:** extends #213 (core CAPTCHA provider layer)
**Branch base:** `b-1.6`
**Status:** design approved, pending spec review

## Problem

The core CAPTCHA layer (`source/Internal/Domain/Captcha/`) gates the widget behind
a single shop-wide boolean, `blCaptchaRequireConsent`:

- `true` (default) → the widget **never** auto-loads; the visitor only sees a consent
  notice, and server-side verification is skipped (fail-open).
- `false` → the widget always loads and always verifies.

Neither state can read a **per-visitor** consent decision. A merchant running a cookie/
consent tool (Cookiebot, Usercentrics, Borlabs, Klaro, …) cannot make the CAPTCHA switch
on only for visitors who accepted "Google reCAPTCHA" in the banner. Today that requires a
custom module implementing `CaptchaConsentInterface` and rebinding it in DI.

This feature adds a **config-driven cookie consent mode** to core so the common case needs
no module, while keeping the `CaptchaConsentInterface` seam for the hard cases (base64/JSON
cookies, signed values, client-only consent tools).

## Why not a single universal cookie reader

There is no standard cookie. Each tool writes a different cookie name and a different value
format (Cookiebot `CookieConsent` structured string; Usercentrics base64 JSON; Borlabs
`borlabs-cookie` JSON; custom banners — anything, sometimes localStorage only and never a
server-readable cookie). Core therefore supports the readable-substring case only and leaves
the rest to the interface seam.

## Decisions (locked during brainstorming)

1. **Match rule:** cookie name + marker **substring contains** (no regex, no JSON parsing).
2. **Admin control:** one shop-wide **3-way "Consent mode" dropdown** (not per-provider,
   not an implicit checkbox+fields combination).
3. **Render strategy for cookie mode:** **client-side gate** (script-blocking) — Google's
   script is injected only after the browser confirms the marker. Checked **once on page
   load**, no polling, no tool-specific events → fully tool-agnostic.
4. **Verify strategy:** the server reads the same cookie at submit time and keeps the
   existing documented fail-open behavior.
5. **Structure:** config-driven single default implementation (approach A) — the
   `CaptchaConsentInterface` seam is unchanged; provider output is treated as opaque so the
   generic gate works for any provider.

## The three consent modes (`sCaptchaConsentMode`)

| Mode | Render | Verify | When |
|---|---|---|---|
| `always` | script + widget load immediately | always granted → token verified (fail-closed) | consent handled elsewhere / out of GDPR scope. Equivalent to old `blCaptchaRequireConsent = false`. |
| `gate` *(default)* | widget never auto-loads; notice only | always not-granted → verify skipped (fail-open) | safe default; shop sends nothing to Google until a real consent source is wired. Equivalent to old `blCaptchaRequireConsent = true`. |
| `cookie` | widget emitted inert + JS gate; activates only if marker present in browser | server reads same cookie; marker present → verify, absent → fail-open | the real GDPR case: per-visitor on/off driven by the cookie banner. |

`always`/`gate` are static shop-wide decisions; `cookie` is per-visitor and dynamic
(decided fresh in the browser → cache-safe, re-confirmed on the server at submit).

## Components

### Configuration

`CaptchaConfiguration` (`source/Internal/Domain/Captcha/Configuration/`) gains:

- constants: `MODE_KEY = 'sCaptchaConsentMode'`, `COOKIE_NAME_KEY = 'sCaptchaConsentCookieName'`,
  `COOKIE_MARKER_KEY = 'sCaptchaConsentCookieMarker'`, and mode value constants
  `MODE_ALWAYS = 'always'`, `MODE_GATE = 'gate'`, `MODE_COOKIE = 'cookie'`.
- `getConsentMode(): string` — reads `MODE_KEY`; if unset, derives from the legacy
  `CONSENT_KEY` bool (`true`→`gate`, `false`→`always`); default `gate`. Unknown values
  coerce to `gate`.
- `getConsentCookieName(): string`, `getConsentCookieMarker(): string`.

All keys persist under the `module:captcha` config section (consistent with the existing
keys). `isConsentRequired()` is retained for backward-compat reads but is no longer the
primary control; the legacy bool is read only as the fallback default described above.

### Server-side consent (`ConfigBasedCaptchaConsent::isConsentGranted`)

Becomes a switch on `getConsentMode()`:

- `always` → `true`
- `gate` → `false`
- `cookie` → read `Registry::getUtilsServer()->getOxCookie($name)`; return `true` only if
  `$name !== ''` **and** `$marker !== ''` **and** `str_contains($value, $marker)`. Blank
  name or marker → `false` (fail closed to "no consent", never over-grant).

The `CaptchaConsentInterface` signature is unchanged (`isConsentGranted(Request $request)`);
the cookie is read via `UtilsServer`, not `Request` (Request carries only GET/POST params).
This is the value `CaptchaService::verifyForForm` already consumes — the verify path's shape
does not change, only its truth source.

### Render (`CaptchaService::renderForForm`) — branch on mode

- `always` → current granted path: de-duped head-script + `provider->renderWidget($formId)`.
- `gate` → current notice path: the `o3-captcha-consent-notice` div only.
- `cookie` → **deferred gate**, emitted per protected form:
  1. the consent notice div, visible by default;
  2. an inert `<template>` holding the provider's head-script **and** widget markup;
  3. a bootstrap `<script>` carrying cookie name + marker via `json_encode` (safe JS-string
     embedding).

  The bootstrap script and the existing `headScriptEmitted` de-dup flag remain once-per-request.

### JS gate mechanics

On `DOMContentLoaded` the bootstrap reads `document.cookie`, finds the named cookie, and
tests `value.indexOf(marker) !== -1`. If matched: hide the notice, then move the `<template>`
contents into the DOM, **re-creating each `<script>` as a fresh element** (copying
`src`/attributes/inline text) because template/innerHTML-inserted scripts do not execute.
This is the standard consent-tool "unblocking" technique and is provider-agnostic.

The gate is emitted inline by core → ships in **shop-ce only**, no theme-repo change.

**Assumption:** a provider's head script is a normal `<script src>` (true for reCAPTCHA
v2/v3). A provider emitting only inline script still works (inline text is copied), but a
provider with unusual ordering requirements is out of scope for the generic gate.

### Admin (`CaptchaConfigController` + `captcha_config.tpl`)

- The single "require consent" checkbox is replaced by a **Consent mode `<select>`** with
  three options.
- Two text inputs (cookie name, marker) render hidden and are revealed only when `cookie`
  is selected, reusing the existing JS-toggle pattern already in the template for provider
  credential fields.
- `save()` persists the three new keys as `str` via
  `saveShopConfVar('str', …, 'module:captcha')`; the legacy bool save is dropped.
- New accessors for the template: `getConsentMode()`, `getConsentCookieName()`,
  `getConsentCookieMarker()`.
- New admin lang keys (de + en): mode label, the three option labels, cookie-name and
  marker labels, and a short help line warning that **cookie mode + visitor declined =
  that submission is unprotected** (the fail-open caveat).

## Data flow

```
Page render (cookie mode)
  ViewConfig::getCaptchaWidget(formId)
    → CaptchaService::renderForForm(formId)
      → mode = cookie → notice (visible) + <template>{headScript+widget}</template> + bootstrap JS
  Browser DOMContentLoaded
    → bootstrap reads document.cookie[name], checks contains(marker)
      → present: hide notice, re-create scripts → Google loads, widget renders
      → absent:  notice stays, Google never contacted

Form submit
  <Form>::send()/addme()/forgotPassword()/registerUser()
    → CaptchaService::verifyForForm(formId, request)
      → isConsentGranted(request): cookie mode reads UtilsServer cookie + marker
        → granted:     provider->verify(request, formId)
        → not granted: return true (fail-open)
```

## Error handling / edge cases

- **Cookie mode, blank name or marker:** server returns not-granted; client bootstrap
  no-ops → notice stays. Net behavior = `gate`. Safe.
- **Multiple protected forms on one page:** notice + template are per-form; bootstrap and
  head-script de-dup stay once-per-request.
- **Injection:** cookie name/marker are operator-set and emitted only into a `json_encode`'d
  JS string and admin inputs (escaped); provider widget markup is trusted core/module output;
  notice text stays translated + `htmlspecialchars`'d.
- **Unknown/legacy config:** unknown `sCaptchaConsentMode` coerces to `gate`; missing mode
  with legacy bool maps as described; missing both defaults to `gate`.

## Testing (PHPUnit, mirroring existing captcha tests)

- `CaptchaConfiguration`: mode getters, legacy bool→mode mapping, default, unknown-value
  coercion.
- `ConfigBasedCaptchaConsent`: all three modes; cookie mode with marker present / absent /
  blank-config (mock `UtilsServer`).
- `CaptchaService::renderForForm`: `always`→widget, `gate`→notice-only, `cookie`→notice +
  template + bootstrap, and assert **no live Google `<script>` outside the template** in
  cookie mode.
- `CaptchaService::verifyForForm`: fail-open per mode.
- `CaptchaConfigController::save`: persists the three new keys.

## Out of scope

- Regex / JSON / base64 cookie parsing (use a module via `CaptchaConsentInterface`).
- Live re-check on consent change without reload (polling / tool-specific events).
- Per-provider consent configuration (consent is a shop-wide policy).
- Reverse-proxy/full-page-cache vary headers (cookie mode is cache-safe by construction;
  `always`/`gate` are static).

## Delivery

Single repo: **shop-ce** (PHP services, admin controller/template, admin lang keys, inline
JS gate). No theme-repo or storefront-lang changes — the existing `O3_CAPTCHA_CONSENT_NOTICE`
storefront key and `captcha_form` theme blocks are reused unchanged.
