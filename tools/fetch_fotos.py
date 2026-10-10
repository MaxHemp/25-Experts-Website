#!/usr/bin/env python3
"""Lädt die Symbolbilder und das Header-Video der Website vom CDN ins Repo.
Quelle der Wahrheit: tools/fotos.json
  - "fotos":  12 Symbolbilder  -> assets/img/fotos/
  - "videos": Header-Video (MP4/WebM/Poster) -> Ordner aus "dir" (assets/video/)
Einträge können ein eigenes Zielverzeichnis ("dir", relativ zum Repo) und eine
Prüfsumme ("md5") haben; stimmt die Prüfsumme nicht, wird die Datei verworfen.
Aufruf (im Repo-Stammverzeichnis, Python 3.8+ ohne Zusatzpakete):
    python tools/fetch_fotos.py --site        lädt fehlende Dateien
    python tools/fetch_fotos.py --check       nur prüfen: Exit-Code 1, wenn Dateien fehlen
    python tools/fetch_fotos.py --force       alle Dateien neu laden
Wird auch vom GitHub-Workflow .github/workflows/fotos.yml aufgerufen; das Ergebnis wird dort committet."""
import hashlib, json, os, sys, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
DEFAULT_DIR = os.path.join("assets", "img", "fotos")
MANIFEST = os.path.join(HERE, "fotos.json")


def target(entry):
    return os.path.join(ROOT, entry.get("dir", DEFAULT_DIR), entry["file"])


def present(entry):
    dst = target(entry)
    if not os.path.exists(dst) or os.path.getsize(dst) < 1000:
        return False
    if entry.get("md5"):
        return md5(dst) == entry["md5"]
    return True


def md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def main():
    data = json.load(open(MANIFEST, encoding="utf-8"))
    entries = data["fotos"] + data.get("videos", [])
    force = "--force" in sys.argv
    check = "--check" in sys.argv
    missing = [e for e in entries if not present(e)]
    if check:
        print("fehlend:", [e["file"] for e in missing] if missing else "keine")
        sys.exit(1 if missing else 0)
    todo = entries if force else missing
    if not todo:
        print("alle", len(entries), "Dateien vorhanden")
        return
    failed = []
    for e in todo:
        dst = target(e)
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        part = dst + ".part"
        try:
            print("lade", e["file"], "…", end=" ", flush=True)
            req = urllib.request.Request(e["url"], headers={"User-Agent": "25experts-fetch/1.0"})
            with urllib.request.urlopen(req, timeout=120) as r, open(part, "wb") as out:
                out.write(r.read())
            if e.get("md5") and md5(part) != e["md5"]:
                raise ValueError("Prüfsumme stimmt nicht")
            os.replace(part, dst)
            print(os.path.getsize(dst) // 1024, "KB")
        except Exception as ex:  # noqa: BLE001
            print("FEHLER:", ex)
            failed.append(e["file"])
            if os.path.exists(part):
                os.remove(part)
    if failed:
        print("nicht geladen:", failed)
        sys.exit(2)
    print("fertig:", len(todo), "Dateien geladen")


if __name__ == "__main__":
    main()
