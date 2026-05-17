#!/usr/bin/env python3
from __future__ import annotations

import argparse
import re
from dataclasses import dataclass
from pathlib import Path


DATE_RE = re.compile(r"(?<!\d)(\d{2}-\d{2}-\d{4})(?!\d)")


@dataclass
class ContractFile:
    path: Path
    dates: list[str]
    contract_type: str
    code: str


def extract_contract_type(stem: str) -> str:
    parts = stem.split("_")
    for part in parts:
        if re.fullmatch(r"\d{3,4}", part):
            return part
    return ""


def extract_code(stem: str) -> str:
    parts = stem.split("_")
    tail: list[str] = []
    for part in reversed(parts):
        if DATE_RE.fullmatch(part):
            break
        if re.fullmatch(r"\d[\d-]*", part):
            tail.append(part)
            continue
        if tail:
            break
    if not tail:
        return ""
    return "_".join(reversed(tail))


def scan_folder(folder: Path) -> list[ContractFile]:
    rows: list[ContractFile] = []
    for path in sorted(folder.glob("*.pdf")):
        stem = path.stem
        rows.append(
            ContractFile(
                path=path,
                dates=DATE_RE.findall(stem),
                contract_type=extract_contract_type(stem),
                code=extract_code(stem),
            )
        )
    return rows


def main() -> int:
    parser = argparse.ArgumentParser(description="Dry-run seguro para revisar fechas en nombres de contratos.")
    parser.add_argument("folder", help="Carpeta con PDFs")
    args = parser.parse_args()

    folder = Path(args.folder).expanduser().resolve()
    if not folder.is_dir():
        raise SystemExit(f"No existe la carpeta: {folder}")

    rows = scan_folder(folder)
    ok = [r for r in rows if len(r.dates) == 2]
    one_date = [r for r in rows if len(r.dates) == 1]
    missing = [r for r in rows if len(r.dates) == 0]
    substitution_one_date = [r for r in one_date if r.contract_type in {"410", "510"}]
    suspicious = [r for r in one_date if r.contract_type not in {"410", "510"}]

    print(f"carpeta: {folder}")
    print(f"total_pdfs: {len(rows)}")
    print(f"ok_dos_fechas: {len(ok)}")
    print(f"solo_una_fecha: {len(one_date)}")
    print(f"sin_fechas: {len(missing)}")
    print(f"sustitucion_una_fecha: {len(substitution_one_date)}")
    print("")

    if ok:
        print("[OK] PDFs con inicio y fin en el nombre")
        for row in ok:
            print(f"- {row.path.name} -> inicio={row.dates[0]} fin={row.dates[1]}")
        print("")

    if suspicious:
        print("[REVISAR] PDFs con una sola fecha y tipo no sustitucion")
        for row in suspicious:
            print(f"- {row.path.name} -> fecha={row.dates[0]} tipo={row.contract_type or 'desconocido'}")
        print("")

    if missing:
        print("[PENDIENTE PDF] PDFs sin fechas en el nombre")
        for row in missing:
            code = row.code or "sin_codigo"
            print(f"- {row.path.name} -> tipo={row.contract_type or 'desconocido'} codigo={code}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
