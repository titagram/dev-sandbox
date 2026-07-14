declare const expect: any;
declare const it: any;

import { readFileSync } from "node:fs";
import { resolve } from "node:path";

const readSource = (relativePath: string) =>
  readFileSync(resolve(process.cwd(), relativePath), "utf8");

it("uses Hades Agent browser branding", () => {
  const html = readFileSync(resolve(process.cwd(), "public/index.html"), "utf8");
  const favicon = readFileSync(resolve(process.cwd(), "public/favicon.svg"), "utf8");

  expect(html).toContain("<title>Hades Agent — Project Intelligence</title>");
  expect(html).toContain('href="%PUBLIC_URL%/favicon.svg"');
  expect(favicon).toContain('viewBox="0 0 64 64"');
});

it("uses Hades Agent throughout user-visible frontend copy", () => {
  const legacyProductName = ["Dev", "Board"].join("");
  const visibleSources = {
    login: readSource("src/pages/LoginPage.tsx"),
    shell: readSource("src/components/devboard/AppShell.tsx"),
    engineering: readSource("src/pages/EngineeringPage.tsx"),
    wiki: readSource("src/pages/WikiPage.tsx"),
    system: readSource("src/pages/SystemPage.tsx"),
    mockData: readSource("src/api/mockData.ts"),
  };

  expect(visibleSources.login).toContain(">Hades Agent</p>");
  expect(visibleSources.shell).toContain(">Hades Agent</p>");
  expect(visibleSources.engineering).toContain(
    "Cockpit for Hades Agent operational and technical surfaces.",
  );
  expect(visibleSources.wiki).toContain(
    "Manual project wiki refresh from Hades Agent.",
  );
  expect(visibleSources.system).toContain('title="Export Hades Agent backup"');
  expect(visibleSources.system).toContain(
    "Hades Agent control-plane state and Hades Agent-held evidence.",
  );
  expect(visibleSources.mockData).toContain('name: "Hades Agent Core Platform"');
  expect(visibleSources.mockData).toContain(
    "Hades Agent quality verification is deterministic and controlled.",
  );
  expect(visibleSources.mockData).toContain('label: "Hades Agent storage"');

  for (const source of Object.values(visibleSources)) {
    expect(source).not.toContain(legacyProductName);
  }
});
