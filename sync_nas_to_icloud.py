#!/usr/bin/env python3
from __future__ import annotations

import argparse
import csv
import shutil
import unicodedata
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path


NAS_ROOT = Path("/Volumes/CONTRATOS/CONTRATOS")
ICLOUD_ROOT = Path("/Users/isidrojosesuarezrodriguez/Desktop/Comite/CONTRATOS")
REPORTS_DIR = Path("/Users/isidrojosesuarezrodriguez/Desktop/Contratos/reports")


@dataclass(frozen=True)
class FileInfo:
    root: Path
    rel: str
    path: Path
    size: int
    mtime: int


def normalized_rel(value: str) -> str:
    return unicodedata.normalize("NFC", value)


def scan(root: Path) -> dict[str, FileInfo]:
    data: dict[str, FileInfo] = {}
    for path in root.rglob("*.pdf"):
        if not path.is_file():
            continue
        stat = path.stat()
        rel = normalized_rel(str(path.relative_to(root)))
        data[rel] = FileInfo(root=root, rel=rel, path=path, size=stat.st_size, mtime=int(stat.st_mtime))
    return data


def fmt_ts(ts: int) -> str:
    return datetime.fromtimestamp(ts).strftime("%Y-%m-%d %H:%M:%S")


def write_csv(path: Path, rows: list[dict[str, object]], headers: list[str]) -> None:
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=headers)
        writer.writeheader()
        writer.writerows(rows)


def ensure_parent(path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)


def copy_file(src: Path, dst: Path) -> None:
    ensure_parent(dst)
    shutil.copy2(src, dst)


def main() -> int:
    parser = argparse.ArgumentParser(description="Sincroniza contratos del NAS hacia iCloud de forma conservadora.")
    parser.add_argument("--apply", action="store_true", help="Aplica los cambios. Sin esto, solo dry-run.")
    args = parser.parse_args()

    REPORTS_DIR.mkdir(parents=True, exist_ok=True)
    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    prefix = REPORTS_DIR / f"nas_to_icloud_sync_{stamp}"

    nas = scan(NAS_ROOT)
    icloud = scan(ICLOUD_ROOT)

    actions: list[dict[str, object]] = []
    skipped: list[dict[str, object]] = []

    for rel, nas_info in sorted(nas.items()):
        icloud_info = icloud.get(rel)
        if icloud_info is None:
            actions.append(
                {
                    "action": "copy_missing_to_icloud",
                    "rel": rel,
                    "nas_size": nas_info.size,
                    "icloud_size": "",
                    "nas_mtime": fmt_ts(nas_info.mtime),
                    "icloud_mtime": "",
                    "reason": "No existe en iCloud con la misma ruta",
                }
            )
            continue

        if nas_info.size != icloud_info.size:
            actions.append(
                {
                    "action": "update_icloud_from_nas",
                    "rel": rel,
                    "nas_size": nas_info.size,
                    "icloud_size": icloud_info.size,
                    "nas_mtime": fmt_ts(nas_info.mtime),
                    "icloud_mtime": fmt_ts(icloud_info.mtime),
                    "reason": "Tamaño distinto",
                }
            )
            continue

        if nas_info.mtime > icloud_info.mtime:
            actions.append(
                {
                    "action": "refresh_icloud_mtime_or_content",
                    "rel": rel,
                    "nas_size": nas_info.size,
                    "icloud_size": icloud_info.size,
                    "nas_mtime": fmt_ts(nas_info.mtime),
                    "icloud_mtime": fmt_ts(icloud_info.mtime),
                    "reason": "NAS más reciente",
                }
            )
            continue

    nas_only = set(nas) - set(icloud)
    icloud_only = set(icloud) - set(nas)
    for rel in sorted(icloud_only):
        skipped.append(
            {
                "action": "keep_icloud_only",
                "rel": rel,
                "icloud_size": icloud[rel].size,
                "icloud_mtime": fmt_ts(icloud[rel].mtime),
                "reason": "Existe solo en iCloud; no se borra automáticamente",
            }
        )

    applied = 0
    if args.apply:
        for row in actions:
            rel = str(row["rel"])
            src = NAS_ROOT / rel
            dst = ICLOUD_ROOT / rel
            copy_file(src, dst)
            applied += 1

    write_csv(
        prefix.with_name(prefix.name + "_actions.csv"),
        actions,
        ["action", "rel", "nas_size", "icloud_size", "nas_mtime", "icloud_mtime", "reason"],
    )
    write_csv(
        prefix.with_name(prefix.name + "_icloud_only_kept.csv"),
        skipped,
        ["action", "rel", "icloud_size", "icloud_mtime", "reason"],
    )

    summary = [
        "Sincronizacion NAS -> iCloud",
        f"Generado: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}",
        f"Modo: {'APPLY' if args.apply else 'DRY-RUN'}",
        f"NAS root: {NAS_ROOT}",
        f"iCloud root: {ICLOUD_ROOT}",
        "",
        f"Acciones propuestas/aplicadas: {len(actions)}",
        f"Archivos solo en iCloud mantenidos sin borrar: {len(skipped)}",
        f"Aplicadas realmente: {applied}",
        "",
        "Desglose acciones:",
    ]

    counts: dict[str, int] = {}
    for row in actions:
        key = str(row["action"])
        counts[key] = counts.get(key, 0) + 1
    for key in sorted(counts):
        summary.append(f"- {key}: {counts[key]}")

    summary.extend(
        [
            "",
            "Muestra de acciones:",
        ]
    )
    for row in actions[:20]:
        summary.append(f"- {row['action']} | {row['rel']} | {row['reason']}")

    summary.extend(
        [
            "",
            "Archivos generados:",
            f"- {prefix.name}_summary.txt",
            f"- {prefix.name}_actions.csv",
            f"- {prefix.name}_icloud_only_kept.csv",
        ]
    )

    summary_path = prefix.with_name(prefix.name + "_summary.txt")
    summary_path.write_text("\n".join(summary) + "\n", encoding="utf-8")
    print(summary_path)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
