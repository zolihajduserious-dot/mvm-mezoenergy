from pathlib import Path

from pypdf import PdfReader, PdfWriter
from pypdf.generic import ArrayObject, ByteStringObject, ContentStream, TextStringObject


SOURCE = Path("templates/mvm/primavill_igenybejelento_2026_lakossagi.pdf")
TARGET = Path("templates/mvm/primavill_igenybejelento_2026_lakossagi_blank.pdf")
REMOVE_TERMS = ("{", "}", "d.", "Project", "Person")


def text_from_operand(operand) -> str:
    if isinstance(operand, list):
        return "".join(text_from_operand(item) for item in operand)

    if isinstance(operand, TextStringObject):
        return str(operand)

    if isinstance(operand, ByteStringObject):
        try:
            return bytes(operand).decode("latin-1", errors="ignore")
        except Exception:
            return ""

    if isinstance(operand, ArrayObject):
        return "".join(text_from_operand(item) for item in operand)

    return ""


reader = PdfReader(str(SOURCE))
writer = PdfWriter()
removed = 0

for page in reader.pages:
    content = page.get_contents()

    if content is not None:
        stream = ContentStream(content, reader)
        new_operations = []

        for operands, operator in stream.operations:
            operator_name = operator.decode("latin-1") if isinstance(operator, bytes) else str(operator)
            text = text_from_operand(operands)

            if operator_name in {"Tj", "TJ", "'", '"'} and any(term in text for term in REMOVE_TERMS):
                removed += 1
                continue

            new_operations.append((operands, operator))

        stream.operations = new_operations
        page.replace_contents(stream)

    writer.add_page(page)

with TARGET.open("wb") as file:
    writer.write(file)

print(f"Restored {TARGET}; removed {removed} placeholder operations.")
