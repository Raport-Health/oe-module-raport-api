# RAPORT API module for OpenEMR

Adds four read operations to OpenEMR's FHIR API, which Raport uses to sync a clinic's schedule and
charts. MIT licensed. Requires PHP 8.2 or later. Tested on OpenEMR 8.0.0.3.

## Install

1. Download `oe-module-raport-api-<version>.zip` from the
   [latest release](https://github.com/Raport-Health/oe-module-raport-api/releases/latest) and
   extract it into `interface/modules/custom_modules/`. No Composer step is needed. Keep the hidden
   `.htaccess` file, which blocks direct web access on Apache with `mod_rewrite` and
   `AllowOverride FileInfo`. Other web servers need an equivalent rule. Make the folder readable by
   the web server, because OpenEMR disables a module whose bootstrap file it cannot read.
2. In Administration > Modules > Manage Modules, find "RAPORT API", click Install, then Enable. A
   Release column of 0.0.0 is expected.
3. In Administration > Config > Connectors, turn on the FHIR API (`rest_fhir_api`) and its system
   scopes (`rest_system_scopes_api`). If you set the Site Address Override (`site_addr_oath`), give
   it the origin only, such as `https://ehr.example.com`. OpenEMR adds its own path.
4. Register a private-key JWT system client with `api:fhir` and the scopes below, then enable it in
   Administration > System > API Clients. Register only after step 2, because OpenEMR rejects a whole
   registration that names a scope it does not offer yet. If your web server requires client
   certificates, the client needs one too.

Disabling the module removes its operations and scopes, and built-in FHIR keeps working. The module
has no data migrations or background jobs.

## Operations

Each is a GET under `/apis/{site}/fhir/`, served only to OAuth system clients, and answers with a
FHIR `Parameters` resource. Each needs `api:fhir` plus its own scope, which no other scope grants.

| Operation | Scope | Returns |
| --- | --- | --- |
| `Encounter/{uuid}/$raport-document` | `system/Encounter.$raport-document` | The encounter's notes as one PDF, and a `revision` hash of their content. Pass a `knownRevision` from an earlier call to skip the PDF while nothing changed. [Definition](OperationDefinition.json) |
| `$raport-visits` | `system/*.$raport-visits` | Calendar providers with no query, one patient's appointments and encounters with `patient`, or every visit in a window of up to 31 days with `start` and `end`. [Definition](OperationDefinition-visits.json) |
| `Patient/{uuid}/$raport-problems` | `system/Patient.$raport-problems` | The problem list with parsed diagnosis codes, which OpenEMR 8.0.0.3's native Condition leaves out. [Definition](OperationDefinition-problems.json) |
| `Patient/{uuid}/$raport-labs` | `system/Patient.$raport-labs` | Procedure orders with their tests, answers, reports and results, statuses as OpenEMR stores them. [Definition](OperationDefinition-labs.json) |

Errors are FHIR OperationOutcomes: `400` bad input, `401` token or scope (from OpenEMR), `403`
permission, `404` unknown record, `409` inconsistent data and `422` unsupported note content. A `409`
or `422` names the record and repeats until someone fixes it in OpenEMR. No response is ever partial.

Before reading, visits, problems and labs fill in missing UUIDs with OpenEMR's own helper. That is
the module's only write.

## Supported notes

The document operation prints:

- SOAP, Clinical Notes, Speech Dictation, Review of Systems, Review of Systems Checks and Clinical
  Instructions.
- Lab order forms (`procedure_order`), printed from the order's data.
- Layout-based forms (LBF) with text, text area, provider, static text and template text fields. A
  layout name with an underscore is unsupported. A field holding only `0` fails, because OpenEMR's
  own report silently drops it.

Encounter headers, vitals, Misc Billing Options, inactive lab orders and forms saved blank are
skipped and listed as `excludedForm`. Any other form fails the encounter with `422`, so test a
clinic's own layouts first.

LBF fields with the History source are stored per patient, not per note. The export shows each note
the value saved while it was the patient's newest note. A later edit, from an older note or the
History page, counts toward the newest note.

The PDF is a current rendering, not a signed historical copy. Its `date` is when it was generated,
so use `revision` to detect changes.

## Permissions and audit

Every system client runs as OpenEMR's `oe-system` user, which a fresh install makes an administrator
(`admin|super`). Any enabled system client with these scopes can read broadly, so review your
enabled clients. The module still applies OpenEMR's chart, squad, sensitivity and form permissions,
and any denial fails the whole response with `403`.

Each call writes one audit row without response bodies. PDF content and tokens are never logged.
PDF rendering blocks remote assets, PHP and JavaScript.

## Development

Needs Docker Compose and OpenSSL.

```sh
sh local/up.sh
sh local/check.sh
```

`up.sh` starts OpenEMR 8.0.0.3 at `https://127.0.0.1:19443`. Wait for its first install to finish,
then `check.sh` runs the test suite against it. For FHIR schema and PDF text checks, install
`jsonschema` and Poppler's `pdftotext`, then:

```sh
curl --fail --location https://hl7.org/fhir/R4/fhir.schema.json.zip --output local/artifacts/fhir.schema.json.zip
python3 tests/check-artifacts.py
```

Credentials and artifacts stay in the ignored `local/` folder. Never point the harness at a clinic
database. Stop it with `docker compose -f local/compose.yaml stop`.

## License

MIT, see [LICENSE](LICENSE). OpenEMR and its dependencies keep their own licenses.
