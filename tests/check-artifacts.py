#!/usr/bin/env python3
# SPDX-License-Identifier: MIT
"""Run after local/check.sh; requires jsonschema, Poppler and the official R4 schema ZIP."""
import json
from pathlib import Path
import re
import subprocess
from zipfile import ZipFile

from jsonschema import Draft6Validator

root = Path(__file__).resolve().parents[1]
artifacts = root / "local/artifacts"
with ZipFile(artifacts / "fhir.schema.json.zip") as archive:
    validator = Draft6Validator(json.loads(archive.read("fhir.schema.json")))
paths = [root / name for name in ["OperationDefinition.json", "OperationDefinition-visits.json", "OperationDefinition-problems.json", "OperationDefinition-labs.json"]]
paths += [artifacts / name for name in ["export-alpha.json", "export-empty.json", "export-signed.json", "export-labs.json", "labs-empty.json", "labs-preliminary.json", "labs-corrected.json"]]
for path in paths:
    validator.validate(json.loads(path.read_text()))
    print(f"PASS FHIR R4 schema: {path.name}")


def pdf_text(name):
    return subprocess.check_output(["pdftotext", str(artifacts / name), "-"], text=True)


alpha = pdf_text("export-alpha.pdf")
for marker in ["CLINICAL NOTE", "SOAP SUBJECTIVE", "SOAP OBJECTIVE", "SOAP ASSESSMENT", "SOAP PLAN", "LBF NOTE"]:
    assert "ALPHA " + marker in alpha, marker
assert "ALPHA TEMPLATE NOTE & more" in alpha and "<strong>" not in alpha, "template HTML renders as formatting"
assert "System Operation User" in alpha, "provider field prints the provider's name"
assert "BETA" not in alpha
beta = pdf_text("export-beta.pdf")
assert "BETA CLINICAL NOTE" in beta and "ALPHA" not in beta
long_note = pdf_text("export-long.pdf")
for number in range(1, 121):
    assert f"LONG-LINE-{number:03d}" in long_note, number
multiple = pdf_text("export-multiple.pdf")
assert "ALPHA EDITED CLINICAL" in multiple and "SECOND CLINICAL ENTRY" in multiple
assert "Signature recorded: " in pdf_text("export-signed.pdf")
assert "Synthetic blank note amendment" in pdf_text("export-signed-blank.pdf"), "a signed blank note prints its amendment"
assert "Synthetic encounter sign-off" in pdf_text("export-encounter-signed.pdf"), "an encounter amendment prints"
first, second = pdf_text("export-hpi-first.pdf"), pdf_text("export-hpi-second.pdf")
assert "FIRST VISIT HPI" in first and "SECOND VISIT HPI" not in first, "a note shows its own history value"
assert "SECOND VISIT HPI" in second and "ALPHA SECOND VISIT" in second
# Every filled field of the one-row forms prints, after a layout note that leaves a tag open: each text marker, and one
# YES or yes per choice or checkbox.
forms, expected = pdf_text("export-forms.pdf"), json.loads((artifacts / "export-forms.json").read_text())
for marker in expected["markers"]:
    assert marker in forms, marker
assert len(re.findall(r"\bYES\b", forms)) == expected["ros"], "every review of systems answer prints"
assert len(re.findall(r"\byes\b", forms)) == expected["reviewofs"], "every review of systems checkbox prints"
labs = pdf_text("export-labs.pdf")
for marker in ["HISTORY marker", "INSTRUCTIONS marker", "REASON marker", "ANSWER marker 0", "REPORT NOTES marker", "COMMENTS marker", "0-5", "<5", "Detected", "DNR", "prelim"]:
    assert marker in labs, marker
assert "<script>plain text</script>" in labs, "order text is rendered as literal text"
print("PASS PDF contents: all note types, patient isolation, 120 paragraphs, multiple entries, signature evidence")
print("PASS order PDF: history, instructions, reasons, answers, comments, zero/text/comparator results and native statuses")
