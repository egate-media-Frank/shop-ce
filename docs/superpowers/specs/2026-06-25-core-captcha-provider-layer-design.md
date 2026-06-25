# Design: Pluggable CAPTCHA provider layer in core (Google reCAPTCHA v2 as first provider)

- **Date:** 2026-06-25
- **Status:** Approved (design); pending spec review
- **Related:** #113 (Altcha — becomes a provider under this layer), #191 (closed external captcha-module — superseded), #99/#144 (revocation anti-spam seam — stays separate for now)

## 1. Summary

Add a small, pluggable CAPTCHA layer to core. The layer ships present and active, but **no provider is enabled by default**. In the admin backend the merchant selects an active provider, enters its credentials, and toggles which forms it protects. Google reCAPTCHA **v2 ("I'm not a robot" checkbox)** is the first built-in provider.

The provider seam is a DI-tagged interface, so **third-party developers can ship their own CAPTCHA as a module**: implement the interface, tag the service in the module's `services.yaml`, activate — and it appears in the admin provider list automatically with its own credential fields. No core changes required.

## 2. Goals / Non-goals

**Goals**
- A core provider abstraction with at least two interchangeable implementations envisioned (Google reCAPTCHA now, Altcha #113 later).
- Protect the 7 public forms that already expose a `captcha_form` template block, with a per-form on/off toggle.
- Consent-gated script loading by default (EU-safe), with a decoupled hook so a consent tool (e.g. usercentrics) can grant consent without core depending on it.
- Module-extensible: new providers appear in the admin dropdown via DI tagging, declaring their own config fields.
- Server-side verification wired into all 7 form submit paths, re-rendering with preserved input on failure.

**Non-goals (this iteration)**
- reCAPTCHA v3 / invisible / Enterprise (v2 checkbox only).
- Unifying the revocation form's `RevocationAntiSpamServiceInterface` into this layer (it keeps its own richer seam; may adopt a captcha provider later).
- A new database table (configuration lives in `oxconfig`).
- Shipping the usercentrics consent integration itself (we ship only the hook).

## 3. Decisions (from brainstorming)

| # | Decision |
|---|---|
| D1 | Pluggable provider layer in core; Google reCAPTCHA is provider #1; Altcha (#113) slots in later behind the same interface. |
| D2 | Cover all 7 forms with an existing `captcha_form` hook (contact, newsletter, suggest, forgot-password, price-alarm, invite, registration), each individually switchable in admin. Revocation form stays separate. |
| D3 | Google reCAPTCHA **v2 checkbox**. |
| D4 | No provider on by default. Core layer present; admin selects provider + enters credentials to activate. |
| D5 | Consent-gated by default; click-to-load placeholder until consent; decoupled `CaptchaConsentInterface` hook; core stays module-free. |
| D6 | Third-party providers ship as modules via the `oxid.captcha.provider` DI tag; admin dropdown + credential fields are built dynamically. |

## 4. Architecture

New domain: **`source/Internal/Domain/Captcha/`** (mirrors `Internal/Domain/Revocation`). DI-wired via `Captcha/services.yaml`.

```
Internal/Domain/Captcha/
  Provider/
    CaptchaProviderInterface.php
    GoogleReCaptchaV2Provider.php
    NullCaptchaProvider.php
    CaptchaProviderLocator.php
    SiteVerify/
      SiteVerifyClientInterface.php
      CurlSiteVerifyClient.php          // default impl over Core\Curl
  Configuration/
    CaptchaConfigurationInterface.php
    CaptchaConfiguration.php            // reads/writes oxconfig
  Consent/
    CaptchaConsentInterface.php
    ConfigBasedCaptchaConsent.php       // default; overridable by a module
  Field/
    CaptchaConfigField.php              // value object: key, label, type
  CaptchaServiceInterface.php
  CaptchaService.php                    // facade used by storefront + controllers
  services.yaml
```

### 4.1 Provider interface (the seam)

```php
interface CaptchaProviderInterface
{
    public function getId(): string;                 // 'google_recaptcha_v2'
    public function getTitle(): string;              // dropdown label (translatable ident)
    public function isConfigured(): bool;            // required credentials present?
    /** @return CaptchaConfigField[]  declarative admin fields (text|password|checkbox) */
    public function getConfigFields(): array;
    public function renderWidget(): string;          // markup for the form block (or click-to-load placeholder)
    public function getHeadScript(): ?string;        // <script> tag; null when consent not granted
    public function verify(Request $request): bool;  // server-side validation of the response
}
```

- **`GoogleReCaptchaV2Provider`** — `getConfigFields()` declares `siteKey` (text) and `secretKey` (password); renders the `<div class="g-recaptcha" data-sitekey="…">`; `getHeadScript()` returns the `api.js` tag; `verify()` reads `g-recaptcha-response`, POSTs it with the secret + remote IP to `https://www.google.com/recaptcha/api/siteverify` via `SiteVerifyClientInterface`, and returns the `success` flag.
- **`NullCaptchaProvider`** — the resolved provider when none is selected: `isConfigured()=false`, `renderWidget()=''`, `getHeadScript()=null`, **`verify()=true`** (forms are never blocked when CAPTCHA is off).
- **`CaptchaProviderLocator`** — built from `!tagged oxid.captcha.provider`; `getAll()` (for the dropdown) and `getById(id)` (falls back to `NullCaptchaProvider`).
- **`SiteVerifyClientInterface`** — wraps the outbound HTTP call so `verify()` is unit-testable with no network; default `CurlSiteVerifyClient` uses core `Core\Curl`.

### 4.2 Service facade

```php
interface CaptchaServiceInterface
{
    public function isEnabledForForm(string $formId): bool;   // active provider configured AND form toggle on
    public function renderForForm(string $formId): string;    // widget + (consent-gated) script, or ''
    public function verifyForForm(string $formId, Request $request): bool;
}
```
`CaptchaService` resolves the active provider id from `CaptchaConfiguration`, asks the locator for it, applies the per-form toggle, and applies consent gating (placeholder vs live widget). `verifyForForm` returns **true** when the form is not protected (so unprotected forms are unaffected).

### 4.3 Configuration (`oxconfig`)

| Key | Meaning | Default |
|---|---|---|
| `sCaptchaProvider` | active provider id (`''` = none) | `''` |
| `blCaptchaRequireConsent` | gate script behind consent | `true` |
| `blCaptchaForm_contact` … `_register` (7) | per-form toggles | `false` |
| `sCaptcha_<providerId>_<fieldKey>` | provider credential values (namespaced) | — |

Provider credentials are stored under keys namespaced by provider id (`getConfigFields()` keys), so modules never collide with core or each other.

### 4.4 Consent

`CaptchaConsentInterface::isConsentGranted(Request): bool`. Default `ConfigBasedCaptchaConsent` returns `true` only when `blCaptchaRequireConsent` is off; otherwise `false`. When not granted, `CaptchaService::renderForForm()` emits a **click-to-load placeholder** and the provider's head script is withheld. A consent tool integrates by overriding the `CaptchaConsentInterface` DI binding in its own module — core never references usercentrics.

## 5. Data flow

**Render** — storefront form template reaches `[{block name="captcha_form"}]` → `[{$oViewConf->getCaptchaWidget('contact')}]` → `CaptchaService::renderForForm('contact')` → if active provider configured **and** form enabled → consent granted ? (widget + script) : (placeholder) → else `''`.

**Submit** — controller calls `CaptchaService::verifyForForm('contact', $request)` before processing → active provider `verify()` (Google: siteverify POST) → on **false**, the controller sets a form error and re-renders **preserving submitted input** (never persists); on **true** (or form unprotected), processing continues.

## 6. Storefront integration

- One new **`ViewConfig::getCaptchaWidget(string $formId): string`** method delegating to `CaptchaService`.
- Fill the 7 empty blocks in **both themes** (o3-theme + wave): contact, newsletter, suggest, forgotpwd_email, pricealarm, invite, user_billing (registration) — 14 template edits, each `[{block name="captcha_form"}][{$oViewConf->getCaptchaWidget('<formId>')}][{/block}]`.

## 7. Server-side verification integration (cross-cutting)

A thin **`CaptchaVerificationHelper`** (resolves `CaptchaServiceInterface` from the container) is invoked from each submit path; on failure it raises the form error + preserves input. Touched:
- `ContactController`, `NewsletterController`, `SuggestController`, `ForgotPasswordController`, `PriceAlarmController`, `InviteController`
- `UserComponent` (registration: `registerUser` / `createUser`)

This is the only genuinely invasive change; everything else is additive, isolated files.

## 8. Admin screen

`Admin/CaptchaConfigController` + `captcha_config.tpl` + `menu.xml` entry + DE/EN lang keys, mirroring `RevocationConfig`:
- **Provider dropdown** built dynamically from `CaptchaProviderLocator::getAll()` (id → `getTitle()`), plus a "None" entry.
- **Provider credential fields** rendered generically from the selected provider's `getConfigFields()` (text/password/checkbox).
- **7 per-form checkboxes**.
- **"Require consent before loading"** checkbox.

## 9. Module extension contract (deliverable: short doc)

A third-party provider is a module that:
1. Implements `CaptchaProviderInterface`.
2. Registers the service in the module's `services.yaml` tagged `oxid.captcha.provider`.
3. Implements the calls to its own Anbieter inside `renderWidget()` / `getHeadScript()` / `verify()`, and declares its credentials via `getConfigFields()`.

On activation the provider is collected by the locator and appears in the admin dropdown with its own fields. No core or admin-template changes. A `docs/` page documents this contract with a minimal example.

## 10. Error handling

- siteverify network/HTTP error or malformed JSON → `verify()` returns **false** (fail-closed for a protected form) and logs at WARNING per the project logging standard (`__METHOD__ . ' - '`, quoted values).
- Missing/empty `g-recaptcha-response` → `false` without an outbound call.
- Provider id in config not found in locator → `NullCaptchaProvider` (forms behave as unprotected; logged at NOTICE).
- Per the graceful-degradation rule, a misconfigured CAPTCHA must not 500 a public form; it falls back to unprotected + log.

## 11. Testing

Unit tests (no network; `SiteVerifyClientInterface` mocked):
- `GoogleReCaptchaV2Provider::verify` — success, failure, malformed response, transport error, empty response token.
- `NullCaptchaProvider` — renders nothing, verify true.
- `CaptchaService` — active-vs-null provider resolution, per-form toggle, consent → placeholder vs live widget, unprotected form → verify true.
- `CaptchaConfiguration` — defaults and namespaced provider keys.
- `ConfigBasedCaptchaConsent` — granted only when consent not required.
- `CaptchaProviderLocator` — tag collection + getById fallback.

cs-fixer clean; the ≥90% coverage gate must stay green.

## 12. Files (summary)

**New:** `Internal/Domain/Captcha/**` (interfaces, Google + Null providers, locator, siteverify client, configuration, consent, service, field VO, `services.yaml`); `Admin/CaptchaConfigController`; `captcha_config.tpl`; docs page.
**Edited (additive):** `ViewConfig` (+1 method); `menu.xml`; admin DE/EN `lang.php`.
**Edited (cross-cutting):** 7 controllers/component; 14 theme templates (2 themes × 7 forms).

## 13. Out of scope / follow-ups

- Altcha provider (delivered under #113 #207–#210, now as a provider in this layer).
- Revocation form adopting a captcha provider (extends #144 task list).
- reCAPTCHA v3 / Enterprise.
- An actual usercentrics consent binding (separate module).
