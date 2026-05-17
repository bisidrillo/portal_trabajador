#!/usr/bin/env python3
from __future__ import annotations

import argparse
import re
import shutil
import subprocess
import tempfile
import unicodedata
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable


ROOTS = {
    "icloud": Path("/Users/isidrojosesuarezrodriguez/Desktop/Comite/CONTRATOS"),
    "nas": Path("/Volumes/CONTRATOS/CONTRATOS"),
}

DATE_RE = re.compile(r"(?<!\d)(\d{2})/(\d{2})/(\d{2,4})(?!\d)")
FILENAME_DATE_RE = re.compile(r"(?<!\d)(\d{2}-\d{2}-\d{4})(?!\d)")
CLAUSE_RANGE_RE = re.compile(
    r"desde(?:\s+el)?\s+(\d{1,2}/\d{1,2}/\d{2,4}).*?hasta(?:\s+el)?\s+(\d{1,2}/\d{1,2}/\d{2,4})",
    re.IGNORECASE | re.DOTALL,
)
CODE_RE = re.compile(r"_(\d[\d-]*)\.pdf$", re.IGNORECASE)


@dataclass
class Proposal:
    source_path: Path
    target_name: str
    reason: str
    start_date: str
    end_date: str
    role: str


def normalize_text(text: str) -> str:
    text = unicodedata.normalize("NFKC", text)
    text = text.replace("\r", "\n")
    text = re.sub(r"[ \t]+", " ", text)
    return text


def slug_name(name: str) -> str:
    return unicodedata.normalize("NFC", name.strip())


def extract_pdf_text(path: Path) -> str:
    try:
        text = subprocess.check_output(
            ["pdftotext", str(path), "-"],
            stderr=subprocess.DEVNULL,
            text=True,
        )
        text = normalize_text(text)
        if re.search(r"\d{1,2}/\d{1,2}/\d{2,4}", text):
            return text
    except Exception:
        pass

    with tempfile.TemporaryDirectory() as tmpdir:
        prefix = str(Path(tmpdir) / "page")
        subprocess.run(
            ["pdftoppm", "-f", "1", "-l", "3", "-png", str(path), prefix],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=True,
        )
        chunks: list[str] = []
        for image in sorted(Path(tmpdir).glob("page-*.png")):
            ocr = subprocess.check_output(
                ["tesseract", str(image), "stdout", "-l", "spa"],
                stderr=subprocess.DEVNULL,
                text=True,
            )
            chunks.append(ocr)
        return normalize_text("\n".join(chunks))


def to_iso_date(date_value: str) -> tuple[int, int, int] | None:
    cleaned = re.sub(r"\s+", "", date_value).replace(".", "/")
    match = DATE_RE.search(cleaned)
    if not match:
        return None
    day = int(match.group(1))
    month = int(match.group(2))
    year = int(match.group(3))
    if year < 100:
        year += 2000
    if not (1 <= day <= 31 and 1 <= month <= 12 and 1900 <= year <= 2100):
        return None
    return year, month, day


def to_filename_date(date_value: str) -> str | None:
    parsed = to_iso_date(date_value)
    if parsed is None:
        return None
    year, month, day = parsed
    return f"{day:02d}-{month:02d}-{year:04d}"


def find_dates(text: str) -> tuple[str | None, str | None]:
    clause_match = CLAUSE_RANGE_RE.search(text)
    if clause_match:
        start = to_filename_date(clause_match.group(1))
        end = to_filename_date(clause_match.group(2))
        if start and end:
            return start, end

    lines = [line.strip() for line in text.splitlines() if line.strip()]
    start = None
    end = None
    for i, line in enumerate(lines):
        upper = line.upper()
        if "FECHA DE INICIO" in upper:
            candidate_lines = [line] + lines[i + 1 : i + 4]
            for candidate in candidate_lines:
                start = start or first_date(candidate)
        if "FECHA DE FIN" in upper or "FECHA DE TERMINO" in upper or "FECHA DE TÉRMINO" in upper:
            candidate_lines = [line] + lines[i + 1 : i + 4]
            for candidate in candidate_lines:
                end = end or first_date(candidate)
    return start, end


