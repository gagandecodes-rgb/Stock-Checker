const { chromium } = require("playwright");

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  await page.goto(process.env.SOURCE_URL, { timeout: 60000 });
  await page.waitForTimeout(5000);

  const productLinks = await page.$$eval(
    "a[href*='/p/']",
    els => [...new Set(els.map(e => e.href))]
  );

  let results = [];

  for (const url of productLinks.slice(0, 25)) {
    const p = await browser.newPage();
    try {
      await p.goto(url, { timeout: 60000 });
      await p.waitForTimeout(3000);

      const html = await p.content();
      if (html.includes("Out of Stock") || html.includes("Sold Out")) {
        results.push({ id: url, in_stock: false });
        await p.close();
        continue;
      }

      const title = await p.title();
      const price = await p.$eval("text=Rs.", el => el.innerText).catch(() => "Check Link");
      const image = await p.$eval("img", el => el.src).catch(() => null);

      const category = html.toLowerCase().includes("men")
        ? "MEN"
        : "WOMEN";

      results.push({
        id: url,
        url,
        name: title,
        price,
        image,
        category,
        in_stock: true
      });
    } catch (e) {}
    await p.close();
  }

  console.log(JSON.stringify(results));
  await browser.close();
})();
