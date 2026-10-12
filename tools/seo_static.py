#!/usr/bin/env python3
"""Schreibt strukturierte Daten (schema.org/JSON-LD) in die statischen Seiten der Website.
Quelle der Stammdaten: edition/seo.json (dieselben Daten nutzt edition/shell.php für die PHP-Seiten).
Der Block steht zwischen <!-- SEO-LD:START --> und <!-- SEO-LD:END --> im <head> und wird bei jedem
Lauf neu erzeugt. Aufruf im Repo-Stammverzeichnis (Python 3.8+, ohne Zusatzpakete):
    python3 tools/seo_static.py
Die FAQ der Startseite wird aus den <details>-Elementen von index.html gelesen."""
import html, json, os, re

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
SEO = json.load(open(os.path.join(ROOT, "edition", "seo.json"), encoding="utf-8"))
SITE = "https://25-experts.de"
ORG_ID = SEO["organisation"]["@id"]
START, END = "<!-- SEO-LD:START -->", "<!-- SEO-LD:END -->"

SEITEN = {
    "index.html": {"pfad": "/", "typ": "WebPage", "krumen": []},
    "format.html": {"pfad": "/format", "typ": "WebPage", "krumen": [("Das Format", "/format")]},
    "neutralitaetskodex.html": {"pfad": "/neutralitaetskodex", "typ": "WebPage", "krumen": [("Unser Anspruch", "/neutralitaetskodex")]},
    "ueber-uns.html": {"pfad": "/ueber-uns", "typ": "AboutPage", "krumen": [("Über uns", "/ueber-uns")]},
    "kontakt.html": {"pfad": "/kontakt", "typ": "ContactPage", "krumen": [("Kontakt", "/kontakt")]},
}


def text(s):
    return re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", s))).strip()


def meta(s, name, attr="name"):
    m = re.search(r'<meta %s="%s" content="([^"]*)"' % (attr, re.escape(name)), s)
    return html.unescape(m.group(1)) if m else ""


def faq(s):
    out = []
    for frage, antwort in re.findall(r"<details><summary>(.*?)</summary><div[^>]*>(.*?)</div></details>", s, re.S):
        out.append({"@type": "Question", "name": text(frage), "acceptedAnswer": {"@type": "Answer", "text": text(antwort)}})
    return out


def graph(datei, s):
    cfg = SEITEN[datei]
    url = SITE + cfg["pfad"]
    titel = re.search(r"<title>(.*?)</title>", s, re.S).group(1).strip()
    org = dict(SEO["organisation"])
    knoten = [org, SEO["website"]]
    seite = {"@type": cfg["typ"], "@id": url + "#webpage", "url": url, "name": html.unescape(titel),
             "description": meta(s, "description"), "inLanguage": "de-DE",
             "isPartOf": {"@id": SEO["website"]["@id"]}, "publisher": {"@id": ORG_ID},
             "breadcrumb": {"@id": url + "#breadcrumb"}}
    if datei == "index.html":
        seite["about"] = {"@id": ORG_ID}
        knoten.append(SEO["personen"][0])
        fragen = faq(s)
        if fragen:
            knoten.append({"@type": "FAQPage", "@id": url + "#faq", "mainEntity": fragen, "isPartOf": {"@id": url + "#webpage"}})
    if datei == "ueber-uns.html":
        seite["mainEntity"] = [{"@id": p["@id"]} for p in SEO["personen"]]
        knoten.extend(SEO["personen"])
    knoten.append(seite)
    krumen = [("Startseite", "/")] + cfg["krumen"]
    knoten.append({"@type": "BreadcrumbList", "@id": url + "#breadcrumb", "itemListElement": [
        {"@type": "ListItem", "position": i + 1, "name": n, "item": SITE + p} for i, (n, p) in enumerate(krumen)]})
    return {"@context": "https://schema.org", "@graph": knoten}


def main():
    for datei in SEITEN:
        pfad = os.path.join(ROOT, datei)
        s = open(pfad, encoding="utf-8").read()
        # alten Einzelblock (Organization) der Startseite ablösen
        s = re.sub(r'\n  <script type="application/ld\+json">\s*\{\s*"@context": "https://schema.org",\s*"@type": "Organization".*?</script>', "", s, flags=re.S)
        s = re.sub(r"\n  " + re.escape(START) + ".*?" + re.escape(END), "", s, flags=re.S)
        block = ("\n  " + START + '\n  <script type="application/ld+json">\n  '
                 + json.dumps(graph(datei, s), ensure_ascii=False, separators=(",", ":")) + "\n  </script>\n  " + END)
        s = s.replace("\n</head>", block + "\n</head>", 1)
        open(pfad, "w", encoding="utf-8").write(s)
        print("JSON-LD aktualisiert:", datei)


if __name__ == "__main__":
    main()
