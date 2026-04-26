const fs = require('fs');

async function main() {
  const pdfjsLib = await import('file:///C:/Users/kapcs/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/pdfjs-dist/legacy/build/pdf.mjs');
  const sourcePath = 'C:/mezoenergy24/templates/mvm/primavill_igenybejelento_2026_lakossagi.pdf';
  const data = new Uint8Array(fs.readFileSync(sourcePath));
  const pdf = await pdfjsLib.getDocument({ data, disableFontFace: true }).promise;
  const output = [];

  for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber++) {
    const page = await pdf.getPage(pageNumber);
    const viewport = page.getViewport({ scale: 1 });
    const content = await page.getTextContent({ includeMarkedContent: false });
    const textItems = [];
    let fullText = '';

    for (const item of content.items) {
      if (!item.str) {
        continue;
      }

      const start = fullText.length;
      fullText += item.str;
      const end = fullText.length;
      const transform = item.transform;
      const fontHeightPt = Math.abs(transform[3] || item.height || 8);
      const xPt = transform[4];
      const baselineYFromBottomPt = transform[5];
      const yTopPt = viewport.height - baselineYFromBottomPt - fontHeightPt;
      const widthPt = item.width || Math.max(10, item.str.length * fontHeightPt * 0.45);

      textItems.push({
        start,
        end,
        str: item.str,
        xMm: xPt * 25.4 / 72,
        yMm: yTopPt * 25.4 / 72,
        widthMm: widthPt * 25.4 / 72,
        heightMm: fontHeightPt * 25.4 / 72,
      });
    }

    const matches = [...fullText.matchAll(/\{d\.[^}]{1,180}\}/g)];

    for (const match of matches) {
      const token = match[0];
      const start = match.index;
      const end = start + token.length;
      const touched = textItems.filter((item) => item.end > start && item.start < end);

      if (touched.length === 0) {
        continue;
      }

      const first = touched[0];
      const last = touched[touched.length - 1];
      const minX = Math.min(...touched.map((item) => item.xMm));
      const minY = Math.min(...touched.map((item) => item.yMm));
      const maxRight = Math.max(...touched.map((item) => item.xMm + item.widthMm));
      const maxBottom = Math.max(...touched.map((item) => item.yMm + item.heightMm));
      output.push({
        page: pageNumber,
        token,
        x: Number(minX.toFixed(2)),
        y: Number(minY.toFixed(2)),
        w: Number(Math.max(8, maxRight - minX).toFixed(2)),
        h: Number(Math.max(3.5, maxBottom - minY).toFixed(2)),
        first: first.str,
        last: last.str,
      });
    }
  }

  console.log(JSON.stringify(output, null, 2));
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
