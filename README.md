# RAPORT API module for OpenEMR

An original MIT module that adds four read operations to OpenEMR's authenticated
FHIR API: an encounter's supported notes as one document, the providers,
encounters and appointment links a calendar needs, and a patient's problem list
with parsed diagnosis codes and native procedure orders/reports/results.
No RAPORT account or service is required. Tested on OpenEMR 8.0.0.3.

## Install

1. Put the module in `interface/modules/custom_modules/oe-module-raport-api`, with
   Composer from the OpenEMR root (`composer require raport/oe-module-raport-api`)
   or by extracting a release archive there.
2. In Administration > Modules > Manage Modules, register, install and enable it.
3. Turn on the FHIR API and its system scopes (`rest_fhir_api` and
   `rest_system_scopes_api`, under Administration > Config > Connectors).
   `site_addr_oath` must be the site's public base URL.
4. Register a private-key JWT system client with `api:fhir` and the operation
   scopes it needs, then enable it in Administration > System > API Clients.

Every system client runs as `oe-system`, so read [Permissions and audit](#permissions-and-audit)
before granting scopes. Disabling the module removes its operations and scopes while
built-in FHIR keeps working. There are no data migrations or background jobs.

## Encounter document operation

```text
GET /apis/{site}/fhir/Encounter/{encounterUuid}/$raport-document
Authorization: Bearer <system access token>
```

Requires `api:fhir` and `system/Encounter.$raport-document`. Ordinary Encounter
read scope does not grant export. OpenEMR's native private-key JWT/client-credentials
flow supplies the token; user, patient and browser-session API contexts are excluded.
No request body. The one query parameter, `knownRevision`, takes a revision this
operation returned earlier; while it still matches, the response omits `document`
and skips building the PDF, which is most of an export's cost. See
[OperationDefinition.json](OperationDefinition.json), also advertised through the
existing FHIR metadata and OperationDefinition list.

The FHIR R4 `Parameters` response contains:

| Output | Meaning |
| --- | --- |
| `complete`, `noteCount` | Every active registered form was classified and every supported note rendered successfully |
| `document` | DocumentReference containing the combined inline PDF, patient/encounter references and stable site/encounter identifier. Absent when there are no notes or `knownRevision` matches |
| `revision` | SHA-256 over ordered content, rendering, layout, membership and provenance; stable across unchanged reads, independent of volatile PDF metadata |
| repeated `note` | Registration ID, source form ID/type, author/date where available, revision, effective native lock state, recorded signatures and source-record provenance |
| repeated `encounterSignature` | Encounter-level signature records, separate from individual form signatures |
| repeated `excludedForm` | Encounter header and vitals forms, whose data belongs to dedicated resources, Misc Billing Options forms, which hold claim fields rather than note text, `procedure_order` forms whose order is inactive, and forms saved blank, which print nothing |

The DocumentReference is an on-demand result, not a persisted resource with a
separate read endpoint. Its `date` is generation time, **not last modification**;
use `revision` for change detection. Signature evidence is explicitly
`verification: not-performed`. The PDF is a current rendering, not a historical
signed snapshot. We do not invent `docStatus: final`. Native date strings labeled
`*AtLocal` retain clinic-local meaning rather than pretending to be UTC instants.

## Supported content and explicit limits

- Native SOAP and Clinical Notes, including multiple active entries in one form.
- Native Speech Dictation, Review of Systems Checks, Review of Systems and
  Clinical Instructions, printed by their own reports.
- Native registered `procedure_order` forms, including order history,
  instructions, ordered tests, answers, reports, results and comments. Their
  qualified source snapshot is rendered as escaped text; the native renderer
  embeds scripts and omits some order context. Result edits change the document
  revision. Linked attachment bytes and patient messages are not rendered. A form
  whose order is inactive is listed as `excludedForm`: removing an order's last
  test deactivates the order but leaves its form on the encounter.
- Native layout-based forms (LBF): text, text area, provider, static text and
  template text fields (types 2/3/10/31/34), stored form, current demographic or
  patient history sources (F/D/H), with visible layout definitions. Static text is not stored, so
  like OpenEMR's own report it does not print. A provider field prints the
  provider's current name.
- History-sourced fields (H) are stored per patient: every save adds a
  `history_data` row, and OpenEMR's own report shows every note the newest. The
  export shows each note the newest row saved before the patient's next note on
  any layout with an H field was created. Limits: an H edit made from an older
  note after a newer one exists counts for the newest note, a History page edit
  between visits counts for the earlier note, a note saved in the same second as
  the patient's next note shows the value from before it, and hard-deleting a later note's
  registration moves its value onto the note before it.
- Other widgets/sources, overridden renderers, embedded assets, unknown forms and
  populated hidden/orphan fields fail explicitly. Test a site's own layouts first.
- Each note's HTML is parsed on its own, so a tag or comment a template leaves
  open hides the rest of that note, as it does in OpenEMR, but no later note.
  Comments are dropped. Layout names with an underscore are unsupported, because
  OpenEMR's layout lookup treats `_` as a wildcard.
- OpenEMR's LBF renderer silently omits literal `0`; this version returns an
  unsupported-content error for that value. Expand supported rendering only after
  testing the layouts a clinic actually needs.
- Current demographic changes can change the rendered note and revision without
  editing its stored text. Recorded signatures do not prove the export matches
  the historical content originally signed.
- Two content snapshots detect edits during rendering and return conflict. This
  is not an atomic historical snapshot or a durable change feed.

Errors use FHIR OperationOutcome: `400` malformed input, `403` non-system client
or missing permissions, `404` unknown encounter, `409` inconsistent ownership/source
data or concurrent edits, `422` unsupported content. A missing, invalid or expired
token, or one without the operation scope, gets the host's `401` before the module
runs. Unexpected failures propagate to the host; no partial PDF is returned as complete.

## Visits operation

```text
GET /apis/{site}/fhir/$raport-visits
GET /apis/{site}/fhir/$raport-visits?patient={patientUuid}
GET /apis/{site}/fhir/$raport-visits?start=YYYY-MM-DD&end=YYYY-MM-DD
Authorization: Bearer <system access token>
```

Requires `api:fhir` and `system/*.$raport-visits`. The host matches a scope's
context, resource and operation exactly, so no other scope grants this operation
and it grants nothing else. Only OAuth system clients are served. See
[OperationDefinition-visits.json](OperationDefinition-visits.json), advertised on
every CapabilityStatement `rest` entry and in the OperationDefinition list.

| Mode | Query | Returns |
| --- | --- | --- |
| roster | none | Calendar-qualified providers: username set, `authorized = 1`, `calendar = 1`. Inactive users are included with `active` false |
| patient | `patient` | Every appointment and clinical encounter of that patient |
| window | `start` and `end` | Inclusive clinic-local days, at most 31: appointments dated in the window, recurring series overlapping it, encounters dated in it, every encounter those appointments link even when it is dated outside the window, and every appointment currently linked to one of those encounters |

The `Parameters` response starts with `complete` (always true), then `provider`,
`encounter` and `appointment` entries. Each list is sorted by lower-case UUID, so
unchanged data gives the same response.

- `provider`: UUID, native `users.id`, username, given and family name, NPI as
  stored, `active` and `calendarOwner`. Outside roster mode the list holds every
  provider an appointment or encounter references.
- `encounter`: UUID, patient UUID, `form_encounter.date` verbatim as a clinic-local
  wall clock without an offset, provider and reason.
- `appointment`: UUID, patient UUID, date, start time, end as a clinic-local wall
  clock, provider, status, category code and name, facility Location UUID,
  `recurring` and the linked encounter UUID. Status, category and Location follow
  the host's native FHIR Appointment, so a client needs no second read.

An appointment's link is the Flow Board tracker row that matches its current
patient, date, start time and id; a blank (0) encounter is unlinked. Several
appointments may link one encounter. Recurring series are flagged, never expanded
or linked. The time zone is not returned. Before reading, the module fills missing UUIDs with
the host's own helper, its only write, then reads everything in one
repeatable-read transaction. That helper has no guard against a concurrent
backfill, so two processes filling the same new row at once can each return a
different UUID for it (host behavior).

Errors use FHIR OperationOutcome, and diagnostics name at most one UUID:

| HTTP | When |
| --- | --- |
| `400` | Any other parameter, an array value, `patient` with a date, only one date, a date that does not round-trip as `YYYY-MM-DD`, a reversed window or one over 31 days. Repeated keys collapse to the last value before the module sees them |
| `401` | Missing, invalid or expired token, or no visits scope. The host rejects these before dispatch |
| `403` | Not an OAuth system client, or any ACL denial |
| `404` | Unknown patient UUID |
| `409` | One appointment linked to more than one encounter; a link to a missing, duplicate-numbered or other-patient encounter; a record without a usable UUID or date |

The host never deletes tracker rows, so a `409` repeats until the data is fixed
in OpenEMR: correct the Flow Board tracker row of the named appointment, the
duplicate encounter number, or the named visit's date.

Disabling the module is a full outage for anything that needs this operation. The
host then stops offering `system/*.$raport-visits`, so a new token request naming
it fails with `invalid_scope` for the whole token, and a token issued earlier gets
`404`. Treat a module disable or upgrade window as adapter downtime.

## Problems operation

```text
GET /apis/{site}/fhir/Patient/{patientUuid}/$raport-problems
Authorization: Bearer <system access token>
```

Requires `api:fhir` and `system/Patient.$raport-problems`, which no other scope
grants. Only OAuth system clients are served, and no query parameters are accepted.
See [OperationDefinition-problems.json](OperationDefinition-problems.json),
advertised on the CapabilityStatement's Patient resource and in the
OperationDefinition list.

OpenEMR 8.0.0.3's native FHIR Condition never carries a coding. Its services pass
the raw `lists.diagnosis` string (`ICD10:I10;SNOMED-CT:38341003`) to
`FhirConditionTrait::populateCode`, which builds codings only from a parsed array,
so every Condition falls back to its title as `code.text`. This operation parses
that string with the host's own `CodeTypesService`.

The `Parameters` response starts with `complete` (always true), then one `problem`
per active problem list row (`lists.type = 'medical_problem'`, `activity = 1`),
resolved problems included, sorted by lower-case UUID:

- `uuid`, `title` as stored, and `begdate` and `enddate` as stored. A null or zero
  date is absent.
- `code`, once per diagnosis item in source order. The module splits
  `lists.diagnosis` on `;` and drops blank items and exact repeats. Each carries
  the OpenEMR `type` and `code` from `parseCode`, the FHIR `system` from
  `getSystemForCodeType` (absent for a type the host does not map), and the
  `description` from `lookup_code_description`, which is absent unless the host
  has that code table installed. `parseCodesIntoCodeableConcepts` is not used: it
  keys by code, so it drops a second item with the same code.
- `encounter`, the UUID of each encounter an `issue_encounter` row links, in UUID
  order.

Before reading, the module fills missing problem, patient and encounter UUIDs
with the same host helper and caveat as the visits operation, then reads in one
repeatable-read transaction.

| HTTP | When |
| --- | --- |
| `400` | A malformed patient UUID, or any query parameter |
| `401` | Missing, invalid or expired token, or no problems scope. The host rejects these before dispatch |
| `403` | Not an OAuth system client, or any ACL denial |
| `404` | Unknown patient UUID |
| `409` | A problem without a usable UUID or with a blank title; a diagnosis item without a type or code; a link to a missing, duplicate-numbered or other-patient encounter |

A `409` repeats until someone fixes the named problem in OpenEMR. Disabling the
module removes this operation and its scope the same way as the visits operation.

## Labs operation

```text
GET /apis/{site}/fhir/Patient/{patientUuid}/$raport-labs
Authorization: Bearer <system access token>
```

Requires `api:fhir` and `system/Patient.$raport-labs`. Only OAuth system clients
are served; no query parameters are accepted. See
[OperationDefinition-labs.json](OperationDefinition-labs.json), advertised on the
CapabilityStatement's Patient resource and in the OperationDefinition list.

On OpenEMR 8.0.0.3, UI-created preliminary/corrected reports were serialized as
final, zero values and zero-bound ranges were omitted, and result corrections
did not advance FHIR revision metadata. The versioned
[native laboratory mapper](https://github.com/openemr/openemr/blob/v8_0_0_3/src/Services/FHIR/Observation/FhirObservationLaboratoryService.php)
and native procedure tables were inspected alongside synthetic UI/API probes.
FHIR requires consumers to handle report revisions and distinguish lifecycle
states; see [DiagnosticReport R4](https://hl7.org/fhir/R4/diagnosticreport.html).
This focused [FHIR operation](https://hl7.org/fhir/R4/operations.html) exposes
native content without patching core or inventing resource statuses/terminology.

The R4 `Parameters` response has `complete: true` and repeated entries in this
order:

| Entry | Identity and content |
| --- | --- |
| `order` | Native UUID, optional encounter UUID, source order type/status/priority, dates, history, instructions, diagnosis and available specimen/provider/lab context |
| `test` | Order UUID plus native integer `sequence`; code/name, type, diagnoses, reason and transmission fields |
| `answer` | Order UUID plus test sequence; question code, answer sequence and answer text |
| `report` | Native UUID, order UUID plus test sequence; native status/review status, dates, stored offsets, specimen identifier and notes |
| `result` | Native UUID and report UUID; native type/code/name/value, units, range text, abnormal/status flags, dates, comments and optional document UUID |

All active procedure orders are included, including pending orders with no
reports and legacy/default or non-laboratory types. Clients use the returned
type rather than an inferred laboratory classification. Every associated test,
answer, report and result is included. Families are sorted by UUID or composite
source key. NULL/empty strings and zero dates are absent; literal `0`, comparator
values, text results, negative/prose ranges and comment line breaks survive.
Statuses such as `prelim`, `correct`, `cancel` and custom values remain native
strings. Codes and units carry no guessed LOINC or UCUM assertion. Dates remain
database strings with separate stored report offsets; no offset is inferred.

A correction keeps its native dates, and there is no correction change feed, so
clients re-read the whole snapshot to see it. `complete` covers this contract, not detailed specimen rows, billing/account
data, attachment bytes or linked patient messages. The only write is the same
native missing-UUID backfill, with the concurrency caveat documented for visits;
the clinical read then uses one repeatable-read transaction.

Errors: `400` malformed UUID/query parameters, host `401` authentication/scope
failure, `403` principal/ACL denial, `404` unknown patient and `409` unusable UUIDs
or inconsistent test, encounter or attachment ownership. No partial response is
declared complete. Disabling the module removes this operation and its scope.
There is no lab order creation, transmission or result write API.

## Permissions and audit

The host enforces OAuth scopes. The module additionally checks chart/notes access,
patient squad, encounter sensitivity/category and registered form/layout ACLs;
it validates patient/encounter ownership before invoking native renderers.
Native session/global context is restored afterward. It never impersonates a
human administrator or grants an ACL.

A fresh OpenEMR installation grants its `oe-system` principal administrator ACL
access. An export scope can therefore cover clinic records broadly. Restricted
principal, squad, sensitivity and form ACL behavior was also tested locally.
The module records metadata-only audits through OpenEMR's audit service and
suppresses native response-body logging for every operation, including when API
body logging is enabled. PDF content and tokens are not written into that audit.
Remote assets, PHP and JavaScript are disabled in PDF rendering.

The visits operation passes the principal explicitly to the host ACL checks.
Every mode needs `admin|users`; patient and window modes also need `patients|appt`
and `encounters|auth_a`. Each returned encounter is checked against its sensitivity
and its appointment category ACO (`encounters|notes` for every category of a fresh
install), and each returned patient against its squad.
Any denial fails the whole response with `403`, so `complete` never hides an
omitted row. `admin|super` passes every one of these checks. Each call from an
OAuth system client writes one audit row naming `$raport-visits`, with the URL but
not its query string, empty bodies, and the patient id in patient mode.

The problems operation needs `patients|med`, plus the patient's squad when it has
one. Any denial fails with `403`. Each call writes one audit row naming
`Patient.$raport-problems`, with empty bodies and the patient id once the patient
is found.

The labs operation needs `patients|med` and the patient's squad permission.
Each linked encounter must belong unambiguously to the same patient and pass
sensitivity/category ACLs. A linked result document additionally needs
`patients|docs` and same-patient ownership. Each system-client call writes one
metadata-only audit row naming `Patient.$raport-labs`. Any denial fails the whole
read. Registered order exports also retain the encounter and form ACL checks.

## Reproduce the local checks

Requirements: Docker Compose and OpenSSL; no host PHP or Composer required.

```sh
sh local/up.sh
# Wait for initial installation, then:
sh local/check.sh
```

The digest-pinned host is **OpenEMR 8.0.0.3 / PHP 8.4.16**, with MariaDB 10.11.
PHP 8.2 is the declared minimum, but has not been separately tested. OpenEMR
binds only to `127.0.0.1:19443` (HTTPS) and `127.0.0.1:18080` (HTTP). Login is
`admin`; generated credentials are in ignored `local/.env`. HTTP tests trust
the local self-signed certificate explicitly and still verify TLS.

`check.sh` first takes the harness lock, the directory `/tmp/raport-harness.lock`
inside the OpenEMR container, and stops with "harness busy" while another run
holds it. That `/tmp` is tmpfs, so a container restart clears a lock left by a
crash. It then runs Composer validation and PHP syntax checks together with real
OAuth HTTP and native-renderer tests. It covers authentication/scopes including
all four module scopes in one token, patient isolation, permissions, source ownership,
discovery, empty encounters, edits, signatures, deleted notes, unsupported layouts,
context restoration, audit content, visit modes, window boundaries, appointment
links and their conflicts, problem codes, links and conflicts, pending/multiple
lab orders and reports, zero/text/comparator/range fidelity, native statuses,
corrections, registered order PDFs and module disablement. PDFs include a 120-paragraph
pagination fixture.

For additional schema and extracted PDF-content checks, use Python with
`jsonschema` and Poppler's `pdftotext` installed:

```sh
curl --fail --location https://hl7.org/fhir/R4/fhir.schema.json.zip \
  --output local/artifacts/fhir.schema.json.zip
python3 tests/check-artifacts.py
```

Schema validation checks FHIR structure, not every terminology or semantic rule.
Render the generated PDFs to images for visual inspection after layout changes.
All local artifacts, database/site files, credentials and upstream reference
downloads stay in ignored `local/artifacts/` or `local/.env`.

The harness is disposable development infrastructure. It uses native installation,
seeds the module registration row, and initializes missing user UUIDs to avoid
the fresh host's first-system-token failure. Tests restore temporary settings,
permissions and renderer files, delete their synthetic chart, schedule and user
rows, and disable test OAuth clients/revoke tokens; audit and UUID registry
records remain. Native helpers can commit, so cleanup does not rely on a
surrounding transaction. Never target a clinic DB.

Stop without deleting local data:

```sh
docker compose -f local/compose.yaml stop
```

## License

See [LICENSE](LICENSE). These independently authored files are MIT-licensed;
OpenEMR, its runtime renderers and dependencies retain their existing licenses.
No upstream skeleton/core source is included in the module archive. Packaging
a combined OpenEMR distribution requires complying with its applicable GPL terms.
Never publish credentials, database/site files, logs or generated chart exports.
Apache direct access to the module directory is denied by `.htaccess`, which
needs `mod_rewrite` and `AllowOverride FileInfo`; other servers need an equivalent rule. The module has no public assets.
