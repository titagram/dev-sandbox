declare const expect: any;
declare const it: any;

import { readFileSync } from "node:fs";
import { resolve } from "node:path";

it("uses Hades Agent browser branding", () => {
  const html = readFileSync(resolve(process.cwd(), "public/index.html"), "utf8");
  const favicon = readFileSync(resolve(process.cwd(), "public/favicon.svg"), "utf8");

  expect(html).toContain("<title>Hades Agent — Project Intelligence</title>");
  expect(html).toContain('href="%PUBLIC_URL%/favicon.svg"');
  expect(favicon).toContain('viewBox="0 0 64 64"');
});
