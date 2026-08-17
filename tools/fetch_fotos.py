#!/usr/bin/env python3
"""Lädt die 12 Symbolbilder der Website vom CDN und legt sie unter assets/img/fotos/ ab.
Quelle der Wahrheit: tools/fotos.json (Kopie von 02-brand/fotos/fotos.json aus dem Hauptpaket).
Aufruf (im Repo-Stammverzeichnis, Python 3.8+ ohne Zusatzpakete):
    python tools/fetch_fotos.py --site        lädt fehlende Fotos nach assets/img/fotos/
    python tools/fetch_fotos.py --check       nur prüfen: Exit-Code 1, wenn Fotos fehlen
    python tools/fetch_fotos.py --force       alle Fotos neu laden
Wird auch vom GitHub-Workflow .github/workflows/fotos.yml aufgerufen; das Ergebnis wird dort committet."""
import json, os, sys, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
TARGET = os.path.join(ROOT, "assets", "img", "fotos")
MANIFEST = os.path.join(HERE, "fotos.json")

def main():
    fotos = json.load(open(MANIFEST, encoding="utf-8"))["fotos"]
    force = "--force" in sys.argv
    check = "--check" in sys.argv
    os.makedirs(TARGET, exist_ok=True)
    missing = [f for f in fotos if not os.path.exists(os.path.join(TARGET, f["file"])) or os.path.getsize(os.path.join(TARGET, f["file"])) < 1000]
    if check:
        print("fehlend:", [f["file"] for f in missing] if missing else "keine")
        sys.exit(1 if missing else 0)
    todo = fotos if force else missing
    if not todo:
        print("alle", len(fotos), "Fotos vorhanden in", os.path.relpath(TARGET, ROOT))
        return
    failed = []
    for f in todo:
        dst = os.path.join(TARGET, f["file"])
        try:
            print("lade", f["file"], "…", end=" ", flush=True)
            req = urllib.request.Request(f["url"], headers={"User-Agent": "25experts-fetch/1.0"})
            with urllib.request.urlopen(req, timeout=60) as r, open(dst + ".part", "wb") as out:
                out.write(r.read())
            os.replace(dst + ".part", dst)
            print(os.path.getsize(dst) // 1024, "KB")
        except Exception as e:  # noqa: BLE001
            print("FEHLER:", e)
            failed.append(f["file"])
            if os.path.exists(dst + ".part"):
                os.remove(dst + ".part")
    if failed:
        print("nicht geladen:", failed)
        sys.exit(2)
    print("fertig:", len(todo), "Fotos nach", os.path.relpath(TARGET, ROOT))

if __name__ == "__main__":
    main()
