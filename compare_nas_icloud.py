#!/usr/bin/env python3
from __future__ import annotations

import csv
import hashlib
import os
import unicodedata
from collections import defaultdict
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path


NAS_ROOT = Path("/Volumes/CONTRATOS/CONTRATOS")
ICLOUD_ROOT = Path("/Users/isidrojosesuarezrodriguez/Desktop/Comite/CONTRATOS")
OUTPUT_DIR = Path("/Users/isidrojosesuarezrodriguez/Desktop/Contratos/reports")


@dataclass(frozen=True)
class FileInfo:
    rel: str
    size: int
    mtime: int


def normalized_rel(value: str) -> str:
    return unicodedata.normalize("NFC", value)


def ascii_fold(value: str) -> str:
    value = unicodedata.normalize("NFKD", value)
    value = "".join(ch for ch in value if not unicodedata.combining(ch))
    return value.casefold()


def scan(root: Path) -> dict[str, FileInfo]:
    files: dict[str, FileInfo] = {}
    for path in root.rglob("*.pdf"):
        if not path.is_file():
            continue
        rel = normalized_rel(str(path.relative_to(root)))
        stat = path.stat()
        files[rel] = FileInfo(rel=rel, size=stat.st_size, mtime=int(stat.st_mtime))
    return files


def write_csv(path: Path, rows: list[dict[str, object]], headers: list[str]) -> None:
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=headers)
        writer.writeheader()
        writer.writerows(rows)


def fmt_ts(ts: int) -> str:
    return datetime.fromtimestamp(ts).strftime("%Y-%m-%d %H:%M:%S")


