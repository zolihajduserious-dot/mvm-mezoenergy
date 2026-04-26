from pathlib import Path
from zipfile import ZipFile
import re
from html import unescape

docx = Path("templates/mvm/primavill_igenybejelento_2026_lakossagi.docx")
tokens = {}

with ZipFile(docx) as z:
    for name in z.namelist():
        if not re.match(r"word/(document|header\d+|footer\d+)\.xml$", name):
            continue
        xml = z.read(name).decode("utf-8", errors="ignore")
        text = "".join(unescape(m.group(1)) for m in re.finditer(r"<w:t[^>]*>(.*?)</w:t>", xml, re.S))
        for token in re.findall(r"\{d\.[^}]{1,180}\}", text):
            tokens.setdefault(token, set()).add(name)

for token, names in sorted(tokens.items()):
    print(token, "=>", ", ".join(sorted(names)))
