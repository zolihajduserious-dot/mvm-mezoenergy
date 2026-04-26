from pathlib import Path

from pypdf import PdfReader, PdfWriter
from pypdf.generic import ArrayObject, ByteStringObject, ContentStream, TextStringObject


SOURCE = Path("templates/mvm/primavill_igenybejelento_2026_lakossagi.pdf")
TARGET = Path("templates/mvm/primavill_igenybejelento_2026_lakossagi_blank.pdf")


def cleaned_text(value: str, state: dict) -> str:
    result = []

    for char in value:
        if state.get("inside_placeholder", False):
            if char == "}":
                state["inside_placeholder"] = False
            continue

        if char == "{":
            state["inside_placeholder"] = True
            continue

        result.append(char)

    return "".join(result)


def clean_operand(operand, state: dict):
    if isinstance(operand, TextStringObject):
        return TextStringObject(cleaned_text(str(operand), state))

    if isinstance(operand, ByteStringObject):
        try:
            decoded = bytes(operand).decode("utf-8")
        except UnicodeDecodeError:
            try:
                decoded = bytes(operand).decode("latin-1")
            except UnicodeDecodeError:
                return operand

        return TextStringObject(cleaned_text(decoded, state))

    if isinstance(operand, ArrayObject):
        cleaned = ArrayObject()

        for item in operand:
            cleaned.append(clean_operand(item, state))

        return cleaned

    return operand


reader = PdfReader(str(SOURCE))
writer = PdfWriter()
removed_segments = 0

for page in reader.pages:
    content = page.get_contents()

    if content is not None:
        stream = ContentStream(content, reader)
        state = {"inside_placeholder": False}
        new_operations = []

        for operands, operator in stream.operations:
            before = repr(operands)
            cleaned_operands = [clean_operand(operand, state) for operand in operands]
            after = repr(cleaned_operands)

            if before != after:
                removed_segments += 1

            new_operations.append((cleaned_operands, operator))

        stream.operations = new_operations
        page.replace_contents(stream)

    writer.add_page(page)

with TARGET.open("wb") as file:
    writer.write(file)

print(f"Wrote {TARGET} with {removed_segments} cleaned text operations.")