def first_date(text: str) -> str | None:
    for match in DATE_RE.finditer(text):
        value = to_filename_date(match.group(0))
        if value:
            return value
    return None


def clean_role(value: str) -> str:
    value = unicodedata.normalize("NFKC", value).upper()
    value = value.replace('"', " ").replace("'", " ")
    value = re.sub(r"[^A-Z0-9 /-]", " ", value)
    value = re.sub(r"\s+", " ", value).strip(" -")
    return value


def canonical_role(raw_role: str, department: str) -> str:
    role = clean_role(raw_role)
    dept = department.upper()
    if role == "" or role.startswith("FECHA DE ") or role.startswith("IDENTIFICADOR"):
        return ""
    direct_map = {
        "CAMAREROS ASALARIADOS": "AYTE CAMARERA",
        "AYUDANTE DE CAMARERO": "AYTE CAMARERA",
        "AYUDANTE CAMARERO/A TP": "AYTE CAMARERA",
        "AYUDANTE CAMARERO/A": "AYTE CAMARERA",
        "COCINEROS ASALARIADOS": "COCINERO",
        "AYUDANTES DE COCINA": "AYTE COCINA",
        "RECEPCIONISTAS DE HOTELES": "AYTE RECEPCION",
        "AUXILIARES ADMINISTRATIVOS SIN TAREAS DE ATENCION AL PUBLICO NO CLASIFICADOS BAJO OTROS EPIGRAFES": "AUXILIAR ADMINISTRATIVA",
        "PEONES DEL TRANSPORTE DE MERCANCIAS Y DESCARGADORES": "MOZO DE ALMACEN",
        "TRABAJADORES CUALIFICADOS EN HUERTAS INVERNADEROS VIVEROS Y JARDINES": "JARDINERO",
        "BANISTAS SOCORRISTAS": "PISCINERO",
        "BANISTAS": "PISCINERO",
        "ESPECIALISTAS EN TRATAMIENTOS DE ESTETICA BIENESTAR Y AFINES": "AYTE QUIROMASAJISTA",
        "AYUDANTE DE QUIROMASAJISTA TP": "AYTE QUIROMASAJISTA",
        "MOZO DE ALMACEN": "MOZO DE ALMACEN",
        "AYTE DE ECONOMATO": "AYTE DE ECONOMATO",
    }
    if role in direct_map:
        return direct_map[role]

    if "CAMARER" in role and dept == "RESTAURANTE":
        return "AYTE CAMARERA"
    if "COCIN" in role and dept == "COCINA":
        return "COCINERO"
    if "RECEPCION" in role and dept == "RECEPCION":
        return "AYTE RECEPCION"
    if "LIMPIEZA" in role and dept == "PISOS":
        return "CAMARERA DE LIMPIEZA"
    return role.title()


def extract_role(text: str, department: str) -> str | None:
    patterns = [
        r"prestara sus servicios como\s+\(?\d+\)?\s*([A-ZÁÉÍÓÚÑ /.-]+)",
        r"puesto de trabajo de\s+([A-ZÁÉÍÓÚÑ /.-]+)",
        r"Ocupaci[oó]n Desempeñad[ao]\s*:?\s*([A-ZÁÉÍÓÚÑ /.-]+)",
    ]
    for pattern in patterns:
        match = re.search(pattern, text, flags=re.IGNORECASE)
        if match:
            candidate = canonical_role(match.group(1), department)
            if candidate:
                return candidate
    return None