def main() -> int:
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

    nas = scan(NAS_ROOT)
    icloud = scan(ICLOUD_ROOT)

    nas_keys = set(nas)
    icloud_keys = set(icloud)

    only_nas = sorted(nas_keys - icloud_keys)
    only_icloud = sorted(icloud_keys - nas_keys)

    different_meta: list[dict[str, object]] = []
    same_path = sorted(nas_keys & icloud_keys)
    for rel in same_path:
        n = nas[rel]
        i = icloud[rel]
        if n.size != i.size or n.mtime != i.mtime:
            different_meta.append(
                {
                    "rel": rel,
                    "nas_size": n.size,
                    "icloud_size": i.size,
                    "nas_mtime": fmt_ts(n.mtime),
                    "icloud_mtime": fmt_ts(i.mtime),
                }
            )

    nas_fold = defaultdict(list)
    for rel in only_nas:
        nas_fold[ascii_fold(rel)].append(rel)

    icloud_fold = defaultdict(list)
    for rel in only_icloud:
        icloud_fold[ascii_fold(rel)].append(rel)

    normalization_pairs: list[dict[str, object]] = []
    unmatched_only_nas = []
    unmatched_only_icloud = []

    matched_folds = sorted(set(nas_fold) & set(icloud_fold))
    for fold in matched_folds:
        nas_list = sorted(nas_fold[fold])
        icloud_list = sorted(icloud_fold[fold])
        count = min(len(nas_list), len(icloud_list))
        for idx in range(count):
            n_rel = nas_list[idx]
            i_rel = icloud_list[idx]
            normalization_pairs.append(
                {
                    "nas_rel": n_rel,
                    "icloud_rel": i_rel,
                    "same_size": nas[n_rel].size == icloud[i_rel].size,
                    "nas_size": nas[n_rel].size,
                    "icloud_size": icloud[i_rel].size,
                    "nas_mtime": fmt_ts(nas[n_rel].mtime),
                    "icloud_mtime": fmt_ts(icloud[i_rel].mtime),
                }
            )
        unmatched_only_nas.extend(nas_list[count:])
        unmatched_only_icloud.extend(icloud_list[count:])

    for fold, rels in nas_fold.items():
        if fold not in icloud_fold:
            unmatched_only_nas.extend(rels)
    for fold, rels in icloud_fold.items():
        if fold not in nas_fold:
            unmatched_only_icloud.extend(rels)

    unmatched_only_nas = sorted(set(unmatched_only_nas))
    unmatched_only_icloud = sorted(set(unmatched_only_icloud))

    stamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    prefix = OUTPUT_DIR / f"nas_icloud_compare_{stamp}"

    write_csv(
        prefix.with_name(prefix.name + "_only_nas.csv"),
        [{"rel": rel, "size": nas[rel].size, "mtime": fmt_ts(nas[rel].mtime)} for rel in only_nas],
        ["rel", "size", "mtime"],
    )
    write_csv(
        prefix.with_name(prefix.name + "_only_icloud.csv"),
        [{"rel": rel, "size": icloud[rel].size, "mtime": fmt_ts(icloud[rel].mtime)} for rel in only_icloud],
        ["rel", "size", "mtime"],
    )
    write_csv(
        prefix.with_name(prefix.name + "_different_meta.csv"),
        different_meta,
        ["rel", "nas_size", "icloud_size", "nas_mtime", "icloud_mtime"],
    )
    write_csv(
        prefix.with_name(prefix.name + "_normalization_pairs.csv"),
        normalization_pairs,
        ["nas_rel", "icloud_rel", "same_size", "nas_size", "icloud_size", "nas_mtime", "icloud_mtime"],
    )
    write_csv(
        prefix.with_name(prefix.name + "_unmatched_only_nas.csv"),
        [{"rel": rel, "size": nas[rel].size, "mtime": fmt_ts(nas[rel].mtime)} for rel in unmatched_only_nas],
        ["rel", "size", "mtime"],
    )
    write_csv(
        prefix.with_name(prefix.name + "_unmatched_only_icloud.csv"),
        [{"rel": rel, "size": icloud[rel].size, "mtime": fmt_ts(icloud[rel].mtime)} for rel in unmatched_only_icloud],
        ["rel", "size", "mtime"],
    )

    summary_path = prefix.with_name(prefix.name + "_summary.txt")
    lines = [
        "Comparacion NAS vs iCloud",
        f"Generado: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}",
        f"NAS root: {NAS_ROOT}",
        f"iCloud root: {ICLOUD_ROOT}",
        "",
        f"PDFs NAS: {len(nas)}",
        f"PDFs iCloud: {len(icloud)}",
        f"Solo NAS: {len(only_nas)}",
        f"Solo iCloud: {len(only_icloud)}",
        f"Misma ruta con metadatos distintos: {len(different_meta)}",
        f"Pares por normalizacion/acento: {len(normalization_pairs)}",
        f"Solo NAS sin pareja de normalizacion: {len(unmatched_only_nas)}",
        f"Solo iCloud sin pareja de normalizacion: {len(unmatched_only_icloud)}",
        "",
        "Archivos generados:",
        f"- {prefix.name}_summary.txt",
        f"- {prefix.name}_only_nas.csv",
        f"- {prefix.name}_only_icloud.csv",
        f"- {prefix.name}_different_meta.csv",
        f"- {prefix.name}_normalization_pairs.csv",
        f"- {prefix.name}_unmatched_only_nas.csv",
        f"- {prefix.name}_unmatched_only_icloud.csv",
        "",
        "Muestra solo NAS:",
    ]
    lines.extend(f"- {rel}" for rel in only_nas[:15])
    lines.append("")
    lines.append("Muestra solo iCloud:")
    lines.extend(f"- {rel}" for rel in only_icloud[:15])
    lines.append("")
    lines.append("Muestra metadatos distintos:")
    for row in different_meta[:15]:
        lines.append(
            f"- {row['rel']} | NAS {row['nas_mtime']} ({row['nas_size']}) | iCloud {row['icloud_mtime']} ({row['icloud_size']})"
        )

    summary_path.write_text("\n".join(lines) + "\n", encoding="utf-8")

    print(summary_path)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
