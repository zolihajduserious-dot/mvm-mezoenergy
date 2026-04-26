const fs = require('fs');
const { createCanvas, DOMMatrix, ImageData, Path2D } = require('@napi-rs/canvas');

globalThis.DOMMatrix = DOMMatrix;
globalThis.ImageData = ImageData;
globalThis.Path2D = Path2D;

async function main() {
  const pdfPath = process.argv[2];
  const pageNumber = Number(process.argv[3] || 1);
  const outputPath = process.argv[4] || `.tmp_render_page${pageNumber}.png`;

  const pdfjsLib = await import('file:///C:/Users/kapcs/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/pdfjs-dist/legacy/build/pdf.mjs');
  const data = new Uint8Array(fs.readFileSync(pdfPath));
  const pdf = await pdfjsLib.getDocument({ data, disableFontFace: true }).promise;
  const page = await pdf.getPage(pageNumber);
  const viewport = page.getViewport({ scale: 2 });
  const canvas = createCanvas(Math.ceil(viewport.width), Math.ceil(viewport.height));
  const context = canvas.getContext('2d');
  await page.render({ canvasContext: context, viewport }).promise;
  fs.writeFileSync(outputPath, canvas.toBuffer('image/png'));
  console.log(outputPath);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