def parse_filename(path: Path) -> tuple[str, str, str, str]:
    parts = path.stem.split("_")
    if len(parts) < 4:
        raise ValueError(f"Nombre no compatible: {path.name}")

    code_match = CODE_RE.search(path.name)
    code = code_match.group(1) if code_match else ""

    contract_type = ""
    type_index = -1
    for i, part in enumerate(parts):
        if re.fullmatch(r"\d{3,4}", part):
            contract_type = part
            type_index = i
            break
    if type_index == -1:
        raise ValueError(f"Tipo no detectado: {path.name}")

    worker = "_".join(parts[:type_index]).strip("_")
    department = parts[type_index + 1] if type_index + 1 < len(parts) else ""
    return slug_name(worker), contract_type, department, code


def build_target_name(worker: str, contract_type: str, department: str, role: str, start: str, end: str, code: str) -> str:
    pieces = [worker, contract_type, department]
    if role:
        pieces.append(role)
    pieces.extend([start, end])
    if code:
        pieces.append(code)
    return "_".join(pieces) + ".pdf"


def propose_for_file(path: Path, forced_department: str | None = None) -> Proposal | None:
    worker, contract_type, department, code = parse_filename(path)
    department = forced_department or department
    if not department:
        return None
    text = extract_pdf_text(path)
    start, end = find_dates(text)
    if not start or not end:
        return None
    role = extract_role(text, department) or ""
    target = build_target_name(worker, contract_type, department, role, start, end, code)
    if target == path.name:
        return None
    return Proposal(
        source_path=path,
        target_name=target,
        reason="pdf_dates_and_role" if role else "pdf_dates_only",
        start_date=start,
        end_date=end,
        role=role,
    )


def collect_proposals(folder_rel: str, limit: int | None = None) -> list[Proposal]:
    icloud_folder = ROOTS["icloud"] / folder_rel
    if not icloud_folder.is_dir():
        raise SystemExit(f"No existe la carpeta iCloud: {icloud_folder}")
    department = Path(folder_rel).name
    proposals: list[Proposal] = []
    for path in sorted(icloud_folder.glob("*.pdf")):
        if len(FILENAME_DATE_RE.findall(path.name)) >= 2:
            continue
        proposal = propose_for_file(path, forced_department=department if department != "SIN DEPARTAMENTO" else None)
        if proposal:
            proposals.append(proposal)
            if limit is not None and len(proposals) >= limit:
                break
    return proposals


def apply_proposals(folder_rel: str, proposals: Iterable[Proposal]) -> None:
    for proposal in proposals:
        rel_old = Path(folder_rel) / proposal.source_path.name
        rel_new = Path(folder_rel) / proposal.target_name
        for root in ROOTS.values():
            src = root / rel_old
            dst = root / rel_new
            if not src.exists():
                continue
            dst.parent.mkdir(parents=True, exist_ok=True)
            if dst.exists():
                src.unlink()
            else:
                shutil.move(str(src), str(dst))


def main() -> int:
    parser = argparse.ArgumentParser(description="Normaliza contratos por carpeta usando fechas del PDF y categoria de clausulas.")
    parser.add_argument("folder_rel", help="Ruta relativa dentro de CONTRATOS, por ejemplo 502/RESTAURANTE")
    parser.add_argument("--apply", action="store_true", help="Aplica los renombres en iCloud y NAS")
    parser.add_argument("--limit", type=int, default=None, help="Limita el numero de propuestas del dry-run/apply")
    args = parser.parse_args()

    proposals = collect_proposals(args.folder_rel, limit=args.limit)
    print(f"folder: {args.folder_rel}")
    print(f"proposals: {len(proposals)}")
    for proposal in proposals:
        print(f"- {proposal.source_path.name}")
        print(f"  -> {proposal.target_name}")
        print(f"  reason={proposal.reason} start={proposal.start_date} end={proposal.end_date} role={proposal.role or 'SIN_ROLE'}")

    if args.apply and proposals:
        apply_proposals(args.folder_rel, proposals)
        print("")
        print("apply: ok")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
