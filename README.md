# Visol.PaperTiger.ZebraAdaptor

Render [Sitegeist.PaperTiger](https://github.com/sitegeist/Sitegeist.PaperTiger) forms through a
headless Neos setup — [Zebra](https://github.com/networkteam/zebra) / Next.js — instead of Fusion.

PaperTiger models forms as content: a `Form` node with a collection of field nodes and a collection
of action nodes, rendered and submitted by Fusion. In a headless setup Fusion never renders the
form, so this package does three things instead:

1. **Serialises the form into the content API payload** — field/action nodes plus a client-side
   validation and trigger schema, and an HMAC-signed form identifier.
2. **Accepts the submission over JSON** — `FormApiController` validates the HMAC and honeypots,
   runs server-side field validators, and executes the form actions (message, e-mail, redirect,
   database storage, plus anything a domain package registers).
3. **Handles file uploads** out of band, so a file is stored before the form is submitted.

Rendering the actual form markup is the frontend's job; this package is backend-only.

---

## Requirements

- Neos 8.3
- PHP 8.1+
- [`networkteam/neos-contentapi`](https://github.com/networkteam/Networkteam.Neos.ContentApi) —
  the `Networkteam.Neos.ContentApi:BaseNode` prototype this package extends, and the
  `ErrorHandlingTrait` used by the controller. The constraint is deliberately `*` so a project can
  pin a fork; make sure the version you resolve provides both.

## Installation

```bash
composer require visol/neos-papertiger-zebraadaptor
```

`Sitegeist.PaperTiger`'s own Fusion is switched off (`Neos.Neos.fusion.autoInclude`), because this
package replaces the rendering path.

---

## What editors get

On top of PaperTiger's own node types:

| Node type | Purpose |
|---|---|
| `Visol.PaperTiger.ZebraAdaptor:Action.DatabaseStorage` | Persists the submission through [`wegmeister/databasestorage`](https://github.com/die-wegmeister/Wegmeister.DatabaseStorage) so editors can review and export entries in the backend module. |
| `Visol.PaperTiger.ZebraAdaptor:Mixin.HelpText` | Adds a `helpText` property to every field. Not rendered here — it is exposed through the content API for the frontend. |

Field types that make no sense headless (`Field.FriendlyCaptcha`, `Field.Slider`, `Field.Date`,
`Field.Number`, `Field.TelephoneNumber`) are removed from the element list in
`NodeTypes/Override/DisabledFieldTypes.yaml`. Re-enable one by setting its
`Sitegeist.PaperTiger:Field.Constraint` supertype back to `true`.

`Sitegeist.PaperTiger:Field.Button` is the submit button — it already renders as
`<button type="submit">`. This package only re-icons it and sets editor-facing defaults.

No starter node template is shipped: a new `Form` node gets PaperTiger's empty `fields` and
`actions` collections. What a form should start with is a project decision (the field labels are
stored content, not translatable UI labels), so define it in your own override:

```yaml
'Sitegeist.PaperTiger:Form':
  options:
    template:
      childNodes:
        fields:
          name: 'fields'
          childNodes:
            fieldset:
              type: 'Sitegeist.PaperTiger:Fieldset'
              childNodes:
                email:
                  type: 'Sitegeist.PaperTiger:Field.Email'
                  properties: { name: 'email', label: 'E-mail', isRequired: true }
                button:
                  type: 'Sitegeist.PaperTiger:Field.Button'
                  properties: { name: 'submit', label: 'Submit' }
                honeypot:
                  type: 'Sitegeist.PaperTiger:Field.Honeypot'
```

Keep the whole template in one place — YAML deep-merge appends keys added by a second override, so
a template split across packages cannot control where the added fields land.

---

## Content API payload

`Resources/Private/Fusion/Api/` extends `Networkteam.Neos.ContentApi:BaseNode`:

- **Form nodes** get a `form` object:
  ```jsonc
  {
    "method": "post",
    "formIdentifierWithHmac": "<uuid>::<hmac>",   // submit this as the "__form" field
    "hasActions": true,
    "validationSchema": { "<uuid>[email]": { "validators": { "email": { "message": "…" }, … } } },
    "triggerSchema":    { "<uuid>[email]": "blur" }
  }
  ```
  The two schemas are [validare](https://www.npmjs.com/package/@validare/core)-native (`@validare/core`)
  but are plain data — any client-side validator can consume them. Checkbox/radio groups and required
  consent checkboxes carry a group-aware `"choice": { "min": 1, "message": "…" }` validator instead of
  `notEmpty`, and each trigger is a single DOM event string.
- **Honeypot field nodes** get `timestampWithHmac`.
- **E-mail action nodes** get `formFields` (`identifier` → `fieldName`), so the editor UI can offer
  the available `{placeholder}` names.
- A `honeypot-timestamp` content API **query** returns a fresh signed timestamp:
  `<neos-base-uri>/neos/content-api/query/honeypot-timestamp`. Fetch it on its own cache lifecycle
  (the signature is valid for 24h; refreshing every 12h is a safe default).

---

## HTTP API

### `POST /api/form/submit`

Body: a JSON array of `{ "name": …, "value": … }` pairs — the flat form data, plus:

| Field | Meaning |
|---|---|
| `__form` | `formIdentifierWithHmac` from the payload. Tampering aborts the submission. |
| `__language` | Content dimension to resolve the form node in. Required. |
| `<uuid>[…][one\|two\|three]` | Honeypot values. `one` must be empty; `two`/`three` are the signed timestamp. |
| `<field>[_uploadedFileIdentifier]` | HMAC'd identifier returned by the upload endpoint. |

Fields whose name does not start with the form identifier are discarded, so a tampered payload
cannot inject values from another form.

**200** — `{"data": { … }}` where the keys depend on which actions ran:

| Key | Set by |
|---|---|
| `message` | `Action.Message` |
| `redirectUri` | `Action.Redirect` (node URIs are resolved to real URIs) |
| `errors` | `[{"action": "Email"}, …]` — actions that threw. The submission itself succeeded. |
| `revalidateDocument` | `true` when the form contains a field registered in `documentRevalidatingFieldTypes` |
| *custom* | The `successKey` of a registered action handler |

**422** — `{"data": {"status": "invalid", "fieldErrors": {"<uuid>[email]": ["…"]}}}` when a
registered field validator rejected the input. No actions ran.

### `POST /api/form/upload`

Multipart, honeypot-gated (`_hp_one`, `_hp_two`, `_hp_three`). Stores the file in the `form`
resource collection and returns `{"identifier": "<uuid>::<hmac>", "name": "…"}`. Submit that
identifier as `<field>[_uploadedFileIdentifier]`. Hard limit 128 MB (`413` above it).

### `POST /api/form/upload-cancel`

Honeypot-gated. Takes `identifier` and deletes the resource. Idempotent. Scoped to the `form`
collection, and safe under Flow's SHA1 deduplication — two users uploading the same file cannot
delete each other's row.

All three routes are granted to `Neos.Flow:Everybody` in `Configuration/Policy.yaml`. Submissions
are logged to `Data/Logs/FormApi.log`, each with a random id that ties the log lines of one request
together.

---

## Extension points

Everything customer-specific is registered through settings, so this package never needs to know
about your node types.

### Custom form actions

```yaml
Visol:
  PaperTiger:
    ZebraAdaptor:
      formActionHandlers:
        'Your.Package:Action.Subscribe':
          className: 'Your\Package\Service\SubscribeService'
          successKey: 'subscribeSuccess'   # optional: set to true in the response on success
```

The service implements `Visol\PaperTiger\ZebraAdaptor\Contract\FormActionHandlerInterface`. A
handler that throws is logged and reported in `data.errors` without failing the whole submission.

### Server-side field validation

```yaml
      formFieldValidators:
        'Your.Package:Field.UniqueEmail': 'Your\Package\Validation\UniqueEmailValidator'
```

The service implements `Visol\PaperTiger\ZebraAdaptor\Contract\FormFieldValidatorInterface`. Any
non-empty return short-circuits the submission with `422` before any action runs.

### Cache revalidation

```yaml
      documentRevalidatingFieldTypes:
        'Your.Package:Field.TimeSlots': true
```

Only forms containing such a field make the response set `revalidateDocument`. This is
server-authoritative on purpose: a generic contact form cannot be used to bust the frontend cache.

### Field type behaviour

`fieldTypes.*` assigns your field types to the behaviours the generated schemas care about. Every
entry is a map of arbitrary key → NodeType name, merged across packages. Comparison is by exact
NodeType name, so a subtype of a PaperTiger field must be registered explicitly.

| Key | Effect |
|---|---|
| `email` | Adds the `email` validator |
| `choice` | Required checkable field (radio/checkbox group, consent checkbox): gets the group-aware `choice` (`min: 1`) validator instead of `notEmpty` |
| `multiValue` | Field name gets the `[]` suffix |
| `omitted` | No validator/trigger config; also kept out of `{allFormValues}` |
| `notEmptyMessage.{selectOption,selectAtLeastOneOption,acceptRequired,selectFile}` | Which "required" message the field gets |
| `triggerEvents.{keyup,change}` | Client-side validation trigger (single DOM event; unlisted types fall back to `blur`) |

### E-mail

`Action.Email` ships **no** default sender address, and the field is editable. If your project may
only send from one authorised address, fix it in your own NodeTypes override:

```yaml
'Sitegeist.PaperTiger:Action.Email':
  properties:
    'senderAddress':
      defaultValue: 'noreply@example.com'
      ui:
        inspector:
          editorOptions:
            readonly: true
```

Mail transport is **not** configured here — configure
[`sitegeist/neos-symfonymailer`](https://github.com/sitegeist/Sitegeist.Neos.SymfonyMailer) in your
project:

```yaml
Sitegeist:
  Neos:
    SymfonyMailer:
      dsn: 'smtp://user:pass@smtp.example.com:587'
```

Other settings:

| Setting | Default | Meaning |
|---|---|---|
| `form.overrideRecipientAddress` | `~` | Redirect all mail to this address and prefix the subject with `TEST <hostname>`. Use in non-production contexts. |
| `form.allFormValues.excludeFieldNames` | `[]` | Field names never listed in `{allFormValues}`. |

The `{allFormValues}` placeholder in the e-mail body renders a definition list of every submitted
field. Individual fields are available as `{fieldName}`.

---

## Translations

Ships `en` (source) and `de`. Node type labels resolve through
`Visol.PaperTiger.ZebraAdaptor:NodeTypes.PaperTiger.*`; validation messages live in
`ValidationErrors` and are looked up in the current content dimension's language.

---

## Rendering the form in the frontend

This package ships no frontend components — it gives you a JSON payload and three endpoints. What
follows is the contract you have to honour, and a working reference implementation.

### 1. Field names are the contract

Everything hinges on one convention: **every input's `name` attribute is
`<formIdentifier>[<fieldName>]`**, with `[]` appended for multi-value fields. `formIdentifier` is
the form node's identifier; `fieldName` is the `name` property the editor configured on the field
node.

```
6f56da41-…-5259f099c528[email]
6f56da41-…-5259f099c528[interests][]     ← multi-value (Field.CheckBoxes)
```

The three schemas are keyed by exactly these strings, and `FormApiController` discards any field
whose name does not start with the form identifier. Get this wrong and validation silently does
nothing while the submission drops your data.

`fieldTypes.multiValue` decides which types get the `[]` suffix, but that setting is server-side and
not exposed in the payload — so the frontend either knows it per component (a checkbox-group
component always passes `isMultiValue`), or derives it from the schema keys, which already carry the
suffix. Keep the prefixing itself in one place:

```tsx
const FormIdentifierContext = createContext<string | null>(null)

export function useFormFieldName(fieldName?: string, isMultiValue = false) {
  const formIdentifier = useContext(FormIdentifierContext)
  if (!fieldName) return { name: undefined, id: undefined }
  if (!formIdentifier) return { name: fieldName, id: `field-${fieldName}` }
  const prefixed = `${formIdentifier}[${fieldName}]${isMultiValue ? '[]' : ''}`
  return { name: prefixed, id: `field-${prefixed}` }
}
```

Each field component then renders whatever markup it likes, as long as the input carries
`resolvedName` and the field node's own properties (`label`, `helpText`, `placeholder`,
`isRequired`, `minimumLength`, `maximumLength`, `options`, …) are respected.

### 2. The form element

Render the `fields` and `actions` content collections inside a `<form noValidate>` — `noValidate`
because the client-side validator replaces native browser validation — plus two hidden inputs:

```tsx
<form id={identifier} ref={formRef} onSubmit={handleSubmit} noValidate>
  <input type="hidden" name="__form" value={form.formIdentifierWithHmac} />
  <input type="hidden" name="__language" value={language} />
  {/* fields collection, then actions collection */}
</form>
```

`__language` must be the content dimension the form was rendered in — the controller uses it to
resolve the form node again server-side. Deriving it from the node's `contextPath`
(`…@user;language=de`) works.

Each field wrapper needs an empty container for its error message:

```tsx
<div className="validation-result__container" />
```

### 3. Wiring the client-side validator

The two schemas are [validare](https://www.npmjs.com/package/@validare/core)-native
(`@validare/core`), so the consumer feeds them to the constructor directly — no adapter step:

```tsx
import { validare, Trigger } from '@validare/core'
import type { ElementValidatedPayload } from '@validare/core'
import { deDE } from '@/lib/validation/validareDeDE'

fvRef.current = validare(formRef.current, {
  locale: deDE,
  fields: JSON.parse(validationSchema),        // ← straight from the payload
  plugins: {
    trigger: new Trigger({
      event: JSON.parse(triggerSchema),        // ← { "<uuid>[field]": "blur" | "change" | "keyup" }
    }),
  },
})
```

`validationSchema` is already validare-shaped: `email` (not `emailAddress`, and no
`requireGlobalDomain`), `choice: { min: 1, message }` on radio/checkbox groups and consent
checkboxes (validare validates each element and its group-aware `choice` counts checked ones, so a
group passes as soon as one is selected), and `notEmpty` only on non-checkable required fields.
`triggerSchema` is one DOM event string per field, passed straight to `addEventListener`. The
messages are already translated server-side into the content dimension's language, so `locale` only
covers validare's own built-in strings.

Run this in an effect that fires **once** (guard on the ref) and `destroy()` it on unmount —
re-initialising on every render leaks listeners and double-renders messages.

validare's `Message` plugin renders into a single global container, so render each element's
messages yourself into its own server-rendered `.validation-result__container` (the same node the
server-side `422` handler writes into) via the `core.element.validated` event, and drive field-level
error styling from the field events:

```tsx
  .on('core.field.invalid', (payload) =>
    payload.elements[0]?.closest('[data-node-type]')?.classList.add('field--error'))
  .on('core.field.valid', (payload) =>
    payload.elements[0]?.closest('[data-node-type]')?.classList.remove('field--error'))
  .on('core.element.validated', (payload: ElementValidatedPayload) => {
    const container = findResultContainer(payload.element)   // walk up to nearest .validation-result__container
    if (!container) return
    container.textContent = payload.valid ? '' : Object.values(payload.validators)
      .filter((r) => !r.valid && r.message).map((r) => r.message).join(' ')
  })
```

react-aria field components (dropdown, checkboxes, radio buttons) update the underlying form element
programmatically, so the native DOM events `Trigger` listens to never fire. Bridge them: expose a
`revalidateField(name)` through context that those components call on change — deferred one frame so
react-aria has committed the value, and resetting the field first because `validate()` early-returns
a cached result:

```tsx
const revalidateField = useCallback((name?: string) => {
  if (!name) return
  requestAnimationFrame(() => {
    const fv = fvRef.current
    if (!fv) return
    fv.resetField(name)
    void fv.validateField(name)
  })
}, [])
```

Both schemas are plain JSON, so a different client-side validator can consume them too. The
adaptations that used to live in the frontend are now handled server-side — `email` is already
named validare-style, each trigger is already a single event, and required groups already carry
`choice` instead of `notEmpty`. The one thing any other library still has to provide is a
**group-aware "at least one checked" rule** to back the `choice` entries: validate the whole element
set (count `elements.filter(el => el.checked)`) rather than a single element's value, or a
radio/checkbox group can never pass.

### 4. Submitting

Validate first, submit only when valid:

```tsx
const handleSubmit = async (e) => {
  e.preventDefault()
  if (!fvRef.current) return post(new FormData(formRef.current))   // no schema → let the server decide
  if (await fvRef.current.validate() === 'Valid') post(new FormData(formRef.current))
}
```

Post the entries as the JSON array described under [`POST /api/form/submit`](#post-apiformsubmit).
Doing this from a server action keeps the Neos base URI off the client.

### 5. Honeypots

`Field.Honeypot` must render **three** hidden inputs named `n<nodeIdentifier>[one|two|three]`:

| Input | Value |
|---|---|
| `[one]` | always empty — a bot filling it aborts the submission |
| `[two]` | the signed timestamp, set on render |
| `[three]` | the signed timestamp, set **only after the first real user interaction** |

Fetch the timestamp from the `honeypot-timestamp` query rather than reading it off the document
payload, so its cache lifecycle is independent of the document's (the signature is valid 24h;
refresh every 12h). `[three]` is what actually separates humans from bots — set it from a listener
on `touchstart`/`keydown`/`mousemove`/`touchmove` that removes itself after firing.

The server rejects a timestamp whose age is under 10 seconds or over 24 hours. Note the age is
measured from when the *timestamp was generated*, not from page load — with the query cached for
12h the value is normally already old enough, so this check only bites when you generate a fresh
timestamp per request. The actual bot deterrent is `[one]` staying empty and `[three]` requiring
genuine interaction.

### 6. File uploads

`Field.Upload` uploads out of band, before the form is submitted:

1. `POST /api/form/upload` — multipart, plus `_hp_one` / `_hp_two` / `_hp_three` copied from the
   parent form's honeypot inputs (the endpoint is public and applies the same gate).
2. Store the returned `identifier` in a hidden input named
   `<formIdentifier>[<fieldName>][_uploadedFileIdentifier]` (`[]` before `[_uploadedFileIdentifier]`
   for multi-file fields).
3. Strip the raw `<input type="file">` from the submitted data — only the HMAC'd identifier goes to
   `/api/form/submit`.
4. When the user removes a file before submitting, `POST /api/form/upload-cancel` with the
   identifier.

### 7. Server-side field errors

A `422` carries `fieldErrors` keyed by the same prefixed field name. Render them into the same
`.validation-result__container` the client-side messages use, so both look identical:

```tsx
for (const [prefixedName, messages] of Object.entries(fieldErrors)) {
  const input = form.querySelector(`[name='${CSS.escape(prefixedName)}']`)
  // …walk up to .validation-result__container, set textContent, add .field--error
}
```

Scroll the first offending field into view. Re-fire the effect on a monotonic counter, not on the
`fieldErrors` object alone — an identical re-submission produces an equal object and React would
skip the update.

### 8. After a successful submission

The response tells you what to do:

- `message` — HTML from `Action.Message`; replace the form with it.
- `redirectUri` — from `Action.Redirect`; navigate (a short delay lets the user read the message).
- `errors` — the submission was stored but an action failed. Show a notice; do **not** invite a
  re-submit, the data is already in.
- `revalidateDocument` — drop your cached copy of this document (`revalidateTag` in Next.js), the
  submission changed server-computed data embedded in it.
- A registered handler's `successKey` — branch on it for action-specific confirmations.

### Reference implementation

The ABL monorepo (`neos-next/next`) implements all of the above: `PaperTigerForm`
(`components/clientComponents/content/paper-tiger-form/`) owns the form element, the
validare lifecycle and the `useFormFieldName` context; one server component per node type
under `components/serverComponents/content/SitegeistPaperTiger_*` renders the fields; and
`serverActions/submitForm.ts` performs the POST and the cache revalidation.

## License

MIT — see [LICENSE](LICENSE).
